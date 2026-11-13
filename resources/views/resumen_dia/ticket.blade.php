<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Resumen del Día</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Courier New', Courier, monospace; }
        body { width: 100%; max-width: 300px; margin: 0 auto; padding: 10px; font-size: 12px; color: #000; }
        .centro { text-align: center; }
        .derecha { text-align: right; }
        .linea { border-top: 1px dashed #000; margin: 10px 0; }
        .linea-doble { border-top: 2px solid #000; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 0; vertical-align: top; }
        h2 { font-size: 16px; margin-bottom: 5px; }
        p { font-size: 12px; margin-bottom: 3px; }
        .resaltado { font-weight: bold; font-size: 13px; }
    </style>
</head>
<body>
    <div class="centro">
        <h2>RESTAURANTE EL LOBITO</h2>
        <p>RESUMEN DEL DÍA</p>
        <p>Desde: {{ $fechaInicio }}  Hasta: {{ $fechaFin }}</p>
        <p>Cajero: {{ $cajero }}</p>
        <p>{{ (int) $resumen['cantidad_turnos'] }} cierre(s)</p>
    </div>

    @foreach ($resumen['turnos'] as $detalle)
        @php
            $turno = $detalle['apertura'];
            $cierre = $detalle['cierre'];
            $ventas = $detalle['ventas'];
            $anuladas = $detalle['ventas_anuladas'];
            $esperado = (float) $turno->monto_inicial + (float) $ventas['efectivo'] + (float) $detalle['otros_ingresos'] - (float) $detalle['gastos_activos'] - (float) $detalle['retiros'];
        @endphp

        <div class="linea-doble"></div>
        <div class="centro">
            <p><strong>CIERRE #{{ $cierre->id }} — {{ $turno->turno ?? 'Turno' }}</strong></p>
            <p>{{ $turno->cajero ?: 'Sin cajero' }}</p>
            <p style="font-size:10px;">
                Apertura {{ $turno->fecha ? \Carbon\Carbon::parse($turno->fecha)->format('d/m/Y H:i') : '—' }} ·
                Cierre {{ $cierre->fecha ? \Carbon\Carbon::parse($cierre->fecha)->format('d/m/Y H:i') : '—' }}
            </p>
        </div>

        <div class="linea"></div>
        <table>
            <tr><td>Fondo de Apertura:</td><td class="derecha">C$ {{ number_format((float) $turno->monto_inicial, 2) }}</td></tr>
        </table>

        <div class="linea"></div>
        <div class="centro"><p><strong>VENTAS CLASIFICADAS</strong></p></div>
        <table>
            <tr><td>Efectivo C$:</td><td class="derecha">C$ {{ number_format((float) $ventas['efectivo'], 2) }}</td></tr>
            <tr><td class="resaltado">Tarjeta:</td><td class="derecha resaltado">C$ {{ number_format((float) $ventas['tarjeta'], 2) }}</td></tr>
            @foreach ($ventas['tarjetas_por_banco'] as $banco => $montoBanco)
                <tr><td>&nbsp;&nbsp;{{ $banco }}:</td><td class="derecha">C$ {{ number_format((float) $montoBanco, 2) }}</td></tr>
            @endforeach
            <tr><td>Transferencia:</td><td class="derecha">C$ {{ number_format((float) $ventas['transferencia'], 2) }}</td></tr>
            @if ((float) $ventas['otro'] > 0)
                <tr><td>Otros:</td><td class="derecha">C$ {{ number_format((float) $ventas['otro'], 2) }}</td></tr>
            @endif
        </table>
        <div class="linea"></div>
        <table>
            <tr><td class="resaltado">TOTAL VENTAS:</td><td class="derecha resaltado">C$ {{ number_format((float) $ventas['total'], 2) }}</td></tr>
            <tr><td>Ventas Anuladas:</td><td class="derecha">C$ {{ number_format((float) $anuladas['monto'], 2) }} ({{ (int) $anuladas['cantidad'] }})</td></tr>
            <tr><td>Cuentas cobradas:</td><td class="derecha">{{ (int) $ventas['cantidad'] }}</td></tr>
        </table>

        <div class="linea"></div>
        <div class="centro"><p><strong>GASTOS</strong></p></div>
        <table>
            <tr><td>Gastos Activos:</td><td class="derecha">C$ {{ number_format((float) $detalle['gastos_activos'], 2) }}</td></tr>
            <tr><td>Otros Ingresos:</td><td class="derecha">C$ {{ number_format((float) $detalle['otros_ingresos'], 2) }}</td></tr>
            <tr><td>Retiros:</td><td class="derecha">C$ {{ number_format((float) $detalle['retiros'], 2) }}</td></tr>
            <tr><td>Gastos Anulados:</td><td class="derecha">C$ {{ number_format((float) $detalle['gastos_anulados'], 2) }} ({{ (int) $detalle['cantidad_gastos_anulados'] }})</td></tr>
            <tr><td>Cambios Entregados:</td><td class="derecha">C$ {{ number_format((float) $detalle['cambios'], 2) }}</td></tr>
            <tr><td>Diferencia de caja:</td><td class="derecha">C$ {{ number_format((float) $detalle['diferencia'], 2) }}</td></tr>
        </table>

        <div class="linea"></div>
        <table>
            <tr><td class="resaltado">EFECTIVO ESPERADO DEL CIERRE:</td></tr>
            <tr><td class="derecha resaltado" style="font-size:15px;">C$ {{ number_format($esperado, 2) }}</td></tr>
        </table>

        @if (($detalle['propinas']['disponible'] ?? false))
            <div class="linea"></div>
            <table>
                <tr><td>Propinas cobradas:</td><td class="derecha">C$ {{ number_format((float) ($detalle['propinas']['total'] ?? 0), 2) }}</td></tr>
                <tr><td>Cuentas con propina:</td><td class="derecha">{{ (int) ($detalle['propinas']['cantidad'] ?? 0) }}</td></tr>
                <tr><td>Atribuidas a meseros:</td><td class="derecha">C$ {{ number_format((float) ($detalle['propinas']['atribuidas'] ?? 0), 2) }}</td></tr>
                <tr><td>Pendientes:</td><td class="derecha">C$ {{ number_format((float) ($detalle['propinas']['pendientes'] ?? 0), 2) }}</td></tr>
                @foreach (($detalle['propinas']['meseros'] ?? collect()) as $rm)
                    <tr><td>&nbsp;&nbsp;{{ $rm['mesero'] }}:</td><td class="derecha">C$ {{ number_format((float) $rm['propinas'], 2) }}</td></tr>
                @endforeach
                <tr><td>Pagadas al personal:</td><td class="derecha">No registrado</td></tr>
            </table>
        @endif
    @endforeach

    <div class="linea-doble"></div>
    <div class="centro" style="margin-top: 20px; margin-bottom: 10px;">
        <p>_______________________</p>
        <p>Firma del Cajero / Administrador</p>
    </div>

    <script>
        window.onload = function () { window.print(); };
    </script>
</body>
</html>
