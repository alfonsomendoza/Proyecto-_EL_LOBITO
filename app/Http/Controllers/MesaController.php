<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MesaController extends Controller
{
    public function index()
    {
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        $mesas = DB::table('mesas')
            ->orderByRaw('CAST(numero AS UNSIGNED) ASC')
            ->get();

        $clientes = DB::table('clientes')
            ->where('activo', 1)
            ->orderBy('nombre')
            ->get();

        $ventasActivas = DB::table('ventas')
            ->select('id', 'mesa_id', 'estado')
            ->whereNotNull('mesa_id')
            ->whereIn('estado', ['ACTIVA', 'ABIERTA'])
            ->get()
            ->keyBy('mesa_id');

        return view('mesas.index', compact('mesas', 'clientes', 'ventasActivas'));
    }

    public function api(Request $request)
    {
        $accion = $request->input('accion');

        if ($accion === 'crear_mesa') {
            $usuario = DB::table('usuarios')
                ->leftJoin('roles', 'usuarios.rol_id', '=', 'roles.id')
                ->where('usuarios.id', Auth::id())
                ->select('roles.nombre as rol_nombre')
                ->first();

            $rol = $usuario->rol_nombre ?? 'MESERO';

            if ($rol !== 'ADMIN') {
                return response()->json(['exito' => false, 'error' => 'Solo el Administrador puede crear mesas.']);
            }

            $numero = trim($request->input('numero', ''));
            if (empty($numero)) {
                return response()->json(['exito' => false, 'error' => 'El identificador de la mesa es obligatorio.']);
            }

            try {
                $existe = DB::table('mesas')->where('numero', $numero)->exists();
                if ($existe) {
                    throw new \Exception('Ya existe una mesa con ese identificador.');
                }

                DB::table('mesas')->insert([
                    'numero' => $numero,
                    'estado' => 'LIBRE'
                ]);

                return response()->json(['exito' => true, 'mensaje' => 'Mesa creada correctamente.']);
            } catch (\Exception $e) {
                return response()->json(['exito' => false, 'error' => $e->getMessage()]);
            }
        }

        if ($accion === 'abrir_mesa') {
            return $this->abrirMesa($request);
        }

        return response()->json(['exito' => false, 'error' => 'Acción desconocida.'], 400);
    }

    private function abrirMesa(Request $request)
    {
        $request->validate([
            'mesa_id' => ['required', 'integer', 'exists:mesas,id'],
            'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
        ]);

        $mesaId = (int) $request->input('mesa_id');
        $clienteId = $request->filled('cliente_id') ? (int) $request->input('cliente_id') : null;

        try {
            DB::beginTransaction();

            $mesa = DB::table('mesas')->where('id', $mesaId)->lockForUpdate()->first();

            if (!$mesa || $mesa->estado !== 'LIBRE') {
                DB::rollBack();
                return response()->json(['exito' => false, 'error' => 'La mesa no está libre o ya fue ocupada.'], 409);
            }

            $ventaExistente = DB::table('ventas')
                ->where('mesa_id', $mesaId)
                ->whereIn('estado', ['ACTIVA', 'ABIERTA'])
                ->lockForUpdate()
                ->first();

            if ($ventaExistente) {
                DB::rollBack();
                return response()->json(['exito' => false, 'error' => 'La mesa ya tiene una venta activa.'], 409);
            }

            // Crear Venta Maestra
            $ventaId = DB::table('ventas')->insertGetId([
                'mesa_id' => $mesaId,
                'cliente_id' => $clienteId,
                'descripcion' => 'Consumo mesa ' . $mesa->numero,
                'monto' => 0,
                'fecha' => now(),
                'estado' => 'ACTIVA',
                'usuario_id' => Auth::id(),
                'descuento_porcentaje' => 0,
                'tasa_cambio' => 1,
            ]);

            // Crear Cuenta Principal por defecto
            $cuentaId = DB::table('cuentas')->insertGetId([
                'venta_id' => $ventaId,
                'nombre' => 'Cuenta Principal',
                'total' => 0,
                'estado' => 'ABIERTA',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Cambiar estado de la mesa a OCUPADA
            DB::table('mesas')->where('id', $mesaId)->update(['estado' => 'OCUPADA']);

            DB::commit();

            return response()->json([
                'exito' => true,
                'mensaje' => 'Mesa abierta correctamente.',
                'venta_id' => $ventaId
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return response()->json(['exito' => false, 'error' => 'No fue posible abrir la mesa.'], 500);
        }
    }
}
