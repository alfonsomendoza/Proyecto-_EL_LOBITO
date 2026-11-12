<?php

namespace App\Http\Controllers;

use App\Models\Codigo2FAAdministrado;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AdminCodigo2FAController extends Controller
{
    protected AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Consulta del estado: ADMIN y GERENTE.
     */
    private function verificarPermiso()
    {
        $rol = strtoupper((string) (Auth::user()->rol->nombre ?? ''));
        if (! in_array($rol, ['ADMIN', 'GERENTE'], true)) {
            abort(403, 'No tienes permiso para consultar los códigos 2FA.');
        }
    }

    /**
     * Emitir o revocar un código de acceso es una modificación de la
     * seguridad del usuario: EXCLUSIVO de ADMIN.
     */
    private function verificarPermisoAdmin()
    {
        $rol = strtoupper((string) (Auth::user()->rol->nombre ?? ''));
        if ($rol !== 'ADMIN') {
            abort(403, 'Solo el administrador puede generar o revocar códigos 2FA.');
        }
    }

    public function index()
    {
        $this->verificarPermiso();
        $usuarios = User::with('rol')->whereHas('rol', function ($q) {
            $q->whereNotNull('nombre');
        })->get();
        return view('admin.2fa.codigos.index', compact('usuarios'));
    }

    public function generar(Request $request, $usuarioId)
    {
        $this->verificarPermisoAdmin();
        
        $usuario = User::findOrFail($usuarioId);
        $generador = Auth::user();
        
        // No permitir generarse código a sí mismo (según requerimiento, salvo autorización posterior)
        if ($generador->id == $usuario->id) {
            return back()->withErrors(['error' => 'No puedes generarte un código a ti mismo.']);
        }
        
        // Invalidar códigos anteriores pendientes
        Codigo2FAAdministrado::where('usuario_id', $usuario->id)
            ->where('usado', false)
            ->where(function($q) {
                $q->whereNull('expira_en')
                  ->orWhere('expira_en', '>', now());
            })
            ->update(['usado' => true, 'usado_en' => now()]);
        
        // Generar código criptográficamente seguro de 6 dígitos
        $codigo = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        
        Codigo2FAAdministrado::create([
            'usuario_id' => $usuario->id,
            'codigo_hash' => Hash::make($codigo),
            'generado_por' => $generador->id,
            'generado_en' => now(),
            'expira_en' => now()->addMinutes(5), // 5 minutos por defecto
            'usado' => false,
            'intentos' => 0,
            'ip_entrega' => $request->ip(),
        ]);
        
        $this->auditService->log('codigo_2fa_generado', $usuario->id, 'Código generado por ' . $generador->username, ['generado_por' => $generador->id], $request);
        
        // Mostrar código solo en esta respuesta (nunca guardado en logs)
        return back()->with('codigo_generado', $codigo)->with('usuario_codigo', $usuario->username);
    }

    public function historial()
    {
        $this->verificarPermiso();
        $codigos = Codigo2FAAdministrado::with(['usuario', 'generador'])
            ->orderBy('generado_en', 'desc')
            ->limit(100)
            ->get();
        return view('admin.2fa.codigos.historial', compact('codigos'));
    }

    public function revocar($id)
    {
        $this->verificarPermisoAdmin();
        $codigo = Codigo2FAAdministrado::findOrFail($id);
        if (! $codigo->usado) {
            $codigo->usado = true;
            $codigo->usado_en = now();
            $codigo->save();
            $this->auditService->log('codigo_2fa_revocado', $codigo->usuario_id, 'Código revocado por ' . Auth::user()->username);
        }
        return back()->with('success', 'Código revocado.');
    }
}
