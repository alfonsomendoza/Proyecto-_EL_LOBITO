<?php

namespace App\Http\Controllers;

use App\Models\CajaApertura;
use App\Models\CajaCierre;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CajaAperturaController extends Controller
{
    private function rolActual(): string
    {
        return strtoupper((string) (Auth::user()?->rol?->nombre ?? ''));
    }

    private function puedeAdministrarCaja(): bool
    {
        return in_array($this->rolActual(), ['ADMIN', 'GERENTE', 'CAJERO'], true);
    }

    private function puedeAnular(): bool
    {
        return in_array($this->rolActual(), ['ADMIN', 'GERENTE'], true);
    }

    public function registrarApertura(Request $request)
    {
        // Autorización en servidor: la apertura maneja montos de caja.
        // Solo ADMIN, GERENTE y CAJERO pueden abrir caja.
        $rol = $this->rolActual();

        if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO'], true)) {
            return response()->json(['exito' => false, 'error' => 'No tienes permiso para abrir la caja.'], 403);
        }

        $request->validate([
            'monto_inicial' => 'required|numeric|min:0',
        ], [
            'monto_inicial.min' => 'El fondo de apertura no puede ser negativo.'
        ]);

        try {
            return DB::transaction(function () use ($request) {
                $cajaAbierta = CajaApertura::where('estado', 'ABIERTA')->lockForUpdate()->exists();

                if ($cajaAbierta) {
                    return response()->json([
                        'exito' => false,
                        'error' => 'Ya existe una caja abierta actualmente. Debe cerrarla antes de abrir un nuevo turno.'
                    ], 409);
                }

                $apertura = CajaApertura::create([
                    'fecha' => $request->input('fecha', now()),
                    'cajero' => Auth::user()->nombre ?? $request->input('cajero', 'Admin'),
                    'turno' => $request->input('turno', 'Mañana'),
                    'caja_id' => $request->input('caja_id', 1),
                    'monto_inicial' => $request->input('monto_inicial', 0),
                    'conteo_json' => $request->input('conteo_json', '{}'),
                    'estado' => 'ABIERTA',
                    'usuario_id' => Auth::id(),
                ]);

                return response()->json([
                    'exito' => true,
                    'mensaje' => 'Caja abierta correctamente.',
                    'apertura_id' => $apertura->id
                ], 200);
            });
        } catch (\Throwable $e) {
            return response()->json([
                'exito' => false,
                'error' => 'No fue posible abrir la caja.'
            ], 500);
        }

        return response()->json([
            'exito' => true,
            'mensaje' => 'Caja abierta correctamente.',
            'apertura_id' => $apertura->id
        ]);
    }

    public function imprimirTicketApertura(Request $request)
    {
        // Autorización en servidor: el ticket de apertura contiene montos.
        // Solo ADMIN, GERENTE y CAJERO pueden acceder.
        if (!$this->puedeAdministrarCaja()) {
            abort(403, 'No tienes permiso para imprimir el ticket de apertura.');
        }

        $idApertura = $request->query('id', 0);

        if ($idApertura > 0) {
            $caja = CajaApertura::find($idApertura);
        } else {
            $caja = CajaApertura::where('estado', 'ABIERTA')->latest('id')->first();
        }

        if (!$caja) {
            abort(404, "No se encontró información de apertura para imprimir.");
        }

        return view('layouts.ticket_apertura', compact('caja'));
    }

    public function anular(int $id)
    {
        if (!$this->puedeAnular()) {
            return response()->json(['exito' => false, 'error' => 'Solo ADMIN y GERENTE pueden anular una apertura.'], 403);
        }

        $motivo = trim((string) request()->input('motivo', ''));
        if ($motivo === '') {
            return response()->json(['exito' => false, 'error' => 'El motivo de anulación es obligatorio.'], 422);
        }

        try {
            return DB::transaction(function () use ($id, $motivo) {
                if (Schema::hasTable('caja_bloqueo')) {
                    DB::table('caja_bloqueo')->where('id', 1)->lockForUpdate()->get();
                }

                $apertura = CajaApertura::where('id', $id)->lockForUpdate()->first();
                if (!$apertura) {
                    return response()->json(['exito' => false, 'error' => 'La apertura no existe.'], 404);
                }

                if ($apertura->estado === 'ANULADA') {
                    return response()->json(['exito' => false, 'error' => 'La apertura ya estaba anulada.'], 409);
                }

                $otraAbierta = CajaApertura::query()
                    ->where('estado', 'ABIERTA')
                    ->where('id', '!=', $apertura->id)
                    ->lockForUpdate()
                    ->exists();
                if ($otraAbierta) {
                    return response()->json(['exito' => false, 'error' => 'Ya existe otra caja abierta actualmente.'], 409);
                }

                // Verificar no haya cierres vigentes para esta apertura
                if (Schema::hasTable('caja_cierre')) {
                    $cierreVigente = CajaCierre::query()
                        ->where('apertura_id', $apertura->id)
                        ->where('estado', '!=', 'ANULADO')
                        ->lockForUpdate()
                        ->exists();
                    if ($cierreVigente) {
                        return response()->json(['exito' => false, 'error' => 'No es posible anular una apertura con cierre vigente. Anule primero el cierre o regularice el turno.'], 409);
                    }
                }

                $estadoAnterior = $apertura->estado;
                $apertura->estado = 'ANULADA';
                $apertura->save();

                if (Schema::hasTable('anulaciones_caja_auditoria')) {
                    $usuario = Auth::id();
                    $rol = Auth::check() ? strtoupper((string) (Auth::user()->rol->nombre ?? '')) : null;
                    DB::table('anulaciones_caja_auditoria')->insert([
                        'tipo' => 'apertura',
                        'registro_original_id' => (int) $apertura->id,
                        'apertura_id' => (int) $apertura->id,
                        'cierre_id' => null,
                        'fecha_anulacion' => now(),
                        'usuario_id' => $usuario,
                        'rol' => $rol,
                        'motivo' => $motivo,
                        'estado_anterior' => $estadoAnterior,
                        'estado_resultante' => 'ANULADA',
                        'referencia_json' => json_encode(['caja_id' => $apertura->caja_id, 'turno' => $apertura->turno]),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return response()->json(['exito' => true, 'mensaje' => 'Apertura anulada correctamente.'], 200);
            });
        } catch (\Throwable $e) {
            return response()->json(['exito' => false, 'error' => 'No fue posible anular la apertura.'], 500);
        }
    }

    public function historial()
    {
        if (!$this->puedeAdministrarCaja()) {
            abort(403, 'No tienes permiso para acceder al historial de caja.');
        }

        $aperturas = CajaApertura::orderByDesc('id')->limit(200)->get();
        $cierres = [];
        if (Schema::hasTable('caja_cierre')) {
            $cierres = CajaCierre::orderByDesc('id')->limit(200)->get();
        }
        $anulaciones = [];
        if (Schema::hasTable('anulaciones_caja_auditoria')) {
            $anulaciones = DB::table('anulaciones_caja_auditoria')->orderByDesc('id')->limit(300)->get();
        }

        return view('caja.historial', compact('aperturas', 'cierres', 'anulaciones'));
    }
}
