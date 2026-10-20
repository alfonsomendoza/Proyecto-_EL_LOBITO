@extends('layouts.app')

@section('content')
{{--
    MÓDULO DE VENTAS
    =================
    Página propia (no es una copia del Monitor de mesas): consulta las
    cuentas PENDIENTES de cobro y el historial de ventas PAGADAS.

    El cobro NO se reimplementa aquí: "Cobrar" abre el POS acotado a esa
    cuenta (pos.index?cuenta=), donde vive el modal de cobro real que
    reutiliza VentaController::pagarCuenta.

    Autorización: VentaController::panelVentas solo permite ADMIN, GERENTE
    y CAJERO (403 para MESERO y COCINERO). Ocultar el botón no basta.
--}}
<div class="monitor-card" style="max-width: 1100px; margin: 0 auto;">
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center;">
        <span><i class="fas fa-shopping-cart"></i> Ventas — Cuentas por cobrar</span>
        <a href="{{ route('mesas.index') }}" style="text-decoration: none; color: white; font-size: 13px; font-weight: 600;">
            <i class="fas fa-desktop"></i> Ir al Monitor
        </a>
    </div>

    <div style="padding: 25px;">

        {{-- ============================ PENDIENTES DE COBRO ============================ --}}
        <h3 style="color: var(--color-dark); font-size: 16px; margin-bottom: 12px;">
            <i class="fas fa-hourglass-half"></i> Pendientes de cobro ({{ $pendientes->count() }})
        </h3>

        <div style="border-radius: 8px; border: 1px solid #e3e6f0; overflow: hidden; margin-bottom: 35px;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                <thead style="background-color: #f4f6f9; border-bottom: 1px solid #ddd;">
                    <tr>
                        <th style="padding: 12px 15px;">Mesa</th>
                        <th style="padding: 12px 15px;">Cuenta</th>
                        <th style="padding: 12px 15px;">Fecha</th>
                        <th style="padding: 12px 15px; text-align: right;">Total</th>
                        <th style="padding: 12px 15px; text-align: center;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pendientes as $cuenta)
                        <tr style="border-bottom: 1px solid #f0f0f0;">
                            <td style="padding: 12px 15px; font-weight: 600;">
                                @if($cuenta->mesa_numero)
                                    Mesa {{ $cuenta->mesa_numero }}
                                @else
                                    {{-- Venta sin mesa asociada: solo se puede consultar. --}}
                                    <span style="color: #aaa;">Sin mesa</span>
                                @endif
                            </td>
                            <td style="padding: 12px 15px;">
                                {{ $cuenta->cuenta_nombre ?? 'Principal' }}
                                <small style="color: #6c757d;">(#{{ $cuenta->cuenta_id }})</small>
                            </td>
                            <td style="padding: 12px 15px; color: #666;">
                                {{ $cuenta->fecha ? \Carbon\Carbon::parse($cuenta->fecha)->format('d/m/Y H:i') : '—' }}
                            </td>
                            <td style="padding: 12px 15px; text-align: right; font-weight: bold; color: var(--color-dark);">
                                C$ {{ number_format($cuenta->total, 2) }}
                            </td>
                            <td style="padding: 12px 15px; text-align: center; white-space: nowrap;">
                                <a href="{{ route('pos.cuentas.prefactura', ['cuentaId' => $cuenta->cuenta_id]) }}"
                                   target="_blank"
                                   style="text-decoration: none; color: #36b9cc; border: 1px solid #36b9cc; padding: 5px 10px; border-radius: 4px; font-size: 12px;">
                                    <i class="fas fa-receipt"></i> Detalle
                                </a>

                                @if($cuenta->mesa_id)
                                    {{-- Reutiliza el cobro real del POS con la cuenta
                                         preseleccionada (?cuenta=). --}}
                                    <a href="{{ route('pos.index', $cuenta->mesa_id) }}?cuenta={{ $cuenta->cuenta_id }}"
                                       style="text-decoration: none; background: var(--color-success); color: white; padding: 6px 12px; border-radius: 4px; font-size: 12px; font-weight: 600; margin-left: 6px;">
                                        <i class="fas fa-cash-register"></i> Cobrar
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="padding: 20px; text-align: center; color: #aaa;">
                                No hay cuentas pendientes de cobro.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- ============================ HISTORIAL DE PAGADAS ============================ --}}
        <h3 style="color: var(--color-dark); font-size: 16px; margin-bottom: 12px;">
            <i class="fas fa-circle-check"></i> Ventas cobradas
            @if ($rango['activo'])
                — del {{ $rango['desde'] }} al {{ $rango['hasta'] }}
            @else
                (últimas {{ $pagadas->count() }})
            @endif
        </h3>

        {{-- Filtro por rango de fechas (Desde/Hasta). Sin filtros: últimas 50. --}}
        <form method="GET" action="{{ route('ventas.index') }}"
              style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; border:1px solid #e3e6f0; border-radius:8px; padding:14px 16px; margin-bottom:16px; background:#fbfbfd;">
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="desde" style="font-size:0.85rem; font-weight:bold; color:#555;">Desde:</label>
                <input type="date" id="desde" name="desde" value="{{ $rango['desde'] }}"
                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="hasta" style="font-size:0.85rem; font-weight:bold; color:#555;">Hasta:</label>
                <input type="date" id="hasta" name="hasta" value="{{ $rango['hasta'] }}"
                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <button type="submit"
                    style="background-color: var(--color-primary); color:#fff; border:none; padding:9px 18px; border-radius:5px; font-weight:bold; cursor:pointer;">
                <i class="fas fa-search"></i> Filtrar
            </button>
            <a href="{{ route('ventas.index') }}"
               style="background-color:#e9ecef; color:#333; border:none; padding:9px 18px; border-radius:5px; font-weight:bold; text-decoration:none;">
                <i class="fas fa-eraser"></i> Limpiar filtros
            </a>
        </form>

        @if (! ($rango['valido'] ?? true))
            <div style="background:#fff7e6; border:1px solid #f5d9a8; border-radius:6px; padding:12px 16px; margin-bottom:16px; font-size:13px; color:#7a5a1a;">
                <i class="fas fa-exclamation-triangle"></i> {{ $rango['error'] }}
            </div>
        @else

        {{-- Totales del período mostrado (calculados en el servidor). --}}
        <div style="display:flex; gap:15px; flex-wrap:wrap; margin-bottom:16px;">
            <div style="flex:1; min-width:180px; border:1px solid #e3e6f0; border-radius:8px; padding:12px 16px; background:#f8f9fa;">
                <div style="font-size:12px; color:#6c757d; text-transform:uppercase; letter-spacing:0.5px;">Ventas cobradas</div>
                <div style="font-size:20px; font-weight:bold; color: var(--color-dark);">{{ $cantidadCobradas }}</div>
            </div>
            <div style="flex:1; min-width:180px; border:1px solid #e3e6f0; border-radius:8px; padding:12px 16px; background:#e8f8f5;">
                <div style="font-size:12px; color:#6c757d; text-transform:uppercase; letter-spacing:0.5px;">Total cobrado</div>
                <div style="font-size:20px; font-weight:bold; color: var(--color-success);">C$ {{ number_format($totalCobrado, 2) }}</div>
            </div>
        </div>

        <div style="border-radius: 8px; border: 1px solid #e3e6f0; overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                <thead style="background-color: #f4f6f9; border-bottom: 1px solid #ddd;">
                    <tr>
                        <th style="padding: 12px 15px;">Venta</th>
                        <th style="padding: 12px 15px;">Mesa</th>
                        <th style="padding: 12px 15px;">Fecha</th>
                        <th style="padding: 12px 15px;">Método</th>
                        <th style="padding: 12px 15px;">Cuenta(s) cobrada(s)</th>
                        <th style="padding: 12px 15px; text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pagadas as $venta)
                        <tr style="border-bottom: 1px solid #f0f0f0;">
                            <td style="padding: 12px 15px; color: #666;">#{{ $venta->venta_id }}</td>
                            <td style="padding: 12px 15px;">
                                @if($venta->mesa_numero)
                                    Mesa {{ $venta->mesa_numero }}
                                @else
                                    <span style="color: #aaa;">Mostrador</span>
                                @endif
                            </td>
                            <td style="padding: 12px 15px; color: #666;">
                                {{ $venta->fecha ? \Carbon\Carbon::parse($venta->fecha)->format('d/m/Y H:i') : '—' }}
                            </td>
                            <td style="padding: 12px 15px;">{{ $venta->metodo_pago ?? '—' }}</td>
                            <td style="padding: 12px 15px;">
                                @forelse (($cuentasCobradas[$venta->venta_id] ?? collect()) as $cobro)
                                    <div style="font-size: 12px; line-height: 1.5;">
                                        {{ $cobro->numero_factura }} ·
                                        {{ $cobro->cuenta_nombre ?? 'Cuenta' }}
                                        <small style="color: #6c757d;">(#{{ $cobro->cuenta_id }})</small>:
                                        C$ {{ number_format((float) $cobro->total, 2) }}
                                    </div>
                                @empty
                                    <span style="color: #aaa;">—</span>
                                @endforelse
                            </td>
                            <td style="padding: 12px 15px; text-align: right; font-weight: bold; color: var(--color-dark);">
                                C$ {{ number_format((float) $venta->monto, 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="padding: 20px; text-align: center; color: #aaa;">
                                @if ($rango['activo'])
                                    No hay ventas cobradas en el período {{ $rango['desde'] }} → {{ $rango['hasta'] }}.
                                @else
                                    Todavía no hay ventas cobradas.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @endif

    </div>
</div>
@endsection
