<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * Pruebas aisladas del módulo de administración de Usuarios.
 *
 * IMPORTANTE:
 * - Corren en SQLite :memory: (configurado en phpunit.xml).
 * - El esquema se construye a mano porque la tabla real vive en MySQL; así
 *   ninguna prueba escribe en la base "sistemadecaja".
 * - setUp() falla a propósito si la conexión no es SQLite en memoria.
 *
 * Reglas comprobadas:
 * - Todo el módulo (listado y API) está reservado a ADMIN en el backend.
 * - Búsqueda por usuario y por nombre (en servidor).
 * - Filtro por rol y por estado (activos / eliminados / todos).
 * - Dos secciones separadas: "Usuarios activos" y "Usuarios eliminados".
 * - Los usuarios eliminados NO se mezclan con los activos.
 * - Borrado lógico: activo = 0 (no se borra la fila).
 * - Reactivación ("Restablecer usuario") del MISMO registro, sin duplicar.
 * - Contraseña siempre hasheada; nunca en texto plano.
 * - Rechazo de contraseñas cortas o que no coinciden.
 * - Los nombres con caracteres HTML se muestran como texto (sin XSS).
 * - El botón "Cambiar contraseña" se renderiza en el HTML.
 */
class UsuarioModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Candado de seguridad: nunca apuntar a la base MySQL real.
        $this->assertSame(
            'sqlite',
            DB::connection()->getDriverName(),
            'Las pruebas deben ejecutarse en SQLite.'
        );
        $this->assertSame(
            ':memory:',
            (string) config('database.connections.sqlite.database'),
            'La base de pruebas debe ser SQLite en memoria.'
        );

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

        // Refleja el esquema real: username UNIQUE y columna activo (borrado lógico).
        Schema::create('usuarios', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 100);
            $t->string('username', 50)->unique();
            $t->string('password_hash', 255);
            $t->integer('rol_id');
            $t->boolean('activo')->default(1);
        });
    }

    private function rolId(string $nombre): int
    {
        $existe = DB::table('roles')->where('nombre', $nombre)->first();
        if ($existe) {
            return (int) $existe->id;
        }

        return (int) DB::table('roles')->insertGetId(['nombre' => $nombre]);
    }

    private function usuario(string $rolNombre, ?string $username = null, ?string $nombre = null, int $activo = 1): User
    {
        $username = $username ?? strtolower($rolNombre).'_test';

        $id = DB::table('usuarios')->insertGetId([
            'nombre' => $nombre ?? ($rolNombre.' Test'),
            'username' => $username,
            'password_hash' => Hash::make('secret123'),
            'rol_id' => $this->rolId($rolNombre),
            'activo' => $activo,
        ]);

        return User::findOrFail($id);
    }

    /* ==================================================================
     | 1. Listado y control de acceso
     | ================================================================== */

    public function test_admin_puede_ver_el_listado(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');

        $this->actingAs($admin)
            ->get(route('usuarios.index'))
            ->assertStatus(200)
            ->assertSee('Admin Test');
    }

    public function test_roles_no_admin_no_pueden_ver_el_listado(): void
    {
        // El módulo está reservado a ADMIN y GERENTE ahora
        // CAJERO, MESERO y COCINERO son redirigidos a inicio
        foreach (['CAJERO', 'MESERO', 'COCINERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get(route('usuarios.index'))
                ->assertRedirect(route('inicio'));
        }
    }

    public function test_acceso_no_autenticado_redirige_a_login(): void
    {
        $this->get(route('usuarios.index'))->assertRedirect(route('login'));
        $this->post(route('usuarios.api'), ['accion' => 'crear'])->assertRedirect(route('login'));
    }

    /* ==================================================================
     | 2. Búsqueda y filtros (en servidor)
     | ================================================================== */

    public function test_busqueda_por_usuario_login(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $this->usuario('CAJERO', 'jperez', 'Juan Pérez');
        $this->usuario('MESERO', 'mgomez', 'María Gómez');

        $this->actingAs($admin)
            ->get(route('usuarios.index', ['q' => 'jperez']))
            ->assertStatus(200)
            ->assertSee('Juan Pérez')
            ->assertDontSee('María Gómez');
    }

    public function test_busqueda_por_nombre_de_persona(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $this->usuario('CAJERO', 'jperez', 'Juan Pérez');
        $this->usuario('MESERO', 'mgomez', 'María Gómez');

        $this->actingAs($admin)
            ->get(route('usuarios.index', ['q' => 'María']))
            ->assertStatus(200)
            ->assertSee('María Gómez')
            ->assertDontSee('>jperez<');
    }

    public function test_filtro_por_rol(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $this->usuario('CAJERO', 'cajero_uno', 'Cajero Uno');
        $this->usuario('MESERO', 'mesero_uno', 'Mesero Uno');

        $this->actingAs($admin)
            ->get(route('usuarios.index', ['rol' => 'CAJERO']))
            ->assertStatus(200)
            ->assertSee('Cajero Uno')
            ->assertDontSee('Mesero Uno');
    }

    public function test_filtro_por_estado_eliminados_oculta_activos(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $this->usuario('CAJERO', 'activo_uno', 'Activo Uno', 1);
        $this->usuario('CAJERO', 'borrado_uno', 'Borrado Uno', 0);

        $this->actingAs($admin)
            ->get(route('usuarios.index', ['estado' => 'eliminados']))
            ->assertStatus(200)
            ->assertSee('Borrado Uno')
            ->assertDontSee('Activo Uno');
    }

    public function test_por_defecto_se_muestran_las_dos_secciones(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $this->usuario('CAJERO', 'activo_uno', 'Activo Uno', 1);
        $this->usuario('CAJERO', 'borrado_uno', 'Borrado Uno', 0);

        $this->actingAs($admin)
            ->get(route('usuarios.index'))
            ->assertStatus(200)
            // Sección A y Sección B, claramente diferenciadas.
            ->assertSee('Usuarios activos')
            ->assertSee('Usuarios eliminados')
            // El activo y el eliminado aparecen, cada uno en su sección.
            ->assertSee('Activo Uno')
            ->assertSee('Borrado Uno')
            ->assertSee('Restablecer usuario');
    }

    public function test_usuario_eliminado_no_aparece_entre_los_activos(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $this->usuario('CAJERO', 'activo_uno', 'Activo Uno', 1);
        $this->usuario('CAJERO', 'borrado_uno', 'Borrado Uno', 0);

        $this->actingAs($admin)
            ->get(route('usuarios.index', ['estado' => 'activos']))
            ->assertStatus(200)
            ->assertSee('Activo Uno')
            // El eliminado no se mezcla con los activos: no aparece.
            ->assertDontSee('Borrado Uno');
    }

    public function test_seccion_eliminados_muestra_mensaje_cuando_esta_vacia(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $this->usuario('CAJERO', 'activo_uno', 'Activo Uno', 1);

        $this->actingAs($admin)
            ->get(route('usuarios.index', ['estado' => 'eliminados']))
            ->assertStatus(200)
            ->assertSee('Usuarios eliminados')
            ->assertSee('No hay usuarios eliminados.');
    }

    public function test_estado_todos_mezcla_activos_y_eliminados(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $this->usuario('CAJERO', 'activo_uno', 'Activo Uno', 1);
        $this->usuario('CAJERO', 'borrado_uno', 'Borrado Uno', 0);

        $this->actingAs($admin)
            ->get(route('usuarios.index', ['estado' => 'todos']))
            ->assertStatus(200)
            ->assertSee('Activo Uno')
            ->assertSee('Borrado Uno')
            ->assertSee('ELIMINADO');
    }

    public function test_busqueda_y_filtro_combinados(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $this->usuario('CAJERO', 'jperez', 'Juan Pérez');
        $this->usuario('MESERO', 'jmesero', 'Juan Mesero');

        $this->actingAs($admin)
            ->get(route('usuarios.index', ['q' => 'Juan', 'rol' => 'CAJERO']))
            ->assertStatus(200)
            ->assertSee('Juan Pérez')
            ->assertDontSee('Juan Mesero');
    }

    /* ==================================================================
     | 3. Vista: botón cambiar contraseña y estilo de filtros
     | ================================================================== */

    public function test_el_boton_cambiar_contrasena_aparece_en_el_html(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $this->usuario('CAJERO', 'cajero_uno', 'Cajero Uno');

        $this->actingAs($admin)
            ->get(route('usuarios.index'))
            ->assertStatus(200)
            ->assertSee('Cambiar contraseña')
            ->assertSee('btn-reset-pass', false)
            ->assertSee('panel-auto', false)
            ->assertSee('panel-manual', false);
    }

    public function test_el_boton_restablecer_aparece_para_eliminados(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $this->usuario('CAJERO', 'borrado_uno', 'Borrado Uno', 0);

        $this->actingAs($admin)
            ->get(route('usuarios.index', ['estado' => 'eliminados']))
            ->assertStatus(200)
            ->assertSee('Usuarios eliminados')
            ->assertSee('Restablecer usuario')
            ->assertSee('btn-reactivar-usuario', false)
            // El botón lleva el nombre para poder identificar al usuario.
            ->assertSee('data-nombre="Borrado Uno"', false);
    }

    /* ==================================================================
     | 4. Crear usuario
     | ================================================================== */

    public function test_admin_crea_usuario_con_hash_no_texto_plano(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $rolCajero = $this->rolId('CAJERO');

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'crear',
                'nombre' => 'Nuevo Cajero',
                'username' => 'nuevo_cajero',
                'password' => 'claveSegura123',
                'rol_id' => $rolCajero,
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $fila = DB::table('usuarios')->where('username', 'nuevo_cajero')->first();
        $this->assertNotNull($fila);
        // No se guarda la contraseña en texto plano.
        $this->assertNotSame('claveSegura123', $fila->password_hash);
        $this->assertTrue(Hash::check('claveSegura123', $fila->password_hash));
    }

    public function test_crear_usuario_duplicado_es_rechazado(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $this->usuario('CAJERO', 'repetido', 'Repetido');
        $rolMesero = $this->rolId('MESERO');

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'crear',
                'nombre' => 'Otro',
                'username' => 'repetido',
                'password' => 'claveSegura123',
                'rol_id' => $rolMesero,
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => false]);

        $this->assertSame(1, DB::table('usuarios')->where('username', 'repetido')->count());
    }

    public function test_crear_usuario_con_contrasena_corta_es_rechazado(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $rolCajero = $this->rolId('CAJERO');

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'crear',
                'nombre' => 'Corto',
                'username' => 'corto',
                'password' => '123',
                'rol_id' => $rolCajero,
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => false]);

        $this->assertDatabaseMissing('usuarios', ['username' => 'corto']);
    }

    public function test_crear_usuario_con_rol_inexistente_es_rechazado(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'crear',
                'nombre' => 'Fantasma',
                'username' => 'fantasma',
                'password' => 'claveSegura123',
                'rol_id' => 9999,
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => false]);

        $this->assertDatabaseMissing('usuarios', ['username' => 'fantasma']);
    }

    /* ==================================================================
     | 5. Eliminación lógica y reactivación
     | ================================================================== */

    public function test_eliminar_desactiva_sin_borrar_la_fila(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $objetivo = $this->usuario('CAJERO', 'eliminable', 'Eliminable');

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'eliminar',
                'id' => $objetivo->id,
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        // La fila sigue existiendo, solo activo = 0.
        $this->assertDatabaseHas('usuarios', ['id' => $objetivo->id, 'activo' => 0]);
    }

    public function test_admin_no_puede_eliminarse_a_si_mismo(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'eliminar',
                'id' => $admin->id,
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => false]);

        $this->assertDatabaseHas('usuarios', ['id' => $admin->id, 'activo' => 1]);
    }

    public function test_reactivar_recupera_el_registro_sin_duplicar(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $objetivo = $this->usuario('CAJERO', 'reactivable', 'Reactivar Uno', 0);

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'reactivar',
                'id' => $objetivo->id,
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        // Se reactivó el mismo registro...
        $this->assertDatabaseHas('usuarios', ['id' => $objetivo->id, 'activo' => 1]);
        // ...y no se creó un duplicado con el mismo username.
        $this->assertSame(1, DB::table('usuarios')->where('username', 'reactivable')->count());
    }

    public function test_reactivar_usuario_inexistente_falla(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'reactivar',
                'id' => 99999,
            ])
            ->assertStatus(404)
            ->assertJson(['exito' => false]);
    }

    public function test_reactivar_usuario_ya_activo_falla(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $activo = $this->usuario('CAJERO', 'ya_activo', 'Ya Activo', 1);

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'reactivar',
                'id' => $activo->id,
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => false]);
    }

    public function test_eliminado_pasa_a_la_seccion_de_activos_tras_restablecer(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $objetivo = $this->usuario('CAJERO', 'vuelve_uno', 'Vuelve Uno', 0);

        // Antes: aparece en la sección de eliminados...
        $this->actingAs($admin)
            ->get(route('usuarios.index', ['estado' => 'eliminados']))
            ->assertSee('Vuelve Uno');

        // Restablecer el mismo registro.
        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'reactivar',
                'id' => $objetivo->id,
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        // Después: desaparece de eliminados...
        $this->actingAs($admin)
            ->get(route('usuarios.index', ['estado' => 'eliminados']))
            ->assertDontSee('Vuelve Uno');

        // ...y aparece entre los activos.
        $this->actingAs($admin)
            ->get(route('usuarios.index', ['estado' => 'activos']))
            ->assertSee('Vuelve Uno');

        // Mismo registro, sin duplicados.
        $this->assertSame(1, DB::table('usuarios')->where('username', 'vuelve_uno')->count());
    }

    public function test_nombres_con_html_se_muestran_como_texto_sin_ejecutarse(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');

        // Nombre malicioso en un usuario activo y en uno eliminado.
        $this->usuario('CAJERO', 'xss_activo', '<script>alert(1)</script>', 1);
        $this->usuario('CAJERO', 'xss_eliminado', '<img src=x onerror=alert(2)>', 0);

        $respuesta = $this->actingAs($admin)->get(route('usuarios.index', ['estado' => 'todos']));
        $respuesta->assertStatus(200);

        // El HTML crudo NO se inyecta...
        $respuesta->assertDontSee('<script>alert(1)</script>', false);
        $respuesta->assertDontSee('<img src=x onerror=alert(2)>', false);

        // ...se ve escapado, como texto.
        $respuesta->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        $respuesta->assertSee('&lt;img src=x onerror=alert(2)&gt;', false);
    }

    /* ==================================================================
     | 6. Cambio / generación de contraseña
     | ================================================================== */

    public function test_reset_password_manual_guarda_el_hash(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $objetivo = $this->usuario('CAJERO', 'cambia_pass', 'Cambia Pass');

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'reset_password',
                'usuario_id' => $objetivo->id,
                'password' => 'nuevaClave456',
                'password_confirmation' => 'nuevaClave456',
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $fila = DB::table('usuarios')->where('id', $objetivo->id)->first();
        $this->assertNotSame('nuevaClave456', $fila->password_hash);
        $this->assertTrue(Hash::check('nuevaClave456', $fila->password_hash));
    }

    public function test_reset_password_generada_larga_es_aceptada(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $objetivo = $this->usuario('CAJERO', 'gen_pass', 'Gen Pass');

        // Contraseña tipo "generada": 12 caracteres con mayúsculas, números y símbolos.
        $generada = 'Ab3$xY7!qZ9m';

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'reset_password',
                'usuario_id' => $objetivo->id,
                'password' => $generada,
                'password_confirmation' => $generada,
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => true]);

        $this->assertTrue(Hash::check($generada, DB::table('usuarios')->where('id', $objetivo->id)->value('password_hash')));
    }

    public function test_reset_password_no_coincidentes_falla(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $objetivo = $this->usuario('CAJERO', 'no_coincide', 'No Coincide');
        $hashOriginal = DB::table('usuarios')->where('id', $objetivo->id)->value('password_hash');

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'reset_password',
                'usuario_id' => $objetivo->id,
                'password' => 'nuevaClave456',
                'password_confirmation' => 'otraClave789',
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => false]);

        // No se cambió nada si la validación falla.
        $this->assertSame($hashOriginal, DB::table('usuarios')->where('id', $objetivo->id)->value('password_hash'));
    }

    public function test_reset_password_corta_falla(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');
        $objetivo = $this->usuario('CAJERO', 'corta_pass', 'Corta Pass');

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'reset_password',
                'usuario_id' => $objetivo->id,
                'password' => '123',
                'password_confirmation' => '123',
            ])
            ->assertStatus(200)
            ->assertJson(['exito' => false]);
    }

    public function test_reset_password_usuario_inexistente_falla(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');

        $this->actingAs($admin)
            ->post(route('usuarios.api'), [
                'accion' => 'reset_password',
                'usuario_id' => 99999,
                'password' => 'nuevaClave456',
                'password_confirmation' => 'nuevaClave456',
            ])
            ->assertStatus(404)
            ->assertJson(['exito' => false]);
    }

    /* ==================================================================
     | 7. Autorización de los endpoints (manipulación de la petición)
     | ================================================================== */

    public function test_endpoints_rechazan_a_roles_no_admin(): void
    {
        $objetivo = $this->usuario('CAJERO', 'victima', 'Víctima');
        $hashOriginal = DB::table('usuarios')->where('id', $objetivo->id)->value('password_hash');

        foreach (['CAJERO', 'MESERO', 'COCINERO'] as $rol) {
            $actor = $this->usuario($rol);

            // Eliminar
            $this->actingAs($actor)
                ->post(route('usuarios.api'), ['accion' => 'eliminar', 'id' => $objetivo->id])
                ->assertStatus(403)
                ->assertJson(['exito' => false]);

            // Reactivar
            $this->actingAs($actor)
                ->post(route('usuarios.api'), ['accion' => 'reactivar', 'id' => $objetivo->id])
                ->assertStatus(403)
                ->assertJson(['exito' => false]);

            // Crear
            $this->actingAs($actor)
                ->post(route('usuarios.api'), [
                    'accion' => 'crear',
                    'nombre' => 'Intruso',
                    'username' => 'intruso_'.strtolower($rol),
                    'password' => 'claveSegura123',
                    'rol_id' => $this->rolId('CAJERO'),
                ])
                ->assertStatus(403)
                ->assertJson(['exito' => false]);

            // Cambiar contraseña
            $this->actingAs($actor)
                ->post(route('usuarios.api'), [
                    'accion' => 'reset_password',
                    'usuario_id' => $objetivo->id,
                    'password' => 'intruso123',
                    'password_confirmation' => 'intruso123',
                ])
                ->assertStatus(403)
                ->assertJson(['exito' => false]);
        }

        // Nada cambió en la base.
        $this->assertDatabaseHas('usuarios', ['id' => $objetivo->id, 'activo' => 1]);
        $this->assertSame($hashOriginal, DB::table('usuarios')->where('id', $objetivo->id)->value('password_hash'));
        $this->assertDatabaseMissing('usuarios', ['username' => 'intruso_gerente']);
    }

    public function test_accion_invalida_responde_error(): void
    {
        $admin = $this->usuario('ADMIN', 'admin_test', 'Admin Test');

        $this->actingAs($admin)
            ->post(route('usuarios.api'), ['accion' => 'teletransportar'])
            ->assertStatus(200)
            ->assertJson(['exito' => false]);
    }
}
