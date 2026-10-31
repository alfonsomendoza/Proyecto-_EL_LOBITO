<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Emula el aislamiento entre peticiones que en producción da el proceso
     * HTTP: en las pruebas el store de sesión es un singleton y conserva las
     * claves de la petición anterior. AuthenticateSession guarda
     * `password_hash_web`; cuando un test cambia de usuario con `actingAs`
     * (sin un login real), ese hash pertenece a otro usuario y la sesión se
     * cerraría de forma artificial.
     *
     * Solo se limpia ese hash cuando la sesión NO tiene un login real
     * (`login_web_*`), que es justo el caso de `actingAs`. Un login real
     * conserva su hash para poder probar la invalidación por cambio de
     * contraseña. El resto de datos de sesión (por ejemplo, importaciones)
     * se mantiene intacto.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->limpiarHashDeSesionDeOtraPeticion();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    private function limpiarHashDeSesionDeOtraPeticion(): void
    {
        $store = $this->app->make('session')->driver();
        $atributos = $store->all();

        foreach (array_keys($atributos) as $clave) {
            if (str_starts_with($clave, 'login_web_')) {
                // Hay un login real en esta sesión: el hash debe conservarse
                // para que AuthenticateSession detecte cambios de contraseña.
                return;
            }
        }

        foreach (array_keys($atributos) as $clave) {
            if (str_starts_with($clave, 'password_hash_')) {
                $store->forget($clave);
            }
        }
    }
}
