# BACKUP.md — Módulo de copias de seguridad de El Lobito

Este documento describe el módulo de respaldos de la base de datos, su
configuración, permisos, manejo de errores y el procedimiento para verificar
y probar una restauración en un entorno aislado.

> **Regla crítica:** La restauración es una operación de alto riesgo y NO está
> habilitada en producción. Este módulo solo crea, descarga, elimina y audita
> copias. La restauración se prueba únicamente en una base aislada de pruebas.

---

## 1. Qué hace el módulo

- Crea un volcado SQL de la base de datos real (por defecto `sistemadecaja`)
  usando `mysqldump` cuando está disponible o, si no, el generador PHP/PDO
  integrado (que no requiere herramientas externas). Si `mysqldump` existe
  pero falla en ejecución, se reintenta con el generador PHP/PDO.
- Verifica el archivo generado: tamaño, estructura de volcado MySQL y hash SHA-256.
- Registra cada operación (crear/eliminar) con usuario, fecha, estado y resultado.
- Permite descargar copias solo a usuarios autorizados.
- Permite eliminar copias manualmente (ADMIN/GERENTE), protegiendo la más reciente.
- Bloquea ejecuciones simultáneas peligrosas.

No incluye restauración automática ni eliminación automática por antigüedad.

## 2. Permisos

| Operación            | ADMIN | GERENTE | CAJERO | MESERO |
|----------------------|:-----:|:-------:|:------:|:------:|
| Ver panel / historial|  Sí   |   Sí    |  No    |  No    |
| Crear copia          |  Sí   |   Sí    |  No    |  No    |
| Descargar copia      |  Sí   |   Sí    |  No    |  No    |
| Eliminar copia       |  Sí   |   Sí    |  No    |  No    |
| Restaurar (aislado)  |  —    |   —     |  —     |  —     |

La autorización se valida SIEMPRE en el backend (`BackupController::autorizado`):
`ADMIN` y `GERENTE`. Ocultar botones en Blade/JS no es el control de seguridad.

## 3. Configuración

### Variables de entorno (en `.env`, sin valores secretos aquí)

```dotenv
# Ruta absoluta de mysqldump (OPCIONAL: si no se define, se usa el generador PHP/PDO).
BACKUP_MYSQLDUMP_PATH=C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe

# Discos y directorio del respaldo (opcionales).
BACKUP_DISK=local
BACKUP_PATH=backups

# Conexión a respaldar (vacío = conexión por defecto = sistemadecaja).
BACKUP_DB_CONNECTION=

# Límites del proceso y validación.
BACKUP_TIMEOUT=300
BACKUP_MIN_BYTES=2048
BACKUP_LOCK_SECONDS=60
```

`mysqldump.exe` NO está en el PATH de este equipo. El sistema prefiere usar
`mysqldump` cuando encuentra el binario (variable `BACKUP_MYSQLDUMP_PATH` o el
comando `mysqldump` en el PATH); si no lo encuentra, genera el volcado con el
mismo PHP a través de PDO (`PdoDumper`), de modo que **no es obligatorio**
configurar la ruta. La credencial de la base se lee de `config/database.php`
(variables `DB_USERNAME`/`DB_PASSWORD` del `.env`) — NUNCA se escribe en el
código del módulo.

### Archivos del módulo

| Archivo | Responsabilidad |
|---|---|
| `config/backup.php` | Configuración (disco, ruta, timeout, mínimo, lock). |
| `app/Services/Backup/DatabaseDumper.php` | Contrato del dumper (aislable en pruebas). |
| `app/Services/Backup/MysqldumpDumper.php` | Generación con `mysqldump` (cuando está disponible). |
| `app/Services/Backup/PdoDumper.php` | Generación PHP/PDO sin herramientas externas. |
| `app/Services/Backup/CompositeDumper.php` | Elige entre mysqldump y PHP/PDO, con reintento en ejecución. |
| `app/Services/BackupService.php` | Creación, verificación, historial, descarga, eliminación. |
| `app/Models/BackupRegistro.php` | Registro/historial (tabla `backup_registros`). |
| `app/Http/Controllers/BackupController.php` | HTTP + autorización + respuestas. |
| `resources/views/backups/index.blade.php` | Panel del módulo. |
| `database/migrations/..._create_backup_registros_table.php` | Tabla de historial. |
| `tests/Feature/BackupModuleTest.php`, `tests/Unit/BackupServiceTest.php`, `tests/Feature/PdoBackupDumperTest.php` | Pruebas. |

