<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
 * CAMBIO DE ROL DE USUARIO (SOLO ADMIN)
 * =====================================
 *
 * El rol define toda la autorización del sistema. Estas pruebas garantizan:
 *
 *  - Solo ADMIN cambia roles; GERENTE, CAJERO, MESERO y COCINERO reciben 403.
 *  - El control «Cambiar rol» solo se muestra a ADMIN en el listado.
 *  - El cambio se limita a `usuarios.rol_id`: no toca nombre, username,
 *    contraseña ni estado (activo).
 *  - Se valida que el usuario y el rol existan y que el rol esté en el
 *    catálogo oficial (ADMIN, GERENTE, CAJERO, MESERO, COCINERO).
 *  - No se puede dejar el sistema sin ningún ADMIN activo.
 *  - El cambio queda registrado en la auditoría existente.
 *
 * IMPORTANTE: corren en SQLite :memory: (phpunit.xml); nunca tocan MySQL.
 */
class CambioRolUsuarioTest extends TestCase
{
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
        foreach (['auditoria_seguridad', 'usuarios', 'roles'] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::create('roles', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 30)->unique();
            // La migración real agregó `activo`; se replica para probar que un
            // rol desactivado no se puede asignar.
            $t->boolean('activo')->default(1);
        });

        Schema::create('usuarios', function (Blueprint $t) {
            $t->increments('id');
            $t->string('nombre', 100);
            $t->string('username', 50)->unique();
            $t->string('password_hash', 255);
            $t->integer('rol_id');
            $t->boolean('activo')->default(1);
        });

        Schema::create('auditoria_seguridad', function (Blueprint $t) {
            $t->bigInteger('id')->autoIncrement();
            $t->integer('usuario_id')->nullable();
            $t->string('evento', 100);
            $t->string('ip', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->text('motivo')->nullable();
            $t->text('detalles')->nullable();
            $t->timestamp('creado_en')->nullable();
        });
    }

    /* ------------------------------------------------------------------
     | Utilidades
     * ------------------------------------------------------------------ */

    private function rolId(string $nombre, int $activo = 1): int
    {
        $existente = DB::table('roles')->where('nombre', $nombre)->first();

        if ($existente) {
            return (int) $existente->id;
        }

        return (int) DB::table('roles')->insertGetId([
            'nombre' => $nombre,
            'activo' => $activo,
        ]);
    }

    private function usuario(string $rolNombre, array $extra = []): User
    {
        $id = DB::table('usuarios')->insertGetId(array_merge([
            'nombre' => $rolNombre.' Test',
            'username' => strtolower($rolNombre).'_'.uniqid(),
            'password_hash' => Hash::make('secret123'),
            'rol_id' => $this->rolId($rolNombre),
            'activo' => 1,
        ], $extra));

        return User::findOrFail($id);
    }

    private function cambiarRol(User $actor, int $usuarioId, int $rolId)
    {
        return $this->actingAs($actor)->postJson(route('usuarios.api'), [
            'accion' => 'cambiar_rol',
            'usuario_id' => $usuarioId,
            'rol_id' => $rolId,
        ]);
    }

    /* ------------------------------------------------------------------
     | ADMIN puede cambiar el rol (y solo el rol)
     * ------------------------------------------------------------------ */

    public function test_admin_puede_cambiar_rol_y_solo_se_modifica_el_rol(): void
    {
        $admin = $this->usuario('ADMIN');
        $objetivo = $this->usuario('CAJERO', ['nombre' => 'Cajero Original']);

        $datosAntes = (array) DB::table('usuarios')->find($objetivo->id);

        $respuesta = $this->cambiarRol($admin, $objetivo->id, $this->rolId('MESERO'));

        $respuesta->assertStatus(200)
            ->assertJsonPath('exito', true);

        $datosDespues = (array) DB::table('usuarios')->find($objetivo->id);

        $this->assertSame($this->rolId('MESERO'), (int) $datosDespues['rol_id']);

        // Ningún otro dato del usuario cambia (ni contraseña, ni estado, etc.).
        foreach (['nombre', 'username', 'password_hash', 'activo'] as $campo) {
            $this->assertSame(
                $datosAntes[$campo],
                $datosDespues[$campo],
                "El campo {$campo} no debe cambiar al cambiar de rol."
            );
        }

        // Queda registro en la auditoría existente.
        $auditoria = DB::table('auditoria_seguridad')
            ->where('evento', 'cambio_rol_usuario')
            ->where('usuario_id', $admin->id)
            ->first();

        $this->assertNotNull($auditoria, 'El cambio de rol debe auditarse.');
    }

    public function test_admin_puede_cambiarse_el_rol_a_si_mismo_si_queda_otro_admin(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->usuario('ADMIN'); // segundo ADMIN activo

        $this->cambiarRol($admin, $admin->id, $this->rolId('GERENTE'))
            ->assertStatus(200)
            ->assertJsonPath('exito', true);

        $this->assertSame($this->rolId('GERENTE'), (int) DB::table('usuarios')->find($admin->id)->rol_id);
    }

    /* ------------------------------------------------------------------
     | Solo ADMIN
     * ------------------------------------------------------------------ */

    public function test_ningun_otro_rol_puede_cambiar_roles(): void
    {
        $objetivo = $this->usuario('CAJERO');
        $rolMesero = $this->rolId('MESERO');

        foreach (['GERENTE', 'CAJERO', 'MESERO', 'COCINERO'] as $rolActor) {
            $actor = $this->usuario($rolActor);

            $this->cambiarRol($actor, $objetivo->id, $rolMesero)
                ->assertStatus(403)
                ->assertJsonPath('exito', false);

            $this->assertNotSame(
                $rolMesero,
                (int) DB::table('usuarios')->find($objetivo->id)->rol_id,
                "El rol {$rolActor} no debe poder cambiar roles."
            );
        }
    }

    public function test_el_listado_muestra_cambiar_rol_solo_al_admin(): void
    {
        $admin = $this->usuario('ADMIN');
        $gerente = $this->usuario('GERENTE');
        $this->usuario('CAJERO');

        // El botón real lleva `data-rol-id="<id>"`; el JS del modal se incluye
        // para todos, así que se comprueba el atributo renderizado, no el texto.
        $respuestaAdmin = $this->actingAs($admin)->get(route('usuarios.index'));
        $respuestaAdmin->assertStatus(200);
        $this->assertMatchesRegularExpression('/data-rol-id="[0-9]+"/', $respuestaAdmin->getContent());

        $respuestaGerente = $this->actingAs($gerente)->get(route('usuarios.index'));
        $respuestaGerente->assertStatus(200);
        $this->assertDoesNotMatchRegularExpression('/data-rol-id="[0-9]+"/', $respuestaGerente->getContent());
    }

    /* ------------------------------------------------------------------
     | Validaciones de usuario y rol
     * ------------------------------------------------------------------ */

    public function test_rechaza_usuario_inexistente(): void
    {
        $admin = $this->usuario('ADMIN');

        $this->cambiarRol($admin, 999999, $this->rolId('MESERO'))
            ->assertStatus(404)
            ->assertJsonPath('exito', false);
    }

    public function test_rechaza_rol_inexistente(): void
    {
        $admin = $this->usuario('ADMIN');
        $objetivo = $this->usuario('CAJERO');

        $this->cambiarRol($admin, $objetivo->id, 999999)
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertSame($this->rolId('CAJERO'), (int) DB::table('usuarios')->find($objetivo->id)->rol_id);
    }

    public function test_rechaza_rol_fuera_del_catalogo_oficial(): void
    {
        $admin = $this->usuario('ADMIN');
        $objetivo = $this->usuario('CAJERO');
        $rolInventado = $this->rolId('CONTADOR');

        $this->cambiarRol($admin, $objetivo->id, $rolInventado)
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertSame($this->rolId('CAJERO'), (int) DB::table('usuarios')->find($objetivo->id)->rol_id);
    }

    public function test_rechaza_rol_desactivado(): void
    {
        $admin = $this->usuario('ADMIN');
        $objetivo = $this->usuario('CAJERO');
        $rolInactivo = $this->rolId('MESERO', 0);

        DB::table('roles')->where('id', $rolInactivo)->update(['activo' => 0]);

        $this->cambiarRol($admin, $objetivo->id, $rolInactivo)
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertSame($this->rolId('CAJERO'), (int) DB::table('usuarios')->find($objetivo->id)->rol_id);
    }

    public function test_rechaza_ids_invalidos(): void
    {
        $admin = $this->usuario('ADMIN');

        $this->cambiarRol($admin, 0, 0)
            ->assertStatus(422)
            ->assertJsonPath('exito', false);
    }

    /* ------------------------------------------------------------------
     | Protección del último ADMIN activo
     * ------------------------------------------------------------------ */

    public function test_no_se_puede_quitar_el_rol_al_ultimo_admin_activo(): void
    {
        $admin = $this->usuario('ADMIN');

        // Solo hay un ADMIN activo: quitarle el rol dejaría el sistema sin
        // administración. Debe rechazarse y no cambiar nada.
        $this->cambiarRol($admin, $admin->id, $this->rolId('GERENTE'))
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertSame($this->rolId('ADMIN'), (int) DB::table('usuarios')->find($admin->id)->rol_id);
    }

    public function test_un_admin_inactivo_no_cuenta_para_la_proteccion_del_ultimo_admin(): void
    {
        $adminActivo = $this->usuario('ADMIN');
        $this->usuario('ADMIN', ['activo' => 0]);

        // El ADMIN inactivo no opera, así que el activo sigue siendo el último.
        $this->cambiarRol($adminActivo, $adminActivo->id, $this->rolId('GERENTE'))
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertSame($this->rolId('ADMIN'), (int) DB::table('usuarios')->find($adminActivo->id)->rol_id);
    }
}
