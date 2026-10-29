# Contexto del Proyecto: Módulo Avanzado de Gestión de Mesas, Comandas, División de Cuentas y Prefacturación (Restaurante/Bar)

Actúa como un Desarrollador Full-Stack Senior y Arquitecto de Software experto. Necesito diseñar e implementar la lógica de negocio, modelo de datos, endpoints y validaciones para la **FASE 2** de un sistema de restaurante. 

El sistema ya cuenta con los módulos de **VENTAS** y **CAJA** funcionando correctamente bajo una arquitectura limpia y desacoplada. Es una regla crítica y obligatoria **NO REESCRIBIR VENTAS ni CAJA**, ni crear una segunda lógica de cobro. Toda la nueva funcionalidad debe integrarse orgánicamente con lo que ya está construido mediante inyección de servicios o repositorios existentes.

---

## 1. Reglas Principales y Arquitectura (Multivista)
- **Estructura Limpia:** Mantener estrictamente el flujo y patrón arquitectónico del sistema: `index.php` -> `router` -> `views/` -> `API/Controller` -> `Service` -> `Repository`.
- **Persistencia Histórica Estricta:** Prohibido borrar físicamente registros de ventas pagadas, comandas cerradas o productos que tengan historial para no romper la integridad de auditorías o reportes. Se manejan estados (`ACTIVO`/`INACTIVO`, `DISPONIBLE`/`OCUPADA`/`PENDIENTE_PAGO`).
- **Autosuficiencia Administrativa:** Los productos y sus categorías deben poder gestionarse completamente (crear, editar, activar, desactivar) desde las pantallas del sistema, sin necesidad de interactuar directamente con la base de datos por consola SQL.

---

## 2. Matriz Estricta de Roles y Permisos (Backend y Frontend)
El backend debe validar la sesión y el rol del usuario en cada operación crítica. No basta con ocultar el botón en la interfaz visual:
- **MESERO:** 
  - Puede: Ver mesas, abrir mesas, crear comandas, consultar su total e inyectar/agregar productos a la comanda de una mesa. Puede emitir e imprimir la **Pre-factura** de la mesa.
  - NO puede: Cobrar, dar cambio, cerrar caja, y bajo ninguna circunstancia puede anular o eliminar un producto ya registrado en la comanda.
- **CAJERO (Cajera):**
  - Puede: Consultar cuentas pendientes, visualizar las alertas de cobro, procesar y facturar de forma definitiva la venta utilizando el módulo actual de ventas.
  - NO puede: Anular productos de una comanda, eliminar comandas, ni cancelar/anular transacciones de mesas.
- **GERENTE / ADMINISTRADOR:**
  - Acceso total. Son los **únicos autorizados** en el backend para aprobar la anulación o eliminación de un producto ya integrado a una mesa (requiriendo confirmación por PIN, clave o token de autorización), registrando auditoría con usuario, fecha, hora y motivo.

---

## 3. Flujo Operativo y Gestión de Mesas
1. **Apertura de Consumo:** El mesero selecciona una mesa en estado `DISPONIBLE`, abre una comanda y asocia productos del catálogo activo. La mesa cambia inmediatamente a estado `OCUPADA`.
2. **Órdenes de Preparación (Cocina/Barra):** Al guardar los ítems, se genera una orden de preparación que **NO contiene precios, montos, subtotales ni totales**. Solo expone cantidades, nombres de productos y observaciones especiales (ej. *"Sin cebolla"*, *"Extra salsa"*). Se debe controlar mediante estados (`PENDIENTE_IMPRESION`, `IMPRESO`) qué detalles ya se enviaron para evitar duplicar impresiones cuando la mesa pida productos adicionales.

---

