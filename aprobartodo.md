### RESPUESTAS Y APROBACIÓN PARA FASE 2

**1. Tablas de usuarios:**
Confirmo que partimos de cero. No hay tablas de usuarios/roles previas.

**2. Campo "Cajero":**
Sí, aplica el cambio. El backend debe tomar el nombre automáticamente de `$_SESSION['nombre']`. 
*Regla estricta:* Mantén la columna original de texto `cajero` (llénala con la sesión) para compatibilidad, y ADEMÁS guarda el ID en el nuevo campo `usuario_id`. En frontend, oculta el input o déjalo `readonly`. No toques los registros anteriores.

**3. Servidor:**
Entorno PHP estándar. Las sesiones nativas funcionan sin problemas. No hay balanceadores de carga.

**4. Aprobación de SQL y Archivos:**
*   Aprobada la tabla `usuarios` con `ENUM` para roles.
*   Aprobado agregar el campo `usuario_id` (NULLABLE) como Foreign Key en `caja_apertura`, `caja_gastos`, `ventas` y `caja_cierre`.
*   Aprobada la arquitectura de archivos propuesta (incluyendo `auth_check.php`).

**Todo aclarado. Procede con la FASE 2 (Implementación de código).**
Recuerda: 
1. Todas las consultas SQL DEBEN estar parametrizadas.
2. Respeta estrictamente el formato corto de respuesta: **Archivo afectado / Qué se cambió / Código necesario**.