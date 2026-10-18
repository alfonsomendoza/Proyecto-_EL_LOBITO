<?php

namespace App\Http\Controllers;

use App\Services\ConfiguracionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Monitor digital de cocina (función OPCIONAL).
 *
 * Fuente de verdad: `venta_detalles` (igual que las impresiones). Solo se
 * muestran líneas de productos que requieren cocina (productos.requiere_cocina
 * = 1) y que tienen estado de preparación (estado_preparacion NO NULL). Los
 * pedidos históricos sin estado quedan fuera para no reaparecer como tareas.
 *
 * Permisos (validados SIEMPRE en el servidor):
 *  - COCINERO, ADMIN, GERENTE: consultan y avanzan estados.
 *  - CAJERO, MESERO y roles desconocidos: 403.
 *
 * Si la cocina digital está deshabilitada, TODOS los endpoints (HTML y JSON)
 * rechazan el acceso, aunque se conozcan las URL.
 *
 * NUNCA se exponen precios, subtotales, totales ni datos de pago.
 */
class CocinaController extends Controller
{
    /** Roles que pueden ver el monitor. */
    private const ROLES_CONSULTA = ['ADMIN', 'GERENTE', 'COCINERO'];

    /** Roles que además pueden corregir (retroceder) el estado. */
    private const ROLES_CORRECCION = ['ADMIN', 'GERENTE'];

    /** Estados de preparación válidos (independientes de los estados de venta). */
    private const ESTADOS = ['PENDIENTE', 'EN_PREPARACION', 'LISTO'];

    /** Avance normal: lo pueden hacer todos los roles de cocina. */
    private const AVANCE = [
        'PENDIENTE' => 'EN_PREPARACION',
        'EN_PREPARACION' => 'LISTO',
    ];

    /** Retroceso controlado: solo ADMIN y GERENTE. */
    private const RETROCESO = [
        'LISTO' => 'EN_PREPARACION',
        'EN_PREPARACION' => 'PENDIENTE',
    ];

    public function __construct(private ConfiguracionService $configuracion) {}

    /* ------------------------------------------------------------------
     | Pantalla HTML del monitor
     | ------------------------------------------------------------------ */

    public function index()
    {
        if (! $this->accesoPermitido()) {
            abort(403, 'No tienes permiso para el monitor de cocina.');
        }

        if (! $this->configuracion->cocinaDigitalHabilitada()) {
            abort(403, 'La cocina digital está deshabilitada.');
        }

        return view('cocina.index', [
            'puedeCorregir' => $this->puedeCorregir(),
        ]);
    }

    /* ------------------------------------------------------------------
     | Endpoint JSON de sondeo (solo lectura)
     | ------------------------------------------------------------------ */

    public function panel()
    {
        if ($rechazo = $this->rechazoJson()) {
            return $rechazo;
        }

        return response()->json([
            'exito' => true,
            'lineas' => $this->lineasActivas(),
        ]);
    }

    /* ------------------------------------------------------------------
     | Cambio de estado de preparación de una línea
     | ------------------------------------------------------------------ */

