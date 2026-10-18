<?php

namespace App\Http\Controllers;

use App\Models\CajaApertura;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CajaAperturaController extends Controller
{
    public function registrarApertura(Request $request)
    {
        // Autorización en servidor: la apertura maneja montos de caja.
        // COCINERO no puede abrir caja ni iniciar turnos.
        $rol = strtoupper((string) (Auth::user()?->rol?->nombre ?? ''));

        if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'], true)) {
            return response()->json(['exito' => false, 'error' => 'No tienes permiso para abrir la caja.'], 403);
        }

        $request->validate([
            'monto_inicial' => 'required|numeric|min:0',
        ], [
            'monto_inicial.min' => 'El fondo de apertura no puede ser negativo.'
        ]);

        $cajaAbierta = CajaApertura::where('estado', 'ABIERTA')->exists();

        if ($cajaAbierta) {
            return response()->json([
                'exito' => false,
                'error' => 'Ya existe una caja abierta actualmente. Debe cerrarla antes de abrir un nuevo turno.'
            ]);
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
        ]);
    }

    public function imprimirTicketApertura(Request $request)
    {
        // Autorización en servidor: el ticket de apertura contiene montos.
        // COCINERO no debe imprimirlo ni verlo.
        $rol = strtoupper((string) (Auth::user()?->rol?->nombre ?? ''));

        if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'], true)) {
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
}
