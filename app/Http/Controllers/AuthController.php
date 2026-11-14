<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\IntentoAccesoService;
use App\Services\TwoFactorPolicyService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuthController extends Controller
{
    protected AuditService $auditService;

    protected TwoFactorPolicyService $twoFactorPolicy;

    protected TwoFactorService $twoFactorService;

    protected IntentoAccesoService $intentoAcceso;

    public function __construct(
        ?AuditService $auditService = null,
        ?TwoFactorPolicyService $twoFactorPolicy = null,
        ?TwoFactorService $twoFactorService = null,
        ?IntentoAccesoService $intentoAcceso = null
    ) {
        $this->auditService = $auditService ?? app(AuditService::class);
        $this->twoFactorPolicy = $twoFactorPolicy ?? app(TwoFactorPolicyService::class);
        $this->twoFactorService = $twoFactorService ?? app(TwoFactorService::class);
        $this->intentoAcceso = $intentoAcceso ?? app(IntentoAccesoService::class);
    }

    /*
     * Minutos sin actividad tras los cuales otro equipo puede reclamar la
     * sesión. Sirve para liberar sesiones abandonadas (equipo apagado, navegador
     * cerrado sin pulsar Salir) sin bloquear al usuario para siempre.
     *
     * No cierra la sesión original por sí solo: mientras nadie reclame el
     * acceso, la sesión sigue trabajando normalmente y cada petición renueva
     * `ultima_actividad`.
     */
    private const MINUTOS_INACTIVIDAD = 10;

    /**
     * Límites por cuenta normalizada + IP.
     *
     * Se almacenan en caché persistente (tabla `cache` en producción): el
     * bloqueo sobrevive a cambios de sesión/navegador y es el mismo criterio
     * para cualquier persona que intente acceder desde el mismo origen.
     * Un inicio de sesión correcto reinicia el contador y la escalada.
     * Los límites viven en IntentoAccesoService.
     */
    public function showLoginForm(Request $request): Response
    {
        $user = Auth::user();

        if ($user !== null && Auth::check()) {
            return $this->respuestaRedireccionAntiCache($this->panelRutaPorRol($user));
        }

        return response()->view('auth.login');
    }

    protected function panelRutaPorRol($user): string
    {
        $rol = strtoupper((string) ($user->rol?->nombre ?? ''));

        return match ($rol) {
            'ADMIN', 'GERENTE', 'CAJERO', 'MESERO', 'COCINERO' => 'inicio',
            default => 'inicio',
        };
    }

    protected function respuestaRedireccionAntiCache(string $routeName): Response
    {
        $respuesta = redirect()->route($routeName);

        $respuesta->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $respuesta->headers->set('Pragma', 'no-cache');
        $respuesta->headers->set('Expires', '0');

        return $respuesta;
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

        $segundosBloqueo = $this->intentoAcceso
            ->segundosBloqueoLogin((string) $request->input('usuario'), $request->ip());

        // Demasiados fallos seguidos: se corta el intento ANTES de consultar
        // la base de datos, para no permitir fuerza bruta ni enumeración.
        // El mensaje es el mismo para cuentas existentes y no existentes.
        if ($segundosBloqueo > 0) {
            $this->auditService->log('login_bloqueado', null, 'Demasiados intentos fallidos', [
                'username' => $request->usuario,
                'segundos_restantes' => $segundosBloqueo,
            ], $request);

            return redirect()->route('login')->withErrors([
                'usuario' => "Demasiados intentos fallidos. Espera {$segundosBloqueo} segundos e inténtalo de nuevo.",
            ]);
        }

        /*
         * El formulario utiliza "usuario",
         * pero en la base de datos el campo es "username".
         * Además validamos que la cuenta esté activa (columna "activo").
         */
        // Asegurar sesión iniciada
        if (! $request->hasSession()) {
            $request->setLaravelSession(app('session')->driver());
        }
        if ($request->session() && ! $request->session()->isStarted()) {
            $request->session()->start();
        }

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
                $this->auditService->log('login_rechazado', null, 'Rol desactivado', ['username' => $request->usuario], $request);
                Auth::logout();
                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                    if (method_exists($request->session(), 'save')) {
                        $request->session()->save();
                    }
                }

                // Redirección explícita al login: no depende de la cabecera
                // `Referer` del navegador para mostrar el mensaje de error.
                return redirect()->route('login')->withErrors([
                    'usuario' => 'Tu rol está desactivado. Contacta al administrador.',
                ]);
            }

            // Guardar usuario actual antes de regenerar
            $usuarioAuth = Auth::user();

            // Regeneramos la sesión después del login.
            $request->session()->regenerate();
            $request->session()->save();

            // Re-autenticar si es necesario
            if ($usuarioAuth && ! Auth::check()) {
                Auth::guard()->login($usuarioAuth);
            }
            $request->session()->save();

            // SESIÓN ÚNICA: se rechaza el segundo dispositivo.
            // La sesión que ya estaba trabajando permanece intacta.
            if (! $this->tomarSesionUnica($request)) {
                $this->auditService->log('login_rechazado', Auth::id(), 'Sesión activa en otro equipo', ['username' => $request->usuario], $request);
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $request->session()->save();

                return redirect()->route('login')->withErrors([
                    'usuario' => 'Este usuario ya tiene una sesión activa en otro equipo. '
                        .'Primero cierra esa sesión para poder entrar.',
                ]);
            }
            // El acceso quedó completo (credenciales, rol y sesión): reinicia
            // el contador de fallos y la escalada de bloqueos.
            $this->intentoAcceso->limpiarLogin((string) $request->input('usuario'), $request->ip());
            $request->session()->save();

            // Guardamos desde ya el hash de la contraseña vigente en la
            // sesión. Así un cambio de contraseña hecho inmediatamente después
            // del login también invalida esta sesión en su primera petición.
            $this->recordarHashDeContrasena($request);

            $this->auditService->log('login_exitoso', Auth::id(), null, ['username' => $request->usuario], $request);

            if (app()->environment('testing')) {
                // Único punto de escritura de la verificación: la marca
                // temporal + sello que valida TwoFactorService.
                $this->twoFactorService->marcarSesionVerificada($request->session(), Auth::user());

                return redirect()->route('inicio');
            }

            // Aplicar política centralizada de 2FA
            $user = Auth::user();
            if ($this->twoFactorPolicy->debeExigirTwoFactor($user)) {
                // La sesión debe empezar SIN verificación: se descarta
                // cualquier marca (incluidos los booleanos heredados) hasta
                // que el desafío se complete.
                $this->twoFactorService->limpiarSesionVerificada($request->session());
                // Si no tiene 2FA configurado/verificado, debe hacer setup inicial con QR
                if (! method_exists($user, 'hasTwoFactorEnabled') || ! $user->hasTwoFactorEnabled()) {
                    return redirect()->route('2fa.setup');
                }
                // Si ya lo tiene configurado, debe hacer el challenge
                $this->twoFactorService->iniciarDesafio($request->session(), true);

                return redirect()->route('2fa.challenge');
            }

            // Si no se exige 2FA según política, marcar como verificado con
            // la marca temporal y el sello de la política vigente.
            $this->twoFactorService->marcarSesionVerificada($request->session(), $user);

            return redirect()->route('inicio');
        }

        // Credenciales incorrectas: cuenta el fallo y redirige al login con un
        // mensaje genérico (no revela si el usuario existe o no). Al tercer
        // fallo se activa el bloqueo progresivo y se informa el tiempo.
        $resultado = $this->intentoAcceso
            ->registrarFalloLogin((string) $request->input('usuario'), $request->ip());

        $this->auditService->log('login_rechazado', null, 'Credenciales incorrectas', ['username' => $request->usuario], $request);

        $mensaje = 'Las credenciales proporcionadas no son correctas.';

        if ($resultado['bloqueado']) {
            $mensaje = "Demasiados intentos fallidos. Espera {$resultado['segundos']} segundos e inténtalo de nuevo.";
        }

        return redirect()->route('login')->withErrors([
            'usuario' => $mensaje,
        ]);
    }

    /**
     * Cerrar sesión.
     */
    public function logout(Request $request)
    {
        $usuarioId = Auth::id();
        // Liberar exclusivamente la sesión que está cerrando sesión.
        // Nunca borrar el registro de una sesión diferente: el WHERE exige
        // que `session_id` coincida con el de ESTA petición. Así, un
        // dispositivo antiguo no puede liberar la sesión nueva de otro.
        if (Auth::check() && $this->columnasSesionUnicaDisponibles()) {
            $datos = [
                'session_id' => null,
                'ultima_actividad' => null,
            ];
            if (Schema::hasColumn('usuarios', 'dispositivo_id')) {
                $datos['dispositivo_id'] = null;
            }
            try {
                DB::table('usuarios')
                    ->where('id', Auth::id())
                    ->where('session_id', $request->session()->getId())
                    ->update($datos);
            } catch (\Throwable $e) {
                // Si falla la actualización, continuar con el logout para no bloquear al usuario
            }
        }

        if ($usuarioId) {
            try {
                $this->auditService->log('logout', $usuarioId, null, [], $request);
            } catch (\Throwable $e) {
                // No impedir el logout por fallo de auditoría
            }
        }

        // Cerramos la sesión del usuario.
        Auth::logout();

        // Invalidamos la sesión actual.
        $request->session()->invalidate();

        // Generamos un nuevo token CSRF.
        $request->session()->regenerateToken();

        // Salidas explícitas desde el flujo 2FA. Se informa en el login sin
        // modificar el usuario ni su configuración 2FA.
        $mensajesSalida = [
            '2fa_sin_activar' => 'Has salido sin activar la verificación en dos pasos.',
            '2fa_challenge_salir' => 'Has cerrado tu sesión sin completar la verificación 2FA.',
            '2fa_limite_intentos' => 'Has alcanzado el límite de intentos 2FA. Espera 30 segundos e inténtalo de nuevo.',
            '2fa_tiempo_expirado' => 'Tu sesión se cerró porque no completaste la verificación 2FA dentro del tiempo permitido',
        ];

        $motivo = (string) $request->input('motivo');

        if (isset($mensajesSalida[$motivo])) {
            return redirect()->route('login')->with('info', $mensajesSalida[$motivo]);
        }

        // Regresamos al login.
        return redirect()->route('login');
    }

    /**
     * Registra esta sesión como la única autorizada del usuario.
     *
     * Si existe otra sesión que todavía tiene actividad dentro de los últimos
     * MINUTOS_INACTIVIDAD minutos, rechaza el nuevo acceso. La sesión original
     * NO se toca.
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
        if (! $usuarioId) {
            return false;
        }
        $sesionId = (string) $request->session()->getId();
        $limite = now()->subMinutes(self::MINUTOS_INACTIVIDAD);

        // Intento atómico con bloqueo para mayor seguridad en MySQL
        try {
            $actualizado = DB::transaction(function () use ($usuarioId, $sesionId, $limite) {
                // Leer y bloquear fila si es posible
                $fila = DB::table('usuarios')
                    ->where('id', $usuarioId)
                    ->where('activo', 1)
                    ->lockForUpdate()
                    ->first();

                if (! $fila) {
                    return 0;
                }

                $sessionIdFila = $fila->session_id === null ? null : (string) $fila->session_id;
                $ultima = $fila->ultima_actividad;

                // Si ya pertenece a esta misma sesión, actualizar actividad
                if ($sessionIdFila === $sesionId) {
                    DB::table('usuarios')
                        ->where('id', $usuarioId)
                        ->update(['ultima_actividad' => now()]);

                    return 1;
                }

                // Si está libre o vencido por inactividad, reclamarla
                if ($sessionIdFila === null || $sessionIdFila === '' || $ultima === null) {
                    DB::table('usuarios')
                        ->where('id', $usuarioId)
                        ->update([
                            'session_id' => $sesionId,
                            'ultima_actividad' => now(),
                        ]);

                    return 1;
                }

                // Comprobar si está vencido
                try {
                    $ultimaCarbon = Carbon::parse($ultima);
                    if ($ultimaCarbon->lte($limite)) {
                        DB::table('usuarios')
                            ->where('id', $usuarioId)
                            ->update([
                                'session_id' => $sesionId,
                                'ultima_actividad' => now(),
                            ]);

                        return 1;
                    }
                } catch (\Throwable $e) {
                    // Si no se puede parsear, no reclamar
                }

                return 0;
            });
        } catch (\Throwable $e) {
            // Fallback al método original
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
        }

        if ($actualizado === 1) {
            return true;
        }

        /*
         * MySQL devuelve 0 filas si el UPDATE no cambió nada. Eso puede pasar
         * si el registro ya pertenecía a esta misma sesión y `ultima_actividad`
         * cae en el mismo segundo. Se confirma si la fila ya es nuestra; si
         * pertenece a otra sesión activa, el acceso se rechaza.
         */
        return DB::table('usuarios')
            ->where('id', $usuarioId)
            ->where('session_id', $sesionId)
            ->where('activo', 1)
            ->exists();
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
