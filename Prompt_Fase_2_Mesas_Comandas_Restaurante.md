# FASE 2 — MESAS, COMANDAS Y PRE-CUENTA INTEGRADA CON VENTAS

## OBJETIVO

Implementar la siguiente fase del sistema de restaurante:

- Mesas.
- Estados de mesas.
- Comandas por mesa.
- Productos dentro de la comanda.
- Mesero responsable.
- Agregar productos desde el sistema.
- Imprimir órdenes de preparación sin precios ni montos.
- Agregar descripciones/observaciones especiales a los productos.
- Solicitar/cambiar estado de una mesa para cobro.
- Integración con el módulo de VENTAS existente.
- Aviso de mesas pendientes de pago en Ventas.
- Cierre/liberación automática de la mesa después del pago.
- Bloqueo del cierre de caja si existen mesas pendientes de pago.
- Autorización de ADMIN/GERENTE para anular productos de una comanda.

IMPORTANTE:

VENTAS Y CAJA YA FUNCIONAN CORRECTAMENTE.

NO REESCRIBIR VENTAS.

NO REESCRIBIR CAJA.

NO CREAR UNA SEGUNDA LÓGICA DE COBRO.

La nueva funcionalidad debe integrarse con lo que ya existe.

---

# 1. REGLA PRINCIPAL

Antes de modificar archivos:

1. Analiza la arquitectura actual.
2. Identifica cómo funcionan las vistas multivista.
3. Identifica `VentaService`, `VentaRepository` y los endpoints existentes.
4. Identifica `CajaService`, `CajaRepository` y el cierre existente.
5. Identifica cómo se autentica el usuario y cómo se obtienen sus roles.
6. Identifica cómo se administran actualmente los productos.
7. Comprueba si ya existen tablas relacionadas con mesas, comandas o detalles.
8. Comprueba si ya existen endpoints o código parcialmente implementado.

NO crear estructuras duplicadas.

NO crear tablas duplicadas.

NO crear otra conexión a BD.

NO crear otra lógica de ventas.

---

# 2. FLUJO GENERAL DEL RESTAURANTE

El flujo debe quedar conceptualmente así:

```text
MESERO
   ↓
Selecciona mesa
   ↓
Crea/abre comanda
   ↓
Agrega productos
   ↓
La mesa queda OCUPADA
   ↓
Se agregan más productos si es necesario
   ↓
Mesero solicita cuenta / finalizar consumo
   ↓
La mesa queda PENDIENTE DE PAGO
   ↓
Se envía a VENTAS
   ↓
VENTAS muestra alerta de cuenta pendiente
   ↓
Cajero selecciona la cuenta
   ↓
Se cobra utilizando el módulo de VENTAS existente
   ↓
Venta pagada
   ↓
Mesa se CIERRA
   ↓
Mesa vuelve a DISPONIBLE
   ↓
Puede utilizarse nuevamente
```

---

# 3. ESTADOS DE LA MESA

Utilizar estados claros.

Como mínimo evaluar:

```text
DISPONIBLE
OCUPADA
PENDIENTE_PAGO
```

Si la arquitectura necesita otro estado, justificarlo antes.

## DISPONIBLE

La mesa puede ser utilizada por un nuevo cliente.

No tiene una comanda activa.

## OCUPADA

Tiene una comanda activa.

Puede recibir nuevos productos.

## PENDIENTE_PAGO

La comanda fue enviada a cobro.

La mesa ya no debe aceptar modificaciones normales del mesero.

Debe aparecer en Ventas como cuenta pendiente.

---

# 4. IDENTIFICACIÓN DE LA COMANDA

Cada comanda debe tener:

- mesa;
- usuario/mesero que la creó;
- fecha/hora;
- estado;
- productos;
- cantidades;
- precios utilizados;
- subtotal/total según corresponda.

IMPORTANTE:

El precio del producto utilizado en una comanda debe conservarse correctamente.

No depender exclusivamente del precio actual del catálogo para reconstruir una comanda histórica.

---

# 5. PRODUCTOS

Los productos NO deben tener que registrarse directamente en la base de datos.

Debe existir administración desde el sistema.

El usuario autorizado debe poder:

- crear producto;
- editar producto;
- activar producto;
- desactivar producto;
- asignar categoría;
- establecer precio;
- consultar productos.

