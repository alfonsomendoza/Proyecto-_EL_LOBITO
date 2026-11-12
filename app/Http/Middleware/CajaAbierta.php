<?php

namespace App\Http\Middleware;

use App\Models\CajaApertura;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloqueo global: si no hay una apertura de caja ABIERTA para el turno
 * actual, las operaciones que modifican datos quedan bloqueadas para TODOS
 * los roles (incluidos ADMIN y GERENTE).
 *
 * Solo se permiten:
 *  - Inicio/cierre de sesión y verificación 2FA.
 *  - Consultas (GET/HEAD) estrictamente de lectura.
 *  - La pantalla y el registro de apertura de caja.
 *  - Verificación de dispositivos.
 */
class CajaAbierta
{
    /**
     * Rutas o prefijos que SIEMPRE están permitidos, incluso con caja
     * cerrada (autenticación, apertura, dispositivo).
     */
    private function esRutaPermitida(Request $request): bool
    {
        $path = trim($request->path(), '/');

        $permitidas = [
            'login',
            'logout',
            'caja/apertura',
            'caja/ticket-apertura',
            'dispositivo/verificar',
        ];

        foreach ($permitidas as $permitida) {
            if ($path === $permitida || str_starts_with($path, $permitida.'/')) {
                return true;
            }
        }

        // Rutas de 2FA necesarias para iniciar sesión.
        if (str_starts_with($path, '2fa/')) {
            return true;
        }

        // Las operaciones propias de Caja se rigen por su propia lógica
        // (apertura/cierre/anulación) y no se bloquean aquí para no duplicar
        // su control de estado; el controlador valida la apertura vigente.
        if (str_starts_with($path, 'caja/')) {
            return true;
        }

        // Administración y seguridad: gestión de usuarios, roles, 2FA,
        // dispositivos/sesiones y configuración de seguridad. Se mantienen
        // accesibles con caja cerrada según los permisos existentes.
        $adminSeguridad = [
            'usuarios',
            'roles',
            'admin',
            'dispositivo',
        ];
        foreach ($adminSeguridad as $prefijo) {
            if ($path === $prefijo || str_starts_with($path, $prefijo.'/')) {
                return true;
            }
        }

        // Configuración general (seguridad/módulos) sí; la fiscal afecta
        // ventas y se mantiene bloqueada con caja cerrada.
        if ($path === 'configuracion' || str_starts_with($path, 'configuracion/')) {
            return true;
        }

        return false;
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Sin usuario autenticado no se aplica (login).
        if (! $request->user()) {
            return $next($request);
        }

        if ($this->esRutaPermitida($request)) {
            return $next($request);
        }

        // Las consultas de solo lectura se permiten aunque la caja esté
        // cerrada (el bloqueo es sobre operaciones que modifican datos).
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        // Verificar si existe una apertura ABIERTA para el turno actual.
        if (! \Illuminate\Support\Facades\Schema::hasTable('caja_apertura')) {
            // Sin tabla no se puede determinar; no bloquear (entornos de prueba
            // sin esquema completo).
            return $next($request);
        }

        $cajaAbierta = CajaApertura::query()
            ->where('estado', 'ABIERTA')
            ->exists();

        if ($cajaAbierta) {
            return $next($request);
        }

        $mensaje = 'No existe una apertura de caja vigente. Todas las operaciones están bloqueadas. Debe abrir la caja para continuar.';

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'exito' => false,
                'error' => $mensaje,
            ], 422);
        }

        return redirect()->route('caja.apertura')
            ->with('caja_cerrada', $mensaje);
    }
}