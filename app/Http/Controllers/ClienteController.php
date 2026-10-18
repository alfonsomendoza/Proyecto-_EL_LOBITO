<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ClienteController extends Controller
{
    // Método para mostrar la vista con la tabla
    public function index()
    {
        // Autorización en servidor: COCINERO no debe acceder al módulo de
        // clientes. ADMIN, GERENTE, CAJERO y MESERO conservan su acceso.
        $rol = strtoupper((string) (Auth::user()?->rol?->nombre ?? ''));

        if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'], true)) {
            abort(403, 'No tienes permiso para acceder a los clientes.');
        }

        // Activos para respetar la eliminación lógica.
        $clientes = Cliente::where('activo', 1)
            ->orderBy('nombre')
            ->get();

        // Listado aparte de clientes eliminados (activo=0) para consultarlos
        // y poder reactivarlos. No se mezclan con los activos.
        $clientesEliminados = Cliente::where('activo', 0)
            ->orderBy('nombre')
            ->get();

        return view('clientes.index', compact('clientes', 'clientesEliminados'));
    }

    // Método para manejar Crear, Editar, Eliminar y Reactivar por AJAX
    public function storeOrUpdate(Request $request)
    {
        $usuario = Auth::user();
        if (!$usuario) {
            return $this->noAutorizado();
        }

        // El rol se resuelve SIEMPRE en el servidor, nunca desde el formulario.
        $rol = strtoupper((string) ($usuario->rol->nombre ?? ''));
        $accion = (string) $request->accion;
        $id = (int) $request->id;

        try {
            // CREAR: ADMIN, GERENTE, CAJERO y MESERO (se conserva su permiso actual).
            if ($accion === 'crear') {
                if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'], true)) {
                    return $this->noAutorizado();
                }

                $request->validate(['nombre' => 'required|string|max:150']);

                Cliente::create([
                    'nombre' => $request->nombre,
                    'telefono' => $request->telefono,
                    'identificacion' => $request->identificacion,
                    'direccion' => $request->direccion,
                    'activo' => 1,
                ]);

                return response()->json(['exito' => true, 'mensaje' => 'Cliente guardado exitosamente.']);
            }

            // EDITAR: ADMIN, GERENTE y CAJERO. La edición NO reactiva: no toca 'activo'.
            if ($accion === 'editar') {
                if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO'], true)) {
                    return $this->noAutorizado();
                }

                // Cliente General (ID 1): protegido también en el backend.
                if ($id === 1) {
                    return $this->clienteProtegido();
                }

                $request->validate(['nombre' => 'required|string|max:150']);

                $cliente = Cliente::find($id);
                if (!$cliente) {
                    return response()->json(['exito' => false, 'error' => 'El cliente no existe.'], 404);
                }

                // update() no toca 'activo': un cliente eliminado sigue eliminado.
                $cliente->update([
                    'nombre' => $request->nombre,
                    'telefono' => $request->telefono,
                    'identificacion' => $request->identificacion,
                    'direccion' => $request->direccion,
                ]);

                return response()->json(['exito' => true, 'mensaje' => 'Cliente actualizado.']);
            }

            // ELIMINAR (lógico): solo ADMIN y GERENTE.
            if ($accion === 'eliminar') {
                if (!in_array($rol, ['ADMIN', 'GERENTE'], true)) {
                    return $this->noAutorizado();
                }

                // El Cliente General no puede eliminarse ni por petición directa.
                if ($id === 1) {
                    return $this->clienteProtegido();
                }

                $cliente = Cliente::find($id);
                if (!$cliente) {
                    return response()->json(['exito' => false, 'error' => 'El cliente no existe.'], 404);
                }

                // Soft delete: conserva ID, ventas e historial. No borra la fila.
                $cliente->update(['activo' => 0]);

                return response()->json(['exito' => true, 'mensaje' => 'Cliente eliminado.']);
            }

            // REACTIVAR: solo ADMIN y GERENTE. Recupera el MISMO registro (sin duplicar).
            if ($accion === 'reactivar') {
                if (!in_array($rol, ['ADMIN', 'GERENTE'], true)) {
                    return $this->noAutorizado();
                }

                if ($id === 1) {
                    return $this->clienteProtegido();
                }

                $cliente = Cliente::find($id);
                if (!$cliente) {
                    return response()->json(['exito' => false, 'error' => 'El cliente no existe.'], 404);
                }

                $cliente->update(['activo' => 1]);

                return response()->json(['exito' => true, 'mensaje' => 'Cliente reactivado.']);
            }

            return response()->json(['exito' => false, 'error' => 'Acción no válida.'], 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['exito' => false, 'error' => $e->validator->errors()->first()], 422);
        } catch (\Exception $e) {
            // Guardamos el error real en el log (storage/logs/laravel.log) para depuración
            Log::error('Error en ClienteController: ' . $e->getMessage());
            return response()->json(['exito' => false, 'error' => 'Error interno al procesar el cliente.'], 500);
        }
    }

    private function noAutorizado()
    {
        return response()->json([
            'exito' => false,
            'error' => 'No tienes permiso para esta operación.',
        ], 403);
    }

    private function clienteProtegido()
    {
        return response()->json([
            'exito' => false,
            'error' => 'El Cliente General del sistema no puede modificarse ni eliminarse.',
        ], 403);
    }
}
