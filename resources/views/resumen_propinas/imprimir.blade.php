@php
    $money = fn ($valor) => 'C$ '.number_format((float) $valor, 2, '.', ',');
    $total = (float) ($resumen['total'] ?? 0);
    $atribuido = (float) ($resumen['atribuido'] ?? 0);
    $pendiente = (float) ($resumen['pendiente'] ?? 0);
    $participacion = fn ($valor) => $total > 0 ? round(((float) $valor) * 100 / $total, 1) : 0.0;
    $opciones = $resumen['opciones'] ?? ['meseros' => [], 'usuarios' => [], 'mesas' => [], 'turnos' => [], 'cobradores' => []];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resumen de Propinas {{ $desde }} → {{ $hasta }}</title>
    <style>
        @page { size: portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Verdana, sans-serif; color: #212529; font-size: 11px; margin: 0; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        h2 { font-size: 12px; margin: 16px 0 6px; padding-bottom: 4px; border-bottom: 1px solid #ced4da; text-transform: uppercase; letter-spacing: .5px; color: #34495e; }
        .muted { color: #6c757d; }
        .meta { margin-bottom: 10px; }
        .meta div { margin: 2px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #dee2e6; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background: #f4f6f9; font-size: 10px; text-transform: uppercase; letter-spacing: .3px; }
        td.num, th.num { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: bold; background: #f8f9fa; }
        .kpis { display: flex; flex-wrap: wrap; gap: 6px; }
        .kpi { border: 1px solid #dee2e6; border-radius: 6px; padding: 6px 10px; min-width: 120px; }
        .kpi .label { font-size: 9px; text-transform: uppercase; letter-spacing: .4px; color: #6c757d; }
        .kpi .value { font-size: 14px; font-weight: bold; margin-top: 2px; }
        .aviso { border: 1px solid #f5d9a8; background: #fff7e6; color: #7a5a1a; padding: 6px 10px; border-radius: 6px; margin: 8px 0; }
        .notas { margin-top: 14px; font-size: 9px; color: #6c757d; line-height: 1.5; }
        .firma { margin-top: 26px; display: flex; gap: 40px; }
        .firma div { flex: 1; border-top: 1px solid #495057; padding-top: 4px; font-size: 9px; color: #6c757d; }
        .acciones { margin-bottom: 12px; }
        .acciones button, .acciones a { font: inherit; padding: 6px 12px; border: 1px solid #ced4da; background: #f4f6f9; border-radius: 4px; cursor: pointer; text-decoration: none; color: #212529; }
        .no-print { display: block; }
        @media print {
            .no-print { display: none !important; }
            tr { page-break-inside: avoid; }
            thead { display: table-header-group; }
        }
    </style>
</head>
<body>

<div class="acciones no-print">
    <button type="button" onclick="window.print()">Imprimir</button>
    <a href="{{ route('reportes.propinas', ['desde' => $desde, 'hasta' => $hasta]) }}">Volver al resumen</a>
    <a href="{{ route('reportes.propinas.csv', request()->query()) }}">Exportar CSV</a>
</div>

<div class="meta">
    <h1>Caja y Restaurante «El Lobito»</h1>
    <div class="muted">Resumen de Propinas</div>
    <div><strong>Período:</strong> {{ $desde }} → {{ $hasta }}</div>
    <div><strong>Filtros:</strong>
        @if (empty($chips))
            Ninguno
        @else
            {{ implode(' · ', array_column($chips, 'texto')) }}
        @endif
    </div>
    <div class="muted">Generado: {{ now()->format('d/m/Y H:i:s') }}</div>
</div>

@if (! ($resumen['exito'] ?? false))
    <div class="aviso">{{ $resumen['error'] ?? 'No hay información para el período consultado.' }}</div>
@else

    <h2>Resumen general</h2>
    <div class="kpis">
        <div class="kpi">
            <div class="label">Propinas cobradas al cliente</div>
            <div class="value">{{ $money($total) }}</div>
        </div>
        <div class="kpi">
            <div class="label">Atribuido a meseros</div>
            <div class="value">{{ $money($atribuido) }}</div>
        </div>
        <div class="kpi">
            <div class="label">Pendiente de atribución</div>
            <div class="value">{{ $money($pendiente) }}</div>
        </div>
        <div class="kpi">
            <div class="label">Cuentas con propina</div>
            <div class="value">{{ $resumen['cantidad'] }}</div>
        </div>
        <div class="kpi">
            <div class="label">Meseros participantes</div>
            <div class="value">{{ $resumen['meseros_participantes'] }}</div>
        </div>
    </div>

    <div class="muted" style="margin-top:8px;">
        Conciliación: {{ $money($atribuido) }} atribuido + {{ $money($pendiente) }} pendiente =
        <strong>{{ $money($atribuido + $pendiente) }}</strong> (igual a las propinas cobradas).
    </div>

    <div class="aviso">
        Cuentas pagadas sin factura vigente en el período: <strong>{{ (int) ($resumen['cuentas_sin_factura'] ?? 0) }}</strong>.
        No se suman al total.
    </div>

    <h2>Desglose por mesero</h2>
    @if ($resumen['resumen_meseros']->isEmpty())
        <div class="muted">Sin propinas para los filtros seleccionados.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Mesero</th>
                    <th>Estado</th>
                    <th class="num">Mesas</th>
                    <th class="num">Cuentas</th>
                    <th class="num">Consumo</th>
                    <th class="num">Propinas</th>
                    <th class="num">Participación</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($resumen['resumen_meseros'] as $fila)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($fila['fecha'])->format('d/m/Y') }}</td>
                        <td>{{ $fila['mesero'] }}</td>
                        <td>{{ $fila['pendiente'] ? 'Pendiente de atribución' : 'Atribuida' }}</td>
                        <td class="num">{{ $fila['mesas_atendidas'] }}</td>
                        <td class="num">{{ $fila['cuentas_cobradas'] }}</td>
                        <td class="num">{{ $money($fila['consumo']) }}</td>
                        <td class="num">{{ $money($fila['propinas']) }}</td>
                        <td class="num">{{ number_format($participacion($fila['propinas']), 1) }} %</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6">Total atribuido + pendiente</td>
                    <td class="num">{{ $money($total) }}</td>
                    <td class="num">100.0 %</td>
                </tr>
            </tfoot>
        </table>
    @endif

    <h2>Detalle de cuentas</h2>
    @if ($resumen['detalle']->isEmpty())
        <div class="muted">No se registraron propinas cobradas en el período {{ $desde }} → {{ $hasta }}.</div>
    @else
        <table>
            <thead>
                <tr>
                    <th>Fecha emisión</th>
                    <th>Factura</th>
                    <th>Mesa</th>
                    <th>Cuenta</th>
                    <th>Mesero</th>
                    <th>Abrió</th>
                    <th>Cobró</th>
                    <th>Turno</th>
                    <th class="num">Consumo</th>
                    <th class="num">Propina</th>
                    <th>Atribución</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($resumen['detalle'] as $linea)
                    <tr>
                        <td>{{ $linea['fecha'] ? \Illuminate\Support\Carbon::parse($linea['fecha'])->format('d/m/Y H:i') : '—' }}</td>
                        <td>{{ $linea['numero_factura'] ?? '—' }}</td>
                        <td>{{ $linea['mesa_nombre'] ?: '—' }}</td>
                        <td>{{ $linea['cuenta_nombre'] }} #{{ $linea['cuenta_id'] }}</td>
                        <td>{{ $linea['mesero_atendio'] ?? 'No disponible' }}</td>
                        <td>{{ $linea['usuario_abre_mesa'] ?? 'No disponible' }}</td>
                        <td>{{ $linea['usuario_cobro'] ?? 'No disponible' }}</td>
                        <td>{{ $linea['turno'] ?? '—' }}</td>
                        <td class="num">{{ $money($linea['monto_consumo'] ?? 0) }}</td>
                        <td class="num">{{ $money($linea['propina']) }}</td>
                        <td>
                            @if ($linea['atribucion'] === 'ATRIBUIDA')
                                {{ $linea['mesero_atribuido'] }}
                            @else
                                Pendiente ({{ $linea['motivo_pendiente'] === 'varios_meseros' ? 'varios meseros' : 'sin mesero' }})
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="9">Total cobrado</td>
                    <td class="num">{{ $money($total) }}</td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    @endif

    <div class="firma">
        <div>Revisado por</div>
        <div>Entregado a</div>
        <div>Recibido por</div>
    </div>

    <div class="notas">
        Solo cuentas PAGADAS con propina mayor a cero y factura EMITIDA; se excluye la última factura anulada y se toma
        una sola factura vigente por cuenta. El período corresponde a la fecha de emisión de la factura. La atribución
        solo aplica a usuarios con rol MESERO enviados en comandas; si hay varios meseros en la venta, la propina queda
        pendiente y no se reparte. El pago de propinas al personal no está registrado en el sistema. Documento de solo
        lectura.
    </div>

@endif

</body>
</html>
