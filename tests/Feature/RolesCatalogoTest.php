<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Pruebas aisladas del catálogo oficial de roles.
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (configurado en phpunit.xml).
 * - El esquema se construye a mano: ninguna prueba toca la base real
 *   "sistemadecaja".
 * - NUNCA se fuerzan IDs ni se altera el esquema: solo se inserta el nombre.
 */
class RolesCatalogoTest extends TestCase
{
    // Catálogo oficial esperado (mismo que RolController).
    private const OFICIALES = ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO', 'COCINERO'];

    protected function setUp(): void
    {
        parent::setUp();

        // Candado de seguridad: nunca apuntar a la base MySQL real.
        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas deben ejecutarse en SQLite.');
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'), 'La base de pruebas debe ser SQLite en memoria.');

        $this->crearEsquema();
    }

    private function crearEsquema(): void
    {
        foreach (['usuarios', 'roles'] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::create('roles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 30)->unique();
        });

        Schema::create('usuarios', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 100);
            $t->string('username', 50)->unique();
            $t->string('password_hash', 255);
            $t->integer('rol_id');
            $t->boolean('activo')->default(1);
        });
    }

    private function rol(string $nombre, ?int $idForzado = null): int
    {
        if ($idForzado !== null) {
            DB::table('roles')->insert(['id' => $idForzado, 'nombre' => $nombre]);

            return $idForzado;
        }

        return (int) DB::table('roles')->insertGetId(['nombre' => $nombre]);
    }

    private function usuario(string $rolNombre): User
    {
        // Nos aseguramos de que el rol exista (puede que no esté aún).
        $existe = DB::table('roles')->where('nombre', $rolNombre)->first();
        $rolId = $existe ? (int) $existe->id : $this->rol($rolNombre);

        $id = DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre.' Test',
            'username' => strtolower($rolNombre).'_test',
            'password_hash' => Hash::make('secret123'),
            'rol_id' => $rolId,
            'activo' => 1,
        ]);

        return User::findOrFail($id);
    }

    /* ==================================================================
     | 1. Lista de roles faltantes en orden ascendente
     | ================================================================== */

    public function test_lista_de_roles_faltantes_en_orden_ascendente(): void
    {
        // Existen solo ADMIN, CAJERO y MESERO: faltan GERENTE y COCINERO.
        $this->rol('ADMIN');
        $this->rol('CAJERO');
        $this->rol('MESERO');

        $admin = $this->usuario('ADMIN');

        $resp = $this->actingAs($admin)->get(route('roles.index'));
        $resp->assertStatus(200);

        // El catálogo oficial se muestra en orden (ID ascendente).
        $resp->assertSeeInOrder(['ADMIN', 'GERENTE', 'CAJERO', 'MESERO', 'COCINERO']);

        // Faltan GERENTE y COCINERO, y el botón de crear está disponible.
        $resp->assertSee('GERENTE')
            ->assertSee('COCINERO')
            ->assertSee('FALTANTE')
            ->assertSee('<button class="btn-crear-rol"', false)
            ->assertDontSee('El catálogo de roles está completo');
    }

    public function test_catalogo_completo_informa_y_deshabilita_creacion(): void
    {
        foreach (self::OFICIALES as $r) {
            $this->rol($r);
        }

        $admin = $this->usuario('ADMIN');

        $this->actingAs($admin)
            ->get(route('roles.index'))
            ->assertStatus(200)
            ->assertSee('El catálogo de roles está completo')
            // No debe ofrecerse el botón de creación.
            ->assertDontSee('<button class="btn-crear-rol"', false);

        // Intentar crear otro rol (incluso oficial ya existente) se rechaza.
        $this->actingAs($admin)
            ->post(route('roles.api'), ['accion' => 'crear', 'nombre' => 'CAJERO'])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertSame(5, DB::table('roles')->count());
    }

    /* ==================================================================
     | 2. Solo ADMIN crea roles oficiales faltantes
     | ================================================================== */

    public function test_admin_crea_un_rol_oficial_faltante(): void
    {
        $admin = $this->usuario('ADMIN'); // id 1 = ADMIN

        // GERENTE (id esperado 2) falta y su slot está libre.
        $this->assertSame(1, DB::table('roles')->count());

        $this->actingAs($admin)
            ->post(route('roles.api'), ['accion' => 'crear', 'nombre' => 'GERENTE'])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertDatabaseHas('roles', ['nombre' => 'GERENTE']);
        $this->assertSame(2, DB::table('roles')->count());
    }

    /* ==================================================================
     | 3. Nombres no oficiales y duplicados
     | ================================================================== */

    public function test_se_rechazan_nombres_no_oficiales(): void
    {
        $admin = $this->usuario('ADMIN');

        foreach (['SUPERADMIN', 'INVITADO', 'ADMINISTRADOR', ''] as $invalido) {
            $this->actingAs($admin)
                ->post(route('roles.api'), ['accion' => 'crear', 'nombre' => $invalido])
                ->assertStatus(422)
                ->assertJson(['exito' => false]);
        }

        $this->assertSame(1, DB::table('roles')->count());
    }

    public function test_se_rechazan_duplicados_ignorando_mayusculas_y_espacios(): void
    {
        $admin = $this->usuario('ADMIN'); // ADMIN

        // Duplicado con distinto uso de mayúsculas y espacios.
        $this->actingAs($admin)
            ->post(route('roles.api'), ['accion' => 'crear', 'nombre' => '  admin '])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertSame(1, DB::table('roles')->count());
    }

    public function test_conflicto_con_id_esperado_ocupado_es_rechazado(): void
    {
        $admin = $this->usuario('ADMIN'); // id 1 = ADMIN
        // Un rol NO oficial ocupa el id esperado de GERENTE (2).
        $this->rol('ALGO_RARO', 2);
        // CAJERO espera id 3, libre.
        $this->rol('CAJERO');

        // Crear GERENTE (espera id 2) debe fallar por conflicto de ID.
        $this->actingAs($admin)
            ->post(route('roles.api'), ['accion' => 'crear', 'nombre' => 'GERENTE'])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertDatabaseMissing('roles', ['nombre' => 'GERENTE']);
    }

    /* ==================================================================
     | 4. Manipulación de la petición: no eleva privilegios
     | ================================================================== */

    public function test_enviar_admin_o_rol_id_no_eleva_privilegios(): void
    {
        $cajero = $this->usuario('CAJERO');

        $this->actingAs($cajero)
            ->post(route('roles.api'), [
                'accion' => 'crear',
                'nombre' => 'ADMIN',
                'rol' => 'ADMIN',
                'rol_id' => 1,
                'rol_id_actual' => 1,
            ])
            ->assertStatus(403)
            ->assertJson(['exito' => false]);

        // No se creó nada: el rol ADMIN ya no cambia y no hay roles nuevos.
        $this->assertSame(1, DB::table('roles')->count());
    }

    /* ==================================================================
     | 4b. Acciones administrativas no permitidas (eliminar/editar)
     | ================================================================== */

    public function test_no_existe_accion_de_eliminar_roles(): void
    {
        $admin = $this->usuario('ADMIN');
        $cajeroId = $this->rol('CAJERO');

        $this->actingAs($admin)
            ->post(route('roles.api'), ['accion' => 'eliminar', 'id' => $cajeroId])
            ->assertJson(['exito' => false]);

        // El rol sigue existiendo.
        $this->assertDatabaseHas('roles', ['id' => $cajeroId, 'nombre' => 'CAJERO']);
    }

    /* ==================================================================
     | 5. GERENTE y demás roles no administran el catálogo
     | ================================================================== */

    public function test_gerente_y_otros_roles_no_pueden_ver_ni_crear(): void
    {
        // Preparar roles y un usuario por rol.
        $this->rol('ADMIN');
        $this->rol('GERENTE');

        foreach (['GERENTE', 'CAJERO', 'MESERO', 'COCINERO'] as $rolActor) {
            $actor = $this->usuario($rolActor);

            // No pueden entrar a la pantalla.
            $this->actingAs($actor)
                ->get(route('roles.index'))
                ->assertRedirect(route('inicio'));

            // No pueden crear vía API.
            $this->actingAs($actor)
                ->post(route('roles.api'), ['accion' => 'crear', 'nombre' => 'MESERO'])
                ->assertStatus(403)
                ->assertJson(['exito' => false]);
        }
    }

    /* ==================================================================
     | 6. Rutas rechazan solicitudes no autenticadas
     | ================================================================== */

    public function test_rutas_rechazan_solicitudes_no_autenticadas(): void
    {
        $this->get(route('roles.index'))->assertRedirect(route('login'));
        $this->post(route('roles.api'), ['accion' => 'crear', 'nombre' => 'ADMIN'])
            ->assertRedirect(route('login'));

        $this->assertSame(0, DB::table('roles')->count());
    }

    /* ==================================================================
     | 7. ADMIN crea TODOS los roles oficiales faltantes
     | ================================================================== */

    public function test_admin_crea_todos_los_roles_oficiales_faltantes(): void
    {
        $admin = $this->usuario('ADMIN'); // crea ADMIN (id 1)

        // Faltan los otros cuatro roles oficiales: se crean uno por uno.
        foreach (['GERENTE', 'CAJERO', 'MESERO', 'COCINERO'] as $rol) {
            $this->actingAs($admin)
                ->post(route('roles.api'), ['accion' => 'crear', 'nombre' => $rol])
                ->assertStatus(200)
                ->assertJson(['exito' => true]);

            $this->assertDatabaseHas('roles', ['nombre' => $rol]);
        }

        // El catálogo queda completo (5 roles) y ya no permite crear más.
        $this->assertSame(5, DB::table('roles')->count());

        $this->actingAs($admin)
            ->post(route('roles.api'), ['accion' => 'crear', 'nombre' => 'GERENTE'])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertSame(5, DB::table('roles')->count());
    }

    /* ==================================================================
     | 8. El rol ADMIN nunca se crea (ni duplica) desde el catálogo
     | ================================================================== */

    public function test_admin_no_puede_crear_un_segundo_rol_admin(): void
    {
        $admin = $this->usuario('ADMIN');

        // Aunque sea ADMIN, la operación de crear ADMIN se rechaza siempre.
        $this->actingAs($admin)
            ->post(route('roles.api'), ['accion' => 'crear', 'nombre' => 'ADMIN'])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertSame(1, DB::table('roles')->where('nombre', 'ADMIN')->count());
        $this->assertSame(1, DB::table('roles')->count());
    }

    public function test_no_se_puede_crear_admin_manipulando_la_peticion(): void
    {
        $admin = $this->usuario('ADMIN');

        // Intentos con variaciones de mayúsculas/espacios y campos
        // manipulados para simular privilegios.
        foreach (['ADMIN', ' admin ', 'Admin'] as $intento) {
            $this->actingAs($admin)
                ->post(route('roles.api'), [
                    'accion' => 'crear',
                    'nombre' => $intento,
                    'rol' => 'ADMIN',
                    'rol_id' => 1,
                    'id' => 1,
                ])
                ->assertStatus(422)
                ->assertJson(['exito' => false]);
        }

        // Un rol distinto tampoco puede crear ADMIN (403 antes de validar).
        $cajero = $this->usuario('CAJERO');

        $this->actingAs($cajero)
            ->post(route('roles.api'), ['accion' => 'crear', 'nombre' => 'ADMIN'])
            ->assertStatus(403)
            ->assertJson(['exito' => false]);

        $this->assertSame(1, DB::table('roles')->where('nombre', 'ADMIN')->count());
    }

    /* ==================================================================
     | 9. Los cinco roles registrados se reconocen correctamente
     | ================================================================== */

    public function test_los_cinco_roles_registrados_se_reconocen(): void
    {
        foreach (self::OFICIALES as $rol) {
            $this->rol($rol);
        }

        $admin = $this->usuario('ADMIN'); // usa el ADMIN ya existente

        $html = $this->actingAs($admin)
            ->get(route('roles.index'))
            ->assertStatus(200)
            ->getContent();

        // Cada rol oficial aparece y los cinco figuran como REGISTRADO.
        foreach (self::OFICIALES as $rol) {
            $this->assertStringContainsString($rol, $html);
        }
        $this->assertSame(5, substr_count($html, 'REGISTRADO'));

        // Sin botones de creación cuando el catálogo está completo.
        // (La clase también aparece en el <script> del listener, así que
        //  comprobamos el marcado real del botón, no la clase suelta.)
        $this->assertStringNotContainsString('<button class="btn-crear-rol"', $html);
    }

    /* ==================================================================
     | 10. El botón Roles aparece solo para ADMIN en la pantalla de inicio
     | ================================================================== */

    public function test_boton_roles_visible_solo_para_admin_en_inicio(): void
    {
        $admin = $this->usuario('ADMIN');

        // ADMIN ve el acceso a Roles (también lo tiene en el menú lateral).
        $this->actingAs($admin)
            ->get(route('inicio'))
            ->assertStatus(200)
            ->assertSee(route('roles.index'));

        // Ningún otro rol ve el acceso a Roles.
        foreach (['GERENTE', 'CAJERO', 'MESERO', 'COCINERO'] as $rol) {
            $actor = $this->usuario($rol);

            $this->actingAs($actor)
                ->get(route('inicio'))
                ->assertStatus(200)
                ->assertDontSee(route('roles.index'));
        }
    }
}
