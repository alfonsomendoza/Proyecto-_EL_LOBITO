<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        // Autorización en servidor: crear mesas y abrir mesas crea ventas y
        // cuentas. COCINERO no puede ejecutar estas acciones. La consulta del
        // Monitor (index) sí le queda permitida.
        $rol = strtoupper((string) (Auth::user()?->rol?->nombre ?? ''));

        if (!in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'], true)) {
            return response()->json(['exito' => false, 'error' => 'No tienes permiso para operar mesas.'], 403);
        }

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

    /**
     * Abre (o reutiliza) la venta activa de una mesa desde el Monitor.
     *
     * Reglas de negocio protegidas:
     *  - Abrir una mesa NO la marca OCUPADA: la mesa pasa a OCUPADA recién
     *    cuando se registra consumo real (VentaController@enviarPedidoDesdePos).
     *  - Es idempotente: reabrir NUNCA crea una segunda venta ni una segunda
     *    cuenta principal.
     *  - No reabre cuentas cobradas ni añade cuentas a una venta que ya tiene
     *    historial de cobro: en ese caso responde 409 sin tocar nada.
     *  - El cliente se conserva salvo que llegue una selección explícita y
     *    válida de otro cliente.
     *
     * Respuestas HTTP:
     *  - 422: mesa_id inválido o cliente_id inexistente.
     *  - 404: la mesa no existe.
     *  - 409: la venta activa ya no tiene cuentas abiertas (cerrada/cobrada).
     *  - 200: mesa lista para el POS.
     *
     * Nota: NO se usa $request->validate() porque devuelve un redirect HTML a
     * peticiones no-JSON (el Monitor hace fetch sin cabecera Accept). Aquí se
     * responde siempre en JSON con el código correcto.
     */
    private function abrirMesa(Request $request)
    {
        // 1. Validación de entrada con respuesta JSON controlada.
        $mesaId = $request->input('mesa_id');

        if (!is_numeric($mesaId) || (int) $mesaId <= 0) {
            return response()->json(['exito' => false, 'error' => 'Debes indicar una mesa válida.'], 422);
        }

        $mesaId = (int) $mesaId;

        $clienteId = $request->input('cliente_id');
        $clienteId = (is_numeric($clienteId) && (int) $clienteId > 0) ? (int) $clienteId : null;

        // 2. Cliente explícito: debe existir para poder asociarse a la venta.
        //    (No se exige "activo" para no romper referencias históricas.)
        if ($clienteId !== null) {
            $clienteValido = DB::table('clientes')->where('id', $clienteId)->exists();

            if (!$clienteValido) {
                return response()->json(['exito' => false, 'error' => 'El cliente indicado no existe.'], 422);
            }
        }

        try {
            $resultado = DB::transaction(function () use ($mesaId, $clienteId) {
                // 3. Bloqueo de la mesa: serializa aperturas simultáneas y evita
                //    ventas duplicadas ante solicitudes concurrentes.
                $mesa = DB::table('mesas')->where('id', $mesaId)->lockForUpdate()->first();

                if (!$mesa) {
                    return ['estado' => 'mesa_inexistente'];
                }

                // Validación de caja abierta (solo escrituras de consumo)
                if (Schema::hasTable('caja_bloqueo')) {
                    try {
                        DB::table('caja_bloqueo')->where('id', 1)->lockForUpdate()->get();
                    } catch (\Throwable $e) {
                        // Si no existe el registro singleton, continuar sin bloquearlo
                    }
                }
                $aperturaAbierta = false;
                if (Schema::hasTable('caja_apertura')) {
                    try {
                        $aperturaAbierta = DB::table('caja_apertura')->where('estado', 'ABIERTA')->lockForUpdate()->exists();
                    } catch (\Throwable $e) {
                        $aperturaAbierta = DB::table('caja_apertura')->where('estado', 'ABIERTA')->exists();
                    }
                }
                if (!$aperturaAbierta) {
                    return ['estado' => 'caja_cerrada'];
                }

                // 4. Venta activa de ESTA mesa (nunca de otra).
                $venta = DB::table('ventas')
                    ->where('mesa_id', $mesaId)
                    ->whereIn('estado', ['ACTIVA', 'ABIERTA'])
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if ($venta) {
                    // 5. Cuentas de la venta, bloqueadas para decidir con el
                    //    último estado confirmado.
                    $cuentas = DB::table('cuentas')
                        ->where('venta_id', $venta->id)
                        ->lockForUpdate()
                        ->get();

                    $tieneCuentaAbierta = $cuentas->contains(function ($c) {
                        return $c->estado === 'ABIERTA';
                    });

                    // 6. Recuperación segura de una venta SIN cuenta abierta.
                    //    NO se crea una cuenta principal automáticamente sin
                    //    comprobar antes que la venta esté realmente vacía y sin
                    //    historial. Se rechaza (409) si:
                    //      - ya tiene cuentas (aunque estén PAGADA/CANCELADA),
                    //        lo que indica cierres o cobros previos, o
                    //      - conserva consumos ACTIVOS o asignaciones pendientes,
                    //        o
                    //      - existe historial de pago (datos de cobro en la venta
                    //        o facturas emitidas).
                    //    Solo si la venta está genuinamente vacía y sin cobros se
                    //    le crea una cuenta principal para poder operar.
                    if (!$tieneCuentaAbierta) {
                        $historialPago = ($venta->metodo_pago !== null)
                            || ($venta->monto_recibido !== null)
                            || DB::table('facturas')->where('venta_id', $venta->id)->exists();

                        $consumoActivo = DB::table('venta_detalles')
                            ->where('venta_id', $venta->id)
                            ->where('estado', 'ACTIVO')
                            ->exists();

                        $asignacionesPendientes = DB::table('cuenta_detalles')
                            ->where('venta_id', $venta->id)
                            ->where('eliminado', false)
                            ->exists();

                        $noRecuperable = $cuentas->isNotEmpty()
                            || $historialPago
                            || $consumoActivo
                            || $asignacionesPendientes;

                        if ($noRecuperable) {
                            return ['estado' => 'venta_sin_cuenta_abierta'];
                        }

                        // Venta realmente vacía y sin historial: se crea la
                        // cuenta principal. No se altera ningún cobro previo
                        // porque no existe.
                        DB::table('cuentas')->insert([
                            'venta_id' => $venta->id,
                            'nombre' => 'Cuenta Principal',
                            'total' => 0,
                            'estado' => 'ABIERTA',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    // 7. La selección de cliente se conserva salvo que llegue
                    //    una selección explícita y válida.
                    if ($clienteId !== null) {
                        DB::table('ventas')->where('id', $venta->id)->update(['cliente_id' => $clienteId]);
                    }

                    return ['estado' => 'ok', 'venta_id' => $venta->id];
                }

                // 8. No hay venta activa: se crea una venta vacía con su cuenta
                //    principal. La mesa permanece LIBRE hasta el primer producto.
                $ventaId = DB::table('ventas')->insertGetId([
                    'mesa_id' => $mesaId,
                    'cliente_id' => $clienteId,
                    'apertura_id' => null,
                    'descripcion' => 'Consumo Mesa ' . $mesa->numero,
                    'monto' => 0,
                    'metodo_pago' => null,
                    'banco' => null,
                    'monto_recibido' => null,
                    'tasa_cambio' => null,
                    'cambio' => null,
                    'fecha' => now(),
                    'estado' => 'ACTIVA',
                    'usuario_id' => Auth::id(),
                    'descuento_porcentaje' => 0,
                ]);

                DB::table('cuentas')->insert([
                    'venta_id' => $ventaId,
                    'nombre' => 'Cuenta Principal',
                    'total' => 0,
                    'estado' => 'ABIERTA',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['estado' => 'ok', 'venta_id' => $ventaId];
            });

            // 9. Traducir el resultado de la transacción a la respuesta HTTP.
            if ($resultado['estado'] === 'mesa_inexistente') {
                return response()->json(['exito' => false, 'error' => 'La mesa no existe.'], 404);
            }

            if ($resultado['estado'] === 'venta_sin_cuenta_abierta') {
                return response()->json([
                    'exito' => false,
                    'error' => 'La venta de esta mesa ya está cerrada o no tiene cuentas abiertas. No se puede reabrir sin alterar su historial.',
                ], 409);
            }

            $ventaIdResp = $resultado['venta_id'] ?? $resultado['id_venta'] ?? ($ventaId ?? null);
            return response()->json([
                'exito' => true,
                'mensaje' => 'Mesa lista para tomar el pedido.',
                'venta_id' => $ventaIdResp,
            ]);
        } catch (\Throwable $e) {
            // Falla inesperada: se registra y se responde 500. La transacción
            // ya hizo rollback, por lo que no queda ningún dato a medias.
            report($e);
            return response()->json(['exito' => false, 'error' => 'No fue posible abrir la mesa.'], 500);
        }
    }
}
