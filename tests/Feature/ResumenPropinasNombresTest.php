<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

/*
 * NOMBRES REALES EN EL RESUMEN DE PROPINAS
 * ========================================
 *
 * Regresión del problema: los desplegables/chips del reporte mostraban
 * «Usuario #N» aunque el mesero tenía nombre real en `usuarios`. La causa
 * era que el snapshot `cuentas.mesero_responsable_id` apunta a usuarios que
 * NO enviaron comandas, y el mapa de nombres solo se construía para los
 * meseros de las comandas.
 *
 * Verifica:
 *  - El mesero con nombre aparece con su nombre REAL (reporte, imprimir,
 *    resumen individual, ticket y desglose de Operaciones).
 *  - Varios meseros: cada uno con su nombre.
 *  - Propina pendiente: conserva «Pendiente de atribución».
 *  - Usuario sin nombre o inexistente: «Usuario no identificado»,
 *    nunca un ID.
 *  - Cajero y quien abrió la mesa NO heredan la propina por su acción.
 *  - Importes y totales no cambian.
 *
 * Corre en SQLite :memory: (phpunit.xml); nunca toca MySQL ni "sistemadecaja".
 */
class ResumenPropinasNombresTest extends TestCase
{
    use CreaEsquemaCompleto;

    protected function setUp(): void
    {
        parent::setUp();

        // Candado de seguridad: nunca apuntar a la base MySQL real.
        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas deben ejecutarse en SQLite.');
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'), 'La base de pruebas debe ser SQLite en memoria.');

        $this->crearEsquemaCompleto();

        Schema::dropIfExists('caja_gastos');
        Schema::create('caja_gastos', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('apertura_id');
            $t->string('concepto', 255);
            $t->decimal('monto', 12, 2);
            $t->dateTime('fecha')->nullable();
            $t->string('estado', 20)->default('ACTIVO');
            $t->integer('usuario_id')->nullable();
        });

