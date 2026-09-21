<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
         */
        if (Auth::attempt([
            'username' => $request->usuario,
            'password' => $request->password,
        ])) {
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
}