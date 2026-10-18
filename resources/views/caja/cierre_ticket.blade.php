<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket de Cierre</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Courier New', Courier, monospace; }
        body { width: 100%; max-width: 300px; margin: 0 auto; padding: 10px; font-size: 12px; color: #000; }
        .centro { text-align: center; }
        .derecha { text-align: right; }
        .linea { border-top: 1px dashed #000; margin: 10px 0; }
        .linea-doble { border-top: 2px solid #000; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 0; vertical-align: top; }
        .etiqueta { font-weight: bold; width: 45%; }
        h2 { font-size: 16px; margin-bottom: 5px; }
        p { font-size: 12px; margin-bottom: 3px; }
        .resaltado { font-weight: bold; font-size: 13px; }
        .anulado { text-decoration: line-through; color: #666; }
    </style>
</head>
<body>
    @php
        // conteo_json puede venir como { local: {...}, dolares: {...} } o, en
        // datos muy antiguos, solo con las denominaciones locales.
        $conteo = $cierre->conteo_json ?? [];
        if (array_key_exists('local', $conteo)) {
            $conteoLocal = $conteo['local'] ?? [];
            $conteoDolares = $conteo['dolares'] ?? [];
        } else {
            $conteoLocal = $conteo;
            $conteoDolares = [];
        }

        $etiquetas = [
            'EFECTIVO' => 'Efectivo C$',
            'EFECTIVO_CORDOBA' => 'Efectivo C$',
            'EFECTIVO_DOLAR' => 'Efectivo US$',
            'TARJETA' => 'Tarjeta',
            'TARJETA_DEBITO' => 'Tarjeta Débito',
            'TARJETA_CREDITO' => 'Tarjeta Crédito',
            'TRANSFERENCIA' => 'Transferencia',
        ];

        $filasConteo = function (array $denominaciones, string $simbolo): string {
            $html = '';
            $conteo = [];
            foreach ($denominaciones as $valor => $cantidad) {
                if ((int) $cantidad > 0) {
                    $conteo[(float) $valor] = (int) $cantidad;
                }
            }
            krsort($conteo, SORT_NUMERIC);
            if (empty($conteo)) {
                return '<tr><td colspan="3" style="text-align:center; color:#666;">Sin billetes contados</td></tr>';
            }
            foreach ($conteo as $valor => $cantidad) {
                $html .= '<tr><td>' . $simbolo . ' ' . number_format((float) $valor, 2) . '</td>'
                    . '<td class="derecha">x ' . $cantidad . '</td>'
                    . '<td class="derecha">' . $simbolo . ' ' . number_format(((float) $valor) * $cantidad, 2) . '</td></tr>';
            }
            return $html;
        };
    @endphp

    <div class="centro">
        <h2>RESTAURANTE EL LOBITO</h2>
        <p>CIERRE DE CAJA {{ $cierre->estado === 'ANULADO' ? '(ANULADO)' : '' }}</p>
        <p>Comprobante #{{ $cierre->id }}</p>
    </div>

    <div class="linea"></div>

    <table>
        <tr><td class="etiqueta">Fecha:</td><td class="derecha">{{ $cierre->fecha ? $cierre->fecha->format('d/m/Y H:i:s') : '--' }}</td></tr>
        <tr><td class="etiqueta">Cajero:</td><td class="derecha">{{ $cierre->cajero ?? '--' }}</td></tr>
        <tr><td class="etiqueta">Turno:</td><td class="derecha">{{ $cierre->turno ?? '--' }}</td></tr>
        <tr><td class="etiqueta">Caja N°:</td><td class="derecha">{{ $cierre->caja_id }}</td></tr>
    </table>

    <div class="linea"></div>

    <table>
        <tr><td colspan="2" class="resaltado centro">— EFECTIVO —</td></tr>
        <tr><td>Fondo Apertura:</td><td class="derecha">C$ {{ number_format((float) $cierre->monto_apertura, 2) }}</td></tr>
        <tr><td>Ventas Efectivo C$:</td><td class="derecha">C$ {{ number_format((float) $cierre->ventas_efectivo, 2) }}</td></tr>
        <tr><td>Otros Ingresos:</td><td class="derecha">C$ {{ number_format((float) $cierre->otros_ingresos, 2) }}</td></tr>
        <tr><td>Retiros:</td><td class="derecha">- C$ {{ number_format((float) $cierre->retiros, 2) }}</td></tr>
        <tr><td>Egresos/Gastos:</td><td class="derecha">- C$ {{ number_format((float) $cierre->egresos, 2) }}</td></tr>
    </table>
    <div class="linea"></div>
    <table>
        <tr><td class="resaltado">= Efectivo Esperado:</td><td class="derecha resaltado">C$ {{ number_format((float) $cierre->total_esperado, 2) }}</td></tr>
    </table>

    @if ($resumen && $resumen['cuentas']->isNotEmpty())
        <div class="linea"></div>
        <div class="centro"><p><strong>DETALLE DE VENTAS</strong></p></div>
        <table>
            @foreach ($resumen['cuentas'] as $cuenta)
                <tr>
                    <td>{{ $cuenta->nombre }}<br>
                        <small>{{ $etiquetas[$cuenta->metodo_pago] ?? ($cuenta->metodo_pago ?? 'Efectivo') }}@if (!empty($cuenta->banco)) · {{ $cuenta->banco }}@endif</small>
                    </td>
                    <td class="derecha">C$ {{ number_format((float) $cuenta->total, 2) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($resumen && ($resumen['tarjeta'] > 0 || $resumen['transferencia'] > 0))
        <div class="linea-doble"></div>
        <div class="centro"><p><strong>PAGOS QUE NO ENTRAN A LA GAVETA</strong></p></div>
        @if ($resumen['tarjeta'] > 0)
            <table>
                @foreach ($resumen['tarjetas_por_banco'] as $banco => $monto)
                    <tr><td>Tarjeta {{ $banco }}:</td><td class="derecha">C$ {{ number_format($monto, 2) }}</td></tr>
                @endforeach
                <tr><td class="resaltado">Total Tarjetas:</td><td class="derecha resaltado">C$ {{ number_format($resumen['tarjeta'], 2) }}</td></tr>
            </table>
        @endif
        @if ($resumen['transferencia'] > 0)
            <table>
                <tr><td class="resaltado">Transferencias:</td><td class="derecha resaltado">C$ {{ number_format($resumen['transferencia'], 2) }}</td></tr>
            </table>
        @endif
    @endif

    @if ($gastos->isNotEmpty())
        <div class="linea"></div>
        <div class="centro"><p><strong>DETALLE DE GASTOS</strong></p></div>
        <table>
            @foreach ($gastos as $gasto)
                <tr>
                    <td>{{ $gasto->concepto }}</td>
                    <td class="derecha">C$ {{ number_format((float) $gasto->monto, 2) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="linea"></div>
    <div class="centro"><p><strong>CONTEO DE CIERRE — CÓRDOBAS</strong></p></div>
    <table>{!! $filasConteo($conteoLocal, 'C$') !!}</table>

    @if (collect($conteoDolares)->filter(fn ($c) => (int) $c > 0)->isNotEmpty())
        <div class="linea"></div>
        <div class="centro"><p><strong>CONTEO DE CIERRE — DÓLARES</strong></p></div>
        <table>{!! $filasConteo($conteoDolares, '$') !!}</table>
    @endif

    <div class="linea"></div>
    <div class="centro"><p><strong>MONEDA EXTRANJERA</strong></p></div>
    <table>
        <tr><td>Monto en USD:</td><td class="derecha">$ {{ number_format((float) $cierre->monto_extranjero, 2) }}</td></tr>
        <tr><td>Tasa Aplicada:</td><td class="derecha">C$ {{ number_format((float) $cierre->tasa_cambio, 4) }}</td></tr>
    </table>

    <div class="linea-doble"></div>
    <table>
        <tr><td class="resaltado">Total Esperado:</td><td class="derecha resaltado">C$ {{ number_format((float) $cierre->total_esperado, 2) }}</td></tr>
        <tr><td>Total Físico C$:</td><td class="derecha">C$ {{ number_format((float) $cierre->total_contado, 2) }}</td></tr>
        <tr><td>Diferencia:</td><td class="derecha">C$ {{ number_format(abs((float) $cierre->diferencia), 2) }}</td></tr>
        <tr><td class="resaltado">Estado:</td><td class="derecha resaltado">{{ $cierre->estado }}</td></tr>
    </table>

    @if ($cierre->observaciones)
        <div class="linea"></div>
        <p><strong>Observaciones:</strong> {{ $cierre->observaciones }}</p>
    @endif

    <div class="linea"></div>
    <div class="centro" style="margin-top: 30px; margin-bottom: 10px;">
        <p>_______________________</p>
        <p>Firma del Cajero</p>
    </div>

    <script>
        window.onload = function () { window.print(); };
    </script>
</body>
</html>