No permitir que un producto utilizado históricamente sea eliminado físicamente si eso puede romper ventas/comandas históricas.

Preferir:

```text
ACTIVO
INACTIVO
```

para productos que ya tengan historial.

---

# 6. AGREGAR PRODUCTOS A UNA MESA

El mesero debe poder:

1. seleccionar una mesa disponible;
2. abrir una comanda;
3. seleccionar productos;
4. indicar cantidad;
5. agregar productos;
6. continuar agregando productos;
7. consultar el total actual.

Ejemplo:

```text
MESA 5

Tacos       3
Refresco    2
Cerveza     2

Total: C$ XXX.XX
```

Los productos deben salir del catálogo del sistema.

NO solicitar al usuario que escriba manualmente el producto en la BD.

---

# 7. MODIFICACIÓN DE COMANDA

Mientras la mesa esté:

```text
OCUPADA
```

el mesero puede agregar productos.

Debe respetarse el sistema de permisos.

El mesero NO puede anular/eliminar productos de una comanda una vez registrados.

---

# 8. ANULACIÓN DE PRODUCTOS

REGLA IMPORTANTE:

Para anular un producto ya registrado en una mesa:

- ADMIN puede autorizar.
- GERENTE puede autorizar.
- MESERO no puede.
- CAJERO no puede.

No basta con ocultar el botón.

El backend debe validar el permiso.

Flujo:

```text
Mesero solicita anulación
        ↓
Sistema solicita autorización
        ↓
ADMIN / GERENTE
        ↓
Confirma anulación
        ↓
Producto queda ANULADO
```

No borrar físicamente el registro si eso destruye el historial.

Preferir un estado como:

```text
ACTIVO
ANULADO
```

y guardar cuando sea posible:

- usuario que anuló;
- fecha/hora;
- motivo.

Si para esto se requiere una modificación de BD, primero explicar el cambio.

---

# 9. SOLICITAR CUENTA

Cuando el cliente termina de consumir:

El mesero debe poder seleccionar:

```text
SOLICITAR CUENTA
```

La mesa pasa de:

```text
OCUPADA
```

a:

```text
PENDIENTE_PAGO
```

Debe generarse una referencia identificable para Ventas.

Ejemplo:

```text
Mesa 5
Comanda #123
Total: C$ 850.00
Mesero: Juan
Estado: PENDIENTE DE PAGO
```

---

# 10. INTEGRACIÓN CON VENTAS

NO crear otro módulo de cobro.

Utilizar el módulo de VENTAS existente.

Cuando una mesa solicite cuenta:

```text
Comanda
   ↓
Cuenta pendiente
   ↓
VENTAS
```

Ventas debe poder identificar claramente:

- mesa;
- comanda;
- mesero;
- monto;
- estado.

La venta debe conservar la relación con la apertura de caja existente cuando corresponda.

---

# 11. ALERTA EN VENTAS

Cuando exista una mesa pendiente de pago, Ventas debe mostrar una alerta visible.

Ejemplo:

```text
⚠ CUENTAS PENDIENTES

Mesa 5
Comanda #123
Mesero: Juan
Total: C$ 850.00

[ COBRAR ]
```

Si existen varias:

```text
⚠ 3 MESAS PENDIENTES DE PAGO
```

La alerta debe actualizarse sin recargar innecesariamente toda la aplicación cuando sea posible.

Puede utilizar AJAX/fetch si la arquitectura actual lo permite.

NO cargar todas las comandas históricas para mostrar esta alerta.

Consultar únicamente:

```text
mesas/comandas pendientes de pago
```

---

# 12. COBRO EN VENTAS

Al seleccionar:

```text
[ COBRAR ]
```

se debe utilizar la pantalla/lógica de ventas existente.

Mantener los métodos de pago actuales:

- efectivo Córdobas;
- efectivo dólares;
- débito;
- crédito;
- bancos;
- cambio;
- tasa de cambio;
- demás lógica existente.

NO duplicar esa lógica.

La cuenta de la mesa debe entregar a Ventas el monto correspondiente.

El cajero debe poder completar el cobro utilizando el flujo actual.

---

# 13. DESPUÉS DEL PAGO

Cuando la venta se registre correctamente como PAGADA:

```text
Venta pagada
      ↓
Comanda cerrada
      ↓
Mesa cerrada
      ↓
Mesa = DISPONIBLE
```

La mesa debe quedar preparada para utilizarse nuevamente.

Ejemplo:

```text
ANTES:

Mesa 5
Estado: PENDIENTE_PAGO
Total: C$ 850

DESPUÉS DEL PAGO:

Mesa 5
Estado: DISPONIBLE
Comanda anterior: CERRADA
Nueva comanda: ninguna
Total actual: C$ 0
```

IMPORTANTE:

No borrar el historial de la comanda ni de la venta.

La mesa se libera para una NUEVA comanda.

---

# 14. REUTILIZACIÓN DE LA MESA

Una mesa liberada puede utilizarse nuevamente.

Ejemplo:

```text
Mesa 5
   ↓
Cliente A
   ↓
Comanda #100
   ↓
Pago
   ↓
Mesa disponible
   ↓
Cliente B
   ↓
Nueva Comanda #101
```

Nunca reutilizar la misma comanda para otro cliente.

Cada consumo debe tener su propia comanda/historial.

---

# 15. BLOQUEO DE CIERRE DE CAJA

REGLA CRÍTICA:

No se puede cerrar la caja si existen mesas con cuentas pendientes de pago.

Antes del cierre:

```text
Cierre de caja
      ↓
Comprobar mesas PENDIENTE_PAGO
      ↓
¿Existen?
```

Si existen:

```text
NO PERMITIR CIERRE

Hay mesas pendientes de pago.

Mesa 3 - C$ 450
Mesa 5 - C$ 850
```

El cierre solamente puede continuar cuando todas las cuentas pendientes hayan sido pagadas/cerradas.

IMPORTANTE:

Esta validación debe hacerse en BACKEND.

No confiar únicamente en el botón del frontend.

Aunque el usuario intente llamar directamente al endpoint de cierre, debe ser rechazado.

---

# 16. INTEGRACIÓN CON CAJA

La validación debe integrarse al flujo de cierre existente.

NO crear otro cierre.

NO duplicar `CajaService`.

NO duplicar `CajaRepository`.

Si ya existe:

```text
CajaService.cerrar(...)
```

agregar la validación en el punto apropiado de la arquitectura existente.

Antes de modificarlo:

- identificar cómo funciona;
- identificar dónde se valida el cierre;
- utilizar los repositories/services existentes.

---

# 17. CONSISTENCIA DE ESTADOS

Evitar situaciones como:

```text
Venta pagada
pero mesa sigue ocupada
```

o:

```text
Mesa disponible
pero tiene una comanda pendiente
```

o:

```text
Mesa pendiente
pero no existe cuenta asociada
```

Las operaciones críticas deben mantener consistencia.

Cuando sea necesario, utilizar transacciones de BD.

Especialmente:

```text
cobro exitoso
+
cierre de comanda
+
liberación de mesa
```

deben manejarse de forma segura.

---

# 18. CONCURRENCIA

Prevenir que dos cajeros cobren simultáneamente la misma mesa.

Antes de procesar el pago:

comprobar nuevamente que la cuenta siga pendiente.

No confiar solamente en el estado mostrado en pantalla.

Si ya fue pagada:

```text
La cuenta ya fue procesada.
```

No registrar un segundo cobro.

---

# 19. PERMISOS

Respetar el sistema de roles existente.

## MESERO

Puede:

- ver mesas;
- abrir mesa;
- crear comanda;
- agregar productos;
- consultar su comanda;
- solicitar cuenta.

No puede:

- cobrar;
- dar cambio;
- anular productos;
- eliminar comandas;
- cerrar caja.

## CAJERO

Puede:

- consultar cuentas pendientes;
- cobrar;
- dar cambio;
- procesar pagos;
- trabajar con ventas;
- realizar las operaciones de caja permitidas actualmente.

No puede:

- anular productos de una comanda;
- eliminar comandas;
- administrar usuarios.

## GERENTE

Puede:

- consultar mesas;
- consultar comandas;
- corregir/anular productos;
- autorizar anulaciones;
- realizar operaciones administrativas permitidas.

## ADMIN

Acceso total.

---

# 20. PRODUCTOS DESDE EL SISTEMA

