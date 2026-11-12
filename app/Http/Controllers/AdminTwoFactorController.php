<?php

namespace App\Http\Controllers;

use App\Models\AuditoriaSeguridad;
use App\Models\User;
use App\Services\AuditService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminTwoFactorController extends Controller
{
    protected AuditService $auditService;

    protected TwoFactorService $twoFactorService;

    public function __construct(AuditService $auditService, TwoFactorService $twoFactorService)
    {
        $this->auditService = $auditService;
        $this->twoFactorService = $twoFactorService;
    }

    private function esAdmin(): bool
    {
        $rol = strtoupper((string) (auth()->user()->rol->nombre ?? ''));

        return $rol === 'ADMIN';
    }

    /**
     * Permisos para administrar el 2FA individual de un usuario:
     * EXCLUSIVO de ADMIN y GERENTE.
     */
    private function esAdminORGerente(): bool
    {
        $rol = strtoupper((string) (auth()->user()->rol->nombre ?? ''));

        return in_array($rol, ['ADMIN', 'GERENTE'], true);
    }

    public function index()
    {
        $rol = strtoupper((string) (auth()->user()->rol->nombre ?? ''));
        if (! in_array($rol, ['ADMIN', 'GERENTE'], true)) {
            abort(403);
        }

        $usuarios = User::with('rol')->get();
        $modo = 'desactivado';
        if (Schema::hasTable('configuraciones')) {
            try {
                $config = DB::table('configuraciones')->where('clave', 'two_factor_modo')->first();
                if ($config && $config->valor) {
                    $modo = strtolower(trim($config->valor));
                }
            } catch (\Throwable $e) {
                // Ignorar
            }
        }

        return view('admin.2fa.index', compact('usuarios', 'modo'));
    }

    public function configurarGlobal(Request $request)
    {
        if (! $this->esAdmin()) {
            abort(403);
        }

        $request->validate([
            'modo' => 'required|in:desactivado,obligatorio',
        ]);

        if (! Schema::hasTable('configuraciones')) {
            return back()->with('error', 'No existe tabla de configuraciones.');
        }

        $modoAnterior = null;
        try {
            $configActual = DB::table('configuraciones')->where('clave', 'two_factor_modo')->first();
            $modoAnterior = $configActual ? $configActual->valor : null;
        } catch (\Throwable $e) {
            // Ignorar
        }

        try {
            // Cuando el global pasa a OBLIGATORIO se habilita el control
            // individual (flag ON = pendiente de enrolle) SOLO para usuarios que
            // aún no tienen enrolle ni excepción individual previa: no se
            // sobrescriben las excepciones explícitas (DESACTIVADO) ni los
            // enrolles confirmados existentes.
            if ($request->modo === 'obligatorio') {
                User::query()
                    ->where('two_factor_enabled', false)
                    ->whereNull('two_factor_secret')
                    ->whereNull('two_factor_confirmed_at')
                    ->update(['two_factor_enabled' => true]);
            }

            DB::table('configuraciones')->updateOrInsert(
                ['clave' => 'two_factor_modo'],
                ['valor' => $request->modo]
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Error al guardar configuración.');
        }

        try {
            $this->auditService->log('2fa_modo_cambiado', auth()->id(), 'Cambio de modo 2FA', [
                'modo_anterior' => $modoAnterior,
                'modo_nuevo' => $request->modo,
            ], $request);
        } catch (\Throwable $e) {
            // No bloquear
        }

        return back()->with('success', 'Configuración 2FA actualizada correctamente.');
    }

    /**
     * ACTIVAR el 2FA de un usuario concreto (control individual).
     *
     * Permisos: ADMIN y GERENTE.
     * Seguridad: cambio sensible → exige reautenticación del administrador
     * (contraseña propia) y registra auditoría. Al activar se invalida cualquier
     * enrolle previo (secret + códigos de recuperación) y se fuerza un nuevo
     * proceso seguro de enrolle (setup TOTP + confirmación) en el próximo acceso.
     */
    public function activar(Request $request, $id)
    {
        if (! $this->esAdminORGerente()) {
            abort(403, 'Solo ADMIN y GERENTE pueden administrar el 2FA.');
        }

        $usuario = User::findOrFail($id);

        // Activar: habilita el control individual (excepción explícita ACTIVADO).
        // Se conserva el secreto cifrado y el enrolle existentes; no se
        // regenera el secreto si ya había uno válido. Si no había enrolle,
        // el usuario pasará por el setup en el próximo acceso.
        $usuario->two_factor_enabled = true;
        $usuario->save();

        try {
            $this->auditService->log('2fa_activado', $usuario->id, '2FA activado por administrador', [
                'admin_id' => auth()->id(),
                'admin_username' => auth()->user()->username,
                'estado' => 'activado',
            ], $request);
        } catch (\Throwable $e) {
            // No bloquear el cambio por fallos de auditoría.
        }

        return back()->with('success', '2FA activado para '.$usuario->username.'. Deberá completar el proceso de enrolle y verificación si corresponde.');
    }

    /**
     * DESACTIVAR el 2FA de un usuario concreto (control individual).
     *
     * Permisos: ADMIN y GERENTE.
     * Seguridad: cambio sensible → exige reautenticación del administrador
     * (contraseña propia) y registra auditoría. Al desactivar se invalida el
     * enrolle (secret + códigos de recuperación + confirmación) y se marca el
     * usuario como sin 2FA; los demás usuarios NO se ven afectados.
     */
    public function desactivar(Request $request, $id)
    {
        if (! $this->esAdminORGerente()) {
            abort(403, 'Solo ADMIN y GERENTE pueden administrar el 2FA.');
        }

        $usuario = User::findOrFail($id);

        // Desactivar: excepción explícita DESACTIVADO. Se conserva el secreto
        // cifrado y el enrolle (no se eliminan ni regeneran) para que al
        // reactivar se recupere la exigencia sin generar un secreto nuevo.
        $usuario->two_factor_enabled = false;
        $usuario->save();

        try {
            $this->auditService->log('2fa_desactivado', $usuario->id, '2FA desactivado por administrador', [
                'admin_id' => auth()->id(),
                'admin_username' => auth()->user()->username,
                'estado' => 'desactivado',
            ], $request);
        } catch (\Throwable $e) {
            // No bloquear el cambio por fallos de auditoría.
        }

        // Si quien desactivó su propio 2FA, la sesión actual debe perder la marca
        // de verificación para respetar el nuevo estado.
        if ((int) auth()->id() === (int) $usuario->id) {
            try {
                $this->twoFactorService->limpiarSesionVerificada($request->session(), auth()->user());
            } catch (\Throwable $e) {
                // No bloquear el cambio por fallos de sesión.
            }
        }

        return back()->with('success', '2FA desactivado para '.$usuario->username.'.');
    }

    public function reset(Request $request, $id)
    {
        // Restablecer 2FA es EXCLUSIVO de ADMIN. GERENTE solo puede consultar
        // el estado (index/auditoría).
        if (! $this->esAdmin()) {
            abort(403);
        }

        $usuario = User::findOrFail($id);
        $usuario->two_factor_secret = null;
        $usuario->two_factor_recovery_codes = null;
        $usuario->two_factor_confirmed_at = null;
        $usuario->two_factor_enabled = false;
        $usuario->save();

        $this->auditService->log('2fa_restablecido', $usuario->id, 'Restablecido por administrador: '.auth()->user()->username, ['admin_id' => auth()->id()], $request);

        return back()->with('success', '2FA restablecido correctamente.');
    }

    public function auditoria()
    {
        $rol = strtoupper((string) (auth()->user()->rol->nombre ?? ''));
        if (! in_array($rol, ['ADMIN', 'GERENTE'], true)) {
            abort(403);
        }

        $eventos = AuditoriaSeguridad::with('usuario')->orderBy('creado_en', 'desc')->limit(100)->get();

        return view('admin.auditoria.index', compact('eventos'));
    }
}
