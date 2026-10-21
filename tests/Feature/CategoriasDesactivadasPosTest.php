<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

/*
 * CATEGORÍAS DESACTIVADAS EN EL POS
 * =================================
 * Regla: una categoría con `activa = 0` deja de ofrecerse para NUEVAS
 * ventas, pero NO se borra nada:
 *  - La categoría y sus productos siguen en la base de datos.
 *  - Los productos conservan su `categoria_id` y su `activo`.
 *  - Las cuentas/ventas que YA tienen líneas agregadas no se alteran:
 *    se pueden seguir viendo y cobrando.
 *
 * La autorización y la validación reales están en el backend:
 *  - VentaController::pos()        -> filtra lo que se ofrece.
 *  - VentaController::api()        -> enviarPedidoDesdePos() valida contra la
 *    BASE DE DATOS el producto y su categoría (no confía en el navegador).
 *
 * Corren en SQLite :memory: (phpunit.xml). Nunca tocan "sistemadecaja".
 */
class CategoriasDesactivadasPosTest extends TestCase
{
    use CreaEsquemaCompleto;

    protected function setUp(): void
    {
        parent::setUp();

        // Candado de seguridad: nunca apuntar a la base MySQL real.
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));

        $this->crearEsquemaCompleto();
    }

    /* ------------------------------------------------------------------
     | Utilidades
     | ------------------------------------------------------------------ */

    private function usuario(string $rolNombre): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);
        $usuarioId = DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre.' Test',
            'username' => strtolower($rolNombre).'_'.uniqid(),
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]);

        return User::findOrFail($usuarioId);
    }

    private function mesa(string $numero = '1'): int
    {
        return DB::table('mesas')->insertGetId([
            'numero' => $numero,
            'capacidad' => 4,
            'estado' => 'LIBRE',
        ]);
    }

    private function categoria(string $nombre, bool $activa): int
    {
        return DB::table('categorias')->insertGetId([
            'nombre' => $nombre,
            'activa' => $activa,
        ]);
    }

    private function producto(string $nombre, float $precio, ?int $categoriaId, bool $cocina = false): int
    {
        return DB::table('productos')->insertGetId([
            'categoria_id' => $categoriaId,
            'nombre' => $nombre,
            'precio' => $precio,
            'requiere_cocina' => $cocina,
            'activo' => true,
        ]);
    }

    /** Venta ACTIVA con una cuenta ABIERTA lista para recibir pedidos. */
    private function ventaConCuentaAbierta(int $mesaId): array
    {
        $ventaId = DB::table('ventas')->insertGetId([
            'mesa_id' => $mesaId,
            'monto' => 0,
            'estado' => 'ACTIVA',
            'fecha' => now(),
        ]);

        $cuentaId = DB::table('cuentas')->insertGetId([
            'venta_id' => $ventaId,
            'nombre' => 'Cuenta Principal',
            'total' => 0,
            'estado' => 'ABIERTA',
        ]);

        return ['venta' => $ventaId, 'cuenta' => $cuentaId];
    }

    private function enviarCarrito(User $usuario, int $ventaId, int $cuentaId, int $productoId, int $cantidad = 1)
    {
        return $this->actingAs($usuario)->postJson('/pos/api', [
            'accion' => 'enviar_cocina',
            'venta_id' => $ventaId,
            'cuenta_id' => $cuentaId,
            'carrito' => json_encode([
                ['id' => $productoId, 'cantidad' => $cantidad, 'notas' => null],
            ]),
        ]);
    }

    /* ------------------------------------------------------------------
     | 1. Categorías activas siguen disponibles
     | ------------------------------------------------------------------ */

    public function test_categoria_activa_se_ofrece_y_su_producto_aparece_en_el_pos(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa();
        $categoriaId = $this->categoria('Bebidas Activas', true);
        $this->producto('Jugo de naranja', 30, $categoriaId);

        $respuesta = $this->actingAs($admin)->get('/pos/'.$mesaId);

        $respuesta->assertStatus(200);
        $respuesta->assertSee('Bebidas Activas');
        $respuesta->assertSee('Jugo de naranja');
    }

    /* ------------------------------------------------------------------
     | 2. Categorías desactivadas NO se ofrecen
     | ------------------------------------------------------------------ */

    public function test_categoria_desactivada_no_se_ofrece_ni_su_producto_en_el_pos(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa();
        $categoriaId = $this->categoria('Categoria Apagada', false);
        $this->producto('Producto Apagado', 50, $categoriaId);

        $respuesta = $this->actingAs($admin)->get('/pos/'.$mesaId);

        $respuesta->assertStatus(200);
        $respuesta->assertDontSee('Categoria Apagada');
        $respuesta->assertDontSee('Producto Apagado');
    }

    /* ------------------------------------------------------------------
     | 3. Petición manipulada: el backend rechaza contra la base de datos
     | ------------------------------------------------------------------ */

    public function test_peticion_manipulada_no_permite_vender_producto_de_categoria_desactivada(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa();
        $escena = $this->ventaConCuentaAbierta($mesaId);

        $categoriaId = $this->categoria('Categoria Bloqueada', false);
        $productoId = $this->producto('Producto Bloqueado', 80, $categoriaId);

        // Se envía directamente el id del producto, como haría una petición
        // manipulada: la UI no lo ofrece, pero el endpoint debe rechazarlo.
        $respuesta = $this->enviarCarrito(
            $admin,
            $escena['venta'],
            $escena['cuenta'],
            $productoId,
            2
        );

        $respuesta->assertStatus(422);
        $respuesta->assertJson(['exito' => false]);
        $this->assertStringContainsString('desactivada', (string) $respuesta->json('error'));

        // Nada se agregó: ni detalle, ni cuenta, ni comanda, ni inventario.
        $this->assertSame(0, DB::table('venta_detalles')->count());
        $this->assertSame(0, DB::table('cuenta_detalles')->count());
        $this->assertSame(0, DB::table('comandas')->count());
        $this->assertSame(0, DB::table('movimientos_inventario')->count());

        // El producto sigue existiendo y activo (no se borró nada).
        $this->assertSame(1, DB::table('productos')->where('id', $productoId)->count());
        $this->assertSame(1, (int) DB::table('productos')->where('id', $productoId)->value('activo'));
    }

    /* ------------------------------------------------------------------
     | 4. Desactivar no borra y se puede reactivar (ADMIN)
     | ------------------------------------------------------------------ */

    public function test_desactivar_no_borra_categoria_ni_producto_y_admin_puede_reactivar(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa();
        $categoriaId = $this->categoria('Postres', true);
        $productoId = $this->producto('Flan casero', 25, $categoriaId);

        // Desactivar desde la gestión de categorías.
        $this->actingAs($admin)->postJson('/catalogo/api', [
            'accion' => 'desactivar_categoria',
            'id' => $categoriaId,
        ])->assertStatus(200)->assertJson(['exito' => true]);

        // La categoría y el producto siguen existiendo.
        $this->assertSame(1, DB::table('categorias')->where('id', $categoriaId)->count());
        $this->assertSame(0, (int) DB::table('categorias')->where('id', $categoriaId)->value('activa'));
        $this->assertSame(1, DB::table('productos')->where('id', $productoId)->count());
        $this->assertSame(1, (int) DB::table('productos')->where('id', $productoId)->value('activo'));
        $this->assertSame($categoriaId, (int) DB::table('productos')->where('id', $productoId)->value('categoria_id'));

        // Ya no se ofrece en el POS.
        $this->actingAs($admin)->get('/pos/'.$mesaId)
            ->assertStatus(200)
            ->assertDontSee('Postres')
            ->assertDontSee('Flan casero');

        // Reactivar deja todo como estaba.
        $this->actingAs($admin)->postJson('/catalogo/api', [
            'accion' => 'activar_categoria',
            'id' => $categoriaId,
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame(1, (int) DB::table('categorias')->where('id', $categoriaId)->value('activa'));

        $this->actingAs($admin)->get('/pos/'.$mesaId)
            ->assertStatus(200)
            ->assertSee('Postres')
            ->assertSee('Flan casero');
    }

    /* ------------------------------------------------------------------
     | 5. Permisos de gestión de categorías
     | ------------------------------------------------------------------ */

    public function test_cajero_y_mesero_no_pueden_desactivar_categorias(): void
    {
        $categoriaId = $this->categoria('Bebidas', true);

        foreach (['CAJERO', 'MESERO'] as $rol) {
            $usuario = $this->usuario($rol);

            $this->actingAs($usuario)->postJson('/catalogo/api', [
                'accion' => 'desactivar_categoria',
                'id' => $categoriaId,
            ])->assertStatus(403);

            // La categoría permanece activa: el 403 no debe escribir nada.
            $this->assertSame(1, (int) DB::table('categorias')->where('id', $categoriaId)->value('activa'));
        }
    }

    /* ------------------------------------------------------------------
     | 6. Productos sin categoría siguen vendiéndose (sin regresión)
     | ------------------------------------------------------------------ */

    public function test_producto_sin_categoria_sigue_disponible_y_se_puede_vender(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa();
        $escena = $this->ventaConCuentaAbierta($mesaId);
        $productoId = $this->producto('Agua pura', 20, null);

        $this->actingAs($admin)->get('/pos/'.$mesaId)
            ->assertStatus(200)
            ->assertSee('Agua pura');

        $this->enviarCarrito($admin, $escena['venta'], $escena['cuenta'], $productoId)
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertSame(1, DB::table('venta_detalles')->count());
        $this->assertSame(1, DB::table('cuenta_detalles')->count());
    }

    /* ------------------------------------------------------------------
     | 7. Cuentas abiertas no se alteran de forma retroactiva
     | ------------------------------------------------------------------ */

    public function test_cuenta_abierta_con_producto_ya_agregado_sigue_visible_y_cobrable_tras_desactivar(): void
    {
        $admin = $this->usuario('ADMIN');
        $mesaId = $this->mesa();
        $escena = $this->ventaConCuentaAbierta($mesaId);
        $categoriaId = $this->categoria('Platos Fuertes', true);
        $productoId = $this->producto('Plato Persistente', 100, $categoriaId);

        // Se agrega el producto mientras la categoría está activa.
        $this->enviarCarrito($admin, $escena['venta'], $escena['cuenta'], $productoId)
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertSame(1, DB::table('venta_detalles')->count());
        $this->assertSame(1, DB::table('cuenta_detalles')->count());

        // Ahora se desactiva la categoría (venta en curso).
        $this->actingAs($admin)->postJson('/catalogo/api', [
            'accion' => 'desactivar_categoria',
            'id' => $categoriaId,
        ])->assertStatus(200);

        // La línea ya agregada se sigue viendo en la cuenta; no se borra ni
        // se cancela retroactivamente.
        $this->actingAs($admin)->get('/pos/'.$mesaId.'?cuenta='.$escena['cuenta'])
            ->assertStatus(200)
            ->assertSee('Plato Persistente');

        $this->assertSame(1, DB::table('venta_detalles')->count());
        $this->assertSame(1, DB::table('cuenta_detalles')->count());
        $this->assertSame(0, (int) DB::table('cuenta_detalles')->where('eliminado', true)->count());

        // Y la cuenta se puede cobrar con el flujo existente.
        $this->actingAs($admin)->postJson('/pos/cuentas/'.$escena['cuenta'].'/pagar', [
            'metodo_pago' => 'EFECTIVO',
            'monto_recibido' => 500,
        ])->assertStatus(200)->assertJson(['exito' => true]);

        $this->assertSame('PAGADA', DB::table('cuentas')->where('id', $escena['cuenta'])->value('estado'));
    }
}
