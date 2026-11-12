<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Http\Middleware\VerificarSesionUnica;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Regresión específica de MySQL.
 *
 * MySQL informa filas CAMBIADAS en un UPDATE, no filas ENCONTRADAS. Si
 * `ultima_actividad` se actualiza dentro del mismo segundo, el valor no cambia
 * y MySQL devuelve 0. SQLite devuelve 1 (filas encontradas), así que la suite
 * normal NO reproduce este caso: aquí se simula la respuesta 0 de MySQL con
 * mocks y se comprueba que la sesión autorizada NO se cierre, tanto en la
 * petición posterior al login (middleware) como en el propio login.
 */
class SesionUnicaMysqlTest extends TestCase
{
    private function sesionDePrueba(): Store
    {
        $sesion = new Store('sesion-de-prueba', new ArraySessionHandler(120));
        $sesion->start();

        return $sesion;
    }

    private function simularColumnasDeSesionUnica(): void
    {
        Schema::shouldReceive('hasTable')->with('usuarios')->andReturn(true);
        Schema::shouldReceive('hasColumn')->with('usuarios', 'session_id')->andReturn(true);
        Schema::shouldReceive('hasColumn')->with('usuarios', 'ultima_actividad')->andReturn(true);
    }

    private function middlewareConConsulta(
        int $filasActualizadas,
        bool $sigueSiendoPropia
    ): Response {
        $this->simularColumnasDeSesionUnica();

        $sesion = $this->sesionDePrueba();

        $consulta = Mockery::mock();
        $consulta->shouldReceive('where')->andReturnSelf();
        $consulta->shouldReceive('first')->once()->with(['id', 'activo', 'session_id'])->andReturn(
            (object) ['id' => 1, 'activo' => 1, 'session_id' => $sesion->getId()]
        );
        $consulta->shouldReceive('update')->once()->andReturn($filasActualizadas);
        $consulta->shouldReceive('exists')->once()->andReturn($sigueSiendoPropia);

        DB::shouldReceive('table')->with('usuarios')->andReturn($consulta);

        $request = Request::create('/inicio', 'GET');
        $request->setLaravelSession($sesion);
        $request->setUserResolver(fn () => (object) ['id' => 1]);

        return (new VerificarSesionUnica)->handle(
            $request,
            fn () => new Response('ok')
        );
    }

    /**
     * Ejecuta el método privado que registra la sesión única durante el login.
     * Se simula la respuesta 0 de MySQL y si el `session_id` guardado ya es el
     * de esta misma sesión.
     */
    private function reclamarSesionEnLogin(int $filasActualizadas, bool $yaEsPropia): bool
    {
        $this->simularColumnasDeSesionUnica();

        $sesion = $this->sesionDePrueba();

        $consulta = Mockery::mock();
        $consulta->shouldReceive('where')->andReturnSelf();
        $consulta->shouldReceive('update')->once()->andReturn($filasActualizadas);
        $consulta->shouldReceive('exists')->once()->andReturn($yaEsPropia);

        DB::shouldReceive('table')->with('usuarios')->andReturn($consulta);
        Auth::shouldReceive('id')->andReturn(1);

        $request = Request::create('/login', 'POST');
        $request->setLaravelSession($sesion);

        $controlador = new AuthController;
        $metodo = new ReflectionMethod($controlador, 'tomarSesionUnica');
        $metodo->setAccessible(true);

        return (bool) $metodo->invoke($controlador, $request);
    }

    public function test_cero_filas_actualizadas_no_cierra_la_sesion_si_sigue_siendo_la_autorizada(): void
    {
        // Caso real: el login guardó `ultima_actividad` y la petición siguiente
        // llega en el mismo segundo; MySQL devuelve 0 aunque la sesión es válida.
        $respuesta = $this->middlewareConConsulta(0, true);

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame('ok', $respuesta->getContent());
    }

    public function test_cero_filas_actualizadas_cierra_la_sesion_si_ya_no_es_la_autorizada(): void
    {
        // Otra sesión reclamó el acceso: el UPDATE no encontró la fila y la
        // relectura confirma que el `session_id` ya no es el de esta petición.
        $respuesta = $this->middlewareConConsulta(0, false);

        $this->assertSame(302, $respuesta->getStatusCode());
        $this->assertSame(route('login'), $respuesta->headers->get('Location'));
    }

    public function test_login_acepta_cero_filas_si_la_sesion_ya_es_la_propia(): void
    {
        // Mismo caso de MySQL en el propio login: el registro ya pertenece a
        // esta sesión y no debe rechazarse por un UPDATE que no cambió nada.
        $this->assertTrue($this->reclamarSesionEnLogin(0, true));
    }

    public function test_login_rechaza_cero_filas_si_la_sesion_pertenece_a_otro_equipo(): void
    {
        // El UPDATE no encontró la fila libre y la relectura confirma que el
        // `session_id` pertenece a otra sesión: el segundo equipo se rechaza.
        $this->assertFalse($this->reclamarSesionEnLogin(0, false));
    }
}