        Schema::dropIfExists('caja_cierre');
        Schema::create('caja_cierre', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('apertura_id');
            $t->dateTime('fecha')->nullable();
            $t->string('cajero', 100)->nullable();
            $t->string('turno', 50)->nullable();
            $t->integer('caja_id')->default(1);
            $t->decimal('monto_apertura', 12, 2)->default(0);
            $t->decimal('ventas_efectivo', 12, 2)->default(0);
            $t->decimal('otros_ingresos', 12, 2)->default(0);
            $t->decimal('egresos', 12, 2)->default(0);
            $t->decimal('retiros', 12, 2)->default(0);
            $t->decimal('total_esperado', 12, 2)->default(0);
            $t->decimal('tasa_cambio', 10, 4)->default(1);
            $t->decimal('monto_extranjero', 12, 2)->default(0);
            $t->decimal('total_contado', 12, 2)->default(0);
            $t->decimal('diferencia', 12, 2)->default(0);
            $t->string('estado', 20)->default('CUADRA');
            $t->text('conteo_json')->nullable();
            $t->text('observaciones')->nullable();
            $t->integer('usuario_id')->nullable();
        });
    }

    private function usuario(string $rolNombre, string $nombre, string $username): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);

        return User::findOrFail(DB::table('usuarios')->insertGetId([
            'nombre' => $nombre,
            'username' => $username,
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
            'activo' => 1,
        ]));
    }

    /**
     * Crea una cuenta COBRADA con propina + factura EMITIDA a la apertura 1.
     *
     * @return array{cuenta_id: int, venta_id: int}
     */
    private function montarCobro(User $cobrador, array $datos): array
    {
        $mesaId = DB::table('mesas')->insertGetId([
            'numero' => 'Mesa '.uniqid(),
            'capacidad' => 4,
            'estado' => 'LIBRE',
        ]);

        $ventaId = DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'descripcion' => 'Venta nombres',
            'monto' => 100,
            'estado' => 'PAGADA',
            'usuario_id' => $cobrador->id,
            'apertura_id' => 1,
            'fecha' => $datos['fecha'],
        ]);

        $productoId = DB::table('productos')->insertGetId([
            'nombre' => 'Producto nombres '.uniqid(),
            'precio' => 100,
            'requiere_cocina' => 0,
            'controla_stock' => 0,
            'activo' => 1,
        ]);

        $detalleId = DB::table('venta_detalles')->insertGetId([
            'venta_id' => $ventaId,
            'producto_id' => $productoId,
            'cantidad' => 1,
            'precio_unitario' => 100,
            'subtotal' => 100,
            'estado' => 'ACTIVO',
        ]);

        $cuentaId = DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId,
            'nombre' => $datos['nombre'] ?? 'Cuenta nombres',
            'total' => 100,
            'estado' => 'PAGADA',
            'usuario_cobro' => $datos['usuario_cobro'] ?? $cobrador->id,
            'apertura_id' => 1,
            'descuento' => 0,
            'propina' => $datos['propina'],
            'mesero_responsable_id' => $datos['mesero_responsable_id'] ?? null,
            'created_at' => $datos['fecha'],
            'updated_at' => $datos['fecha'],
        ]);

        DB::table('cuenta_detalles')->insert([
            'cuenta_id' => $cuentaId,
            'venta_id' => $ventaId,
            'venta_detalle_id' => $detalleId,
            'cantidad' => 1,
            'subtotal' => 100,
            'eliminado' => false,
        ]);

        DB::table('facturas')->insert([
            'numero_factura' => $datos['numero_factura'],
            'venta_id' => $ventaId,
            'cuenta_id' => $cuentaId,
            'mesa' => 'Mesa',
            'detalle_consumo' => '{}',
            'total' => 100,
            'fecha_emision' => $datos['fecha'],
            'usuario_id' => $datos['factura_usuario_id'] ?? $cobrador->id,
            'estado' => 'EMITIDA',
        ]);

        return ['cuenta_id' => $cuentaId, 'venta_id' => $ventaId];
    }

    /* ------------------------------------------------------------------
     | Nombre real del mesero atribuido: aparece en todas las salidas
     | ------------------------------------------------------------------ */

    public function test_el_mesero_aparece_con_su_nombre_real_en_el_reporte_pantalla(): void
    {
        $admin = $this->usuario('ADMIN', 'Admin Test', 'admin_nombres');
        $mesero = $this->usuario('MESERO', 'Axel Mendoza', 'axel_nombres');

        $this->montarCobro($admin, [
            'propina' => 11.00,
            'fecha' => '2026-10-19 16:37:00',
            'numero_factura' => 'FAC-NOM-0001',
            'mesero_responsable_id' => $mesero->id,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']))
            ->assertStatus(200)
            ->getContent();

        // El desplegable de meseros muestra el nombre real, no un ID.
        $this->assertStringContainsString('Axel Mendoza', $html);
        $this->assertStringNotContainsString('Usuario #' . $mesero->id, $html);
        $this->assertStringNotContainsString('Usuario #', $html);
    }

    public function test_el_mesero_aparece_con_su_nombre_real_en_imprimir_y_csv(): void
    {
        $admin = $this->usuario('ADMIN', 'Admin Test', 'admin_nombres_2');
        $mesero = $this->usuario('MESERO', 'Axel Mendoza', 'axel_nombres_2');

        $this->montarCobro($admin, [
            'propina' => 12.00,
            'fecha' => '2026-10-19 16:37:00',
            'numero_factura' => 'FAC-NOM-0002',
            'mesero_responsable_id' => $mesero->id,
        ]);

        $this->actingAs($admin)
            ->get(route('reportes.propinas.imprimir', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']))
            ->assertStatus(200)
            ->assertSee('Axel Mendoza', false);

        $respuesta = $this->actingAs($admin)
            ->get(route('reportes.propinas.csv', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']))
            ->assertStatus(200);

        $csv = method_exists($respuesta, 'streamedContent') ? $respuesta->streamedContent() : $respuesta->getContent();

        $this->assertStringContainsString('Axel Mendoza', $csv);
        $this->assertStringNotContainsString('Usuario #', $csv);
    }

    public function test_varios_meseros_cada_uno_con_su_nombre(): void
    {
        $admin = $this->usuario('ADMIN', 'Admin Test', 'admin_nombres_3');
        $mesero1 = $this->usuario('MESERO', 'Jesus Ramos', 'jesus_nombres');
        $mesero2 = $this->usuario('MESERO', 'Axel Mendoza', 'axel_nombres_3');

        $this->montarCobro($admin, [
            'propina' => 10.00,
            'fecha' => '2026-10-19 16:00:00',
            'numero_factura' => 'FAC-NOM-0010',
            'mesero_responsable_id' => $mesero1->id,
        ]);
        $this->montarCobro($admin, [
            'propina' => 20.00,
            'fecha' => '2026-10-19 17:00:00',
            'numero_factura' => 'FAC-NOM-0011',
            'mesero_responsable_id' => $mesero2->id,
        ]);

        $servicio = new \App\Services\ResumenPropinasService();
        $resumen = $servicio->resumenDelRango('2026-10-19', '2026-10-19');

        // Opciones del desplegable: nombre real de AMBOS meseros.
        $this->assertSame('Jesus Ramos', $resumen['opciones']['meseros'][$mesero1->id] ?? null);
        $this->assertSame('Axel Mendoza', $resumen['opciones']['meseros'][$mesero2->id] ?? null);

        // Desglose individual por mesero: cada fila con su nombre.
        $nombresFilas = collect($resumen['resumen_meseros'])->pluck('mesero')->all();
        $this->assertContains('Jesus Ramos', $nombresFilas);
        $this->assertContains('Axel Mendoza', $nombresFilas);
        $this->assertNotContains('Usuario #' . $mesero1->id, $nombresFilas);
        $this->assertNotContains('Usuario #' . $mesero2->id, $nombresFilas);

        // La pantalla y la impresión también los muestran.
        $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']))
            ->assertStatus(200)
            ->assertSee('Jesus Ramos')
            ->assertSee('Axel Mendoza');
    }

    /* ------------------------------------------------------------------
     | Pendiente conserva el estado pendiente (sin inventar mesero)
     | ------------------------------------------------------------------ */

    public function test_propuesta_pendiente_conserva_pendiente_de_atribucion(): void
    {
        $admin = $this->usuario('ADMIN', 'Admin Test', 'admin_nombres_4');
        $cobrador = $this->usuario('CAJERO', 'Cajero Nombres', 'cajero_nombres_4');

        // Sin mesero responsable y sin comandas de mesero: PENDIENTE.
        $this->montarCobro($cobrador, [
            'propina' => 15.00,
            'fecha' => '2026-10-19 18:00:00',
            'numero_factura' => 'FAC-NOM-0020',
            'mesero_responsable_id' => null,
        ]);

        $servicio = new \App\Services\ResumenPropinasService();
        $resumen = $servicio->resumenDelRango('2026-10-19', '2026-10-19');

        $this->assertSame(1, (int) $resumen['cantidad_pendientes']);
        $this->assertEqualsWithDelta(15.00, (float) $resumen['pendiente'], 0.001);
        $fila = collect($resumen['resumen_meseros'])->sole();
        $this->assertSame('Pendiente de atribución', $fila['mesero']);
        $this->assertTrue((bool) $fila['pendiente']);

        $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']))
            ->assertStatus(200)
            ->assertSee('Pendiente de atribución');
    }

    /* ------------------------------------------------------------------
     | Usuario sin nombre o inexistente: «Usuario no identificado»
     | ------------------------------------------------------------------ */

    public function test_usuario_sin_nombre_se_muestra_como_no_identificado(): void
    {
        $admin = $this->usuario('ADMIN', 'Admin Test', 'admin_nombres_5');
        $rolMesero = DB::table('roles')->insertGetId(['nombre' => 'MESERO']);

        $meseroSinNombre = User::findOrFail(DB::table('usuarios')->insertGetId([
            'nombre' => '', // vacío: nunca debe renderizarse un ID inventado
            'username' => 'sin_nombre_nombres',
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolMesero,
            'activo' => 1,
        ]));

        $this->montarCobro($admin, [
            'propina' => 25.00,
            'fecha' => '2026-10-19 19:00:00',
            'numero_factura' => 'FAC-NOM-0030',
            'mesero_responsable_id' => $meseroSinNombre->id,
        ]);

        $servicio = new \App\Services\ResumenPropinasService();
        $resumen = $servicio->resumenDelRango('2026-10-19', '2026-10-19');

        $this->assertSame('Usuario no identificado', $resumen['opciones']['meseros'][$meseroSinNombre->id] ?? null);

        $html = $this->actingAs($admin)
            ->get(route('reportes.propinas', ['desde' => '2026-10-19', 'hasta' => '2026-10-19']))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('Usuario no identificado', $html);
        $this->assertStringNotContainsString('Usuario #' . $meseroSinNombre->id, $html);
    }

    public function test_mesero_responsable_inexistente_descarta_atribucion(): void
    {
        $admin = $this->usuario('ADMIN', 'Admin Test', 'admin_nombres_6');

        // apunta a un usuario MESERO que ya no existe (histórico inconsistente):
        // la propina permanece PENDIENTE, nunca con un nombre inventado.
        $this->montarCobro($admin, [
            'propina' => 33.00,
            'fecha' => '2026-10-19 19:30:00',
            'numero_factura' => 'FAC-NOM-0031',
            'mesero_responsable_id' => 999999,
        ]);

        $servicio = new \App\Services\ResumenPropinasService();
        $resumen = $servicio->resumenDelRango('2026-10-19', '2026-10-19');

        $this->assertSame(1, (int) $resumen['cantidad_pendientes']);
        $this->assertEqualsWithDelta(33.00, (float) $resumen['pendiente'], 0.001);
        $this->assertStringNotContainsString('Usuario #999999', json_encode($resumen, JSON_UNESCAPED_UNICODE));
    }

    /* ------------------------------------------------------------------
     | Cajero / quien abrió NO reciben la propina por su acción
     | ------------------------------------------------------------------ */

    public function test_cobrador_y_quien_abrio_no_hereda_la_propina(): void
    {
        $admin = $this->usuario('ADMIN', 'Admin Apellido', 'admin_nombres_7');
        $cajero = $this->usuario('CAJERO', 'Rosario Cajero', 'rosario_nombres_7');

        $this->montarCobro($cajero, [
            'propina' => 40.00,
            'fecha' => '2026-10-19 20:00:00',
            'numero_factura' => 'FAC-NOM-0040',
            'mesero_responsable_id' => null, // sin mesero: pendiente aunque la cobró un CAJERO
            'usuario_cobro' => $cajero->id,
        ]);

        $servicio = new \App\Services\ResumenPropinasService();
        $resumen = $servicio->resumenDelRango('2026-10-19', '2026-10-19');

        // La propina NO se atribuye al cajero ni al admin que abrió la mesa.
        $this->assertSame(1, (int) $resumen['cantidad_pendientes']);
        $this->assertEqualsWithDelta(0.00, (float) $resumen['atribuido'], 0.001);

        // El cobrador aparece en su propio selector, con su nombre real.
        $this->assertSame('Rosario Cajero', $resumen['opciones']['cobradores'][$cajero->id] ?? null);

        // Y ninguna fila atribuye dinero al cajero ni al admin.
        foreach ($resumen['resumen_meseros'] as $fila) {
            $this->assertSame('Pendiente de atribución', $fila['mesero']);
        }
    }

    /* ------------------------------------------------------------------
     | Importes y totales no cambian por la corrección de nombres
     | ------------------------------------------------------------------ */

    public function test_los_importes_y_totales_no_cambian(): void
    {
        $admin = $this->usuario('ADMIN', 'Admin Test', 'admin_nombres_8');
        $mesero = $this->usuario('MESERO', 'Axel Mendoza', 'axel_nombres_8');
        $otroMesero = $this->usuario('MESERO', 'Jesus Ramos', 'jesus_nombres_8');

        $this->montarCobro($admin, [
            'propina' => 55.00,
            'fecha' => '2026-10-19 16:34:00',
            'numero_factura' => 'FAC-NOM-0050',
            'mesero_responsable_id' => $mesero->id,
        ]);
        $this->montarCobro($admin, [
            'propina' => 11.00,
            'fecha' => '2026-10-19 23:10:00',
            'numero_factura' => 'FAC-NOM-0051',
            'mesero_responsable_id' => $otroMesero->id,
        ]);

        $servicio = new \App\Services\ResumenPropinasService();
        $resumen = $servicio->resumenDelRango('2026-10-19', '2026-10-19');

        $this->assertEqualsWithDelta(66.00, (float) $resumen['total'], 0.001);
        $this->assertEqualsWithDelta(66.00, (float) $resumen['atribuido'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $resumen['pendiente'], 0.001);
        $this->assertSame(2, (int) $resumen['cantidad_atribuidas']);
        $meseros = collect($resumen['resumen_meseros'])->keyBy('mesero_id');
        $this->assertEqualsWithDelta(55.00, (float) $meseros[$mesero->id]['propinas'], 0.001);
        $this->assertEqualsWithDelta(11.00, (float) $meseros[$otroMesero->id]['propinas'], 0.001);

        // Login intacto: los importes guardados en BD no se tocan.
        $this->assertEqualsWithDelta(55.00, (float) DB::table('cuentas')->where('mesero_responsable_id', $mesero->id)->sum('propina'), 0.001);
        $this->assertEqualsWithDelta(11.00, (float) DB::table('cuentas')->where('mesero_responsable_id', $otroMesero->id)->sum('propina'), 0.001);
    }

    /* ------------------------------------------------------------------
     | Ticket y desglose de Operaciones muestran el nombre real
     | ------------------------------------------------------------------ */

    public function test_el_ticket_muestra_el_desglose_por_mesero_con_nombres(): void
    {
        $admin = $this->usuario('ADMIN', 'Admin Test', 'admin_nombres_9');
        $mesero = $this->usuario('MESERO', 'Axel Mendoza', 'axel_nombres_9');

        DB::table('caja_apertura')->update(['fecha' => now()->format('Y-m-d') . ' 08:00:00', 'cajero' => 'TEST', 'estado' => 'ABIERTA', 'usuario_id' => $admin->id]);

        $this->montarCobro($admin, [
            'propina' => 36.00,
            'fecha' => now()->format('Y-m-d H:i:s'),
            'numero_factura' => 'FAC-NOM-0060',
            'mesero_responsable_id' => $mesero->id,
        ]);

        $hoy = now()->format('Y-m-d');
        $html = $this->actingAs($admin)
            ->get(route('reportes.resumen.ticket', ['desde' => $hoy, 'hasta' => $hoy]))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('Axel Mendoza', $html);
        $this->assertStringNotContainsString('Usuario #' . $mesero->id, $html);
    }
}
