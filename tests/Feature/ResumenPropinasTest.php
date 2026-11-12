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
     * @param array<int, array{propina: float, estado: string, fecha: string, fecha_cuenta_actualizada?: string, nombre?: string, factura?: bool, detalles?: int, detalles_anulados?: int, detalle_subtotal?: float, descuento?: float, apertura_id?: int, usuario_cobro?: int}> $cuentas
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
                // `cuentas.total` guarda el CONSUMO (no incluye propina), igual
                // que en VentaController::pagarCuenta.
                'total' => 100,
                'estado' => $datos['estado'],
                'usuario_cobro' => $datos['usuario_cobro'] ?? $usuarioId,
                'apertura_id' => $datos['apertura_id'] ?? null,
                'descuento' => $datos['descuento'] ?? 0,
                'propina' => $datos['propina'],
                'created_at' => $datos['fecha'],
                'updated_at' => $datos['fecha_cuenta_actualizada'] ?? $datos['fecha'],
            ]);

            // Varios detalles para la misma cuenta: sirven para comprobar que
            // la propina NO se multiplica por el número de detalles.
            // `detalles_anulados` agrega líneas con `eliminado = 1` (borrado
            // lógico): no deben inflar el consumo.
            $detalles = $datos['detalles'] ?? 1;
            $anulados = $datos['detalles_anulados'] ?? 0;
            $subtotalDetalle = (float) ($datos['detalle_subtotal'] ?? 100);

            for ($i = 0; $i < ($detalles + $anulados); $i++) {
                $eliminado = $i >= $detalles;

                $detalleId = DB::table('venta_detalles')->insertGetId([
                    'venta_id' => $ventaId,
                    'producto_id' => $productoId,
                    'cantidad' => 1,
                    'precio_unitario' => $subtotalDetalle,
                    'subtotal' => $subtotalDetalle,
                    'estado' => 'ACTIVO',
                ]);

                DB::table('cuenta_detalles')->insert([
                    'cuenta_id' => $cuentaId,
                    'venta_id' => $ventaId,
                    'venta_detalle_id' => $detalleId,
                    'cantidad' => 1,
                    'subtotal' => $subtotalDetalle,
                    'eliminado' => $eliminado,
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

    /* ------------------------------------------------------------------
     | Consumo real, facturas anuladas y atribución de usuarios
     | ------------------------------------------------------------------ */

    public function test_el_monto_de_consumo_no_es_cero_y_sale_del_detalle(): void
    {
        $admin = $this->usuario('ADMIN');

        // 3 detalles de C$ 100: el consumo real es 300 (no el total
        // denormalizado de la cuenta ni cero).
        $this->montarVenta([
            [
                'propina' => 15.00,
                'estado' => 'PAGADA',
                'fecha' => '2026-10-18 20:00:00',
                'detalles' => 3,
                'factura' => true,
            ],
        ], $admin->id);

        $resumen = app(\App\Services\ResumenPropinasService::class)->resumenDelDia('2026-10-18');

        $this->assertSame(1, $resumen['cantidad']);
        $this->assertSame(300.0, $resumen['detalle'][0]['monto_consumo'], 'El consumo debe salir de cuenta_detalles, no ser cero.');
        $this->assertSame(15.0, $resumen['total']);
        // No existe registro de pago de propinas al personal: no se inventa.
        $this->assertNull($resumen['propinas_pagadas']);
    }

    public function test_una_factura_anulada_no_duplica_la_propina(): void
    {
        $admin = $this->usuario('ADMIN');

        $venta = $this->montarVenta([
            ['propina' => 60.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:00:00', 'factura' => true],
        ], $admin->id);

        // Una factura ANULADA de la MISMA cuenta (la cuenta se reabrió y se
        // volvió a cobrar). No debe sumar la propina por segunda vez, ni en el
        // día de la anulación ni en ningún otro.
        DB::table('facturas')->insert([
            'numero_factura' => 'FAC-9999',
            'venta_id' => $venta['venta'],
            'cuenta_id' => $venta['cuentas'][0],
            'mesa' => 'Mesa',
            'detalle_consumo' => '{}',
            'total' => 60,
            'fecha_emision' => '2026-10-19 10:00:00',
            'usuario_id' => $admin->id,
            'estado' => 'ANULADA',
        ]);

        $resumen = app(\App\Services\ResumenPropinasService::class)->resumenDelRango('2026-10-18', '2026-10-19');

        $this->assertSame(1, $resumen['cantidad'], 'Solo debe contar la factura vigente.');
        $this->assertSame(60.0, $resumen['total'], 'La propina no debe duplicarse por la factura anulada.');
    }

    public function test_distingue_quien_abrio_la_mesa_de_quien_atendio(): void
    {
        $administrador = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');

        $venta = $this->montarVenta([
            ['propina' => 25.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:00:00', 'factura' => true],
        ], $administrador->id);

        // La comanda la envió el mesero: es quien atendió la mesa, aunque la
        // venta la haya abierto el administrador.
        DB::table('comandas')->insert([
            'venta_id' => $venta['venta'],
            'usuario_id' => $mesero->id,
            'estado' => 'ABIERTA',
            'total' => 100,
            'creado_en' => '2026-10-18 19:00:00',
            'conteo_impresiones' => 1,
        ]);

        $resumen = app(\App\Services\ResumenPropinasService::class)->resumenDelDia('2026-10-18');
        $linea = $resumen['detalle'][0];

        $this->assertSame('MESERO Test', $linea['mesero_atendio'], 'Quien atendió es quien envió la comanda.');
        $this->assertSame('ADMIN Test', $linea['usuario_abrio_mesa'], 'Quien abrió la mesa es el usuario de la venta.');
        $this->assertNotSame($linea['mesero_atendio'], $linea['usuario_abrio_mesa']);
    }

    /* ------------------------------------------------------------------
     | Filtros combinados: mesero, abrió la mesa y mesa
     | ------------------------------------------------------------------ */

    /** Registra una comanda enviada por `$usuarioId` para la venta indicada. */
    private function comanda(int $ventaId, int $usuarioId): void
    {
        DB::table('comandas')->insert([
            'venta_id' => $ventaId,
            'usuario_id' => $usuarioId,
            'estado' => 'ABIERTA',
            'total' => 100,
            'creado_en' => '2026-10-18 19:00:00',
            'conteo_impresiones' => 1,
        ]);
    }

    private function mesaDeVenta(int $ventaId): int
    {
        return (int) DB::table('ventas')->where('id', $ventaId)->value('mesa_id');
    }

    public function test_filtro_por_mesero_que_atendio(): void
    {
        $admin = $this->usuario('ADMIN');
        $meseroUno = $this->usuario('MESERO');
        $meseroDos = $this->usuario('MESERO');

        $ventaUno = $this->montarVenta([
            ['propina' => 137.37, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:00:00', 'factura' => true],
        ], $admin->id);
        $this->comanda($ventaUno['venta'], $meseroUno->id);

        $ventaDos = $this->montarVenta([
            ['propina' => 248.48, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 21:00:00', 'factura' => true],
        ], $admin->id);
        $this->comanda($ventaDos['venta'], $meseroDos->id);

        $respuesta = $this->actingAs($admin)->get(route('reportes.propinas', [
            'desde' => '2026-10-18', 'hasta' => '2026-10-18', 'mesero' => $meseroUno->id,
        ]));

        $respuesta->assertStatus(200)
            ->assertSee('137.37')
            ->assertDontSee('248.48');

        $resumen = app(\App\Services\ResumenPropinasService::class)
            ->resumenDelDia('2026-10-18', ['mesero' => $meseroUno->id]);
        $this->assertSame(1, $resumen['cantidad']);
        $this->assertSame(137.37, $resumen['total']);
    }

    public function test_filtro_por_usuario_que_abrio_la_mesa(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');

        $ventaMesero = $this->montarVenta([
            ['propina' => 161.61, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:00:00', 'factura' => true],
        ], $mesero->id);
        $this->comanda($ventaMesero['venta'], $mesero->id);

        $ventaAdmin = $this->montarVenta([
            ['propina' => 272.72, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 21:00:00', 'factura' => true],
        ], $admin->id);
        $this->comanda($ventaAdmin['venta'], $mesero->id);

        $resumen = app(\App\Services\ResumenPropinasService::class)
            ->resumenDelDia('2026-10-18', ['abrio' => $mesero->id]);

        $this->assertSame(1, $resumen['cantidad'], 'Solo la mesa abierta por ese usuario.');
        $this->assertSame(161.61, $resumen['total']);
    }

    public function test_filtro_por_mesa(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');

        $ventaUno = $this->montarVenta([
            ['propina' => 383.83, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:00:00', 'factura' => true],
        ], $mesero->id);
        $this->comanda($ventaUno['venta'], $mesero->id);

        $ventaDos = $this->montarVenta([
            ['propina' => 494.94, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 21:00:00', 'factura' => true],
        ], $mesero->id);
        $this->comanda($ventaDos['venta'], $mesero->id);

        $resumen = app(\App\Services\ResumenPropinasService::class)
            ->resumenDelDia('2026-10-18', ['mesa' => $this->mesaDeVenta($ventaUno['venta'])]);

        $this->assertSame(1, $resumen['cantidad']);
        $this->assertSame(383.83, $resumen['total']);
    }

    public function test_los_filtros_se_combinan_entre_si(): void
    {
        $admin = $this->usuario('ADMIN');
        $meseroUno = $this->usuario('MESERO');
        $meseroDos = $this->usuario('MESERO');

        $ventaUno = $this->montarVenta([
            ['propina' => 515.15, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:00:00', 'factura' => true],
        ], $meseroUno->id);
        $this->comanda($ventaUno['venta'], $meseroUno->id);

        $ventaDos = $this->montarVenta([
            ['propina' => 626.26, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 21:00:00', 'factura' => true],
        ], $meseroDos->id);
        $this->comanda($ventaDos['venta'], $meseroDos->id);

        $servicio = app(\App\Services\ResumenPropinasService::class);

        // Mesero + abrió + mesa: coinciden solo en la primera venta.
        $resumen = $servicio->resumenDelDia('2026-10-18', [
            'mesero' => $meseroUno->id,
            'abrio' => $meseroUno->id,
            'mesa' => $this->mesaDeVenta($ventaUno['venta']),
        ]);
        $this->assertSame(1, $resumen['cantidad']);
        $this->assertSame(515.15, $resumen['total']);

        // Mesero que no abrió esa mesa: combinación imposible -> vacío.
        $resumen = $servicio->resumenDelDia('2026-10-18', [
            'mesero' => $meseroDos->id,
            'mesa' => $this->mesaDeVenta($ventaUno['venta']),
        ]);
        $this->assertSame(0, $resumen['cantidad']);
        $this->assertSame(0.0, $resumen['total']);
    }

    public function test_filtros_invalidos_se_ignoran_en_la_peticion(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');

        $venta = $this->montarVenta([
            ['propina' => 737.37, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:00:00', 'factura' => true],
        ], $mesero->id);
        $this->comanda($venta['venta'], $mesero->id);

        // Valores no numéricos: no deben filtrar nada (ni provocar error).
        $this->actingAs($admin)->get(route('reportes.propinas', [
            'desde' => '2026-10-18', 'hasta' => '2026-10-18',
            'mesero' => 'abc', 'abrio' => 'x', 'mesa' => '-3',
        ]))->assertStatus(200)->assertSee('737.37');
    }

    /* ------------------------------------------------------------------
     | Atribución: solo MESERO, varios meseros y conciliación
     | ------------------------------------------------------------------ */

    public function test_solo_el_rol_mesero_recibe_propina(): void
    {
        $admin = $this->usuario('ADMIN');
        $cajero = $this->usuario('CAJERO');
        $gerente = $this->usuario('GERENTE');

        $venta = $this->montarVenta([
            ['propina' => 848.48, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:00:00', 'factura' => true],
        ], $admin->id);

        // Comandas enviadas por roles que NO pueden recibir propinas.
        $this->comanda($venta['venta'], $cajero->id);
        $this->comanda($venta['venta'], $gerente->id);

        $resumen = app(\App\Services\ResumenPropinasService::class)->resumenDelDia('2026-10-18');
        $linea = $resumen['detalle'][0];

        $this->assertNull($linea['mesero_atendio'], 'CAJERO/GERENTE no son meseros que atendieron.');
        $this->assertSame('PENDIENTE', $linea['atribucion']);
        $this->assertSame('sin_mesero', $linea['motivo_pendiente']);
        $this->assertSame(0.0, $resumen['atribuido'], 'Ningún rol administrativo recibe propina.');
        $this->assertSame(848.48, $resumen['pendiente']);
    }

    public function test_abrir_o_cobrar_no_atribuye_la_propina(): void
    {
        $admin = $this->usuario('ADMIN');
        $adminDos = $this->usuario('ADMIN');

        // Venta abierta y cobrada por ADMIN, sin comandas de ningún mesero.
        $this->montarVenta([
            ['propina' => 959.59, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:00:00', 'factura' => true],
        ], $admin->id);

        $resumen = app(\App\Services\ResumenPropinasService::class)->resumenDelDia('2026-10-18');

        $this->assertSame(0.0, $resumen['atribuido']);
        $this->assertSame(959.59, $resumen['pendiente']);
        $this->assertSame('PENDIENTE', $resumen['detalle'][0]['atribucion']);
        $this->assertNull($resumen['detalle'][0]['mesero_atribuido_id'], 'Quien abre/cobra no es receptor automático.');
        $this->assertNotSame($adminDos->id, $resumen['detalle'][0]['usuario_cobro_id']);
    }

    public function test_varios_meseros_no_duplican_la_propina_y_queda_pendiente(): void
    {
        $admin = $this->usuario('ADMIN');
        $meseroUno = $this->usuario('MESERO');
        $meseroDos = $this->usuario('MESERO');

        $venta = $this->montarVenta([
            ['propina' => 300.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:00:00', 'factura' => true],
        ], $admin->id);
        $this->comanda($venta['venta'], $meseroUno->id);
        $this->comanda($venta['venta'], $meseroDos->id);

        $resumen = app(\App\Services\ResumenPropinasService::class)->resumenDelDia('2026-10-18');

        $this->assertSame(1, $resumen['cantidad'], 'Una sola cuenta, una sola propina.');
        $this->assertSame(300.0, $resumen['total'], 'La propina NO se multiplica por mesero.');
        $this->assertSame(0.0, $resumen['atribuido'], 'No se reparte sin regla verificable.');
        $this->assertSame(300.0, $resumen['pendiente']);
        $this->assertSame('varios_meseros', $resumen['detalle'][0]['motivo_pendiente']);
        $this->assertCount(2, $resumen['detalle'][0]['meseros_ids']);
        $this->assertCount(1, $resumen['resumen_meseros'], 'Solo una fila pendiente, no una por mesero.');
        $this->assertTrue($resumen['resumen_meseros'][0]['pendiente']);
        $this->assertSame(300.0, $resumen['resumen_meseros'][0]['propinas']);
    }

    public function test_conciliacion_atribuido_mas_pendiente_igual_al_total(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');
        $otroMesero = $this->usuario('MESERO');

        // Atribuida: un solo mesero.
        $ventaAtribuida = $this->montarVenta([
            ['propina' => 137.37, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:00:00', 'factura' => true],
        ], $admin->id);
        $this->comanda($ventaAtribuida['venta'], $mesero->id);

        // Pendiente: dos meseros en la misma venta.
        $ventaCompartida = $this->montarVenta([
            ['propina' => 248.48, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 21:00:00', 'factura' => true],
        ], $admin->id);
        $this->comanda($ventaCompartida['venta'], $mesero->id);
        $this->comanda($ventaCompartida['venta'], $otroMesero->id);

        $resumen = app(\App\Services\ResumenPropinasService::class)->resumenDelDia('2026-10-18');

        $this->assertSame(385.85, $resumen['total']);
        $this->assertSame(137.37, $resumen['atribuido']);
        $this->assertSame(248.48, $resumen['pendiente']);
        $this->assertSame(
            $resumen['total'],
            round($resumen['atribuido'] + $resumen['pendiente'], 2),
            'Atribuido + pendiente debe ser EXACTAMENTE el total.'
        );

        // La suma del resumen individual tampoco duplica ni pierde importes.
        $this->assertSame(385.85, round((float) $resumen['resumen_meseros']->sum('propinas'), 2));

        // Y la interfaz muestra las tres cifras.
        $this->actingAs($admin)->get(route('reportes.propinas', ['desde' => '2026-10-18', 'hasta' => '2026-10-18']))
            ->assertStatus(200)
            ->assertSee('Atribuido a meseros')
            ->assertSee('Pendiente de atribución')
            ->assertSee('385.85');
    }

    public function test_resumen_diario_individual_por_mesero(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');

        // Dos cuentas de la MISMA mesa/venta, atendida por un solo mesero.
        $venta = $this->montarVenta([
            ['propina' => 10.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:00:00', 'factura' => true],
            ['propina' => 15.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:30:00', 'factura' => true],
        ], $admin->id);
        $this->comanda($venta['venta'], $mesero->id);

        $resumen = app(\App\Services\ResumenPropinasService::class)->resumenDelDia('2026-10-18');

        $this->assertCount(1, $resumen['resumen_meseros']);
        $fila = $resumen['resumen_meseros'][0];

        $this->assertSame('2026-10-18', $fila['fecha']);
        $this->assertSame('MESERO Test', $fila['mesero']);
        $this->assertFalse($fila['pendiente']);
        $this->assertSame(1, $fila['mesas_atendidas'], 'Una mesa aunque haya dos cuentas.');
        $this->assertSame(2, $fila['cuentas_cobradas']);
        $this->assertSame(200.0, $fila['consumo'], '100 por cada cuenta (un detalle cada una).');
        $this->assertSame(25.0, $fila['propinas']);

        // La vista muestra el resumen individual y enlaza al detalle del mesero.
        $this->actingAs($admin)->get(route('reportes.propinas', ['desde' => '2026-10-18', 'hasta' => '2026-10-18']))
            ->assertStatus(200)
            ->assertSee('Resumen diario individual por mesero')
            ->assertSee('MESERO Test')
            ->assertSee('mesero='.$mesero->id, false)
            ->assertSee('25.00');
    }

    public function test_filtro_pendiente_muestra_solo_lo_no_atribuido(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');
        $otroMesero = $this->usuario('MESERO');

        $ventaAtribuida = $this->montarVenta([
            ['propina' => 171.71, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:00:00', 'factura' => true],
        ], $admin->id);
        $this->comanda($ventaAtribuida['venta'], $mesero->id);

        $ventaCompartida = $this->montarVenta([
            ['propina' => 282.82, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 21:00:00', 'factura' => true],
        ], $admin->id);
        $this->comanda($ventaCompartida['venta'], $mesero->id);
        $this->comanda($ventaCompartida['venta'], $otroMesero->id);

        $resumen = app(\App\Services\ResumenPropinasService::class)
            ->resumenDelDia('2026-10-18', ['mesero' => 'pendiente']);

        $this->assertSame(1, $resumen['cantidad']);
        $this->assertSame(282.82, $resumen['total']);
        $this->assertSame(0.0, $resumen['atribuido']);
        $this->assertSame(282.82, $resumen['pendiente']);
    }

    public function test_una_misma_propina_no_se_cuenta_en_dos_meseros(): void
    {
        $admin = $this->usuario('ADMIN');
        $meseroUno = $this->usuario('MESERO');
        $meseroDos = $this->usuario('MESERO');

        // Venta compartida entre dos meseros, con dos cuentas cobradas.
        $venta = $this->montarVenta([
            ['propina' => 50.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:00:00', 'factura' => true],
            ['propina' => 60.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:20:00', 'factura' => true],
        ], $admin->id);
        $this->comanda($venta['venta'], $meseroUno->id);
        $this->comanda($venta['venta'], $meseroDos->id);

        $resumen = app(\App\Services\ResumenPropinasService::class)->resumenDelDia('2026-10-18');

        $this->assertSame(110.0, $resumen['total'], '50 + 60 una sola vez.');

        foreach ([$meseroUno->id, $meseroDos->id] as $idMesero) {
            $filtrado = app(\App\Services\ResumenPropinasService::class)
                ->resumenDelDia('2026-10-18', ['mesero' => $idMesero]);
            $this->assertSame(110.0, $filtrado['total'], 'Filtrar por un mesero no aumenta el total.');
            $this->assertSame(0.0, $filtrado['atribuido'], 'La venta compartida sigue pendiente.');
        }
    }

    /* ------------------------------------------------------------------
     | Bloque A: consistencia, filtros nuevos, exportación y paginación
     | ------------------------------------------------------------------ */

    public function test_cuenta_pagada_sin_factura_se_informa_sin_sumar_al_total(): void
    {
        $admin = $this->usuario('ADMIN');

        $this->montarVenta([
            ['propina' => 45.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:00:00', 'factura' => true],
            // Cobrada SIN factura emitida: se informa como inconsistencia,
            // pero NUNCA suma al total de propinas.
            ['propina' => 999.99, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:30:00', 'factura' => false],
        ], $admin->id);

        $resumen = app(\App\Services\ResumenPropinasService::class)->resumenDelDia('2026-10-18');

        $this->assertSame(1, $resumen['cuentas_sin_factura'], 'Una cuenta cobrada sin factura vigente.');
        $this->assertSame(1, $resumen['cantidad'], 'Solo la cuenta con factura EMITIDA entra al detalle.');
        $this->assertSame(45.0, $resumen['total'], 'La cuenta sin factura no suma.');

        $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-10-18', 'hasta' => '2026-10-18']))
            ->assertStatus(200)
            ->assertSee('Cuentas pagadas sin factura vigente')
            ->assertDontSee('999.99');
    }

    public function test_descuento_no_reduce_el_consumo_ni_duplica_la_propina(): void
    {
        $admin = $this->usuario('ADMIN');

        // Consumo bruto 300 (3 x 100), descuento 50, propina 30.
        $this->montarVenta([
            [
                'propina' => 30.00,
                'estado' => 'PAGADA',
                'fecha' => '2026-10-18 20:00:00',
                'factura' => true,
                'detalles' => 3,
                'descuento' => 50.00,
            ],
        ], $admin->id);

        $resumen = app(\App\Services\ResumenPropinasService::class)->resumenDelDia('2026-10-18');
        $linea = $resumen['detalle'][0];

        $this->assertSame(1, $resumen['cantidad']);
        $this->assertSame(30.0, $resumen['total'], 'La propina se cuenta una sola vez.');
        $this->assertSame(300.0, $linea['monto_consumo'], 'El consumo es BRUTO: no se le resta el descuento.');
        $this->assertSame(50.0, $linea['descuento'], 'El descuento se informa aparte.');
        $this->assertSame(30.0, $linea['propina'], 'La propina no se suma al consumo.');
    }

    public function test_detalles_anulados_no_inflan_el_consumo(): void
    {
        $admin = $this->usuario('ADMIN');

        // 1 detalle activo + 2 anulados (borrado lógico) de C$ 100.
        $this->montarVenta([
            [
                'propina' => 10.00,
                'estado' => 'PAGADA',
                'fecha' => '2026-10-18 20:00:00',
                'factura' => true,
                'detalles' => 1,
                'detalles_anulados' => 2,
            ],
        ], $admin->id);

        $resumen = app(\App\Services\ResumenPropinasService::class)->resumenDelDia('2026-10-18');

        $this->assertSame(100.0, $resumen['detalle'][0]['monto_consumo'], 'Solo cuenta el detalle no eliminado.');
        $this->assertSame(10.0, $resumen['total']);
    }

    public function test_filtros_por_turno_y_por_usuario_que_cobro(): void
    {
        $admin = $this->usuario('ADMIN');
        $cajero = $this->usuario('CAJERO');
        $mesero = $this->usuario('MESERO');

        $turnoUno = DB::table('caja_apertura')->insertGetId([
            'fecha' => '2026-10-18 08:00:00',
            'cajero' => 'CAJERO Test',
            'turno' => 'Mañana',
            'monto_inicial' => 1000,
            'estado' => 'ABIERTA',
        ]);
        $turnoDos = DB::table('caja_apertura')->insertGetId([
            'fecha' => '2026-10-18 16:00:00',
            'cajero' => 'ADMIN Test',
            'turno' => 'Tarde',
            'monto_inicial' => 1000,
            'estado' => 'ABIERTA',
        ]);

        $this->montarVenta([
            ['propina' => 111.11, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 09:00:00', 'factura' => true, 'apertura_id' => $turnoUno, 'usuario_cobro' => $cajero->id],
        ], $mesero->id);

        $this->montarVenta([
            ['propina' => 222.22, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 17:00:00', 'factura' => true, 'apertura_id' => $turnoDos, 'usuario_cobro' => $admin->id],
        ], $mesero->id);

        $servicio = app(\App\Services\ResumenPropinasService::class);

        $porTurno = $servicio->resumenDelDia('2026-10-18', ['apertura' => $turnoUno]);
        $this->assertSame(1, $porTurno['cantidad']);
        $this->assertSame(111.11, $porTurno['total']);
        $this->assertArrayHasKey($turnoUno, $porTurno['opciones']['turnos']);
        $this->assertArrayHasKey($cajero->id, $porTurno['opciones']['cobradores']);
        // La conciliación no se altera por filtrar.
        $this->assertSame(
            $porTurno['total'],
            round($porTurno['atribuido'] + $porTurno['pendiente'], 2)
        );

        $porCobro = $servicio->resumenDelDia('2026-10-18', ['cobro' => $cajero->id]);
        $this->assertSame(1, $porCobro['cantidad']);
        $this->assertSame(111.11, $porCobro['total']);

        // Combinación imposible (turno 1 lo cobró el cajero, no el admin).
        $combinado = $servicio->resumenDelDia('2026-10-18', ['apertura' => $turnoUno, 'cobro' => $admin->id]);
        $this->assertSame(0, $combinado['cantidad']);
        $this->assertSame(0.0, $combinado['total']);

        $this->actingAs($admin)
            ->get(route('reportes.propinas', [
                'desde' => '2026-10-18', 'hasta' => '2026-10-18', 'turno' => $turnoUno,
            ]))
            ->assertStatus(200)
            ->assertSee('name="turno"', false)
            ->assertSee('name="cobro"', false)
            ->assertSee('111.11')
            ->assertDontSee('222.22');
    }

    public function test_el_csv_coincide_con_los_totales_de_la_pantalla(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesero = $this->usuario('MESERO');

        $venta = $this->montarVenta([
            ['propina' => 137.37, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:00:00', 'factura' => true],
        ], $admin->id);
        $this->comanda($venta['venta'], $mesero->id);

        $filtros = ['desde' => '2026-10-18', 'hasta' => '2026-10-18'];

        $this->actingAs($admin)->get(route('reportes.propinas', $filtros))
            ->assertStatus(200)
            ->assertSee('137.37');

        $csv = $this->actingAs($admin)->get(route('reportes.propinas.csv', $filtros));

        $csv->assertStatus(200)->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $contenido = $csv->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $contenido, 'El CSV debe empezar con BOM UTF-8.');

        // fputcsv() puede envolver en comillas los campos que contienen
        // espacios (cambia según la versión de PHP): se eliminan las comillas
        // antes de comparar los VALUES, que es lo que debe coincidir.
        $contenidoLimpio = str_replace('"', '', $contenido);

        $this->assertStringContainsString('Propinas cobradas al cliente;137.37', $contenidoLimpio);
        $this->assertStringContainsString('Atribuido a meseros;137.37', $contenidoLimpio);
        $this->assertStringContainsString('Pendiente de atribución;0.00', $contenidoLimpio);
        $this->assertStringContainsString('TOTAL COBRADO;137.37', $contenidoLimpio);
        $this->assertStringContainsString('MESERO Test', $contenidoLimpio);
    }

    public function test_la_paginacion_del_detalle_no_altera_los_totales(): void
    {
        $admin = $this->usuario('ADMIN');

        $cuentas = [];
        for ($i = 0; $i < 55; $i++) {
            $cuentas[] = ['propina' => 1.00, 'estado' => 'PAGADA', 'fecha' => '2026-10-18 20:00:00', 'factura' => true];
        }
        $this->montarVenta($cuentas, $admin->id);

        $primera = $this->actingAs($admin)->get(route('reportes.propinas', [
            'desde' => '2026-10-18', 'hasta' => '2026-10-18', 'pagina' => 1,
        ]));

        $primera->assertStatus(200);
        $this->assertCount(50, $primera->viewData('paginaDetalle')->items(), 'La página 1 muestra 50 filas.');
        $this->assertSame(55, $primera->viewData('paginaDetalle')->total(), 'El total del paginador es el conjunto completo.');
        $primera->assertSee('C$ 55.00');

        $segunda = $this->actingAs($admin)->get(route('reportes.propinas', [
            'desde' => '2026-10-18', 'hasta' => '2026-10-18', 'pagina' => 2,
        ]));

        $segunda->assertStatus(200);
        $this->assertCount(5, $segunda->viewData('paginaDetalle')->items(), 'La página 2 muestra las 5 restantes.');
        $segunda->assertSee('C$ 55.00');

        // El servicio (fuente de KPI y totales) sigue viendo las 55 cuentas.
        $resumen = app(\App\Services\ResumenPropinasService::class)->resumenDelDia('2026-10-18');
        $this->assertSame(55, $resumen['cantidad']);
        $this->assertSame(55.0, $resumen['total']);
    }

    public function test_exportacion_e_impresion_solo_para_admin_y_gerente(): void
    {
        $admin = $this->usuario('ADMIN');
        $gerente = $this->usuario('GERENTE');
        $cajero = $this->usuario('CAJERO');
        $mesero = $this->usuario('MESERO');
        $cocinero = $this->usuario('COCINERO');

        $filtros = ['desde' => '2026-10-18', 'hasta' => '2026-10-18'];

        $this->actingAs($admin)->get(route('reportes.propinas.csv', $filtros))->assertStatus(200);
        $this->actingAs($admin)->get(route('reportes.propinas.imprimir', $filtros))->assertStatus(200);
        $this->actingAs($gerente)->get(route('reportes.propinas.csv', $filtros))->assertStatus(200);
        $this->actingAs($gerente)->get(route('reportes.propinas.imprimir', $filtros))->assertStatus(200);

        foreach ([$cajero, $mesero, $cocinero] as $usuario) {
            $this->actingAs($usuario)->get(route('reportes.propinas.csv', $filtros))->assertStatus(403);
            $this->actingAs($usuario)->get(route('reportes.propinas.imprimir', $filtros))->assertStatus(403);
            $this->actingAs($usuario)->get(route('reportes.propinas', $filtros))->assertStatus(403);
        }
    }
}