Crear/usar una pantalla administrativa para productos.

Debe permitir:

```text
Productos
--------------------------------
[ + Nuevo producto ]

Buscar: __________
Categoría: _______
Estado: __________

Producto | Categoría | Precio | Estado | Acciones
```

Acciones:

```text
Editar
Activar
Desactivar
```

Según permisos.

El mesero solamente debe consultar productos activos.

No mostrar productos inactivos para nuevas comandas.

---

# 21. CATEGORÍAS

Los productos deben poder asociarse a categorías.

Ejemplo:

```text
Comidas
Bebidas
Postres
Entradas
```

Las categorías también deben administrarse desde el sistema si ya existe el módulo correspondiente.

No introducir productos/categorías directamente en SQL manualmente como requisito normal de operación.

---

# 22. INTERFAZ DE MESAS

Crear una vista visual de mesas.

Ejemplo conceptual:

```text
MESAS

┌─────────┐  ┌─────────┐  ┌─────────┐
│ MESA 1  │  │ MESA 2  │  │ MESA 3  │
│ LIBRE   │  │ OCUPADA │  │ PAGO    │
└─────────┘  └─────────┘  └─────────┘

┌─────────┐  ┌─────────┐  ┌─────────┐
│ MESA 4  │  │ MESA 5  │  │ MESA 6  │
│ LIBRE   │  │ OCUPADA │  │ LIBRE   │
└─────────┘  └─────────┘  └─────────┘
```

Utilizar el estilo visual existente del sistema.

No introducir una librería UI innecesaria si no hace falta.

---

# 23. DETALLE DE MESA

Al abrir una mesa:

mostrar:

```text
Mesa
Estado
Mesero
Hora de apertura
Comanda
Productos
Cantidades
Precios
Subtotal
Total
```

Acciones según rol:

```text
Agregar producto
Solicitar cuenta
Anular producto
```

Cada acción debe validar permisos.

---

# 24. NO BORRAR HISTORIAL

No eliminar físicamente:

- ventas pagadas;
- comandas cerradas;
- productos de comandas históricas;
- información necesaria para auditoría.

Una mesa solamente cambia de estado.

Ejemplo:

```text
Mesa 5
```

permanece como mesa.

Su historial debe permanecer.

---

# 25. BASE DE DATOS

Antes de crear tablas nuevas, verificar si ya existen:

```text
mesas
comandas
comanda_detalle
productos
categorias
```

Si existen:

REUTILIZARLAS.

Si no existen, proponer una estructura mínima.

Conceptualmente podría requerirse:

```text
mesas
- id
- nombre/numero
- estado
- activo
```

```text
comandas
- id
- mesa_id
- usuario_id
- estado
- fecha_apertura
- fecha_cierre
- total
```

```text
comanda_detalle
- id
- comanda_id
- producto_id
- cantidad
- precio_unitario
- subtotal
- estado
```

ESTO ES SOLO CONCEPTUAL.

NO crear estas tablas automáticamente sin analizar primero la BD existente.

Si ya existe una estructura equivalente, utilizarla.

---

# 26. AUDITORÍA DE ANULACIONES

Cuando un ADMIN o GERENTE anule un producto, mantener trazabilidad cuando la arquitectura lo permita.

Idealmente:

```text
Producto
Usuario que anuló
Fecha/hora
Motivo
```

Si requiere nuevos campos/tablas:

NO modificar BD automáticamente.

Primero presentar la propuesta y esperar aprobación.

---



# 23-B. IMPRESIÓN DE PEDIDOS DE COCINA / PREPARACIÓN

Cuando el mesero agregue un plato de comida a una mesa/comanda, debe existir la posibilidad de imprimir una ORDEN DE PREPARACIÓN.

IMPORTANTE:

Esta impresión NO es una factura ni un comprobante de pago.

NO debe mostrar precios.

NO debe mostrar montos.

NO debe mostrar subtotal.

NO debe mostrar total.

Su finalidad es únicamente comunicar a cocina/barra qué debe preparar.

## INFORMACIÓN A IMPRIMIR

La orden debe mostrar como mínimo:

