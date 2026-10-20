<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

/*
 * RESUMEN DIARIO DE PROPINAS (solo lectura)
 * =========================================
 *
 * Verifica el módulo "Administración → Resumen de Propinas":
 *  - suma la propina de cada cuenta COBRADA (PAGADA) en una fecha;
 *  - NO cuenta cuentas ABIERTA / CANCELADA ni propina cero;
 *  - NO duplica la propina cuando una cuenta tiene varios detalles;
 *  - el desglose por factura/cuenta coincide con el total general;
 *  - el filtro de fecha funciona;
 *  - ADMIN/GERENTE consultan; CAJERO/MESERO reciben 403;
 *  - sin propinas para la fecha devuelve un estado vacío válido.
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (phpunit.xml); nunca tocan la base real.
 * - El resumen es GENERAL del día: el esquema no registra al mesero.
 */
class ResumenPropinasTest extends TestCase
{
    use CreaEsquemaCompleto;

    protected function setUp(): void
    {
        parent::setUp();

        // Candado de seguridad: nunca apuntar a la base MySQL real.
        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas deben ejecutarse en SQLite.');
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'), 'La base de pruebas debe ser SQLite en memoria.');

        $this->crearEsquemaCompleto();
    }

    /* ------------------------------------------------------------------
     | Utilidades
     | ------------------------------------------------------------------ */

