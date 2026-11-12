<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Services\ClienteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ClienteController extends Controller
{
    public function __construct(
        private ClienteService $clienteService
    ) {}

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

    /**
     * Endpoint API unificado de clientes:
     *  - accion=search  -> búsqueda AJAX por texto libre (usado por Monitor y
     *                       subcuentas).
     *  - accion=crear|editar|eliminar|reactivar -> mismo comportamiento que
     *    antes, pero ahora centralizado en ClienteService para evitar duplicados
     *    y normalizar datos.
     *
     * Los roles se validan en backend (no en JS). El servicio protege
     * Cliente General (ID 1) y rechaza identificación duplicada aunque sea
     * ADMIN.
     */
    public function storeOrUpdate(Request $request)
    {
        $usuario = Auth::user();
        if (!$usuario) {
            return $this->noAutorizado();
        }

        $rol = strtoupper((string) ($usuario->rol->nombre ?? ''));
        $accion = (string) $request->accion;

        try {
            // BÚSQUEDA AJAX (úsala Monitor y subcuentas).
            if ($accion === 'search') {
                $this->validarSoloSoloLecturaParaBusqueda($rol);

                $request->validate([
                    'q' => ['required', 'string', 'max:100'],
                    'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
                ]);

                $q = trim((string) $request->q);
                $limit = (int) ($request->limit ?? 20);

                $resultado = $this->clienteService->buscar($q, $limit);

                return response()->json([
                    'exito' => true,
                    'clientes' => $resultado,
                ]);
            }

            // CREAR: ADMIN, GERENTE, CAJERO y MESERO (reutiliza servicio para
            // detección de duplicados, homónimos y teléfono coincidente).
            if ($accion === 'crear') {
                if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'], true)) {
                    return $this->noAutorizado();
                }

                $request->validate([
                    'nombre' => ['required', 'string', 'max:150'],
                    'telefono' => ['nullable', 'string', 'max:30'],
                    'identificacion' => ['nullable', 'string', 'max:50'],
                    'direccion' => ['nullable', 'string', 'max:255'],
                ]);

                $resultado = $this->clienteService->registrarOActualizar(
                    [
                        'nombre' => $request->nombre,
                        'telefono' => $request->filled('telefono') ? $request->telefono : null,
                        'identificacion' => $request->filled('identificacion') ? $request->identificacion : null,
                        'direccion' => $request->filled('direccion') ? $request->direccion : null,
                    ],
                    $rol
                );

                if (!$resultado['success']) {
                    return response()->json([
                        'exito' => false,
                        'error' => $resultado['error'],
                        'cliente' => $resultado['cliente'] ?? null,
                        'alerta' => $resultado['alerta'] ?? null,
                    ], isset($resultado['cliente']) ? 422 : 422);
                }

                return response()->json([
                    'exito' => true,
                    'mensaje' => 'Cliente guardado exitosamente.',
                    'cliente' => $resultado['cliente'],
                    'alerta' => $resultado['alerta'] ?? null,
                ]);
            }

            // EDITAR: ADMIN, GERENTE y CAJERO. La edición NO reactiva: no toca 'activo'.
            if ($accion === 'editar') {
                if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO'], true)) {
                    return $this->noAutorizado();
                }

                $request->validate([
                    'id' => ['required', 'integer', 'exists:clientes,id'],
                    'nombre' => ['required', 'string', 'max:150'],
                    'telefono' => ['nullable', 'string', 'max:30'],
                    'identificacion' => ['nullable', 'string', 'max:50'],
                    'direccion' => ['nullable', 'string', 'max:255'],
                ]);

                // Cliente General (ID 1): protegido también en el backend.
                if ((int) $request->id === 1) {
                    return $this->clienteProtegido();
                }

                $resultado = $this->clienteService->registrarOActualizar(
                    [
                        'id' => (int) $request->id,
                        'nombre' => $request->nombre,
                        'telefono' => $request->filled('telefono') ? $request->telefono : null,
                        'identificacion' => $request->filled('identificacion') ? $request->identificacion : null,
                        'direccion' => $request->filled('direccion') ? $request->direccion : null,
                    ],
                    $rol
                );

                if (!$resultado['success']) {
                    return response()->json([
                        'exito' => false,
                        'error' => $resultado['error'],
                        'cliente' => $resultado['cliente'] ?? null,
                        'alerta' => $resultado['alerta'] ?? null,
                    ], 422);
                }

                return response()->json([
                    'exito' => true,
                    'mensaje' => 'Cliente actualizado.',
                    'cliente' => $resultado['cliente'],
                    'alerta' => $resultado['alerta'] ?? null,
                ]);
            }

            // ELIMINAR (lógico): solo ADMIN y GERENTE.
            if ($accion === 'eliminar') {
                if (!in_array($rol, ['ADMIN', 'GERENTE'], true)) {
                    return $this->noAutorizado();
                }

                // El Cliente General no puede eliminarse ni por petición directa.
                $id = (int) ($request->id ?? 0);
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

                $id = (int) ($request->id ?? 0);
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
        } catch (\Throwable $e) {
            Log::error('Error en ClienteController: ' . $e->getMessage(), [
                'accion' => $accion,
                'linea' => $e->getLine(),
            ]);
            return response()->json(['exito' => false, 'error' => 'Error interno al procesar el cliente.'], 500);
        }
    }

    private function validarSoloSoloLecturaParaBusqueda(string $rol): void
    {
        if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'], true)) {
            abort(403, 'No tienes permiso para consultar clientes.');
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
