<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CajaAperturaController;
use App\Http\Controllers\CajaCierreController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\FacturaController;
use App\Http\Controllers\ResumenDiarioController;
use App\Http\Controllers\ResumenPropinasController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\MesaController;
use App\Http\Controllers\VentaController;
use App\Http\Controllers\ComandaController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\GastoController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\CocinaController;
use App\Http\Controllers\ConfiguracionController;

Route::middleware(['guest'])->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])
        ->name('login');

    Route::post('/login', [AuthController::class, 'login'])
        ->name('login.post');
});

Route::middleware(['auth'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('logout');

    Route::get('/', function () {
        return view('inicio');
    })->name('inicio');

    Route::get('/inicio', function () {
        return view('inicio');
    })->name('inicio');

    Route::get('/caja/apertura', function () {
        // Autorización en servidor: la pantalla de caja muestra montos.
        // COCINERO no debe acceder. Se conserva el acceso de los demás roles.
        $rol = strtoupper((string) (auth()->user()->rol->nombre ?? ''));

        if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'], true)) {
            abort(403, 'No tienes permiso para acceder a la caja.');
        }

        return view('caja.apertura');
    })->name('caja.apertura');

    Route::post('/caja/apertura', [CajaAperturaController::class, 'registrarApertura'])
        ->name('caja.abrir');

    Route::get('/caja/ticket-apertura', [CajaAperturaController::class, 'imprimirTicketApertura'])
        ->name('caja.ticket_apertura');

    // ------------------------------------------------------------------
    // CIERRE DE CAJA (arqueo del turno).
    // ADMIN, GERENTE y CAJERO cierran e imprimen; solo ADMIN y GERENTE
    // anulan un cierre. El rol se valida SIEMPRE en CajaCierreController.
    // ------------------------------------------------------------------
    Route::get('/caja/cierre', [CajaCierreController::class, 'index'])
        ->name('caja.cierre');

    Route::post('/caja/cierre', [CajaCierreController::class, 'registrar'])
        ->name('caja.cierre.registrar');

    Route::get('/caja/cierre/ticket', [CajaCierreController::class, 'ticket'])
        ->name('caja.cierre.ticket');

    Route::post('/caja/cierres/{id}/anular', [CajaCierreController::class, 'anular'])
        ->whereNumber('id')
        ->name('caja.cierre.anular');

    // ------------------------------------------------------------------
    // OPERACIONES — RESUMEN DEL DÍA (varios turnos de una fecha).
    // Solo ADMIN y GERENTE consultan e imprimen; el rol se valida SIEMPRE
    // en ResumenDiarioController. Es de SOLO LECTURA.
    // ------------------------------------------------------------------
    Route::get('/reportes/resumen', [ResumenDiarioController::class, 'index'])
        ->name('reportes.resumen');

    Route::get('/reportes/resumen/ticket', [ResumenDiarioController::class, 'ticket'])
        ->name('reportes.resumen.ticket');

    // ------------------------------------------------------------------
    // RESUMEN DE PROPINAS DEL DÍA (consulta administrativa de recapitulación).
    // Solo ADMIN y GERENTE; el rol se valida SIEMPRE en el controlador.
    // Es de SOLO LECTURA: no paga ni marca propinas.
    // ------------------------------------------------------------------
    Route::get('/reportes/propinas', [ResumenPropinasController::class, 'index'])
        ->name('reportes.propinas');

    // ------------------------------------------------------------------
    // HISTORIAL DE FACTURAS.
    // ADMIN y GERENTE listan e imprimen; CAJERO solo imprime una factura.
    // El rol se valida SIEMPRE en FacturaController. Es de SOLO LECTURA.
    // ------------------------------------------------------------------
    Route::get('/facturas', [FacturaController::class, 'index'])
        ->name('facturas.index');

    Route::get('/facturas/ticket', [FacturaController::class, 'ticket'])
        ->name('facturas.ticket');

    Route::get('/clientes', [ClienteController::class, 'index'])
        ->name('clientes.index');

    Route::post('/clientes/api', [ClienteController::class, 'storeOrUpdate'])
        ->name('clientes.api');

    Route::get('/catalogo', [CatalogoController::class, 'index'])
        ->name('catalogo.index');

    Route::post('/catalogo/api', [CatalogoController::class, 'storeOrUpdate'])
        ->name('catalogo.api');

    // Módulo de Ventas: cuentas pendientes de cobro e historial de pagadas.
    // La autorización real (ADMIN/GERENTE/CAJERO) se valida en el controlador;
    // el MESERO trabaja desde el Monitor y no accede al cobro.
    Route::get('/ventas', [VentaController::class, 'panelVentas'])
        ->name('ventas.index');

    Route::get('/usuarios', [UsuarioController::class, 'index'])
        ->name('usuarios.index');

    Route::post('/usuarios/api', [UsuarioController::class, 'api'])
        ->name('usuarios.api');

    // Catálogo oficial de roles: solo ADMIN consulta y crea roles faltantes.
    Route::get('/roles', [RolController::class, 'index'])
        ->name('roles.index');

    Route::post('/roles/api', [RolController::class, 'api'])
        ->name('roles.api');

    Route::get('/mesas', [MesaController::class, 'index'])
        ->name('mesas.index');

    Route::post('/mesas/api', [MesaController::class, 'api'])
        ->name('mesas.api');

    // Panel de subcuentas de una mesa: muestra SOLO las cuentas de su venta
    // activa (no crea nada, es de lectura).
    Route::get('/mesas/{mesaId}/cuentas', [VentaController::class, 'panelCuentas'])
        ->name('mesas.cuentas');

    // Crear una subcuenta vacía en la venta activa de la mesa.
    // Autorizan ADMIN, GERENTE, CAJERO y MESERO (solo CREAR).
    Route::post('/mesas/{mesaId}/cuentas', [VentaController::class, 'crearSubcuenta'])
        ->name('mesas.cuentas.crear');

    /*
    |--------------------------------------------------------------------------
    | VENTAS Y DIVISIÓN DE CUENTAS
    |--------------------------------------------------------------------------
    */

    // Tipo de cambio vigente para cobros en USD (solo lectura). IMPORTANTE:
    // DEBE registrarse ANTES de /pos/{id}, porque esa ruta captura cualquier
    // segmento de dos componentes y "tipo-cambio" llegaría como {id}.
    Route::get('/pos/tipo-cambio', [VentaController::class, 'tipoCambio'])
        ->name('pos.tipoCambio');

    Route::get('/pos/{id}', [VentaController::class, 'pos'])
        ->name('pos.index');

    Route::post('/pos/api', [VentaController::class, 'api'])
        ->name('pos.api');

    Route::get('/pos/{mesaId}/cuentas', [VentaController::class, 'cuentas'])
        ->name('pos.cuentas');

    Route::post('/pos/{mesaId}/cuentas/dividir', [VentaController::class, 'guardarDivision'])
        ->name('pos.cuentas.dividir');

    Route::post('/pos/cuentas/{cuentaId}/pagar', [VentaController::class, 'pagarCuenta'])
        ->name('pos.cuentas.pagar');

    // Prefactura imprimible (SOLO LECTURA): no marca nada como pagado,
    // no emite factura y no toca la mesa ni la venta. Acepta ?descuento=&propina=
    // para previsualizar los ajustes capturados en el modal de cobro.
    Route::get('/pos/cuentas/{cuentaId}/prefactura', [VentaController::class, 'prefactura'])
        ->name('pos.cuentas.prefactura');

    // Traslado de cuentas entre mesas (ADMIN, GERENTE y CAJERO).
    //  - Traslado de la MESA COMPLETA (mueve toda la venta):
    Route::post('/pos/cuentas/{cuentaId}/trasladar', [VentaController::class, 'trasladarCuenta'])
        ->name('pos.cuentas.trasladar');

    //  - Traslado INDIVIDUAL de una subcuenta (mueve solo su consumo a
    //    otra mesa). Implementado de verdad esta vez: el método existe,
    //    valida permisos, cocina y trazabilidad (ver trasladarConsumoCuenta).
    Route::post('/pos/cuentas/{cuentaId}/trasladar-consumo', [VentaController::class, 'trasladarConsumoCuenta'])
        ->name('pos.cuentas.trasladoConsumo');

    // (Eliminada) POST /pos/cuentas/{cuentaId}/dividir.
    //
    // Apuntaba a VentaController@dividirCuenta, un método que NO existe:
    // cualquier petición a esa ruta terminaba en un error 500.
    //
    // Las operaciones reales disponibles son:
    //  - Traslado de la mesa completa -> pos.cuentas.trasladar (trasladarCuenta)
    //  - Traslado de una subcuenta     -> pos.cuentas.trasladoConsumo (trasladarConsumoCuenta)
    //  - División de cuentas          -> pos.cuentas.dividir (guardarDivision)

    // Anulación lógica de un producto individual (solo ADMIN y GERENTE).
    Route::post('/pos/detalle/{detalleId}/anular', [VentaController::class, 'anularDetalle'])
        ->name('pos.detalle.anular');

    // Ruta de la Etapa 4 - División de cuentas interactiva
    /*
    |--------------------------------------------------------------------------
    | COMANDAS (Usando tu ComandaController original)
    |--------------------------------------------------------------------------
    */
    // (Eliminada) POST /pos/comanda/api -> ComandaController@api
    // Apuntaba a un método inexistente. El envío real va por pos.api (VentaController@api).

    Route::get('/pos/comanda/{id}', [ComandaController::class, 'imprimir'])
        ->name('pos.comanda.print');

    Route::get('/pos/comandas/{ventaId}', [ComandaController::class, 'imprimirTodas'])
        ->name('pos.comandas.print');

    // Reimpresión por plato individual: recibe el id de venta_detalles.
    Route::get('/pos/comanda-detalle/{detalleId}', [ComandaController::class, 'imprimirPlato'])
        ->name('pos.comanda.plato');

    Route::get('/pos/comanda-completa/{ventaId}', [ComandaController::class, 'imprimirCompleta'])
        ->name('pos.comanda.completa');

    // ------------------------------------------------------------------
    // MÓDULO DE COPIAS DE SEGURIDAD (solo ADMIN y GERENTE).
    // No hay restauración operativa en producción: se documenta y se prueba
    // únicamente en un entorno aislado.
    // ------------------------------------------------------------------
    Route::get('/backups', [BackupController::class, 'index'])
        ->name('backups.index');

    Route::post('/backups', [BackupController::class, 'crear'])
        ->name('backups.crear');

    Route::get('/backups/{id}/descargar', [BackupController::class, 'descargar'])
        ->name('backups.descargar');

    Route::delete('/backups/{id}', [BackupController::class, 'eliminar'])
        ->name('backups.eliminar');

    // ------------------------------------------------------------------
    // MÓDULO DE GASTOS (salidas de dinero).
    // ADMIN, GERENTE y CAJERO registran, consultan e imprimen.
    // Solo ADMIN y GERENTE anulan; el rol se valida en el backend.
    // ------------------------------------------------------------------
    Route::get('/gastos', [GastoController::class, 'index'])
        ->name('gastos.index');

    Route::post('/gastos', [GastoController::class, 'store'])
        ->name('gastos.store');

    Route::post('/gastos/{id}/anular', [GastoController::class, 'anular'])
        ->name('gastos.anular');

    Route::get('/gastos/ticket', [GastoController::class, 'ticket'])
        ->name('gastos.ticket');

    // Ticket imprimible de un gasto individual (activo o anulado).
    Route::get('/gastos/ticket/{id}', [GastoController::class, 'ticketGasto'])
        ->whereNumber('id')
        ->name('gastos.ticket.gasto');

    // ------------------------------------------------------------------
    // MÓDULO DE INVENTARIO (informativo; nunca bloquea la venta).
    // ADMIN, GERENTE y CAJERO consultan; solo ADMIN y GERENTE administran
    // (control, carga inicial, mínimo y ajustes). El rol se valida en backend.
    // ------------------------------------------------------------------
    Route::get('/inventario', [InventarioController::class, 'index'])
        ->name('inventario.index');

    // El historial se declara antes de las rutas con {producto} para evitar
    // que "historial" se interprete como un id de producto.
    Route::get('/inventario/historial', [InventarioController::class, 'historial'])
        ->name('inventario.historial');

    Route::post('/inventario/{producto}/control', [InventarioController::class, 'cambiarControl'])
        ->whereNumber('producto')
        ->name('inventario.control');

    Route::post('/inventario/{producto}/carga-inicial', [InventarioController::class, 'cargaInicial'])
        ->whereNumber('producto')
        ->name('inventario.cargaInicial');

    Route::post('/inventario/{producto}/minimo', [InventarioController::class, 'configurarMinimo'])
        ->whereNumber('producto')
        ->name('inventario.minimo');

    Route::post('/inventario/{producto}/ajuste', [InventarioController::class, 'ajuste'])
        ->whereNumber('producto')
        ->name('inventario.ajuste');

    // ------------------------------------------------------------------
    // MONITOR DIGITAL DE COCINA (función OPCIONAL).
    // COCINERO, ADMIN y GERENTE consultan y actualizan estados; CAJERO y
    // MESERO reciben 403. Si la cocina digital está deshabilitada, todos los
    // endpoints rechazan el acceso. El rol y el interruptor se validan SIEMPRE
    // en el backend (CocinaController), no en la vista.
    // Rutas HTML y JSON separadas.
    // ------------------------------------------------------------------
    Route::get('/cocina', [CocinaController::class, 'index'])
        ->name('cocina.index');

    Route::get('/cocina/api/panel', [CocinaController::class, 'panel'])
        ->name('cocina.api.panel');

    Route::post('/cocina/api/detalles/{detalle}/estado', [CocinaController::class, 'cambiarEstado'])
        ->whereNumber('detalle')
        ->name('cocina.api.estado');

    // ------------------------------------------------------------------
    // CONFIGURACIÓN DE MÓDULOS (EXCLUSIVA de ADMIN).
    // Habilita/deshabilita cocina digital e inventario de forma independiente.
    // El rol se valida en el backend (ConfiguracionController).
    // ------------------------------------------------------------------
    Route::get('/configuracion', [ConfiguracionController::class, 'index'])
        ->name('configuracion.index');

    Route::post('/configuracion/api', [ConfiguracionController::class, 'actualizar'])
        ->name('configuracion.api');
});
