<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Verifica que los usuarios sin sesión sean enviados al login.
     */
    public function test_unauthenticated_users_are_redirected_to_login(): void
    {
        // Accedemos a la página principal sin iniciar sesión.
        $response = $this->get('/');

        // La ruta está protegida y debe redirigir al formulario de login.
        $response->assertRedirect(route('login'));
    }
}
