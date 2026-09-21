<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ClienteController extends Controller
{
    // Método para mostrar la vista con la tabla
    public function index()
    {
        // Traemos solo los activos para respetar la eliminación suave
        $clientes = Cliente::where('activo', 1)->get();
        return view('clientes.index', compact('clientes'));
    }

    // Método para manejar Crear, Editar y Eliminar por AJAX
    public function storeOrUpdate(Request $request)
    {
        try {
            // Protección de seguridad por rol
$rol = $request->user()->rol->nombre ?? '';
            if ($rol === 'MESERO' && $request->accion !== 'crear') {
                return response()->json(['exito' => false, 'error' => 'No tienes permisos para editar o eliminar.']);
            }

            if ($request->accion === 'crear' || $request->accion === 'editar') {
                Cliente::updateOrCreate(
                    ['id' => $request->id],
                    [
                        'nombre' => $request->nombre,
                        'telefono' => $request->telefono,
                        'identificacion' => $request->identificacion,
                        'direccion' => $request->direccion,
                        'activo' => 1
                    ]
                );
                return response()->json(['exito' => true, 'mensaje' => 'Cliente guardado exitosamente.']);
            }

            if ($request->accion === 'eliminar') {
                // Eliminación suave (Soft Delete) cambiando el estado activo
                Cliente::where('id', $request->id)->update(['activo' => 0]);
                return response()->json(['exito' => true, 'mensaje' => 'Cliente eliminado.']);
            }

            return response()->json(['exito' => false, 'error' => 'Acción no válida.']);

        } catch (\Exception $e) {
            // Guardamos el error real en el log (storage/logs/laravel.log) para depuración
            Log::error('Error en ClienteController: ' . $e->getMessage());
            return response()->json(['exito' => false, 'error' => 'Error interno al procesar el cliente.']);
        }
    }
}