## 4. Pre-Facturación, Cuentas Divididas y Propina Flexible
- **Pre-factura de Mesa:** El mesero puede solicitar e imprimir una pre-factura (un estado de cuenta informativo para los clientes). Al emitirse, la mesa activará una **alerta visual o alarma** en el panel indicando textualmente que está por pagarse.
- **División de Cuentas:** El sistema debe permitir la flexibilidad de **dividir la cuenta de una misma mesa en múltiples sub-cuentas independientes** a petición de los comensales (ej. *Mesa 10 - Cuenta 1*, *Mesa 10 - Cuenta 2*). El mesero asignará qué productos e ítems van a cada sub-cuenta de manera balanceada.
- **Solicitud de Cuenta:** Al finalizar el consumo, las sub-cuentas o la cuenta total se envían al módulo de Ventas, cambiando el estado de la mesa a `PENDIENTE_PAGO`. Mientras esté en este estado, la mesa ya no aceptará modificaciones ni productos nuevos por parte del mesero.
- **Gestión Flexible de la Propina:** La propina se calculará e inyectará en la pantalla de cobro del cajero antes de emitir la factura final. Debe permitir:
  1. **Cálculo automático:** Computar un porcentaje estándar (ej. 10% del consumo).
  2. **Ingreso manual:** Permitir al cajero digitar libremente un monto exacto si el cliente decide otorgar más del porcentaje sugerido o un monto personalizado.

---

## 5. Integración Crítica con Ventas y Caja
- **Alertas Dinámicas en Ventas:** La pantalla de Ventas debe mostrar un panel de alertas visible en tiempo real (vía AJAX/Fetch sin recargar la aplicación) que liste detalladamente las mesas abiertas con cuentas listas para pagarse (mostrando Mesa, Comanda/Sub-cuenta, Mesero y Monto).
- **Lógica de Cobro Centralizada:** Al presionar `[ COBRAR ]` en una alerta, los datos de la sub-cuenta o cuenta total se cargan en la pantalla de ventas existente. Se conserva intacta toda la lógica actual de divisas (Córdobas/Dólares), tasas de cambio, vueltos y formas de pago (efectivo, tarjetas, bancos).
- **Cierre Seguro y Reutilización:** Al confirmarse el pago exitoso de la venta, el sistema cierra la comanda (o sub-cuenta) en el historial. Cuando todas las sub-cuentas asociadas a una mesa estén completamente pagadas, la transacción de base de datos debe resetear la mesa automáticamente, **dejándola con el total en cero y en estado `DISPONIBLE`** para un nuevo ciclo de clientes.
- **Control de Concurrencia:** Antes de procesar el pago, el backend verificará en milisegundos que la cuenta siga en estado `PENDIENTE_PAGO` para evitar que dos cajeros cobren la misma mesa en paralelo.
- **Bloqueo Estricto de Cierre de Caja:** El backend (no solo el botón del frontend) tiene prohibido ejecutar el cierre de caja o turno si existe una sola mesa o sub-cuenta en estado `OCUPADA` o `PENDIENTE_PAGO`. Deberá denegar la petición HTTP devolviendo un JSON con el listado exacto de las mesas abiertas y sus montos correspondientes.

---

### TAREA REQUERIDA (FASE 1: ANÁLISIS)
De acuerdo con el orden estricto de implementación del proyecto, **NO modifiques ningún archivo ni crees código todavía**. Ejecuta una auditoría completa del código y la base de datos actual para entregar un diagnóstico detallado respondiendo a los siguientes puntos:

1. **Estructura Existente:** ¿Qué tablas (`mesas`, `comandas`, `productos`, etc.) o fragmentos de código de fases anteriores ya existen en la base de datos?
2. **Componentes Clave:** ¿Cómo están estructurados actualmente `VentaService`, `VentaRepository`, `CajaService` y `CajaRepository` para acoplar la nueva lógica sin alterarlos drásticamente?
3. **Propuesta de Base de Datos:** Presenta el diseño DDL conceptual mínimo necesario para soportar el flujo, haciendo especial énfasis en cómo modelarás la **división de sub-cuentas**, el estado de **impresión de comandas**, la **propina** y la **auditoría de anulaciones** (usuario que anuló, fecha y motivo).
4. **Plan de Archivos:** Lista los nombres de los archivos que propones crear (controladores, vistas, servicios) y cuáles existentes se deben modificar de forma quirúrgica.
5. **Algoritmo de Cierre y Seguridad:** Explica el pseudocódigo o la secuencia lógica del backend para garantizar la consistencia en el momento exacto en que una sub-cuenta es pagada y cómo se valida que la mesa quede libre solo cuando su saldo total sea cero.

**Detén tu ejecución al finalizar esta fase de análisis y espera mi confirmación y aprobación del diagnóstico para proceder con la programación.**