    private function usuario(string $rolNombre): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);

        return User::findOrFail(DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre.' Test',
            'username' => strtolower($rolNombre).'_'.uniqid(),
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]));
    }

    /**
     * Crea una venta con varias cuentas. Cada cuenta recibe su propina, estado
     * y fecha indicados.
     *
     * `fecha` = fecha del COBRO (se escribe en la factura como `fecha_emision`
     * y, de inicio, también en `cuentas.updated_at`).
     * `fecha_cuenta_actualizada` (opcional) permite simular una EDICIÓN
     * POSTERIOR de la cuenta: la factura conserva la fecha de cobro original,
     * pero `cuentas.updated_at` queda en otro día.
     *
     * @param array<int, array{propina: float, estado: string, fecha: string, fecha_cuenta_actualizada?: string, nombre?: string, factura?: bool, detalles?: int}> $cuentas
     */
    private function montarVenta(array $cuentas, int $usuarioId): array
    {
        $mesaId = DB::table('mesas')->insertGetId([
            'numero' => 'Mesa '.uniqid(),
            'capacidad' => 4,
            'estado' => 'LIBRE',
        ]);

        $ventaId = DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'descripcion' => 'Venta de prueba',
            'monto' => 0,
            'estado' => 'ACTIVA',
            'usuario_id' => $usuarioId,
            'fecha' => now(),
        ]);

        $productoId = DB::table('productos')->insertGetId([
            'nombre' => 'Producto '.uniqid(),
            'precio' => 100,
            'requiere_cocina' => 0,
            'controla_stock' => 0,
            'activo' => 1,
        ]);

        $creadas = [];

        foreach ($cuentas as $indice => $datos) {
            $cuentaId = DB::table('cuentas')->insertGetId([
                'venta_id' => $ventaId,
                'nombre' => $datos['nombre'] ?? ('Cuenta '.$indice),
                'total' => 100,
                'estado' => $datos['estado'],
                'usuario_cobro' => $usuarioId,
                'propina' => $datos['propina'],
                'created_at' => $datos['fecha'],
                'updated_at' => $datos['fecha_cuenta_actualizada'] ?? $datos['fecha'],
            ]);

            // Varios detalles para la misma cuenta: sirven para comprobar que
            // la propina NO se multiplica por el número de detalles.
            $detalles = $datos['detalles'] ?? 1;
            for ($i = 0; $i < $detalles; $i++) {
                $detalleId = DB::table('venta_detalles')->insertGetId([
                    'venta_id' => $ventaId,
                    'producto_id' => $productoId,
                    'cantidad' => 1,
                    'precio_unitario' => 100,
                    'subtotal' => 100,
                    'estado' => 'ACTIVO',
                ]);

                DB::table('cuenta_detalles')->insert([
                    'cuenta_id' => $cuentaId,
                    'venta_id' => $ventaId,
                    'venta_detalle_id' => $detalleId,
                    'cantidad' => 1,
                    'subtotal' => 100,
                    'eliminado' => false,
                ]);
            }

            if ($datos['factura'] ?? false) {
                DB::table('facturas')->insert([
                    'numero_factura' => 'FAC-'.str_pad((string) ($indice + 1), 4, '0', STR_PAD_LEFT),
                    'venta_id' => $ventaId,
                    'cuenta_id' => $cuentaId,
                    'mesa' => 'Mesa',
                    'detalle_consumo' => '{}',
                    'total' => 100,
                    'fecha_emision' => $datos['fecha'],
                    'usuario_id' => $usuarioId,
                    'estado' => 'EMITIDA',
                ]);
            }

            $creadas[] = $cuentaId;
        }

        return ['venta' => $ventaId, 'cuentas' => $creadas];
    }

    /* ------------------------------------------------------------------
     | Pruebas
     | ------------------------------------------------------------------ */

    public function test_admin_ve_el_total_de_propinas_del_dia(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->montarVenta([
            ['propina' => 55.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-19 22:23:42', 'factura' => true],
            ['propina' => 11.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-19 23:10:00', 'factura' => true],
        ], $admin->id);

        $respuesta = $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']));

        $respuesta->assertStatus(200)
            ->assertSee('TOTAL PROPINAS')
            ->assertSee('66.00')
            ->assertSee('55.00')
            ->assertSee('11.00')
            ->assertSee('FAC-0001')
            ->assertSee('FAC-0002');
    }

    public function test_cuentas_no_cobradas_no_se_contabilizan(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->montarVenta([
            // Cobrada: sí cuenta.
            ['propina' => 20.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-19 20:00:00', 'factura' => true],
            // Abiertas/canceladas: NO deben contar aunque tengan propina.
            ['propina' => 99.00, 'estado' => 'ABIERTA', 'fecha' => '2026-10-19 20:00:00'],
            ['propina' => 88.00, 'estado' => 'CANCELADA', 'fecha' => '2026-10-19 20:00:00'],
        ], $admin->id);

        $respuesta = $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']));

        $respuesta->assertStatus(200)
            ->assertSee('20.00')
            ->assertDontSee('99.00')
            ->assertDontSee('88.00');

        // La respuesta también refleja 1 cuenta cobrada con propina.
        $respuesta->assertSee('Cuentas cobradas con propina');
    }

    public function test_una_cuenta_con_varios_detalles_no_duplica_la_propina(): void
    {
        $admin = $this->usuario('ADMIN');
        // Una sola cuenta PAGADA con 4 detalles y propina 40: debe contar 40 (no 160).
        $this->montarVenta([
            ['propina' => 40.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-19 21:00:00', 'detalles' => 4, 'factura' => true],
        ], $admin->id);

        $respuesta = $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']));

        $respuesta->assertStatus(200)
            ->assertSee('TOTAL PROPINAS')
            ->assertSee('40.00')
            ->assertDontSee('160.00');
    }

    public function test_el_filtro_de_fecha_funciona(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->montarVenta([
            ['propina' => 30.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 12:00:00', 'factura' => true],
            ['propina' => 70.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-19 12:00:00', 'factura' => true],
        ], $admin->id);

        $dia18 = $this->actingAs($admin)->get(route('reportes.propinas', ['desde' => '2026-10-18', 'hasta' => '2026-10-18']));
        $dia18->assertStatus(200)->assertSee('30.00')->assertDontSee('70.00');

        $dia19 = $this->actingAs($admin)->get(route('reportes.propinas', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']));
        $dia19->assertStatus(200)->assertSee('70.00')->assertDontSee('30.00');
    }

    public function test_sin_propinas_muestra_estado_vacio(): void
    {
        $admin = $this->usuario('ADMIN');

        $respuesta = $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-10-17', 'hasta' => '2026-10-17']));

        $respuesta->assertStatus(200)
            ->assertSee('No se registraron propinas cobradas en el período')
            ->assertSee('TOTAL PROPINAS')
            ->assertSee('0.00');
    }

    public function test_gerente_puede_consultar(): void
    {
        $gerente = $this->usuario('GERENTE');

        $this->actingAs($gerente)
            ->get(route('reportes.propinas', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']))
            ->assertStatus(200);
    }

    public function test_cajero_y_mesero_no_tienen_acceso(): void
    {
        $cajero = $this->usuario('CAJERO');
        $mesero = $this->usuario('MESERO');

        $this->actingAs($cajero)
            ->get(route('reportes.propinas', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']))
            ->assertStatus(403);

        $this->actingAs($mesero)
            ->get(route('reportes.propinas', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']))
            ->assertStatus(403);
    }

    public function test_fecha_invalida_usa_la_fecha_actual(): void
    {
        $admin = $this->usuario('ADMIN');

        // Fecha inválida -> cae a hoy; debe responder 200 sin errores.
        $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => 'no-es-fecha']))
            ->assertStatus(200);
    }

    /* ------------------------------------------------------------------
     | Propinas HISTÓRICAS: el día lo fija la factura, no la última
     | modificación de la cuenta (`cuentas.updated_at`).
     | ------------------------------------------------------------------ */

    public function test_propina_historica_se_muestra_el_dia_de_la_factura_aunque_la_cuenta_se_edite_despues(): void
    {
        $admin = $this->usuario('ADMIN');

        // Cobro real el 18/10, pero la cuenta se MODIFICÓ el 20/10 (p. ej. al
        // renombrarla o recalcular su total). La propina debe seguir contando
        // el 18/10 y NO aparecer el 20/10.
        $this->montarVenta([
            [
                'propina' => 45.00,
                'estado' => 'PAGADA',
                'fecha' => '2026-10-18 21:00:00',
                'fecha_cuenta_actualizada' => '2026-10-20 08:30:00',
                'factura' => true,
            ],
        ], $admin->id);

        $diaCobro = $this->actingAs($admin)->get(route('reportes.propinas', ['desde' => '2026-10-18', 'hasta' => '2026-10-18']));
        $diaCobro->assertStatus(200)
            ->assertSee('TOTAL PROPINAS')
            ->assertSee('45.00')
            ->assertSee('FAC-0001');

        // El día en que solo se editó la cuenta NO debe mostrar la propina.
        $diaEdicion = $this->actingAs($admin)->get(route('reportes.propinas', ['desde' => '2026-10-20', 'hasta' => '2026-10-20']));
        $diaEdicion->assertStatus(200)
            ->assertSee('No se registraron propinas cobradas en el período')
            ->assertDontSee('45.00');
    }

    public function test_recupera_varias_facturas_historicas_de_dias_distintos(): void
    {
        $admin = $this->usuario('ADMIN');

        // Tres cobros con propina en días históricos distintos, todos con la
        // cuenta editada después (updated_at en otro día). Cada uno debe caer
        // en el día de su factura.
        $this->montarVenta([
            ['propina' => 55.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-17 22:00:00', 'fecha_cuenta_actualizada' => '2026-10-20 09:00:00', 'factura' => true],
            ['propina' => 10.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 12:00:00', 'fecha_cuenta_actualizada' => '2026-10-20 09:05:00', 'factura' => true],
            ['propina' => 30.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-19 15:00:00', 'fecha_cuenta_actualizada' => '2026-10-20 09:10:00', 'factura' => true],
        ], $admin->id);

        $this->actingAs($admin)->get(route('reportes.propinas', ['desde' => '2026-10-17', 'hasta' => '2026-10-17']))
            ->assertStatus(200)->assertSee('55.00')->assertDontSee('10.00')->assertDontSee('30.00');

        $this->actingAs($admin)->get(route('reportes.propinas', ['desde' => '2026-10-18', 'hasta' => '2026-10-18']))
            ->assertStatus(200)->assertSee('10.00')->assertDontSee('55.00')->assertDontSee('30.00');

        $this->actingAs($admin)->get(route('reportes.propinas', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']))
            ->assertStatus(200)->assertSee('30.00')->assertDontSee('55.00')->assertDontSee('10.00');
    }

    public function test_no_duplica_la_propina_historica_con_varios_detalles_y_edicion_posterior(): void
    {
        $admin = $this->usuario('ADMIN');

        // Una cuenta histórica con 3 detalles, propina 90 y editada después.
        // Debe contar 90 una sola vez (no 270, no dos veces por día).
        $this->montarVenta([
            [
                'propina' => 90.00,
                'estado' => 'PAGADA',
                'fecha' => '2026-10-18 20:00:00',
                'fecha_cuenta_actualizada' => '2026-10-20 10:00:00',
                'detalles' => 3,
                'factura' => true,
            ],
        ], $admin->id);

        $respuesta = $this->actingAs($admin)->get(route('reportes.propinas', ['desde' => '2026-10-18', 'hasta' => '2026-10-18']));
        $respuesta->assertStatus(200)
            ->assertSee('90.00')
            ->assertDontSee('270.00')
            ->assertSee('FAC-0001');

        // El total del día es exactamente 90.00: una sola cuenta, una sola vez.
        $resumen = app(\App\Services\ResumenPropinasService::class)->resumenDelDia('2026-10-18');
        $this->assertSame(1, $resumen['cantidad'], 'Debe haber una sola cuenta con propina ese día.');
        $this->assertSame(90.0, $resumen['total'], 'La propina no debe duplicarse por los detalles.');
    }

    public function test_cuenta_pagada_sin_factura_no_entra_por_el_desglose_de_facturas(): void
    {
        $admin = $this->usuario('ADMIN');

        // Cuenta PAGADA con propina pero SIN factura (no debería ocurrir en el
        // flujo real, donde el cobro siempre emite factura). No entra al
        // resumen para no mezclar importes sin comprobante.
        $this->montarVenta([
            ['propina' => 25.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 18:00:00', 'factura' => false],
        ], $admin->id);

        $this->actingAs($admin)->get(route('reportes.propinas', ['desde' => '2026-10-18', 'hasta' => '2026-10-18']))
            ->assertStatus(200)
            ->assertSee('No se registraron propinas cobradas en el período');
    }

    /* ------------------------------------------------------------------
     | Filtro Desde/Hasta (rango de fechas)
     | ------------------------------------------------------------------ */

    public function test_desde_y_hasta_iguales_muestran_solo_ese_dia(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->montarVenta([
            ['propina' => 111.11, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 10:00:00', 'factura' => true],
            ['propina' => 222.22, 'estado' => 'PAGADA', 'fecha' => '2026-10-19 10:00:00', 'factura' => true],
            ['propina' => 333.33, 'estado' => 'PAGADA', 'fecha' => '2026-10-20 10:00:00', 'factura' => true],
        ], $admin->id);

        $respuesta = $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']));

        $respuesta->assertStatus(200)
            ->assertSee('222.22')
            ->assertDontSee('111.11')
            ->assertDontSee('333.33');
    }

    public function test_rango_de_varios_dias_incluye_ambos_extremos(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->montarVenta([
            ['propina' => 311.11, 'estado' => 'PAGADA', 'fecha' => '2026-10-17 10:00:00', 'factura' => true],
            ['propina' => 422.22, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 10:00:00', 'factura' => true],
            ['propina' => 533.33, 'estado' => 'PAGADA', 'fecha' => '2026-10-19 10:00:00', 'factura' => true],
            ['propina' => 944.44, 'estado' => 'PAGADA', 'fecha' => '2026-10-21 10:00:00', 'factura' => true],
        ], $admin->id);

        // 18 al 19: incluye ambos días, excluye el 17 y el 21.
        $respuesta = $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-10-18', 'hasta' => '2026-10-19']));

        $respuesta->assertStatus(200)
            ->assertSee('422.22')
            ->assertSee('533.33')
            ->assertDontSee('311.11')
            ->assertDontSee('944.44');

        // El total del rango es 955.55 (422.22 + 533.33).
        $resumen = app(\App\Services\ResumenPropinasService::class)->resumenDelRango('2026-10-18', '2026-10-19');
        $this->assertSame(2, $resumen['cantidad']);
        $this->assertSame(955.55, $resumen['total']);
    }

    public function test_incluye_la_propina_del_ultimo_dia_aunque_tenga_hora_tardia(): void
    {
        $admin = $this->usuario('ADMIN');
        // Cobro a las 23:59 del día Hasta: debe entrar (rango semiabierto).
        $this->montarVenta([
            ['propina' => 642.42, 'estado' => 'PAGADA', 'fecha' => '2026-10-20 23:59:59', 'factura' => true],
        ], $admin->id);

        $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-10-20', 'hasta' => '2026-10-20']))
            ->assertStatus(200)
            ->assertSee('642.42');
    }

    public function test_rango_que_cruza_de_mes_funciona(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->montarVenta([
            ['propina' => 731.31, 'estado' => 'PAGADA', 'fecha' => '2026-10-31 12:00:00', 'factura' => true],
            ['propina' => 742.42, 'estado' => 'PAGADA', 'fecha' => '2026-11-01 12:00:00', 'factura' => true],
        ], $admin->id);

        $respuesta = $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-10-31', 'hasta' => '2026-11-01']));

        $respuesta->assertStatus(200)->assertSee('731.31')->assertSee('742.42');
    }

    public function test_rango_que_cruza_de_anio_funciona(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->montarVenta([
            ['propina' => 851.51, 'estado' => 'PAGADA', 'fecha' => '2026-12-31 23:00:00', 'factura' => true],
            ['propina' => 862.62, 'estado' => 'PAGADA', 'fecha' => '2027-01-01 01:00:00', 'factura' => true],
        ], $admin->id);

        $respuesta = $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-12-31', 'hasta' => '2027-01-01']));

        $respuesta->assertStatus(200)->assertSee('851.51')->assertSee('862.62');
    }

    public function test_rango_sin_resultados_muestra_estado_vacio_y_cero(): void
    {
        $admin = $this->usuario('ADMIN');

        $respuesta = $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-11-01', 'hasta' => '2026-11-30']));

        $respuesta->assertStatus(200)
            ->assertSee('No se registraron propinas cobradas en el período')
            ->assertSee('TOTAL PROPINAS')
            ->assertSee('0.00');
    }

    public function test_desde_posterior_a_hasta_muestra_error_y_no_calcula(): void
    {
        $admin = $this->usuario('ADMIN');
        // Hay propinas el 19, pero el rango está invertido: no debe mostrarlas.
        $this->montarVenta([
            ['propina' => 977.77, 'estado' => 'PAGADA', 'fecha' => '2026-10-19 10:00:00', 'factura' => true],
        ], $admin->id);

        $respuesta = $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-10-20', 'hasta' => '2026-10-19']));

        $respuesta->assertStatus(200)
            ->assertSee('no puede ser posterior')
            ->assertDontSee('977.77');
    }

    public function test_fechas_invalidas_no_calculan_resumen(): void
    {
        $admin = $this->usuario('ADMIN');

        $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-13-40', 'hasta' => '2026-10-19']))
            ->assertStatus(200)
            ->assertSee('no son válidas');

        $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => 'texto', 'hasta' => '2026-10-19']))
            ->assertStatus(200)
            ->assertSee('no son válidas');
    }

    public function test_el_formulario_conserva_los_filtros_enviados(): void
    {
        $admin = $this->usuario('ADMIN');

        $respuesta = $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-10-18', 'hasta' => '2026-10-20']));

        // Los valores vuelven a pintarse en los inputs (consulta compartible).
        $respuesta->assertStatus(200)
            ->assertSee('value="2026-10-18"', false)
            ->assertSee('value="2026-10-20"', false)
            ->assertSee('name="desde"', false)
            ->assertSee('name="hasta"', false)
            ->assertSee('Limpiar filtros');
    }

    public function test_sin_parametros_usa_el_dia_actual(): void
    {
        $admin = $this->usuario('ADMIN');
        $hoy = now()->format('Y-m-d');

        // Una propina cobrada hoy debe aparecer sin enviar filtros.
        $this->montarVenta([
            ['propina' => 686.86, 'estado' => 'PAGADA', 'fecha' => now()->format('Y-m-d H:i:s'), 'factura' => true],
        ], $admin->id);

        $this->actingAs($admin)
            ->get(route('reportes.propinas'))
            ->assertStatus(200)
            ->assertSee('686.86')
            ->assertSee('value="'.$hoy.'"', false);
    }
}
