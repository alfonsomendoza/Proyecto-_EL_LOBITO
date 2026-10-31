<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuthController extends Controller
{
    // Minutos de inactividad antes de liberar automáticamente una sesión.
    private const MINUTOS_INACTIVIDAD = 1;

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

            // SESIÓN ÚNICA: se rechaza el segundo dispositivo.
            // La sesión que ya estaba trabajando permanece intacta.
            if (! $this->tomarSesionUnica($request)) {
                Auth::logout();

                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return back()->withErrors([
                    'usuario' => 'Este usuario ya tiene una sesión abierta en otro equipo. '
                        . 'Ciérrala o pide al administrador que la libere.',
                ]);
            }

            // Guardamos desde ya el hash de la contraseña vigente en la
            // sesión. Así un cambio de contraseña hecho inmediatamente después
            // del login también invalida esta sesión en su primera petición.
            $this->recordarHashDeContrasena($request);

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
        // Liberar exclusivamente la sesión que está cerrando sesión.
        // Nunca borrar el registro de una sesión diferente: el WHERE exige
        // que `session_id` coincida con el de ESTA petición. Así, un
        // dispositivo antiguo no puede liberar la sesión nueva de otro.
        if (Auth::check() && $this->columnasSesionUnicaDisponibles()) {
            DB::table('usuarios')
                ->where('id', Auth::id())
                ->where('session_id', $request->session()->getId())
                ->update([
                    'session_id' => null,
                    'ultima_actividad' => null,
                ]);
        }

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
     * Registra esta sesión como la única autorizada del usuario.
     *
     * Si existe otra sesión que todavía tiene actividad dentro de los últimos
     * 15 minutos, rechaza el nuevo acceso. La sesión original NO se toca.
     *
     * La operación es ATÓMICA: se resuelve con un único UPDATE condicional.
     * MySQL bloquea la fila durante el UPDATE y vuelve a evaluar el WHERE
     * contra el valor ya confirmado. Si dos logins concurrentes del mismo
     * usuario compiten, solo uno encuentra la fila "libre" (session_id NULL,
     * ultima_actividad NULL o vencida); el otro obtiene 0 filas afectadas y es
     * rechazado. No se depende de transacciones abiertas ni de comprobaciones
     * previas separadas (que sí tendrían una condición de carrera).
     */
    private function tomarSesionUnica(Request $request): bool
    {
        // Esquema aún sin migrar (p. ej. pruebas que crean el esquema a mano):
        // no se puede aplicar la política, pero tampoco se rompe el login.
        if (! $this->columnasSesionUnicaDisponibles()) {
            return true;
        }

        $usuarioId = Auth::id();
        $sesionId = $request->session()->getId();
        $limite = now()->subMinutes(self::MINUTOS_INACTIVIDAD);

        $actualizado = DB::table('usuarios')
            ->where('id', $usuarioId)
            ->where('activo', 1)
            ->where(function ($query) use ($limite) {
                $query->whereNull('session_id')
                    ->orWhereNull('ultima_actividad')
                    ->orWhere('ultima_actividad', '<=', $limite);
            })
            ->update([
                'session_id' => $sesionId,
                'ultima_actividad' => now(),
            ]);

        return $actualizado === 1;
    }

    /**
     * ¿La tabla `usuarios` ya tiene las columnas de sesión única?
     *
     * Se comprueba antes de cada consulta para no fallar en instalaciones
     * donde la migración aún no se aplicó (mismo patrón que
     * VerificarRolActivo con `roles.activo`).
     */
    private function columnasSesionUnicaDisponibles(): bool
    {
        return Schema::hasTable('usuarios')
            && Schema::hasColumn('usuarios', 'session_id')
            && Schema::hasColumn('usuarios', 'ultima_actividad');
    }

    /**
     * Guarda en la sesión el HMAC del hash de contraseña con el que se abrió
     * la sesión. AuthenticateSession usa la misma clave y el mismo formato;
     * VerificarSesionUnica la compara en cada petición para detectar cambios
     * de contraseña y liberar el registro de sesión única en la base.
     */
    private function recordarHashDeContrasena(Request $request): void
    {
        $usuario = Auth::user();

        if ($usuario === null) {
            return;
        }

        $hash = (string) $usuario->getAuthPassword();

        try {
            $guard = Auth::guard();

            if (method_exists($guard, 'hashPasswordForCookie')) {
                $hash = (string) $guard->hashPasswordForCookie($hash);
            }
        } catch (\Throwable $e) {
            // Sin guard compatible se guarda el hash tal cual; la comparación
            // de VerificarSesionUnica admite ambos formatos.
        }

        $request->session()->put('password_hash_'.Auth::getDefaultDriver(), $hash);
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