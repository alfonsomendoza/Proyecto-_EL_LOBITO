**Actúa como un Desarrollador Backend Senior/Arquitecto de Software (PHP/SQL).** 
Tu tarea es implementar el **MÓDULO 1: LOGIN, ROLES Y PERMISOS** integrándolo a un proyecto existente sin romper la arquitectura actual.

### 1. CONTEXTO DE BASE DE DATOS EXISTENTE
El sistema YA tiene implementados los módulos de caja y ventas. Las tablas principales (y sus campos clave) son:
*   `caja_apertura` (id, fecha, cajero, caja_id, monto_inicial, estado...)
*   `caja_cierre` (id, apertura_id, ventas_efectivo, total_esperado, estado...)
*   `caja_gastos` (id, apertura_id, concepto, monto...)
*   `ventas` (id, apertura_id, monto, metodo_pago, estado...)

**REGLAS ESTRICTAS DE BASE DE DATOS:**
*   **NO** crear otra tabla de ventas ni de caja.
*   **NO** duplicar la lógica existente de ventas/caja.
*   **NO** eliminar ni renombrar campos existentes.
*   **NO** modificar la lógica de cierre de caja en esta fase.
*   Si requieres agregar un campo (ej. `usuario_id` a `ventas`), primero DEBES explicar: qué tabla se modifica, qué campo se agrega, la relación y qué código existente se actualizará.

### 2. OBJETIVO DEL SPRINT (MÓDULO 1)
Implementar **ÚNICAMENTE**: Login, Logout, Sesión, Usuarios, Roles y Permisos base.
*(Aún NO desarrollarás la lógica de mesas, comandas ni ventas nuevas).*

### 3. MATRIZ DE ROLES Y PERMISOS
El sistema tendrá 4 roles con validación estricta en el **BACKEND** (no solo ocultar botones en frontend):
*   **ADMIN:** Acceso total (Usuarios, Ventas, Caja, Mesas, Eliminar, Reportes, etc).
*   **GERENTE:** Acceso operativo amplio. Puede eliminar en mesas, autorizar, cerrar caja. NO administra usuarios.
*   **CAJERO:** Acceso a ventas, cobrar, manejar pagos. **NO** puede eliminar productos, comandas ni ventas.
*   **MESERO:** Acceso a mesas, abrir mesas, registrar comandas/productos. **NO** cobra, **NO** elimina, **NO** cierra caja.

### 4. REQUERIMIENTOS TÉCNICOS Y DE SEGURIDAD
*   **INYECCIÓN SQL (CRÍTICO):** Todas las consultas SQL DEBEN estar estrictamente preparadas y parametrizadas (usando PDO o MySQLi). Queda absolutamente PROHIBIDO concatenar variables directamente en los strings SQL. 
*   **Contraseñas:** Usa `password_hash()` y `password_verify()`. Cero contraseñas en texto plano, MD5 o SHA1.
*   **Sesión:** Almacena solo datos básicos (`id_usuario`, `nombre`, `rol`). NO guardes la contraseña en sesión.
*   **Middleware/Centralización:** Crea una lógica centralizada para validar la sesión y el rol antes de procesar cualquier acción.
*   **Respuestas API/AJAX:** Todo acceso denegado a un endpoint debe devolver estrictamente el formato: `{"exito": false, "mensaje": "No tiene permisos para realizar esta acción."}`
*   **Auditoría Futura:** Deja el sistema preparado para que las acciones (ventas, aperturas) se vinculen al usuario logueado (revisar si ya hay un campo o si hay que agregarlo).

### 5. METODOLOGÍA DE TRABAJO (PASO A PASO)
Trabajaremos en 3 fases. **NO avances a la siguiente fase sin mi autorización explícita.**

**FASE 1: ANÁLISIS (Haz esto ahora)**
1. Pregúntame por los archivos de conexión, configuración, repositorios o tablas de usuarios si no los tienes aún.
2. Analiza cómo se conecta PHP con la BD y la arquitectura actual.
3. Entrégame un resumen corto: Qué existe, qué falta proponer (ej. estructura mínima de tablas `usuarios` y `roles`), qué archivos se tocarán y si sugieres un cambio en las tablas de ventas/caja para la auditoría.
*(Espera mi confirmación para pasar a la Fase 2).*

**FASE 2: IMPLEMENTACIÓN**
Escribe el código para el Login, validación de sesión y protección de rutas. Reutiliza conexiones y servicios existentes. Asegura el cumplimiento de la regla de consultas parametrizadas.

**FASE 3: PRUEBAS**
Entrégame los casos de prueba o scripts para validar: accesos correctos por rol, rechazos de endpoints sin permisos, login fallidos y cierres de sesión.

### 6. RESTRICCIONES PARA AHORRAR TOKENS (MUY IMPORTANTE)
*   **NO** generes explicaciones largas ni introducciones robóticas.
*   **NO** muestres archivos completos si solo cambiaste unas líneas. Muestra solo el bloque modificado indicando dónde va.
*   Usa este formato exacto para cada cambio:
    *   **Archivo afectado:** `ruta/al/archivo.php`
    *   **Qué se cambió:** "Se agregó middleware de rol y se parametrizó la consulta"
    *   **Código necesario:** `[Sólo el código modificado]`
*   No repitas los requisitos que te acabo de dar.

**Inicia ejecutando la FASE 1.**