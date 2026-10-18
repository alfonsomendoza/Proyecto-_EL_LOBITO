<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

class CocinaMonitorTest extends TestCase
{
    use CreaEsquemaCompleto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));
        $this->crearEsquemaCompleto();
    }

    private function usuario(string $rolNombre): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);
        $usuarioId = DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre . ' Test',
            'username' => strtolower($rolNombre) . '_' . uniqid(),
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]);
        return User::findOrFail($usuarioId);
    }

    private function prepararPedido(bool $cocinaDigital = true): array
    {
        $admin = $this->usuario('ADMIN');
        // Habilitar cocina digital si solicitado
        if ($cocinaDigital) {
            $this->actingAs($admin)->postJson('/configuracion/api', ['modulo' => 'cocina', 'habilitado' => true])->assertStatus(200);
        }

        $mesaId = DB::table('mesas')->insertGetId(['numero' => '1', 'capacidad' => 4, 'estado' => 'LIBRE']);
        $ventaId = DB::table('ventas')->insertGetId(['mesa_id' => $mesaId, 'monto' => 0, 'estado' => 'ACTIVA', 'fecha' => now()]);
        DB::table('cuentas')->insertGetId(['venta_id' => $ventaId, 'nombre' => 'Cuenta Principal', 'total' => 0, 'estado' => 'ABIERTA']);
        $prodCocina = DB::table('productos')->insertGetId(['nombre' => 'Plato', 'precio' => 10, 'requiere_cocina' => 1, 'activo' => true]);
        $prodBarra = DB::table('productos')->insertGetId(['nombre' => 'Barra', 'precio' => 5, 'requiere_cocina' => 0, 'activo' => true]);

        $this->actingAs($admin)->postJson('/pos/api', [
            'accion' => 'enviar_cocina',
            'venta_id' => $ventaId,
            'carrito' => json_encode([['id' => $prodCocina, 'cantidad' => 2, 'notas' => 'sin cebolla'], ['id' => $prodBarra, 'cantidad' => 1, 'notas' => null]]),
        ])->assertStatus(200);

        $detalleCocinaId = DB::table('venta_detalles')->where('venta_id', $ventaId)->where('producto_id', $prodCocina)->value('id');
        $comandaId = DB::table('comandas')->where('venta_id', $ventaId)->value('id');

        return [$admin, $detalleCocinaId, $comandaId, $ventaId, $mesaId];
    }

    public function test_monitor_solo_visible_para_admin_gerente_cocinero_y_bloqueado_si_deshabilitado(): void
    {
        [$admin, $detalleId, $comandaId, $ventaId, $mesaId] = $this->prepararPedido(false); // cocina deshabilitada
        $gerente = $this->usuario('GERENTE');
        $cocinero = $this->usuario('COCINERO');
        $cajero = $this->usuario('CAJERO');
        $mesero = $this->usuario('MESERO');

        $this->actingAs($cajero)->get('/cocina')->assertStatus(403);
        $this->actingAs($mesero)->get('/cocina')->assertStatus(403);
        $this->actingAs($cocinero)->get('/cocina')->assertStatus(403);
        $this->actingAs($gerente)->get('/cocina')->assertStatus(403);
        $this->actingAs($admin)->get('/cocina')->assertStatus(403);

        // Habilitar y probar
        $this->actingAs($admin)->postJson('/configuracion/api', ['modulo' => 'cocina', 'habilitado' => true])->assertStatus(200);
        $this->actingAs($cocinero)->get('/cocina')->assertStatus(200);
        $this->actingAs($gerente)->get('/cocina')->assertStatus(200);
        $this->actingAs($admin)->get('/cocina')->assertStatus(200);
    }

    public function test_panel_muestra_solo_cocina_sin_precios(): void
    {
        [$admin, $detalleId, $comandaId, $ventaId, $mesaId] = $this->prepararPedido(true);
        $cocinero = $this->usuario('COCINERO');

        $resp = $this->actingAs($cocinero)->getJson('/cocina/api/panel')->assertStatus(200);

        // El panel de cocina no debe exponer información financiera: ni precios
        // ni subtotales. Se comprueba sobre la ESTRUCTURA, no sobre el texto
        // crudo: una aserción como assertStringNotContainsString('10') es
        // coincidencia fortuita porque el precio 10 choca con la fecha
        // "2026-10-17" y con cualquier id.
        $lineas = $resp->json('lineas');
        $this->assertNotEmpty($lineas);

        foreach ($lineas as $linea) {
            $this->assertIsArray($linea);
            $this->assertArrayNotHasKey('precio', $linea);
            $this->assertArrayNotHasKey('precio_unitario', $linea);
            $this->assertArrayNotHasKey('subtotal', $linea);
            $this->assertArrayNotHasKey('total', $linea);
            $this->assertArrayNotHasKey('monto', $linea);
        }
    }

    public function test_transiciones_pendiente_enpreparacion_listo_con_retrocesos_restringidos(): void
    {
        [$admin, $detalleId, $comandaId, $ventaId, $mesaId] = $this->prepararPedido(true);
        $cocinero = $this->usuario('COCINERO');
        $gerente = $this->usuario('GERENTE');

        // PENDIENTE -> EN_PREPARACION (puede cocinero)
        $this->actingAs($cocinero)->postJson("/cocina/api/detalles/{$detalleId}/estado", ['estado' => 'EN_PREPARACION'])->assertStatus(200)->assertJson(['exito' => true]);
        // No puede retroceder a PENDIENTE (sólo ADMIN/GERENTE)
        $this->actingAs($cocinero)->postJson("/cocina/api/detalles/{$detalleId}/estado", ['estado' => 'PENDIENTE'])->assertStatus(422);
        // Retrocede GERENTE
        $this->actingAs($gerente)->postJson("/cocina/api/detalles/{$detalleId}/estado", ['estado' => 'PENDIENTE'])->assertStatus(200);
        // Avanza
        $this->actingAs($cocinero)->postJson("/cocina/api/detalles/{$detalleId}/estado", ['estado' => 'EN_PREPARACION'])->assertStatus(200);
        $this->actingAs($cocinero)->postJson("/cocina/api/detalles/{$detalleId}/estado", ['estado' => 'LISTO'])->assertStatus(200);
        // Retroceso ADMIN (o GERENTE) solo
        $this->actingAs($admin)->postJson("/cocina/api/detalles/{$detalleId}/estado", ['estado' => 'EN_PREPARACION'])->assertStatus(200);
    }

    /**
     * Refuerzo: antes esta prueba solo comprobaba que el panel no estaba
     * vacío, aunque su nombre prometía más. Ahora verifica de forma explícita:
     *
     *  1. Una línea de cocina activa con estado_preparacion = NULL (anterior al
     *     módulo) NO se muestra en el monitor.
     *  2. Un producto con requiere_cocina = 0 nunca es tarea de cocina, aunque
     *     tenga comanda y estado de preparación.
     *  3. Las observaciones (notas) de una línea válida se exponen tal cual.
     *
     * Solo se usan columnas y relaciones reales del esquema manual de SQLite y
     * los helpers del proyecto; no se inventa ninguna columna ni relación.
     */
    public function test_linea_historica_null_fuera_del_monitor_y_barra_excluido(): void
    {
        [, $detalleId, $comandaId, $ventaId] = $this->prepararPedido(true);
        $cocinero = $this->usuario('COCINERO');

        // Línea de cocina que creó el POS: PENDIENTE y con observación.
        $detalleCocina = DB::table('venta_detalles')->where('id', $detalleId)->first();
        $productoCocina = (int) $detalleCocina->producto_id;

        // El POS también creó una línea de barra con comanda_id = null.
        $productoBarra = (int) DB::table('productos')->where('requiere_cocina', 0)->value('id');
        $this->assertGreaterThan(0, $productoBarra, 'El helper debe crear un producto de barra.');
        $detalleBarraNatural = (int) DB::table('venta_detalles')
            ->where('venta_id', $ventaId)
            ->where('producto_id', $productoBarra)
            ->value('id');

        // --- Lectura inicial: solo la línea de cocina pendiente. ---
        $lineas = $this->actingAs($cocinero)->getJson('/cocina/api/panel')
            ->assertStatus(200)
            ->json('lineas');

        $this->assertCount(1, $lineas, 'Solo la línea de cocina pendiente debe aparecer.');
        $this->assertSame((int) $detalleId, (int) $lineas[0]['id']);
        $this->assertSame('PENDIENTE', $lineas[0]['estado']);
        // (3) La observación viaja en la respuesta del monitor.
        $this->assertSame('sin cebolla', $lineas[0]['notas']);
        $this->assertNotSame('Barra', $lineas[0]['producto']);

        // (1) Línea de cocina anterior al módulo: mismo producto y misma
        //     comanda, pero con estado_preparacion = NULL.
        $detalleHistorico = (int) DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $productoCocina,
            'cantidad' => 1,
            'precio_unitario' => 10,
            'subtotal' => 10,
            'comanda_id' => $comandaId,
            'estado' => 'ACTIVO',
            'estado_preparacion' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // (2) Producto de barra ligado a la comanda y marcado PENDIENTE: aun
        //     así no debe ser tarea de cocina (requiere_cocina = 0).
        $detalleBarraConComanda = (int) DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $productoBarra,
            'cantidad' => 1,
            'precio_unitario' => 5,
            'subtotal' => 5,
            'comanda_id' => $comandaId,
            'estado' => 'ACTIVO',
            'estado_preparacion' => 'PENDIENTE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // --- Segunda lectura: se comprueban las exclusiones concretas. ---
        $lineas = $this->actingAs($cocinero)->getJson('/cocina/api/panel')
            ->assertStatus(200)
            ->json('lineas');

        $ids = array_map(fn ($linea) => (int) $linea['id'], $lineas);

        $this->assertCount(1, $lineas, 'Las líneas históricas y de barra no deben sumar tareas.');
        $this->assertContains((int) $detalleId, $ids);
        $this->assertNotContains($detalleHistorico, $ids, 'La línea con estado_preparacion NULL no debe listarse.');
        $this->assertNotContains($detalleBarraConComanda, $ids, 'Un producto de barra nunca es tarea de cocina.');
        $this->assertNotContains($detalleBarraNatural, $ids, 'La línea de barra del POS no debe listarse.');

        foreach ($lineas as $linea) {
            $this->assertNotSame('Barra', $linea['producto']);
        }
    }

    public function test_anular_totalmente_desaparece_del_monitor(): void
    {
        [$admin, $detalleId, $comandaId, $ventaId] = $this->prepararPedido(true);
        $cocinero = $this->usuario('COCINERO');

        $detalle = DB::table('venta_detalles')->where('id', $detalleId)->first();
        $this->assertNotNull($detalle);
        $cantidadTotal = (int) $detalle->cantidad;

        $resp = $this->actingAs($admin)->postJson("/pos/detalle/{$detalleId}/anular", [
            'cantidad' => $cantidadTotal,
            'motivo' => 'anulacion total prueba',
        ]);
        $resp->assertStatus(200)->assertJson(['exito' => true]);

        $lineas = $this->actingAs($cocinero)->getJson('/cocina/api/panel')
            ->assertStatus(200)
            ->json('lineas');

        $ids = array_map(fn ($l) => (int) $l['id'], $lineas ?? []);
        $this->assertNotContains((int) $detalleId, $ids, 'Al anular totalmente debe desaparecer del monitor');

        $this->assertDatabaseHas('anulaciones', [
            'venta_detalle_id' => $detalleId,
            'tipo' => 'ITEM_ANULADO',
        ]);
    }

    public function test_anular_parcial_muestra_cantidad_restante_y_otra_linea_misma_comanda_sigue(): void
    {
        [$admin, $detalleId, $comandaId, $ventaId] = $this->prepararPedido(true);
        $cocinero = $this->usuario('COCINERO');

        $detalleOriginal = DB::table('venta_detalles')->where('id', $detalleId)->first();
        $this->assertNotNull($detalleOriginal);
        $productoCocina = (int) $detalleOriginal->producto_id;
        $precioUnitario = (float) $detalleOriginal->precio_unitario;

        $detalleExtra = (int) DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $productoCocina,
            'cantidad' => 3,
            'precio_unitario' => $precioUnitario,
            'subtotal' => round(3 * $precioUnitario, 2),
            'comanda_id' => $comandaId,
            'estado' => 'ACTIVO',
            'estado_preparacion' => 'PENDIENTE',
            'notas' => 'extra',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lineasIniciales = $this->actingAs($cocinero)->getJson('/cocina/api/panel')
            ->assertStatus(200)
            ->json('lineas');
        $this->assertCount(2, $lineasIniciales);

        $this->actingAs($admin)->postJson("/pos/detalle/{$detalleId}/anular", [
            'cantidad' => 1,
            'motivo' => 'anulacion parcial',
        ])->assertStatus(200)->assertJson(['exito' => true, 'estado' => 'ACTIVO']);

        $lineas = $this->actingAs($cocinero)->getJson('/cocina/api/panel')
            ->assertStatus(200)
            ->json('lineas');
        $this->assertCount(2, $lineas, 'Los demás productos activos de la misma comanda deben seguir visibles');

        $lineaReducida = collect($lineas)->firstWhere('id', (int) $detalleId);
        $this->assertNotNull($lineaReducida, 'La línea parcialmente anulada debe seguir visible');
        $this->assertSame(1, (int) $lineaReducida['cantidad'], 'Debe conservar la cantidad restante correcta');
        $this->assertSame('sin cebolla', $lineaReducida['notas']);

        $lineaExtra = collect($lineas)->firstWhere('id', $detalleExtra);
        $this->assertNotNull($lineaExtra, 'La otra línea de la misma comanda debe seguir visible');
        $this->assertSame(3, (int) $lineaExtra['cantidad']);
    }

    public function test_comanda_sin_productos_activos_no_genera_tareas(): void
    {
        [$admin, $detalleId, $comandaId, $ventaId] = $this->prepararPedido(true);
        $cocinero = $this->usuario('COCINERO');

        $detalle = DB::table('venta_detalles')->where('id', $detalleId)->first();
        $cantidadTotal = (int) $detalle->cantidad;

        $this->actingAs($admin)->postJson("/pos/detalle/{$detalleId}/anular", [
            'cantidad' => $cantidadTotal,
            'motivo' => 'cerrar comanda',
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $lineas = $this->actingAs($cocinero)->getJson('/cocina/api/panel')
            ->assertStatus(200)
            ->json('lineas');

        $this->assertIsArray($lineas);
        $this->assertCount(0, $lineas, 'Comanda sin productos activos no debe generar tareas');
    }

    public function test_anulacion_rechazada_no_modifica_datos_y_no_crea_anulacion(): void
    {
        [$admin, $detalleId, $comandaId, $ventaId] = $this->prepararPedido(true);
        $cocinero = $this->usuario('COCINERO');

        $antes = DB::table('venta_detalles')->where('id', $detalleId)->first();
        $anulacionesAntes = DB::table('anulaciones')->count();
        $montoVentaAntes = DB::table('ventas')->where('id', $ventaId)->value('monto');

        $resp = $this->actingAs($admin)->postJson("/pos/detalle/{$detalleId}/anular", [
            'cantidad' => (int) $antes->cantidad + 5,
            'motivo' => 'motivo',
        ]);
        $resp->assertStatus(422);

        $despues = DB::table('venta_detalles')->where('id', $detalleId)->first();
        $this->assertEquals($antes->cantidad, $despues->cantidad);
        $this->assertEquals($antes->subtotal, $despues->subtotal);
        $this->assertEquals($antes->estado, $despues->estado);

        $this->assertSame($anulacionesAntes, DB::table('anulaciones')->count());
        $this->assertSame($montoVentaAntes, DB::table('ventas')->where('id', $ventaId)->value('monto'));
    }

    public function test_anulacion_registra_trazabilidad_y_no_altera_cuentas_pagadas(): void
    {
        [$admin, $detalleId, $comandaId, $ventaId] = $this->prepararPedido(true);

        $detalle = DB::table('venta_detalles')->where('id', $detalleId)->first();
        $cantidadAnular = 1;
        $precio = (float) $detalle->precio_unitario;

        $this->actingAs($admin)->postJson("/pos/detalle/{$detalleId}/anular", [
            'cantidad' => $cantidadAnular,
            'motivo' => 'trazabilidad test',
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $anulacion = DB::table('anulaciones')->where('venta_detalle_id', $detalleId)->first();
        $this->assertNotNull($anulacion);
        $this->assertSame('ITEM_ANULADO', $anulacion->tipo);
        $this->assertSame((int) $ventaId, (int) $anulacion->venta_id);
        $this->assertSame((int) $comandaId, (int) $anulacion->comanda_id);
        $this->assertSame((int) $detalle->producto_id, (int) $anulacion->producto_id);
        $this->assertSame($cantidadAnular, (int) $anulacion->cantidad);
        $this->assertSame($admin->id, (int) $anulacion->usuario_id);
        $this->assertEquals(round($cantidadAnular * $precio, 2), (float) $anulacion->monto);

        $cuentas = DB::table('cuentas')->where('venta_id', $ventaId)->get();
        foreach ($cuentas as $cuenta) {
            $this->assertNotSame('PAGADA', $cuenta->estado);
        }
    }
}
