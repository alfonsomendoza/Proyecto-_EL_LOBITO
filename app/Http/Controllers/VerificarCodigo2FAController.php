<?php

namespace App\Http\Controllers;

use App\Models\Codigo2FAAdministrado;
use App\Services\AuditService;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class VerificarCodigo2FAController extends Controller
{
    protected AuditService $auditService;
    protected TwoFactorService $twoFactorService;

    public function __construct(AuditService $auditService, TwoFactorService $twoFactorService)
    {
        $this->auditService = $auditService;
        $this->twoFactorService = $twoFactorService;
    }

    public function mostrar(Request $request)
    {
        if ($this->twoFactorService->sesionVerificada($request->session(), Auth::user())) {
            return redirect()->route('inicio');
        }
        return view('auth.2fa.codigo-admin');
    }

    public function verificar(Request $request)
    {
        $request->validate([
            'codigo' => 'required|string|size:6',
        ]);

        $usuario = Auth::user();
        
        // Buscar código válido pendiente
        $codigos = Codigo2FAAdministrado::where('usuario_id', $usuario->id)
            ->where('usado', false)
            ->where(function($q) {
                $q->whereNull('expira_en')
                  ->orWhere('expira_en', '>', now());
            })
            ->get();
        
        $valido = false;
        $codigoEncontrado = null;
        
        foreach ($codigos as $codigo) {
            if (Hash::check($request->codigo, $codigo->codigo_hash)) {
                if ($codigo->intentos >= 5) { // Límite de intentos
                    $codigo->usado = true;
                    $codigo->usado_en = now();
                    $codigo->save();
                    $this->auditService->log('2fa_intentos_excedidos', $usuario->id, 'Límite de intentos excedido');
                    return back()->withErrors(['codigo' => 'Código bloqueado por intentos excesivos.']);
                }
                $valido = true;
                $codigoEncontrado = $codigo;
                break;
            }
        }
        
        if (! $valido) {
            // Incrementar intentos en códigos pendientes
            foreach ($codigos as $codigo) {
                $codigo->intentos++;
                $codigo->save();
            }
            $this->auditService->log('2fa_codigo_invalido', $usuario->id, 'Código 2FA inválido');
            return back()->withErrors(['codigo' => 'Código inválido.']);
        }
        
        // Marcar código como usado
        $codigoEncontrado->usado = true;
        $codigoEncontrado->usado_en = now();
        $codigoEncontrado->save();
        
        $this->twoFactorService->marcarSesionVerificada($request->session(), $usuario);
        $this->auditService->log('2fa_verificado_exitoso', $usuario->id);
        
        return redirect()->intended(route('inicio'));
    }
}