```text
--------------------------------
        ORDEN DE PREPARACIÓN
--------------------------------

MESA: 5
COMANDA: #123
MESERO: Juan
FECHA: 06/09/2026
HORA: 12:45 PM

2 x TACOS
1 x HAMBURGUESA
3 x REFRESCO

DESCRIPCIÓN:
Sin cebolla en los tacos.
Hamburguesa bien cocida.

--------------------------------
```

La información exacta puede adaptarse al diseño de impresión existente.

## CANTIDAD

Debe poder imprimirse la cantidad solicitada.

Ejemplo:

```text
3 x TACOS
```

No imprimir:

```text
3 x TACOS - C$ 450.00
```

Debe imprimirse solamente:

```text
3 x TACOS
```

## DESCRIPCIÓN / OBSERVACIONES

Al agregar un producto a una comanda, permitir opcionalmente una descripción o indicación especial.

Ejemplos:

```text
Sin cebolla
Sin chile
Extra salsa
Bien cocido
Separar ingredientes
Para llevar
```

La descripción debe quedar asociada al detalle correspondiente de la comanda.

Ejemplo:

```text
Producto: Tacos
Cantidad: 3
Descripción: 2 sin cebolla y 1 con extra salsa
```

La descripción debe aparecer en la orden de preparación.

## IMPRESIÓN POR PRODUCTO / ORDEN

La interfaz debe permitir al mesero imprimir los productos que correspondan a una orden de preparación.

Evaluar el comportamiento adecuado según la arquitectura existente:

```text
Agregar producto
      ↓
Guardar en comanda
      ↓
Imprimir orden
```

También debe contemplarse que una mesa pueda recibir productos adicionales posteriormente.

Ejemplo:

Primera orden:

```text
2 Tacos
1 Refresco
```

Posteriormente:

```text
3 Tacos
2 Cervezas
```

La segunda impresión debe poder representar solamente los productos nuevos que corresponda enviar a preparación, evitando imprimir nuevamente toda la comanda por error.

## ESTADO DE IMPRESIÓN

Si es necesario para evitar duplicados, implementar un estado o mecanismo equivalente que permita saber qué detalles ya fueron enviados a impresión.

Ejemplo conceptual:

```text
PENDIENTE_IMPRESION
IMPRESO
```

No agregar campos a la BD automáticamente.

Primero comprobar si la estructura existente permite resolverlo.

Si se requiere una modificación de BD, explicar:

- tabla;
- campo;
- propósito;
- impacto;

y esperar aprobación antes de modificarla.

## REIMPRESIÓN

Debe existir una opción controlada para reimprimir una orden cuando sea necesario.

La reimpresión debe indicar claramente que es una reimpresión si la arquitectura lo permite.

Ejemplo:

```text
*** REIMPRESIÓN ***
```

Esto evita confundir una reimpresión con una nueva orden.

## IMPRESIÓN DE COCINA Y VENTAS SON DIFERENTES

NO confundir:

```text
ORDEN DE PREPARACIÓN
```

con:

```text
FACTURA / VENTA / RECIBO
```

La orden de preparación:

- no contiene precios;
- no contiene montos;
- no registra un cobro;
- no cambia el estado de pago;
- no cierra la mesa.

El cobro continúa realizándose únicamente mediante el módulo de VENTAS existente.

Flujo:

```text
MESERO
   ↓
Agrega producto
   ↓
Comanda
   ↓
Imprimir orden de preparación
   ↓
Cocina prepara
   ↓
Mesa continúa OCUPADA
   ↓
Solicitar cuenta
   ↓
VENTAS
   ↓
Cobro
```

## IMPRESORA

Antes de implementar la impresión:

1. Revisar si el sistema ya tiene alguna funcionalidad de impresión.
2. Revisar si existe configuración de impresora.
3. Revisar si existe impresión térmica/ESC-POS u otro mecanismo.
4. Reutilizar la solución existente si está disponible.
5. No agregar una librería nueva sin necesidad.

Si no existe mecanismo de impresión, proponer primero la solución técnica antes de implementarla.

## REGLA DE SEGURIDAD

Imprimir una orden NO debe registrar una venta.

Imprimir una orden NO debe afectar caja.

Imprimir una orden NO debe cambiar el total de la comanda.

Imprimir una orden NO debe cerrar la mesa.

Solamente debe generar la salida de preparación correspondiente.

# 27. PAGINACIÓN

No es necesario paginar visualmente las mesas si el restaurante tiene pocas mesas.

