<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CajaAperturaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\MesaController;
use App\Http\Controllers\VentaController;
use App\Http\Controllers\ComandaController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\GastoController;

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
        return view('caja.apertura');
    })->name('caja.apertura');

    Route::post('/caja/apertura', [CajaAperturaController::class, 'registrarApertura'])
        ->name('caja.abrir');

    Route::get('/caja/ticket-apertura', [CajaAperturaController::class, 'imprimirTicketApertura'])
        ->name('caja.ticket_apertura');

    Route::get('/clientes', [ClienteController::class, 'index'])
        ->name('clientes.index');

    Route::post('/clientes/api', [ClienteController::class, 'storeOrUpdate'])
        ->name('clientes.api');

    Route::get('/catalogo', [CatalogoController::class, 'index'])
        ->name('catalogo.index');

    Route::post('/catalogo/api', [CatalogoController::class, 'storeOrUpdate'])
        ->name('catalogo.api');

    Route::get('/usuarios', [UsuarioController::class, 'index'])
        ->name('usuarios.index');

    Route::post('/usuarios/api', [UsuarioController::class, 'api'])
        ->name('usuarios.api');

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

    // Traslado de cuentas entre mesas (ADMIN, GERENTE y CAJERO).
    Route::post('/pos/cuentas/{cuentaId}/trasladar', [VentaController::class, 'trasladarCuenta'])
        ->name('pos.cuentas.trasladar');

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
});
