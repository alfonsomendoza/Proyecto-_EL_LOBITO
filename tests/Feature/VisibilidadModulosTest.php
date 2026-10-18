<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

/**
 * Visibilidad de los accesos a Cocina digital e Inventario.
 *
 * Regla que se verifica aquí: el enlace solo se muestra si
 *  1) el módulo está habilitado en `configuraciones`, Y
 *  2) el rol tiene permiso.
 * Ocultar el enlace NUNCA sustituye la autorización: por eso las mismas
 * pruebas comprueban que el acceso directo por URL sigue devolviendo 403.
 */
class VisibilidadModulosTest extends TestCase
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
            'nombre' => $rolNombre.' Test',
            'username' => strtolower($rolNombre).'_'.uniqid(),
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]);

        return User::findOrFail($usuarioId);
    }

    /** Fija el interruptor de un módulo directamente en la tabla. */
    private function configurar(string $clave, bool $valor): void
    {
        DB::table('configuraciones')->updateOrInsert(
            ['clave' => $clave],
            ['valor' => $valor ? '1' : '0', 'created_at' => now(), 'updated_at' => now()]
        );
    }

    /** Número de accesos visibles a la Cocina en la página de inicio. */
    private function accesosCocina(User $usuario): int
    {
        $html = $this->actingAs($usuario)->get('/inicio')->assertStatus(200)->getContent();

        return substr_count($html, route('cocina.index'));
    }

    /** Número de accesos visibles a Inventario en la página de inicio. */
    private function accesosInventario(User $usuario): int
    {
        $html = $this->actingAs($usuario)->get('/inicio')->assertStatus(200)->getContent();

        return substr_count($html, route('inventario.index'));
    }

    /* ------------------------------------------------------------------
     | 1 y 2. Cocina deshabilitada: no aparece en menú lateral ni en inicio
     * ------------------------------------------------------------------ */

    public function test_cocina_deshabilitada_no_aparece_en_menu_lateral_ni_en_pantalla_inicio(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->configurar('cocina_digital_habilitada', false);

        // Con el módulo apagado NO debe quedar ningún enlace: ni el del
        // sidebar (layouts/app.blade.php) ni el de la tarjeta de inicio.
        $this->assertSame(0, $this->accesosCocina($admin));

        $html = $this->actingAs($admin)->get('/inicio')->assertStatus(200)->getContent();
        $this->assertStringNotContainsString('Monitor de Cocina', $html);
    }

    /* ------------------------------------------------------------------
     | 3. Cocina habilitada: aparece solo para los roles autorizados
     * ------------------------------------------------------------------ */

    public function test_cocina_habilitada_solo_aparece_a_los_roles_autorizados(): void
    {
        $this->configurar('cocina_digital_habilitada', true);

        // Dos accesos: sidebar + tarjeta de inicio.
        foreach (['ADMIN', 'GERENTE', 'COCINERO'] as $rol) {
            $this->assertSame(2, $this->accesosCocina($this->usuario($rol)), "Cocina debe verse para {$rol}.");
        }

        // Roles sin permiso: ni con el módulo habilitado se les muestra.
        foreach (['MESERO', 'CAJERO'] as $rol) {
            $this->assertSame(0, $this->accesosCocina($this->usuario($rol)), "Cocina no debe verse para {$rol}.");
        }
    }

    /* ------------------------------------------------------------------
     | 4. Cocina deshabilitada: el acceso directo por URL sigue bloqueado
     * ------------------------------------------------------------------ */

    public function test_cocina_deshabilitada_bloquea_el_acceso_directo_por_url(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->configurar('cocina_digital_habilitada', false);

        $this->actingAs($admin)->get('/cocina')->assertStatus(403);

        // El panel JSON también debe quedar bloqueado.
        $this->actingAs($admin)->getJson('/cocina/api/panel')->assertStatus(403);
    }

    /* ------------------------------------------------------------------
     | 5 y 6. Inventario deshabilitado: no aparece en menú ni en inicio
     * ------------------------------------------------------------------ */

    public function test_inventario_deshabilitado_no_aparece_en_menu_lateral_ni_en_pantalla_inicio(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->configurar('inventario_habilitado', false);

        $this->assertSame(0, $this->accesosInventario($admin));
    }

    /* ------------------------------------------------------------------
     | 7. Inventario habilitado: aparece para los roles autorizados
     * ------------------------------------------------------------------ */

    public function test_inventario_habilitado_solo_aparece_a_los_roles_autorizados(): void
    {
        $this->configurar('inventario_habilitado', true);

        foreach (['ADMIN', 'GERENTE', 'CAJERO'] as $rol) {
            $this->assertSame(2, $this->accesosInventario($this->usuario($rol)), "Inventario debe verse para {$rol}.");
        }

        foreach (['MESERO', 'COCINERO'] as $rol) {
            $this->assertSame(0, $this->accesosInventario($this->usuario($rol)), "Inventario no debe verse para {$rol}.");
        }
    }

    /* ------------------------------------------------------------------
     | 8. Inventario deshabilitado: el acceso directo por URL sigue bloqueado
     * ------------------------------------------------------------------ */

    public function test_inventario_deshabilitado_bloquea_el_acceso_directo_por_url(): void
    {
        $gerente = $this->usuario('GERENTE');
        $this->configurar('inventario_habilitado', false);

        $this->actingAs($gerente)->get('/inventario')->assertStatus(403);
        $this->actingAs($gerente)->get('/inventario/historial')->assertStatus(403);
    }

    /* ------------------------------------------------------------------
     | 9. Apagar un módulo no oculta el otro
     * ------------------------------------------------------------------ */

    public function test_deshabilitar_un_modulo_no_oculta_el_otro(): void
    {
        $admin = $this->usuario('ADMIN');

        // Solo apagamos la cocina: inventario sigue habilitado por defecto.
        $this->configurar('cocina_digital_habilitada', false);
        $this->configurar('inventario_habilitado', true);

        $this->assertSame(0, $this->accesosCocina($admin));
        $this->assertSame(2, $this->accesosInventario($admin));

        // Y al revés: solo apagamos inventario, la cocina vuelve a mostrarse.
        $this->configurar('cocina_digital_habilitada', true);
        $this->configurar('inventario_habilitado', false);

        $this->assertSame(2, $this->accesosCocina($admin));
        $this->assertSame(0, $this->accesosInventario($admin));
    }

    /* ------------------------------------------------------------------
     | 10. Roles sin permiso no acceden aunque el módulo esté habilitado
     * ------------------------------------------------------------------ */

    public function test_los_roles_sin_permiso_siguen_sin_acceso_con_el_modulo_habilitado(): void
    {
        $this->configurar('cocina_digital_habilitada', true);
        $this->configurar('inventario_habilitado', true);

        $mesero = $this->usuario('MESERO');
        $cajero = $this->usuario('CAJERO');

        // Cocina: reservada a ADMIN/GERENTE/COCINERO.
        $this->actingAs($mesero)->get('/cocina')->assertStatus(403);
        $this->actingAs($cajero)->get('/cocina')->assertStatus(403);

        // Inventario: reservado a ADMIN/GERENTE/CAJERO.
        $this->actingAs($mesero)->get('/inventario')->assertStatus(403);
        $this->actingAs($cajero)->get('/inventario')->assertStatus(200);

        // Y la configuración sigue siendo exclusiva de ADMIN.
        $this->actingAs($mesero)->get('/configuracion')->assertStatus(403);
    }

    /* ------------------------------------------------------------------
     | Comprobación adicional: el interruptor de Configuración es lo que
     | manda (mismo camino que usaría un ADMIN en la interfaz).
     * ------------------------------------------------------------------ */

    public function test_el_interruptor_de_configuracion_controla_la_visibilidad(): void
    {
        $admin = $this->usuario('ADMIN');

        // Se parte de la cocina deshabilitada (su valor por defecto).
        $this->assertSame(0, $this->accesosCocina($admin));
        $this->actingAs($admin)->get('/cocina')->assertStatus(403);

        // ADMIN la habilita desde Configuración.
        $this->actingAs($admin)
            ->postJson('/configuracion/api', ['modulo' => 'cocina', 'habilitado' => true])
            ->assertStatus(200)
            ->assertJson(['exito' => true, 'habilitado' => true]);

        // Sin recargar nada más: el enlace aparece y la ruta responde.
        $this->assertSame(2, $this->accesosCocina($admin));
        $this->actingAs($admin)->get('/cocina')->assertStatus(200);

        // Y al apagarla de nuevo desaparece y vuelve a bloquearse.
        $this->actingAs($admin)
            ->postJson('/configuracion/api', ['modulo' => 'cocina', 'habilitado' => false])
            ->assertStatus(200);

        $this->assertSame(0, $this->accesosCocina($admin));
        $this->actingAs($admin)->get('/cocina')->assertStatus(403);
    }
}