## 4. Almacenamiento y seguridad

- Los respaldos viven en `storage/app/private/backups/` (disco `local`),
  **fuera del directorio público** y sin URL accesible.
- Los nombres los genera el servidor con formato controlado:
  `backup_<base>_YYYYMMDD_HHMMSS.sql`. No se aceptan rutas ni nombres del cliente.
- El módulo valida que el archivo solicitado pertenezca al directorio autorizado
  (`realpath` + prefijo), rechazando cualquier intento de traversal.
- Credenciales de mysqldump: se entregan mediante un archivo temporal
  `--defaults-extra-file` creado con permisos restrictivos y eliminado en un
  bloque `finally`. El comando se construye como array (sin shell) para evitar
  inyección por argumentos.
  **Limitación en Windows:** el control ACL de `chmod 0600` es limitado; el
  archivo temporal se crea en el directorio temporal del usuario y se elimina al
  terminar. Si se necesita una garantía mayor, evaluar restringir el directorio
  temporal con políticas de Windows.

## 5. Concurrencia

La operación de crear y la de eliminar usan el mismo candado atómico
(`Cache::lock('el-lobito:backup:operacion', …)`). Si ya hay una operación en
curso, la segunda solicitud responde **HTTP 409** sin iniciar otro proceso.

- En producción el driver `CACHE_STORE` por defecto es `database`, que ofrece
  exclusión mutua real entre procesos.
- En pruebas se usa el driver `array`, suficiente para validar el bloqueo dentro
  de un proceso.

## 6. Estados y errores

| Estado | Significado |
|---|---|
| `EN_PROCESO` | La copia se está generando. |
| `COMPLETADO` | Generada y verificada (tamaño, estructura y hash OK). |
| `FALLIDO`   | El proceso falló, se agotó el tiempo, o el archivo no valida. |
| `ELIMINADO` | Copia eliminada manualmente (queda auditado). |

Errores gestionados sin secretos en logs:

- `mysqldump` no encontrado o ruta mal configurada (se intenta el generador PHP/PDO).
- Permisos insuficientes o espacio insuficiente.
- Tiempo máximo de ejecución superado (`BACKUP_TIMEOUT`).
- Proceso interrumpido / código de salida distinto de cero.
- Archivo vacío, incompleto o sin estructura de volcado MySQL.

La validación SIEMPRE revisa el contenido (no solo la extensión ni el tamaño):
detecta marcas reales de volcado (cabecera `MySQL dump`, `CREATE TABLE`,
`INSERT INTO`, `LOCK TABLES`).

## 7. Crear, descargar y eliminar

- **Crear:** `POST /backups` (JSON, con CSRF). Solo ADMIN/GERENTE.
- **Panel:** `GET /backups` — historial con fecha, usuario, tamaño, estado y resultado.
- **Descargar:** `GET /backups/{id}/descargar` — protegida; el archivo se sirve
  con su nombre real y solo si pertenece al módulo.
- **Eliminar:** `DELETE /backups/{id}` — manual, con confirmación en la interfaz,
  solo ADMIN/GERENTE, y auditada. La copia más reciente está protegida: para
  borrarla deliberadamente, primero genere una copia nueva y luego elimine la
  anterior, o utilice un flujo administrativo explícito definido por el equipo.

No existe eliminación automática ni por antigüedad.

## 8. Verificar la integridad de una copia

1. En el panel, confirmar que el registro figure como `COMPLETADO`.
2. Descargar el archivo y calcular su hash:
   ```powershell
   Get-FileHash .\backup_sistemadecaja_<fecha>.sql -Algorithm SHA256
   ```
3. Comparar con el `sha256` mostrado en el panel.