Pero sí debe existir paginación en históricos grandes.

Por ejemplo:

- historial de comandas;
- ventas;
- productos;
- movimientos;
- reportes.

No cargar miles de registros.

Utilizar:

```sql
LIMIT
OFFSET
```

cuando corresponda.

---

# 28. API

Separar las operaciones de mesas/comandas en endpoints si la arquitectura actual lo permite.

Ejemplo conceptual:

```text
api/mesas/listar.php
api/mesas/abrir.php
api/mesas/estado.php

api/comandas/crear.php
api/comandas/detalle.php
api/comandas/agregar-producto.php
api/comandas/solicitar-cuenta.php
api/comandas/anular-producto.php
api/comandas/cerrar.php
```

NO crear duplicados.

Si ya existen endpoints equivalentes, reutilizarlos.

---

# 29. SEGURIDAD BACKEND

Todas las operaciones deben validar:

```text
sesión
usuario
rol
permisos
estado de mesa
estado de comanda
```

Ejemplo:

Un MESERO intenta llamar directamente:

```text
api/comandas/anular-producto.php
```

Debe recibir:

```json
{
    "exito": false,
    "mensaje": "No tiene permisos para realizar esta acción."
}
```

No basta con esconder el botón.

---

# 30. TRANSACCIONES

Utilizar transacciones cuando una operación modifique varias entidades relacionadas.

Especialmente:

```text
Solicitar cuenta
Cobro
Cierre de comanda
Liberación de mesa
```

Evitar estados parciales.

Si una operación falla, no dejar la mesa en un estado inconsistente.

---

# 31. RENDIMIENTO

No cargar:

- todos los productos;
- todas las comandas históricas;
- todas las ventas;
- todos los usuarios;

cuando la pantalla solamente necesita una parte.

Para seleccionar productos:

utilizar búsqueda/filtros o cargar únicamente productos activos necesarios.

Para monitor/mesas:

consultar únicamente información activa.

Para Ventas:

consultar solamente cuentas pendientes.

---

# 32. COMPATIBILIDAD CON LA ARQUITECTURA MULTIVISTA

Respetar la refactorización anterior.

No volver a meter todo en `index.php`.

La nueva estructura debe mantener:

```text
index.php
    ↓
router
    ↓
views/
    ↓
API / Controller
    ↓
Service
    ↓
Repository
```

La vista de mesas debe estar separada.

La vista de comandas debe estar separada.

La administración de productos debe estar separada.

La integración con ventas debe utilizar los Services/Repositories existentes.

---

# 33. PRUEBAS OBLIGATORIAS

Probar como mínimo:

## MESERO

1. Login.
2. Ver mesas.
3. Abrir mesa.
4. Crear comanda.
5. Agregar producto.
6. Agregar varias cantidades.
7. Consultar total.
8. Solicitar cuenta.
9. Confirmar que mesa queda PENDIENTE_PAGO.
10. Intentar anular producto.
11. Confirmar que backend rechaza la operación.

## CAJERO

1. Ver cuentas pendientes.
2. Ver alerta en Ventas.
3. Seleccionar cuenta.
4. Cobrar.
5. Confirmar que venta se registra correctamente.
6. Confirmar que mesa queda DISPONIBLE.
7. Confirmar que la comanda queda CERRADA.
8. Confirmar que la mesa puede reutilizarse.
9. Intentar anular producto.
10. Confirmar rechazo.

## GERENTE

1. Abrir/consultar mesa.
2. Consultar comanda.
3. Autorizar anulación.
4. Confirmar que producto queda ANULADO.
5. Confirmar que no se elimina historial.

## ADMIN

Probar acceso completo.

## CAJA

1. Crear una mesa pendiente de pago.
2. Intentar cerrar caja.
3. Confirmar que el backend bloquea el cierre.
4. Pagar la cuenta.
5. Confirmar que mesa queda DISPONIBLE.
6. Intentar nuevamente cierre.
7. Confirmar que el cierre funciona si no existen pendientes.

## CONCURRENCIA

Intentar cobrar dos veces la misma cuenta.

Debe impedirse el doble cobro.

---

# 34. NO CAMBIAR VENTAS NI CAJA INNECESARIAMENTE

Si para integrar mesas con ventas solamente se necesita:

