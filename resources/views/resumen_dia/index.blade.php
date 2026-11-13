@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 1000px; margin: 0 auto;">
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <span><i class="fas fa-box"></i> Operaciones — Resumen del Día</span>
        @if ($resumen['exito'] ?? false)
            <a href="{{ route('reportes.resumen.ticket', ['desde' => $fechaInicio, 'hasta' => $fechaFin, 'cajero' => $resumen['cajero']]) }}" target="_blank"
               style="background-color:#495057; color:#fff; padding:6px 12px; border-radius:4px; font-weight:bold; font-size:12px; text-decoration:none;">
                <i class="fas fa-print"></i> Imprimir resumen
            </a>
        @endif
    </div>

    <div style="padding: 25px;">

        {{-- Filtros: fechaDesde / fechaHasta / cajero. --}}
        <form method="GET" action="{{ route('reportes.resumen') }}"
              style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; border:1px solid #e3e6f0; border-radius:8px; padding:14px 16px; margin-bottom:22px; background:#fbfbfd;">
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="desde" style="font-size:0.85rem; font-weight:bold; color:#555;">Desde:</label>
                <input type="date" id="desde" name="desde" value="{{ $fechaInicio }}"
                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="hasta" style="font-size:0.85rem; font-weight:bold; color:#555;">Hasta:</label>
                <input type="date" id="hasta" name="hasta" value="{{ $fechaFin }}"
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
                {{ $resumen['error'] ?? 'No se encontraron aperturas de caja para el período seleccionado.' }}
            </div>
        @else

        <p style="font-size:12px; color:#6c757d; margin-bottom:14px;">
            Cada sección corresponde a una <strong>apertura de caja</strong> registrada en el período.
            Si la apertura está abierta, el resumen es <strong>provisional</strong>; si existe un cierre, es <strong>definitivo</strong>.
            Los importes no se combinan ni duplican entre aperturas.
        </p>

        @foreach ($resumen['turnos'] as $detalle)
            @php
                $turno = $detalle['apertura'];
                $cierre = $detalle['cierre'];
                $ventas = $detalle['ventas'];
                $anuladas = $detalle['ventas_anuladas'];
            @endphp
            <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden; margin-bottom:18px;">
                <div style="padding:10px 16px; background:#34495e; color:#fff; font-weight:600; font-size:14px; display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px;">
                    <span>
                        <i class="fas fa-cash-register"></i>
                        @if ($cierre)
                            Cierre #{{ $cierre->id }}
                        @else
                            CAJA ABIERTA
                        @endif
                        — {{ $turno->turno ?? 'Turno' }} —
                        {{ $turno->cajero ?: 'Sin cajero' }}
                    </span>
                    <span style="font-weight:400; font-size:12px; opacity:0.9;">
                        Abrió: {{ $detalle['abrio'] ?? '—' }} ·
                        @if ($cierre)
                            Cerró: {{ $detalle['cerro'] ?? '—' }} ·
                        @endif
                        Apertura: {{ $turno->fecha ? \Carbon\Carbon::parse($turno->fecha)->format('d/m/Y H:i') : '—' }}
                        @if ($cierre)
                            · Cierre: {{ \Carbon\Carbon::parse($cierre->fecha)->format('d/m/Y H:i') }}
                        @endif
                    </span>
                </div>

                <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:14px; padding:14px;">

                    {{-- Ventas por forma de cobro --}}
                    <div style="border:1px solid #e9ecef; border-radius:6px; overflow:hidden;">
                        <div style="padding:8px 12px; background:#3498db; color:#fff; font-weight:600; font-size:12px;">
                            <i class="fas fa-cash-register"></i> Ventas por Forma de Cobro
                        </div>
                        <table style="width:100%; border-collapse:collapse; font-size:13px;">
                            <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 12px;">Fondo de apertura:</td><td style="padding:8px 12px; text-align:right;">C$ {{ number_format((float) $turno->monto_inicial, 2) }}</td></tr>
                            <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 12px;">Efectivo C$:</td><td style="padding:8px 12px; text-align:right;">C$ {{ number_format((float) $ventas['efectivo'], 2) }}</td></tr>
                            <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 12px;">Tarjeta:</td><td style="padding:8px 12px; text-align:right;">C$ {{ number_format((float) $ventas['tarjeta'], 2) }}</td></tr>
                            @foreach ($ventas['tarjetas_por_banco'] as $banco => $montoBanco)
                                <tr style="border-bottom:1px solid #f8f9fa;"><td style="padding:6px 12px 6px 26px; color:#6c757d;">— {{ $banco }}:</td><td style="padding:6px 12px; text-align:right; color:#6c757d;">C$ {{ number_format((float) $montoBanco, 2) }}</td></tr>
                            @endforeach
                            <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 12px;">Transferencia:</td><td style="padding:8px 12px; text-align:right;">C$ {{ number_format((float) $ventas['transferencia'], 2) }}</td></tr>
                            @if ((float) $ventas['otro'] > 0)
                                <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 12px;">Otros:</td><td style="padding:8px 12px; text-align:right;">C$ {{ number_format((float) $ventas['otro'], 2) }}</td></tr>
                            @endif
                            <tr style="background:#f8f9fa; font-weight:bold;"><td style="padding:8px 12px;">TOTAL VENTAS:</td><td style="padding:8px 12px; text-align:right;">C$ {{ number_format((float) $ventas['total'], 2) }}</td></tr>
                            <tr style="color:#721c24; border-bottom:1px solid #f1f1f1;"><td style="padding:8px 12px;">Ventas anuladas:</td><td style="padding:8px 12px; text-align:right;">C$ {{ number_format((float) $anuladas['monto'], 2) }} ({{ (int) $anuladas['cantidad'] }})</td></tr>
                            <tr style="color:#6c757d;"><td style="padding:8px 12px;">Cuentas cobradas:</td><td style="padding:8px 12px; text-align:right;">{{ (int) $ventas['cantidad'] }}</td></tr>
                        </table>
                    </div>

                    {{-- Gastos y efectivo esperado --}}
                    <div style="border:1px solid #e9ecef; border-radius:6px; overflow:hidden;">
                        <div style="padding:8px 12px; background:#e67e22; color:#fff; font-weight:600; font-size:12px;">
                            <i class="fas fa-file-invoice-dollar"></i> Gastos y Efectivo Esperado
                        </div>
                        <table style="width:100%; border-collapse:collapse; font-size:13px;">
                            <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 12px;">Gastos activos:</td><td style="padding:8px 12px; text-align:right; color:#c0392b;">C$ {{ number_format((float) $detalle['gastos_activos'], 2) }}</td></tr>
                            <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 12px;">Otros ingresos:</td><td style="padding:8px 12px; text-align:right;">C$ {{ number_format((float) $detalle['otros_ingresos'], 2) }}</td></tr>
                            <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 12px;">Retiros:</td><td style="padding:8px 12px; text-align:right;">C$ {{ number_format((float) $detalle['retiros'], 2) }}</td></tr>
                            <tr style="border-bottom:1px solid #f1f1f1; color:#6c757d;"><td style="padding:8px 12px;">Gastos anulados (informativo):</td><td style="padding:8px 12px; text-align:right;">C$ {{ number_format((float) $detalle['gastos_anulados'], 2) }} ({{ (int) $detalle['cantidad_gastos_anulados'] }})</td></tr>
                            <tr style="border-bottom:1px solid #f1f1f1; color:#6c757d;"><td style="padding:8px 12px;">Cambios entregados (informativo):</td><td style="padding:8px 12px; text-align:right;">C$ {{ number_format((float) $detalle['cambios'], 2) }}</td></tr>
                            @if ($detalle['diferencia'] !== null)
                                <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 12px;">Diferencia de caja:</td><td style="padding:8px 12px; text-align:right; font-weight:bold; color:{{ ($detalle['diferencia'] ?? 0) < 0 ? '#c0392b' : '#1e7e34' }}">C$ {{ number_format((float) $detalle['diferencia'], 2) }}</td></tr>
                            @else
                                <tr style="border-bottom:1px solid #f1f1f1;"><td style="padding:8px 12px; color:#6c757d;">Diferencia de caja:</td><td style="padding:8px 12px; text-align:right; color:#6c757d; font-style:italic;">Pendiente (caja abierta)</td></tr>
                            @endif
                            <tr style="background:#fff8e1; font-weight:bold;"><td style="padding:8px 12px;">EFECTIVO ESPERADO:</td><td style="padding:8px 12px; text-align:right;">C$ {{ number_format((float) $turno->monto_inicial + (float) $ventas['efectivo'] + (float) $detalle['otros_ingresos'] - (float) $detalle['gastos_activos'] - (float) $detalle['retiros'], 2) }}</td></tr>
                        </table>
                    </div>

                    {{-- Propinas (detalle) --}}
                    <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden; margin:14px;">
                        <div style="padding:8px 14px; background:#8e44ad; color:#fff; font-weight:600; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">
                            <i class="fas fa-hand-holding-usd"></i> Propinas
                        </div>
                        <div style="padding:14px; background:#fff; font-size:13px;">
                            <div style="display:flex; flex-wrap:wrap; gap:14px 24px; border-bottom:1px solid #f1f1f1; padding-bottom:10px;">
                                <div style="min-width:140px;">
                                    <div style="font-size:11px; color:#6c757d; font-weight:600; text-transform:uppercase; letter-spacing:0.4px;">Cobradas</div>
                                    <div style="font-size:20px; font-weight:800; color:#191d21;">C$ {{ number_format((float) ($detalle['propinas']['total'] ?? 0), 2) }}</div>
                                    <div style="font-size:11px; color:#6c757d;">{{ (int) ($detalle['propinas']['cantidad'] ?? 0) }} cuentas</div>
                                </div>
                                <div style="min-width:140px;">
                                    <div style="font-size:11px; color:#6c757d; font-weight:600; text-transform:uppercase; letter-spacing:0.4px;">Atribuidas a meseros</div>
                                    <div style="font-size:20px; font-weight:800; color:#16a085;">C$ {{ number_format((float) ($detalle['propinas']['atribuidas'] ?? 0), 2) }}</div>
                                </div>
                                <div style="min-width:140px;">
                                    <div style="font-size:11px; color:#6c757d; font-weight:600; text-transform:uppercase; letter-spacing:0.4px;">Pendientes</div>
                                    <div style="font-size:20px; font-weight:800; color:#b02a37;">C$ {{ number_format((float) ($detalle['propinas']['pendientes'] ?? 0), 2) }}</div>
                                </div>
                                <div style="min-width:140px;">
                                    <div style="font-size:11px; color:#6c757d; font-weight:600; text-transform:uppercase; letter-spacing:0.4px;">Pago al personal</div>
                                    <div style="font-size:14px; font-weight:700; color:#6c757d; font-style:italic;">No registrado</div>
                                </div>
                            </div>
                            @php
                                $meserosCierre = $detalle['propinas']['meseros'] ?? collect();
                            @endphp
                            @if ($meserosCierre->isNotEmpty())
                                <div style="margin-top:10px;">
                                    <div style="font-size:11px; color:#6c757d; font-weight:600; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:6px;">Desglose por mesero</div>
                                    <table style="width:100%; border-collapse:collapse; font-size:13px;">
                                        @foreach ($meserosCierre as $rm)
                                            <tr style="border-bottom:1px solid #f1f1f1;">
                                                <td style="padding:6px 0;">{{ $rm['mesero'] }}</td>
                                                <td style="padding:6px 0; text-align:right; font-weight:700; color:#191d21;">C$ {{ number_format((float) $rm['propinas'], 2) }}</td>
                                                <td style="padding:6px 0; text-align:right; color:#6c757d;">{{ (int) ($rm['cuentas_cobradas'] ?? 0) }} cuentas</td>
                                            </tr>
                                        @endforeach
                                    </table>
                                </div>
                            @else
                                <div style="margin-top:10px; font-size:13px; color:#6c757d; font-style:italic;">
                                    Sin meseros con propina atribuida en este cierre.
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endforeach

        @endif
    </div>
</div>
@endsection
