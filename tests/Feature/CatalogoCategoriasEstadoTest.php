<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

/*
 * Gestión de ESTADO de las categorías del catálogo.
 *
 * Regresión reparada: existía la creación de categorías y la columna real
 * `categorias.activa`, pero no había forma de activar o desactivar una
 * categoría desde la interfaz. Desactivar NO borra la categoría ni sus
 * productos: solo cambia `activa`.
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (configurado en phpunit.xml).
 * - El esquema se construye a mano porque las migraciones reales usan SQL de
 *   MySQL. Ninguna prueba toca "sistemadecaja".
 * - La autorización real está en el backend (CatalogoController), no en la
 *   vista: por eso se comprueba también el acceso directo por petición.
 */
class CatalogoCategoriasEstadoTest extends TestCase
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

    private function crearCategoria(string $nombre, int $activa = 1): int
    {
        return DB::table('categorias')->insertGetId(['nombre' => $nombre, 'activa' => $activa]);
    }

    private function crearProducto(int $categoriaId, string $nombre): int
    {
        return DB::table('productos')->insertGetId([
            'categoria_id' => $categoriaId,
            'nombre' => $nombre,
            'precio' => 100,
            'activo' => 1,
        ]);
    }

    /* ------------------------------------------------------------------
     | 1. Activar / desactivar (ADMIN y GERENTE)
     | ------------------------------------------------------------------ */

    public function test_admin_y_gerente_pueden_desactivar_y_activar_una_categoria(): void
    {
        foreach (['ADMIN', 'GERENTE'] as $rol) {
            $id = $this->crearCategoria('Bebidas '.$rol);

            $this->actingAs($this->usuario($rol))
                ->post(route('catalogo.api'), ['accion' => 'desactivar_categoria', 'id' => $id])
                ->assertStatus(200)
                ->assertJson(['exito' => true]);

            $this->assertDatabaseHas('categorias', ['id' => $id, 'activa' => 0]);

            $this->post(route('catalogo.api'), ['accion' => 'activar_categoria', 'id' => $id])
                ->assertStatus(200)
                ->assertJson(['exito' => true]);

            $this->assertDatabaseHas('categorias', ['id' => $id, 'activa' => 1]);
        }
    }

    public function test_desactivar_guarda_usuario_y_fecha_y_reactivar_conserva_el_historial(): void
    {
        $admin = $this->usuario('ADMIN');
        $gerente = $this->usuario('GERENTE');
        $id = $this->crearCategoria('Bebidas');

        $this->actingAs($admin)
            ->post(route('catalogo.api'), ['accion' => 'desactivar_categoria', 'id' => $id])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $desactivada = DB::table('categorias')->where('id', $id)->first();

        $this->assertSame(0, (int) $desactivada->activa);
        $this->assertSame((int) $admin->id, (int) $desactivada->desactivado_por);
        $this->assertNotNull($desactivada->desactivado_en);
        $this->assertNull($desactivada->reactivado_por);
        $this->assertNull($desactivada->reactivado_en);

        $fechaDesactivacion = $desactivada->desactivado_en;

        $this->actingAs($gerente)
            ->post(route('catalogo.api'), ['accion' => 'activar_categoria', 'id' => $id])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $reactivada = DB::table('categorias')->where('id', $id)->first();

        $this->assertSame(1, (int) $reactivada->activa);
        $this->assertSame((int) $gerente->id, (int) $reactivada->reactivado_por);
        $this->assertNotNull($reactivada->reactivado_en);

        // La reactivación NO borra la última desactivación registrada.
        $this->assertSame((int) $admin->id, (int) $reactivada->desactivado_por);
        $this->assertSame($fechaDesactivacion, $reactivada->desactivado_en);
    }

    public function test_las_columnas_de_auditoria_aceptan_null_para_registros_anteriores(): void
    {
        $id = $this->crearCategoria('Categoría antigua');

        $fila = DB::table('categorias')->where('id', $id)->first();

        $this->assertNull($fila->desactivado_por);
        $this->assertNull($fila->desactivado_en);
        $this->assertNull($fila->reactivado_por);
        $this->assertNull($fila->reactivado_en);
    }

    public function test_desactivar_una_categoria_no_la_borra_ni_borra_su_producto(): void
    {
        $categoriaId = $this->crearCategoria('Postres');
        $productoId = $this->crearProducto($categoriaId, 'Tres Leches');

        $this->actingAs($this->usuario('ADMIN'))
            ->post(route('catalogo.api'), ['accion' => 'desactivar_categoria', 'id' => $categoriaId])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        // La categoría sigue existiendo: solo cambió su estado a inactiva.
        $this->assertDatabaseHas('categorias', [
            'id' => $categoriaId,
            'nombre' => 'Postres',
            'activa' => 0,
        ]);
        $this->assertSame(1, DB::table('categorias')->count());

        // El producto NO se borra ni se desactiva, y conserva su categoría.
        $this->assertDatabaseHas('productos', [
            'id' => $productoId,
            'categoria_id' => $categoriaId,
            'activo' => 1,
        ]);
        $this->assertSame(1, DB::table('productos')->count());
    }

    public function test_al_volver_a_activar_el_estado_se_refleja_en_el_servidor(): void
    {
        $id = $this->crearCategoria('Bebidas', 0);

        $this->actingAs($this->usuario('ADMIN'))
            ->post(route('catalogo.api'), ['accion' => 'activar_categoria', 'id' => $id])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertSame(1, DB::table('categorias')->where('id', $id)->value('activa'));
    }

    /* ------------------------------------------------------------------
     | 2. Validaciones de servidor
     | ------------------------------------------------------------------ */

    public function test_no_se_puede_desactivar_una_categoria_inexistente(): void
    {
        $this->actingAs($this->usuario('ADMIN'))
            ->post(route('catalogo.api'), ['accion' => 'desactivar_categoria', 'id' => 9999])
            ->assertStatus(404)
            ->assertJson(['exito' => false]);
    }

    /* ------------------------------------------------------------------
     | 3. Autorización en backend (no basta con ocultar el botón)
     | ------------------------------------------------------------------ */

    public function test_cajero_y_mesero_no_pueden_cambiar_el_estado_de_una_categoria(): void
    {
        $id = $this->crearCategoria('Bebidas');

        foreach (['CAJERO', 'MESERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->post(route('catalogo.api'), ['accion' => 'desactivar_categoria', 'id' => $id])
                ->assertStatus(403)
                ->assertJson(['exito' => false]);

            $this->post(route('catalogo.api'), ['accion' => 'activar_categoria', 'id' => $id])
                ->assertStatus(403)
                ->assertJson(['exito' => false]);

            // La categoría no cambió: la denegación es real, no solo visual.
            $this->assertDatabaseHas('categorias', ['id' => $id, 'activa' => 1]);

            // Un usuario sin permiso tampoco deja rastro de auditoría.
            $this->assertNull(DB::table('categorias')->where('id', $id)->value('desactivado_por'));
            $this->assertNull(DB::table('categorias')->where('id', $id)->value('reactivado_por'));
        }
    }

    /* ------------------------------------------------------------------
     | 4. Interfaz: el estado se refleja y el panel respeta el rol
     | ------------------------------------------------------------------ */

    public function test_la_vista_refleja_el_estado_activo_e_inactivo(): void
    {
        $this->crearCategoria('Bebidas');
        $this->crearCategoria('Postres', 0);

        $this->actingAs($this->usuario('ADMIN'))
            ->get(route('catalogo.index'))
            ->assertStatus(200)
            ->assertSee('Bebidas')
            ->assertSee('Postres')
            ->assertSee('Activa')
            ->assertSee('Inactiva');
    }

    public function test_el_panel_de_gestion_solo_se_muestra_a_admin_y_gerente(): void
    {
        $this->crearCategoria('Bebidas');

        foreach (['ADMIN', 'GERENTE'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('catalogo.index'))
                ->assertStatus(200)
                ->assertSee('Gestionar categorías');
        }

        foreach (['CAJERO', 'MESERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('catalogo.index'))
                ->assertStatus(200)
                ->assertDontSee('Gestionar categorías');
        }
    }
}
