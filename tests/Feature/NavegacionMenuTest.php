<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

/**
 * Navegación del menú lateral.
 *
 * Regresión: el acceso "Ventas" del sidebar era un ancla muerta (`href="#"`):
 * al pulsarlo no cambiaba la URL ni se abría ninguna página. Debe apuntar al
 * Monitor de mesas (`mesas.index`), que es el punto de entrada real del flujo
 * de venta/POS.
 *
 * La autorización se valida en el backend (MesaController), no en la vista:
 * por eso aquí también se comprueba el acceso directo por URL.
 */
class NavegacionMenuTest extends TestCase
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

    /** Devuelve el href del primer enlace del menú cuyo texto contenga $texto. */
    private function hrefDelMenu(string $html, string $texto): ?string
    {
        preg_match_all('/<a\s+[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/s', $html, $coincidencias, PREG_SET_ORDER);

        foreach ($coincidencias as $enlace) {
            if (str_contains($enlace[2], $texto)) {
                return $enlace[1];
            }
        }

        return null;
    }

    public function test_el_acceso_ventas_del_menu_apunta_al_monitor_de_mesas(): void
    {
        $admin = $this->usuario('ADMIN');

        $html = $this->actingAs($admin)->get('/inicio')->assertStatus(200)->getContent();

        $href = $this->hrefDelMenu($html, 'Ventas');

        $this->assertNotNull($href, 'El menú lateral debe incluir el acceso "Ventas".');
        $this->assertNotSame('#', $href, 'El acceso "Ventas" no debe ser un ancla muerta.');
        $this->assertSame(route('mesas.index'), $href);
    }

    public function test_el_monitor_de_mesas_se_muestra_con_sus_datos(): void
    {
        DB::table('mesas')->insert(['numero' => '7', 'capacidad' => 4, 'estado' => 'LIBRE']);

        $admin = $this->usuario('ADMIN');

        $this->actingAs($admin)
            ->get('/mesas')
            ->assertStatus(200)
            ->assertSee('7');
    }

    public function test_el_monitor_de_mesas_esta_disponible_para_los_roles_operativos(): void
    {
        foreach (['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'] as $rol) {
            $this->actingAs($this->usuario($rol))
                ->get('/mesas')
                ->assertStatus(200);
        }
    }

    public function test_el_monitor_de_mesas_exige_autenticacion(): void
    {
        $this->get('/mesas')->assertRedirect(route('login'));
    }
}
