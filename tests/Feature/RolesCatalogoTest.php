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

            // Activación (migración 2026_10_18_000003) y auditoría de la
            // última desactivación / reactivación.
            $t->boolean('activo')->default(1);
            $t->integer('desactivado_por')->nullable();
            $t->timestamp('desactivado_en')->nullable();
            $t->integer('reactivado_por')->nullable();
            $t->timestamp('reactivado_en')->nullable();
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

    /**
     * ID real del rol en la tabla (no el "esperado").
     */
    private function rolId(string $nombre): int
    {
        return (int) DB::table('roles')->where('nombre', $nombre)->value('id');
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

    /* ==================================================================
     | 11. Estado inicial: los cinco roles quedan ACTIVOS
     | ================================================================== */

    public function test_los_cinco_roles_inician_activos(): void
    {
        foreach (self::OFICIALES as $rol) {
            $this->rol($rol);
        }

        $admin = $this->usuario('ADMIN');

        // Ningún rol desactivado y la migración deja las columnas de auditoría vacías.
        $this->assertSame(0, DB::table('roles')->where('activo', 0)->count());
        $this->assertSame(5, DB::table('roles')->where('activo', 1)->count());
        $this->assertNull(DB::table('roles')->where('nombre', 'CAJERO')->value('desactivado_en'));

        $this->actingAs($admin)
            ->get(route('roles.index'))
            ->assertStatus(200)
            ->assertSee('ACTIVO')
            ->assertDontSee('INACTIVO');
    }

    /* ==================================================================
     | 12. Desactivar un rol con usuarios conserva las asignaciones
     | ================================================================== */

    public function test_admin_desactiva_rol_con_usuarios_conservando_asignaciones(): void
    {
        $this->rol('ADMIN');
        $cajeroId = $this->rol('CAJERO');
        $admin = $this->usuario('ADMIN');
        $cajero = $this->usuario('CAJERO');

        $resp = $this->actingAs($admin)
            ->post(route('roles.api'), ['accion' => 'desactivar', 'id' => $cajeroId])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        // Se informa el número real de usuarios asociados.
        $this->assertSame(1, $resp->json('usuarios'));

        // El rol NO se borra; queda inactivo.
        $this->assertDatabaseHas('roles', ['id' => $cajeroId, 'activo' => 0]);

        // La asignación del usuario se conserva intacta (no se reasigna a nadie).
        $this->assertSame($cajeroId, (int) DB::table('usuarios')->where('id', $cajero->id)->value('rol_id'));

        // Auditoría: quedó registrado quién y cuándo desactivó.
        $fila = DB::table('roles')->where('id', $cajeroId)->first();
        $this->assertSame((int) $admin->id, (int) $fila->desactivado_por);
        $this->assertNotNull($fila->desactivado_en);
        $this->assertNull($fila->reactivado_en);
    }

    /* ==================================================================
     | 12b. La pantalla muestra estado, usuarios asociados y protección ADMIN
     | ================================================================== */

    public function test_pantalla_muestra_usuarios_asociados_y_estado(): void
    {
        foreach (self::OFICIALES as $rol) {
            $this->rol($rol);
        }

        $admin = $this->usuario('ADMIN');
        $this->usuario('CAJERO');

        // Desactivamos CAJERO (tiene 1 usuario asociado).
        DB::table('roles')->where('nombre', 'CAJERO')->update(['activo' => 0]);

        $html = $this->actingAs($admin)
            ->get(route('roles.index'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringContainsString('INACTIVO', $html);
        $this->assertStringContainsString('btn-estado-rol', $html);
        // ADMIN aparece protegido (nunca se puede desactivar desde la pantalla).
        $this->assertStringContainsString('Protegido', $html);
        // Los cinco roles oficiales siguen listados.
        $this->assertSame(5, DB::table('roles')->count());
    }

    /* ==================================================================
     | 13. Usuario con rol desactivado no puede iniciar sesión
     | ================================================================== */

    public function test_usuario_con_rol_desactivado_no_puede_iniciar_sesion(): void
    {
        $this->rol('ADMIN');
        $this->usuario('CAJERO');

        DB::table('roles')->where('nombre', 'CAJERO')->update(['activo' => 0]);

        $this->post(route('login.post'), [
            'usuario' => 'cajero_test',
            'password' => 'secret123',
        ])->assertSessionHasErrors('usuario');

        $this->assertGuest();

        // Al reactivar el rol, el mismo usuario puede entrar.
        DB::table('roles')->where('nombre', 'CAJERO')->update(['activo' => 1]);

        $this->post(route('login.post'), [
            'usuario' => 'cajero_test',
            'password' => 'secret123',
        ])->assertRedirect(route('inicio'));

        $this->assertAuthenticated();
    }

    /* ==================================================================
     | 14. Sesiones ya abiertas se bloquean en su siguiente solicitud
     | ================================================================== */

    public function test_sesion_existente_se_bloquea_al_desactivar_el_rol(): void
    {
        $this->rol('ADMIN');
        $cajero = $this->usuario('CAJERO');

        // Antes de desactivar, la sesión funciona con normalidad.
        $this->actingAs($cajero)->get(route('inicio'))->assertStatus(200);

        DB::table('roles')->where('nombre', 'CAJERO')->update(['activo' => 0]);

        // La misma sesión queda bloqueada en la siguiente petición web...
        $this->actingAs($cajero)->get(route('inicio'))->assertStatus(403);

        // ...y también en peticiones que esperan JSON.
        $this->actingAs($cajero)
            ->getJson(route('inicio'))
            ->assertStatus(403)
            ->assertJson(['exito' => false]);
    }

    /* ==================================================================
     | 15. Reactivar devuelve el acceso y registra la auditoría
     | ================================================================== */

    public function test_reactivar_rol_devuelve_el_acceso(): void
    {
        $this->rol('ADMIN');
        $admin = $this->usuario('ADMIN');
        $cajero = $this->usuario('CAJERO');
        $cajeroId = $this->rolId('CAJERO');

        DB::table('roles')->where('id', $cajeroId)->update(['activo' => 0]);
        $this->actingAs($cajero)->get(route('inicio'))->assertStatus(403);

        $this->actingAs($admin)
            ->post(route('roles.api'), ['accion' => 'reactivar', 'id' => $cajeroId])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $fila = DB::table('roles')->where('id', $cajeroId)->first();
        $this->assertSame(1, (int) $fila->activo);
        $this->assertSame((int) $admin->id, (int) $fila->reactivado_por);
        $this->assertNotNull($fila->reactivado_en);

        // El usuario recupera el acceso sin modificar su registro.
        $this->assertSame($cajeroId, (int) DB::table('usuarios')->where('id', $cajero->id)->value('rol_id'));
        $this->actingAs($cajero)->get(route('inicio'))->assertStatus(200);
    }

    /* ==================================================================
     | 16. ADMIN no puede desactivarse
     | ================================================================== */

    public function test_admin_no_puede_desactivarse(): void
    {
        $admin = $this->usuario('ADMIN');
        $adminId = $this->rolId('ADMIN');

        $this->actingAs($admin)
            ->post(route('roles.api'), ['accion' => 'desactivar', 'id' => $adminId])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertSame(1, (int) DB::table('roles')->where('id', $adminId)->value('activo'));
    }

    /* ==================================================================
     | 17. Solo ADMIN puede cambiar el estado (autorización en backend)
     | ================================================================== */

    public function test_solo_admin_puede_cambiar_el_estado(): void
    {
        $this->rol('ADMIN');
        $this->rol('GERENTE');
        $gerente = $this->usuario('GERENTE');
        $cajeroId = $this->rol('CAJERO');

        $this->actingAs($gerente)
            ->post(route('roles.api'), ['accion' => 'desactivar', 'id' => $cajeroId])
            ->assertStatus(403)
            ->assertJson(['exito' => false]);

        $this->assertSame(1, (int) DB::table('roles')->where('id', $cajeroId)->value('activo'));
    }

    /* ==================================================================
     | 18. No se puede desactivar un rol no oficial ni inexistente
     | ================================================================== */

    public function test_no_se_puede_desactivar_un_rol_no_oficial(): void
    {
        $admin = $this->usuario('ADMIN');
        $raroId = $this->rol('ALGO_RARO');

        $this->actingAs($admin)
            ->post(route('roles.api'), ['accion' => 'desactivar', 'id' => $raroId])
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertSame(1, (int) DB::table('roles')->where('id', $raroId)->value('activo'));

        // Un rol inexistente tampoco se toca.
        $this->actingAs($admin)
            ->post(route('roles.api'), ['accion' => 'desactivar', 'id' => 9999])
            ->assertStatus(404)
            ->assertJson(['exito' => false]);
    }

    /* ==================================================================
     | 19. Idempotencia: no se repite una operación ya aplicada
     | ================================================================== */

    public function test_operaciones_de_estado_son_idempotentes(): void
    {
        $admin = $this->usuario('ADMIN');
        $cajeroId = $this->rol('CAJERO');

        $this->actingAs($admin)
            ->post(route('roles.api'), ['accion' => 'desactivar', 'id' => $cajeroId])
            ->assertStatus(200);

        $this->actingAs($admin)
            ->post(route('roles.api'), ['accion' => 'desactivar', 'id' => $cajeroId])
            ->assertStatus(422);

        $this->actingAs($admin)
            ->post(route('roles.api'), ['accion' => 'reactivar', 'id' => $cajeroId])
            ->assertStatus(200);

        $this->actingAs($admin)
            ->post(route('roles.api'), ['accion' => 'reactivar', 'id' => $cajeroId])
            ->assertStatus(422);
    }

    /* ==================================================================
     | 19b. El selector de usuarios solo ofrece roles ACTIVOS
     | ================================================================== */

    public function test_selector_de_usuarios_solo_ofrece_roles_activos(): void
    {
        foreach (self::OFICIALES as $rol) {
            $this->rol($rol);
        }

        $admin = $this->usuario('ADMIN');

        // Desactivamos MESERO: no debe ofrecerse para nuevos usuarios.
        DB::table('roles')->where('nombre', 'MESERO')->update(['activo' => 0]);

        $html = $this->actingAs($admin)
            ->get(route('usuarios.index'))
            ->assertStatus(200)
            ->getContent();

        // El JSON que alimenta el selector incluye los activos y excluye el inactivo.
        $this->assertStringContainsString('"nombre":"CAJERO"', $html);
        $this->assertStringNotContainsString('"nombre":"MESERO"', $html);
    }

    /* ==================================================================
     | 20. No se puede asignar un rol inactivo (backend, petición manipulada)
     | ================================================================== */

    public function test_no_se_puede_asignar_un_rol_inactivo(): void
    {
        $this->rol('ADMIN');
        $admin = $this->usuario('ADMIN');
        $cajeroId = $this->rol('CAJERO');

        DB::table('roles')->where('id', $cajeroId)->update(['activo' => 0]);

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'crear',
                'nombre' => 'Nuevo Cajero',
                'username' => 'nuevo_cajero',
                'password' => 'secret123',
                'rol_id' => $cajeroId,
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => false]);

        $this->assertDatabaseMissing('usuarios', ['username' => 'nuevo_cajero']);

        // Con el rol activo, la misma petición sí crea el usuario.
        DB::table('roles')->where('id', $cajeroId)->update(['activo' => 1]);

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'crear',
                'nombre' => 'Nuevo Cajero',
                'username' => 'nuevo_cajero',
                'password' => 'secret123',
                'rol_id' => $cajeroId,
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertDatabaseHas('usuarios', ['username' => 'nuevo_cajero', 'rol_id' => $cajeroId]);
    }

    /* ==================================================================
     | 21. También se bloquean las rutas que resuelven el rol por SQL (JOIN)
     | ================================================================== */

    public function test_ruta_con_join_de_roles_queda_bloqueada(): void
    {
        // `MesaController::api` resuelve el rol con un JOIN a `roles`. La
        // protección efectiva la aporta el middleware: si el rol está
        // desactivado, la petición se rechaza ANTES de llegar al controlador.
        $this->rol('ADMIN');
        $cajero = $this->usuario('CAJERO');
        DB::table('roles')->where('nombre', 'CAJERO')->update(['activo' => 0]);

        $this->actingAs($cajero)
            ->postJson(route('mesas.api'), ['accion' => 'crear_mesa', 'numero' => 'X1'])
            ->assertStatus(403)
            ->assertJson(['exito' => false])
            ->assertJsonFragment(['error' => 'Tu rol está desactivado. Contacta al administrador para que lo reactive.']);
    }

    /* ==================================================================
     | 22. Un rol desactivado puede cerrar sesión (no queda atrapado)
     | ================================================================== */

    public function test_usuario_con_rol_desactivado_puede_cerrar_sesion(): void
    {
        $this->rol('ADMIN');
        $cajero = $this->usuario('CAJERO');
        DB::table('roles')->where('nombre', 'CAJERO')->update(['activo' => 0]);

        // Bloqueado para operar...
        $this->actingAs($cajero)->get(route('inicio'))->assertStatus(403);

        // ...pero sí puede cerrar sesión.
        $this->actingAs($cajero)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
