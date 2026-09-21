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

    // Ruta de la Etapa 4 - División de cuentas interactiva
    /*
    |--------------------------------------------------------------------------
    | COMANDAS (Usando tu ComandaController original)
    |--------------------------------------------------------------------------
    */
    Route::post('/pos/comanda/api', [ComandaController::class, 'api'])
        ->name('pos.comanda.api');

    Route::get('/pos/comanda/{id}', [ComandaController::class, 'imprimir'])
        ->name('pos.comanda.print');

    Route::get('/pos/comandas/{ventaId}', [ComandaController::class, 'imprimirTodas'])
        ->name('pos.comandas.print');
});
