@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 1000px; margin: 0 auto;">
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <span><i class="fas fa-hand-holding-dollar"></i> Administración — Resumen de Propinas del Día</span>
    </div>

    <div style="padding: 25px;">

        {{-- Filtro por rango de fechas (Desde/Hasta). Por defecto, el día actual. --}}
        <form method="GET" action="{{ route('reportes.propinas') }}"
              style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; border:1px solid #e3e6f0; border-radius:8px; padding:14px 16px; margin-bottom:22px; background:#fbfbfd;">
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="desde" style="font-size:0.85rem; font-weight:bold; color:#555;">Desde:</label>
                <input type="date" id="desde" name="desde" value="{{ $desde }}"
                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="hasta" style="font-size:0.85rem; font-weight:bold; color:#555;">Hasta:</label>
                <input type="date" id="hasta" name="hasta" value="{{ $hasta }}"
                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <button type="submit"
                    style="background-color:#6777ef; color:#fff; border:none; padding:9px 18px; border-radius:5px; font-weight:bold; cursor:pointer;">
                <i class="fas fa-search"></i> Filtrar
            </button>
            <a href="{{ route('reportes.propinas') }}"
               style="background-color:#e9ecef; color:#333; border:none; padding:9px 18px; border-radius:5px; font-weight:bold; text-decoration:none;">
                <i class="fas fa-eraser"></i> Limpiar filtros
            </a>
        </form>

        @if (! ($resumen['exito'] ?? false))
            <div style="background:#fff7e6; border:1px solid #f5d9a8; border-radius:6px; padding:14px 16px; font-size:13px; color:#7a5a1a; line-height:1.5;">
                <i class="fas fa-exclamation-triangle"></i>
                {{ $resumen['error'] ?? 'No hay información para esta fecha.' }}
            </div>
        @else

            {{-- Total general de propinas del período. --}}
            <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden; margin-bottom:22px;">
                <div style="padding:10px 16px; background:#16a085; color:#fff; font-weight:600; font-size:14px;">
                    <i class="fas fa-hand-holding-dollar"></i> Total de propinas del período
                </div>
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <tr style="border-bottom:1px solid #f1f1f1;">
                        <td style="padding:8px 14px;">Período:</td>
                        <td style="padding:8px 14px; text-align:right;">{{ $desde }} → {{ $hasta }}</td>
                    </tr>
                    <tr style="border-bottom:1px solid #f1f1f1;">
                        <td style="padding:8px 14px;">Cuentas cobradas con propina:</td>
                        <td style="padding:8px 14px; text-align:right;">{{ $resumen['cantidad'] }}</td>
                    </tr>
                    <tr style="background:#e8f8f5; font-weight:bold; font-size:15px;">
                        <td style="padding:12px 14px;">TOTAL PROPINAS:</td>
                        <td style="padding:12px 14px; text-align:right;">C$ {{ number_format($resumen['total'], 2) }}</td>
                    </tr>
                </table>
            </div>

            {{-- Desglose por factura/cuenta para contrastar con los vouchers. --}}
            <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden;">
                <div style="padding:12px 16px; background:#34495e; color:#fff; font-weight:600; font-size:14px;">
                    <i class="fas fa-list-alt"></i> Desglose por cuenta/factura
                </div>

                @if ($resumen['cantidad'] === 0)
                    {{-- Estado vacío: no hay propinas en el período elegido. --}}
                    <div style="padding:30px 16px; text-align:center; color:#6c757d; font-size:13px;">
                        <i class="fas fa-inbox" style="font-size:28px; display:block; margin-bottom:10px; color:#c9ced6;"></i>
                        No se registraron propinas cobradas en el período {{ $desde }} → {{ $hasta }}.
                    </div>
                @else
                    <table style="width:100%; border-collapse:collapse; font-size:13px;">
                        <thead>
                            <tr style="background:#f4f6f9; color:#495057; text-align:left;">
                                <th style="padding:10px 12px;">Factura</th>
                                <th style="padding:10px 12px;">Cuenta</th>
                                <th style="padding:10px 12px;">Venta</th>
                                <th style="padding:10px 12px;">Fecha de cobro</th>
                                <th style="padding:10px 12px; text-align:right;">Propina</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($resumen['detalle'] as $linea)
                                <tr style="border-bottom:1px solid #f1f1f1;">
                                    <td style="padding:10px 12px;">{{ $linea['numero_factura'] ?? '—' }}</td>
                                    <td style="padding:10px 12px;">
                                        {{ $linea['cuenta_nombre'] }}
                                        <span style="color:#8a94a6; font-size:11px;">#{{ $linea['cuenta_id'] }}</span>
                                    </td>
                                    <td style="padding:10px 12px;">#{{ $linea['venta_id'] }}</td>
                                    <td style="padding:10px 12px;">
                                        {{ $linea['fecha'] ? \Illuminate\Support\Carbon::parse($linea['fecha'])->format('d/m/Y H:i') : '—' }}
                                        @if (($linea['cuenta_actualizada'] ?? null)
                                            && \Illuminate\Support\Carbon::parse($linea['cuenta_actualizada'])->toDateString() !== \Illuminate\Support\Carbon::parse($linea['fecha'])->toDateString())
                                            <span style="color:#b8860b; font-size:11px;" title="La cuenta se modificó después del cobro">
                                                <i class="fas fa-pencil"></i> editada
                                            </span>
                                        @endif
                                    </td>
                                    <td style="padding:10px 12px; text-align:right; font-weight:bold;">C$ {{ number_format($linea['propina'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr style="background:#f8f9fa; font-weight:bold;">
                                <td colspan="4" style="padding:10px 12px; text-align:right;">TOTAL DEL PERÍODO:</td>
                                <td style="padding:10px 12px; text-align:right;">C$ {{ number_format($resumen['total'], 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                @endif
            </div>

            <p style="font-size:11px; color:#6c757d; padding:10px 4px; line-height:1.5;">
                Solo se incluyen cuentas COBRADAS (PAGADA) con propina mayor a cero dentro del período
                Desde/Hasta (ambos días incluidos completos); se excluyen las cuentas ABIERTA y
                CANCELADA. La fecha del período es la de EMISIÓN DE LA FACTURA
                (<code>facturas.fecha_emision</code>), que se registra al cobrar y no cambia después;
                NO se usa la última modificación de la cuenta, porque editar una cuenta antigua no la
                mueve de día. La propina se toma una sola vez por cuenta (no se multiplica por detalles
                ni por pagos). El sistema no registra al mesero que atendió la mesa, por eso el resumen
                es general del período y no se atribuye por persona. La propina cobrada al cliente NO es
                un gasto operativo.
            </p>

        @endif
    </div>
</div>
@endsection