```text
obtener cuenta pendiente
```

utilizar el código existente.

Si se necesita modificar `VentaService` o `CajaService`, hacerlo únicamente donde sea necesario.

Antes de hacerlo indicar:

```text
ARCHIVO:
CAMBIO:
RAZÓN:
IMPACTO:
```

---

# 35. REGLA DE TOKENS

Trabajar por partes.

NO analizar todo el proyecto innecesariamente.

NO mostrar archivos completos si solamente se modifican unas líneas.

NO crear código que todavía no se necesita.

NO implementar funcionalidades de fases futuras.

NO repetir código existente.

Utilizar:

```text
ARCHIVO:
CAMBIO:
RAZÓN:
PRUEBA:
```

Explicaciones breves.

---

# 36. ORDEN DE IMPLEMENTACIÓN

Trabajar exactamente en este orden:

## FASE 1 — ANÁLISIS

Analizar:

- arquitectura actual;
- BD;
- productos;
- mesas existentes;
- comandas existentes;
- ventas;
- caja;
- usuarios;
- roles;
- API;
- services;
- repositories.

NO modificar archivos.

Entregar diagnóstico.

---

## FASE 2 — MESAS

Implementar:

- catálogo de mesas;
- estados;
- vista de mesas;
- abrir mesa;
- liberar mesa.

---

## FASE 3 — COMANDAS

Implementar:

- crear comanda;
- agregar productos;
- cantidades;
- total;
- detalle;
- estados.

---

## FASE 4 — ANULACIONES

Implementar:

- autorización ADMIN/GERENTE;
- rechazo MESERO/CAJERO;
- estado ANULADO;
- trazabilidad cuando sea posible.

---

## FASE 5 — INTEGRACIÓN CON VENTAS

Implementar:

```text
Solicitar cuenta
       ↓
PENDIENTE_PAGO
       ↓
VENTAS
       ↓
ALERTA
       ↓
COBRO
       ↓
MESA DISPONIBLE
```

Utilizar el módulo de ventas existente.

---

## FASE 6 — BLOQUEO DE CAJA

Integrar la validación:

```text
¿Existen mesas PENDIENTE_PAGO?
        ↓
       SI
        ↓
NO PERMITIR CIERRE
```

Validación obligatoria en backend.

---

## FASE 7 — PRODUCTOS

Asegurar que los productos puedan administrarse completamente desde el sistema:

- crear;
- editar;
- activar;
- desactivar;
- categoría;
- precio.

Sin necesidad de modificar directamente la BD.

---

# 37. CRITERIO FINAL DE ÉXITO

El flujo completo debe quedar:

```text
MESERO
   ↓
MESA
   ↓
COMANDA
   ↓
PRODUCTOS
   ↓
SOLICITAR CUENTA
   ↓
PENDIENTE DE PAGO
   ↓
VENTAS
   ↓
ALERTA
   ↓
CAJERO
   ↓
COBRO
   ↓
VENTA PAGADA
   ↓
COMANDA CERRADA
   ↓
MESA DISPONIBLE
   ↓
NUEVO CLIENTE
```

Y para caja:

```text
CIERRE DE CAJA
       ↓
¿MESAS PENDIENTES?
       ↓
   ┌───┴───┐
   SI      NO
   ↓        ↓
BLOQUEAR   CONTINUAR
```

Y para anulaciones:

```text
PRODUCTO EN COMANDA
        ↓
¿ANULAR?
        ↓
¿ADMIN/GERENTE?
    ┌───┴───┐
   NO       SI
   ↓         ↓
RECHAZAR   AUTORIZAR
             ↓
          ANULADO
```

# INICIO

COMIENZA ÚNICAMENTE CON:

## FASE 1 — ANÁLISIS

NO MODIFIQUES ARCHIVOS TODAVÍA.

Primero revisa el proyecto y dime:

1. Qué tablas de mesas/comandas/productos ya existen.
2. Qué código existente puede reutilizarse.
3. Cómo se integra actualmente Ventas.
4. Cómo se integra actualmente Caja.
5. Qué archivos habría que crear/modificar.
6. Si hace falta modificar BD.
7. Cómo propones implementar el flujo completo.

Después DETENTE Y ESPERA MI CONFIRMACIÓN.
