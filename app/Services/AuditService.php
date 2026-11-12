<?php

namespace App\Services;

use App\Models\AuditoriaSeguridad;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AuditService
{
    public function log(string $evento, ?int $usuarioId = null, ?string $motivo = null, array $detalles = [], ?Request $request = null): void
    {
        if ($usuarioId === null && Auth::check()) {
            $usuarioId = Auth::id();
        }

        if ($request === null && app()->bound('request')) {
            $request = request();
        }

        try {
            if (! Schema::hasTable('auditoria_seguridad')) {
                Log::info('AUDIT: ' . $evento, [
                    'usuario_id' => $usuarioId,
                    'motivo' => $motivo,
                    'ip' => $request ? $request->ip() : null,
                    'detalles' => $detalles,
                ]);
                return;
            }

            AuditoriaSeguridad::create([
                'usuario_id' => $usuarioId,
                'evento' => $evento,
                'ip' => $request ? $request->ip() : null,
                'user_agent' => $request ? $request->userAgent() : null,
                'motivo' => $motivo,
                'detalles' => $detalles ? json_encode($detalles) : null,
                'creado_en' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Error al registrar auditoría: ' . $e->getMessage(), [
                'evento' => $evento,
                'usuario_id' => $usuarioId,
            ]);
        }
    }
}
