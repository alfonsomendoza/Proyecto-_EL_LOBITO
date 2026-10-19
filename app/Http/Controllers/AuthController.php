<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class AuthController extends Controller
{
    public function showLoginForm()
    {
        return view('auth.login');
    }

    /**
     * Procesar inicio de sesión.
     */
    public function login(Request $request)
    {
        // Validamos los campos del formulario.
        $request->validate([
            'usuario' => 'required',
            'password' => 'required',
        ]);

        /*
         * El formulario utiliza "usuario",
         * pero en la base de datos el campo es "username".
         * Además validamos que la cuenta esté activa (columna "activo").
         */
        if (Auth::attempt([
            'username' => $request->usuario,
            'password' => $request->password,
            'activo' => 1,
        ])) {
            /*
             * El ROL también debe estar ACTIVO: un rol desactivado no concede
             * acceso aunque el usuario siga activo. Se cierra la sesión recién
             * creada para no dejar autenticado a medias y se avisa igual que
             * unas credenciales incorrectas.
             *
             * Si la columna `roles.activo` no existe (migración pendiente) no
             * se bloquea a nadie: el sistema se comporta como antes.
             */
            if ($this->rolDelUsuarioEstaDesactivado()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return back()->withErrors([
                    'usuario' => 'Tu rol está desactivado. Contacta al administrador.',
                ]);
            }

            // Regeneramos la sesión después del login.
            $request->session()->regenerate();

            // Redirigimos al inicio.
            return redirect()->route('inicio');
        }

        // Credenciales incorrectas.
        return back()->withErrors([
            'usuario' => 'Las credenciales proporcionadas no son correctas.',
        ]);
    }

    /**
     * Cerrar sesión.
     */
    public function logout(Request $request)
    {
        // Cerramos la sesión del usuario.
        Auth::logout();

        // Invalidamos la sesión actual.
        $request->session()->invalidate();

        // Generamos un nuevo token CSRF.
        $request->session()->regenerateToken();

        // Regresamos al login.
        return redirect()->route('login');
    }

    /**
     * ¿El rol del usuario recién autenticado está DESACTIVADO?
     *
     * Devuelve false (no bloquea) si la migración de `roles.activo` todavía no
     * está aplicada, para no romper el inicio de sesión en ese escenario.
     */
    private function rolDelUsuarioEstaDesactivado(): bool
    {
        if (! Schema::hasColumn('roles', 'activo')) {
            return false;
        }

        $rol = Auth::user()?->rol;

        return $rol !== null && (int) $rol->activo === 0;
    }
}