> **Importante:** el hash garantiza que el archivo coincide con el contenido
> registrado, pero **no demuestra por sí solo que sea restaurable**. La
> restaurabilidad se comprueba con una restauración de prueba (sección 9).

## 9. Restauración de prueba en entorno AISLADO

Nunca use las credenciales de producción ni la base real `sistemadecaja`.

1. **Seleccione y verifique** la copia (sección 8).
2. **Cree una base de pruebas separada**, por ejemplo en el mismo MySQL:
   ```sql
   CREATE DATABASE el_lobito_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. **Restaure** únicamente sobre esa base (con el cliente `mysql` del servidor
   MySQL 8, no con credenciales de producción):
   ```powershell
   & "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" `
     --host=localhost --port=3306 `
     --user=<usuario_pruebas> --password `
     el_lobito_restore_test < .\backup_sistemadecaja_<fecha>.sql
   ```
   (Ajuste el usuario de pruebas con permisos solo sobre la base aislada.)
4. **Compruebe las tablas y los datos**:
   ```powershell
   mysql ... el_lobito_restore_test -e "SHOW TABLES; SELECT COUNT(*) FROM ventas;"
   ```
5. **Registre el resultado** en el historial manual del proyecto (fecha, copia,
   estado, observaciones). Esta prueba NO documentada no es una validación.
6. **Limpiar el entorno**: `DROP DATABASE el_lobito_restore_test;` — esto nunca
   toca la base real.

Pasos necesarios ANTES de pensar en una restauración en producción (futura y con
autorización separada):
- Definir quién autoriza cada restauración.
- Respaldar el estado actual de la base antes de restaurar.
- Detener las escrituras de la aplicación durante la operación.
- Procedimiento de verificación posterior (conteos, cierre de caja, mesas).
- Registro de auditoría del evento completo.

## 10. Pruebas automatizadas

Las pruebas usan **SQLite en memoria**, `Storage::fake()` y dumpers aislados;
NUNCA invocan `mysqldump` real ni tocan `sistemadecaja`. El generador PHP/PDO
se prueba con un PDO de SQLite en memoria (datos, `NULL`, caracteres
especiales, definiciones, hash, auditoría y **reimportación del volcado en otra
base SQLite aislada**), sin declarar por sí solo compatibilidad integral con
MySQL.

```bash
php artisan test --filter=BackupModuleTest
php artisan test --filter=PdoBackupDumperTest
php artisan test          # suite completa
```

Cobertura: creación exitosa, proceso fallido, archivo vacío/incompleto/inválido,
hash SHA-256, auditoría de éxitos y fallos, autorización ADMIN/GERENTE, rechazo
CAJERO/MESERO, acceso no autenticado, descarga protegida, manipulación de nombres
y rutas, bloqueo de ejecuciones simultáneas, eliminación con protección de la
copia más reciente, fallos de almacenamiento, timeout, ausencia de rutas de
restauración operativas, generación PHP/PDO completa, escritura fallida que no
deja copia válida, reintento automático de mysqldump → PHP/PDO, selección del
dumper y reimportación del respaldo generado en un SQLite aislado.

## 11. Limitaciones conocidas

- `mysqldump` es opcional: si no está instalado ni configurado, se usa el
  generador PHP/PDO integrado (sin herramientas externas). Si está instalado
  pero falla en ejecución, también se reintenta con PHP/PDO.
- Generador PHP/PDO: las columnas `BLOB`/binarias se vuelcan como literal
  hexadecimal `0x…`; la sesión de la conexión compartida se restaura (modo
  no-buffer y `FOREIGN_KEY_CHECKS`) y una escritura incompleta descarta el
  archivo. La validación final contra MySQL real está pendiente.
- En Windows, el `chmod 0600` del archivo temporal de credenciales tiene
  efectos limitados por el sistema de ACL; se mitiga con directorio temporal de
  usuario y borrado inmediato.
- El bloqueo por caché depende del driver `CACHE_STORE` (en producción se usa
  `database`, con exclusión mutua real).
- La restauración operativa NO está implementada a propósito.