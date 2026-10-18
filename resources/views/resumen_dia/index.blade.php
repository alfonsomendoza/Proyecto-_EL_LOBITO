@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 1000px; margin: 0 auto;">
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <span><i class="fas fa-box"></i> Operaciones — Resumen del Día</span>
        @if ($resumen['exito'] ?? false)
            <a href="{{ route('reportes.resumen.ticket', ['fecha' => $fecha, 'cajero' => $resumen['cajero']]) }}" target="_blank"
               style="background-color:#495057; color:#fff; padding:6px 12px; border-radius:4px; font-weight:bold; font-size:12px; text-decoration:none;">
                <i class="fas fa-print"></i> Imprimir resumen
            </a>
        @endif
    </div>

    <div style="padding: 25px;">

        {{-- Filtros: fecha y cajero (varios turnos del mismo día). --}}
        <form method="GET" action="{{ route('reportes.resumen') }}"
              style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; border:1px solid #e3e6f0; border-radius:8px; padding:14px 16px; margin-bottom:22px; background:#fbfbfd;">
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="fecha" style="font-size:0.85rem; font-weight:bold; color:#555;">Fecha:</label>
                <input type="date" id="fecha" name="fecha" value="{{ $fecha }}"
                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px; min-width:220px;">
                <label for="cajero" style="font-size:0.85rem; font-weight:bold; color:#555;">Cajero:</label>
                <select id="cajero" name="cajero" style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
                    <option value="TODOS">TODOS</option>
                    @foreach ($cajeros as $nombreCajero)
                        <option value="{{ $nombreCajero }}" {{ ($cajero ?? '') === $nombreCajero ? 'selected' : '' }}>
                            {{ $nombreCajero }}
                        </option>
                    @endforeach
                </select>
            </div>
            <button type="submit"
                    style="background-color:#6777ef; color:#fff; border:none; padding:9px 18px; border-radius:5px; font-weight:bold; cursor:pointer;">
                <i class="fas fa-search"></i> Consultar
            </button>
        </form>

        @if (! ($resumen['exito'] ?? false))
            <div style="background:#fff7e6; border:1px solid #f5d9a8; border-radius:6px; padding:14px 16px; font-size:13px; color:#7a5a1a; line-height:1.5;">
                <i class="fas fa-exclamation-triangle"></i>
                {{ $resumen['error'] ?? 'No hay información para esta fecha.' }}
            </div>
        @else

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:18px;">

            {{-- Ventas clasificadas por forma de cobro. --}}
            <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden;">
                <div style="padding:10px 16px; background:#3498db; color:#fff; font-weight:600; font-size:14px;">
                    <i class="fas fa-cash-register"></i> Ventas por Forma de Cobro
                </div>
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 14px;">Fondo de apertura:</td><td style="padding:8px 14px; text-align:right;">C$ {{ number_format($resumen['total_monto_apertura'], 2) }}</td></tr>
                    <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 14px;">Efectivo C$:</td><td style="padding:8px 14px; text-align:right;">C$ {{ number_format($resumen['efectivo'], 2) }}</td></tr>
                    <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 14px;">Tarjeta:</td><td style="padding:8px 14px; text-align:right;">C$ {{ number_format($resumen['tarjeta'], 2) }}</td></tr>
                    @foreach ($resumen['tarjetas_por_banco'] as $banco => $montoBanco)
                        <tr style="border-bottom:1px solid #f8f9fa;"><td style="padding:6px 14px 6px 28px; color:#6c757d;">— {{ $banco }}:</td><td style="padding:6px 14px; text-align:right; color:#6c757d;">C$ {{ number_format($montoBanco, 2) }}</td></tr>
                    @endforeach
                    <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 14px;">Transferencia:</td><td style="padding:8px 14px; text-align:right;">C$ {{ number_format($resumen['transferencia'], 2) }}</td></tr>
                    @if ($resumen['otro'] > 0)
                        <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 14px;">Otros:</td><td style="padding:8px 14px; text-align:right;">C$ {{ number_format($resumen['otro'], 2) }}</td></tr>
                    @endif
                    <tr style="background:#f8f9fa; font-weight:bold;"><td style="padding:10px 14px;">TOTAL VENTAS DEL DÍA:</td><td style="padding:10px 14px; text-align:right;">C$ {{ number_format($resumen['total_ventas'], 2) }}</td></tr>
                    <tr style="border-top:1px solid #f1f1f1; color:#721c24;"><td style="padding:8px 14px;">Ventas anuladas:</td><td style="padding:8px 14px; text-align:right;">C$ {{ number_format($resumen['ventas_anuladas'], 2) }} ({{ $resumen['cantidad_ventas_anuladas'] }})</td></tr>
                    <tr style="color:#6c757d;"><td style="padding:8px 14px;">Cuentas cobradas:</td><td style="padding:8px 14px; text-align:right;">{{ $resumen['cantidad_cuentas'] }}</td></tr>
                </table>
            </div>

            {{-- Gastos y efectivo esperado. --}}
            <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden;">
                <div style="padding:10px 16px; background:#e67e22; color:#fff; font-weight:600; font-size:14px;">
                    <i class="fas fa-file-invoice-dollar"></i> Gastos y Efectivo Esperado
                </div>
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 14px;">Gastos activos:</td><td style="padding:8px 14px; text-align:right; color:#c0392b;">C$ {{ number_format($resumen['gastos_activos'], 2) }}</td></tr>
                    <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 14px;">Otros ingresos:</td><td style="padding:8px 14px; text-align:right;">C$ {{ number_format($resumen['otros_ingresos'], 2) }}</td></tr>
                    <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 14px;">Retiros:</td><td style="padding:8px 14px; text-align:right;">C$ {{ number_format($resumen['retiros'], 2) }}</td></tr>
                    <tr style="border-bottom:1px solid #f1f1f1; color:#6c757d;"><td style="padding:8px 14px;">Gastos anulados (informativo):</td><td style="padding:8px 14px; text-align:right;">C$ {{ number_format($resumen['gastos_anulados'], 2) }} ({{ $resumen['cantidad_gastos_anulados'] }})</td></tr>
                    <tr style="border-bottom:1px solid #f1f1f1; color:#6c757d;"><td style="padding:8px 14px;">Cambios entregados (informativo):</td><td style="padding:8px 14px; text-align:right;">C$ {{ number_format($resumen['cambios_entregados'], 2) }}</td></tr>
                    <tr style="background:#fff8e1; font-weight:bold; font-size:15px;"><td style="padding:12px 14px;">EFECTIVO ESPERADO:</td><td style="padding:12px 14px; text-align:right;">C$ {{ number_format($resumen['efectivo_esperado'], 2) }}</td></tr>
                </table>
                <p style="font-size:11px; color:#6c757d; padding:10px 14px; line-height:1.4;">
                    Fondo de apertura + efectivo C$ + otros ingresos − gastos activos − retiros.
                    Las tarjetas y transferencias no entran a la gaveta y los cambios no se
                    descuentan (el POS ya retiene el neto).
                </p>
            </div>
        </div>

        {{-- Detalle por turno. --}}
        <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden; margin-top:22px;">
            <div style="padding:12px 16px; background:#34495e; color:#fff; font-weight:600; font-size:14px;">
                <i class="fas fa-list-alt"></i> Turnos del Día ({{ $resumen['cantidad_turnos'] }})
            </div>
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#f4f6f9; color:#495057; text-align:left;">
                        <th style="padding:10px 12px;">Turno</th>
                        <th style="padding:10px 12px;">Cajero</th>
                        <th style="padding:10px 12px; text-align:center;">Estado</th>
                        <th style="padding:10px 12px; text-align:right;">Fondo</th>
                        <th style="padding:10px 12px; text-align:right;">Efectivo</th>
                        <th style="padding:10px 12px; text-align:right;">Tarjeta</th>
                        <th style="padding:10px 12px; text-align:right;">Transfer.</th>
                        <th style="padding:10px 12px; text-align:right;">Gastos</th>
                        <th style="padding:10px 12px; text-align:right;">Esperado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($resumen['turnos'] as $detalle)
                        @php
                            $turno = $detalle['apertura'];
                            $ventaTurno = $detalle['ventas'];
                            $esperadoTurno = (float) $turno->monto_inicial + $ventaTurno['efectivo'] + $detalle['otros_ingresos'] - $detalle['egresos'] - $detalle['retiros'];
                        @endphp
                        <tr style="border-bottom:1px solid #f1f1f1;">
                            <td style="padding:10px 12px;">
                                <strong>{{ $turno->turno ?? 'Turno' }}</strong>
                                <div style="font-size:11px; color:#6c757d;">
                                    {{ $detalle['inicio'] ? $detalle['inicio']->format('d/m H:i') : '--' }}
                                    @if ($turno->estado === 'CERRADA')
                                        → {{ $detalle['cierre'] && $detalle['cierre']->fecha ? $detalle['cierre']->fecha->format('H:i') : 'fin' }}
                                    @endif
                                </div>
                            </td>
                            <td style="padding:10px 12px;">{{ $turno->cajero ?? '--' }}</td>
                            <td style="padding:10px 12px; text-align:center;">
                                @if ($turno->estado === 'ABIERTA')
                                    <span style="background:#d1ecf1; color:#0c5460; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">ABIERTO</span>
                                @else
                                    <span style="background:#e2e3e5; color:#383d41; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">CERRADO</span>
                                @endif
                            </td>
                            <td style="padding:10px 12px; text-align:right;">C$ {{ number_format((float) $turno->monto_inicial, 2) }}</td>
                            <td style="padding:10px 12px; text-align:right; font-weight:bold;">C$ {{ number_format($ventaTurno['efectivo'], 2) }}</td>
                            <td style="padding:10px 12px; text-align:right;">C$ {{ number_format($ventaTurno['tarjeta'], 2) }}</td>
                            <td style="padding:10px 12px; text-align:right;">C$ {{ number_format($ventaTurno['transferencia'], 2) }}</td>
                            <td style="padding:10px 12px; text-align:right; color:#c0392b;">C$ {{ number_format($detalle['egresos'], 2) }}</td>
                            <td style="padding:10px 12px; text-align:right; font-weight:bold;">C$ {{ number_format($esperadoTurno, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @endif
    </div>
</div>
@endsection