    public function cambiarEstado(Request $request, int $detalle)
    {
        if ($rechazo = $this->rechazoJson()) {
            return $rechazo;
        }

        // El estado destino debe ser uno de los válidos. Nunca se confía en el
        // estado que envía el navegador para decidir la transición.
        $datos = $request->validate([
            'estado' => ['required', 'string', 'in:'.implode(',', self::ESTADOS)],
        ]);

        $destino = strtoupper((string) $datos['estado']);
        $puedeCorregir = $this->puedeCorregir();

        try {
            $resultado = DB::transaction(function () use ($detalle, $destino, $puedeCorregir) {
                // Bloqueo de la fila: serializa solicitudes simultáneas y
                // reintentos para no corromper el estado.
                $linea = DB::table('venta_detalles')
                    ->where('id', $detalle)
                    ->lockForUpdate()
                    ->first();

                if (! $linea) {
                    throw new \RuntimeException('La línea no existe.');
                }

                // 1. Debe corresponder a un producto de cocina.
                $producto = DB::table('productos')->where('id', $linea->producto_id)->first();
                if (! $producto || (int) $producto->requiere_cocina !== 1) {
                    throw new \RuntimeException('La línea no corresponde a cocina.');
                }

                // 2. La línea debe seguir activa (no anulada/cancelada).
                if ($linea->estado !== 'ACTIVO') {
                    throw new \RuntimeException('La línea ya no está activa.');
                }

                // 3. La comanda y la venta deben ser elegibles.
                if ($linea->comanda_id === null) {
                    throw new \RuntimeException('La línea no pertenece a una comanda.');
                }

                $comanda = DB::table('comandas')->where('id', $linea->comanda_id)->first();
                if (! $comanda || in_array($comanda->estado, ['ANULADA', 'CANCELADA'], true)) {
                    throw new \RuntimeException('La comanda ya no está vigente.');
                }

                $venta = DB::table('ventas')->where('id', $linea->venta_id)->first();
                if (! $venta || ! in_array($venta->estado, ['ACTIVA', 'ABIERTA'], true)) {
                    throw new \RuntimeException('La venta ya no está activa.');
                }

                // 4. Estado real almacenado (no el del navegador).
                $actual = $linea->estado_preparacion;
                if ($actual === null) {
                    throw new \RuntimeException(
                        'La línea es anterior al módulo de cocina y no tiene estado de preparación.'
                    );
                }

                // Idempotencia: pedir el mismo estado no produce efectos.
                if ($actual === $destino) {
                    return ['estado' => $actual, 'sin_cambios' => true];
                }

                // 5. Avance normal (todos los roles de cocina).
                $esAvance = isset(self::AVANCE[$actual]) && self::AVANCE[$actual] === $destino;

                // 6. Retroceso controlado (solo ADMIN/GERENTE).
                $esRetroceso = isset(self::RETROCESO[$actual]) && self::RETROCESO[$actual] === $destino;

                if (! $esAvance && ! $esRetroceso) {
                    throw new \RuntimeException('Transición de estado no permitida.');
                }

                if ($esRetroceso && ! $puedeCorregir) {
                    throw new \RuntimeException('Solo ADMIN o GERENTE pueden retroceder el estado.');
                }

                // 7. Se actualiza EXCLUSIVAMENTE el estado de preparación.
                DB::table('venta_detalles')
                    ->where('id', $detalle)
                    ->update([
                        'estado_preparacion' => $destino,
                        'updated_at' => now(),
                    ]);

                return ['estado' => $destino, 'sin_cambios' => false];
            });
        } catch (\Throwable $e) {
            Log::warning('Cocina: no se pudo cambiar el estado', [
                'detalle_id' => $detalle,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['exito' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json([
            'exito' => true,
            'id' => $detalle,
            'estado' => $resultado['estado'],
            'sin_cambios' => $resultado['sin_cambios'],
        ]);
    }

    /* ------------------------------------------------------------------
     | Consulta de líneas activas para el monitor
     | ------------------------------------------------------------------ */

    /**
     * Devuelve SOLO los campos necesarios. Sin precios ni datos financieros.
     *
     * @return array<int,array<string,mixed>>
     */
    private function lineasActivas(): array
    {
        return DB::table('venta_detalles as vd')
            ->join('productos as p', 'p.id', '=', 'vd.producto_id')
            ->join('ventas as v', 'v.id', '=', 'vd.venta_id')
            ->join('comandas as c', 'c.id', '=', 'vd.comanda_id')
            ->join('mesas as m', 'm.id', '=', 'v.mesa_id')
            ->where('vd.estado', 'ACTIVO')
            ->where('p.requiere_cocina', 1)
            ->whereNotNull('vd.comanda_id')
            ->whereNotNull('vd.estado_preparacion')
            ->whereNotIn('c.estado', ['ANULADA', 'CANCELADA'])
            ->whereIn('v.estado', ['ACTIVA', 'ABIERTA'])
            ->orderBy('vd.created_at')
            ->orderBy('vd.id')
            ->get([
                'vd.id',
                'vd.cantidad',
                'vd.notas',
                'vd.estado_preparacion',
                'vd.created_at',
                'p.nombre as producto',
                'c.numero_ticket as comanda_ticket',
                'm.numero as mesa',
            ])
            ->map(fn ($f) => [
                'id' => (int) $f->id,
                'mesa' => $f->mesa,
                'comanda' => (int) $f->comanda_ticket,
                'hora' => $f->created_at,
                'producto' => $f->producto,
                'cantidad' => (int) $f->cantidad,
                'notas' => $f->notas,
                'estado' => $f->estado_preparacion,
            ])
            ->values()
            ->all();
    }

    /* ------------------------------------------------------------------
     | Autorización / rechazos
     | ------------------------------------------------------------------ */

    private function rolActual(): string
    {
        return strtoupper((string) (Auth::user()?->rol->nombre ?? ''));
    }

    private function accesoPermitido(): bool
    {
        return in_array($this->rolActual(), self::ROLES_CONSULTA, true);
    }

    private function puedeCorregir(): bool
    {
        return in_array($this->rolActual(), self::ROLES_CORRECCION, true);
    }

    /**
     * Rechazo JSON común a panel y cambio de estado. Devuelve null si el acceso
     * está permitido (para seguir con la operación).
     */
    private function rechazoJson(): ?\Illuminate\Http\JsonResponse
    {
        if (! Auth::check()) {
            return response()->json(['exito' => false, 'error' => 'Usuario no autenticado.'], 401);
        }

        if (! $this->accesoPermitido()) {
            return response()->json([
                'exito' => false,
                'error' => 'No tienes permiso para el monitor de cocina.',
            ], 403);
        }

        if (! $this->configuracion->cocinaDigitalHabilitada()) {
            return response()->json([
                'exito' => false,
                'error' => 'La cocina digital está deshabilitada.',
            ], 403);
        }

        return null;
    }
}
