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
        <p>{{ $fecha }} — {{ $cajero }}</p>
        <p>{{ (int) $resumen['cantidad_turnos'] }} turno(s)</p>
    </div>

    <div class="linea"></div>

    <table>
        <tr><td>Fondo de Apertura:</td><td class="derecha">C$ {{ number_format($resumen['total_monto_apertura'], 2) }}</td></tr>
    </table>

    <div class="linea"></div>
    <div class="centro"><p><strong>VENTAS CLASIFICADAS</strong></p></div>
    <table>
        <tr><td>Efectivo C$:</td><td class="derecha">C$ {{ number_format($resumen['efectivo'], 2) }}</td></tr>
        <tr><td class="resaltado">Tarjeta:</td><td class="derecha resaltado">C$ {{ number_format($resumen['tarjeta'], 2) }}</td></tr>
        @foreach ($resumen['tarjetas_por_banco'] as $banco => $montoBanco)
            <tr><td>&nbsp;&nbsp;{{ $banco }}:</td><td class="derecha">C$ {{ number_format($montoBanco, 2) }}</td></tr>
        @endforeach
        <tr><td>Transferencia:</td><td class="derecha">C$ {{ number_format($resumen['transferencia'], 2) }}</td></tr>
        @if ($resumen['otro'] > 0)
            <tr><td>Otros:</td><td class="derecha">C$ {{ number_format($resumen['otro'], 2) }}</td></tr>
        @endif
    </table>
    <div class="linea"></div>
    <table>
        <tr><td class="resaltado">TOTAL VENTAS DEL DÍA:</td><td class="derecha resaltado">C$ {{ number_format($resumen['total_ventas'], 2) }}</td></tr>
        <tr><td>Ventas Anuladas:</td><td class="derecha">C$ {{ number_format($resumen['ventas_anuladas'], 2) }} ({{ (int) $resumen['cantidad_ventas_anuladas'] }})</td></tr>
    </table>

    <div class="linea"></div>
    <div class="centro"><p><strong>GASTOS</strong></p></div>
    <table>
        <tr><td>Gastos Activos:</td><td class="derecha">C$ {{ number_format($resumen['gastos_activos'], 2) }}</td></tr>
        <tr><td>Gastos Anulados:</td><td class="derecha">C$ {{ number_format($resumen['gastos_anulados'], 2) }} ({{ (int) $resumen['cantidad_gastos_anulados'] }})</td></tr>
        <tr><td>Otros Ingresos:</td><td class="derecha">C$ {{ number_format($resumen['otros_ingresos'], 2) }}</td></tr>
        <tr><td>Retiros:</td><td class="derecha">C$ {{ number_format($resumen['retiros'], 2) }}</td></tr>
        <tr><td>Cambios Entregados:</td><td class="derecha">C$ {{ number_format($resumen['cambios_entregados'], 2) }}</td></tr>
    </table>

    <div class="linea-doble"></div>
    <table>
        <tr><td class="resaltado">EFECTIVO ESPERADO DEL DÍA:</td></tr>
        <tr><td class="derecha resaltado" style="font-size:15px;">C$ {{ number_format($resumen['efectivo_esperado'], 2) }}</td></tr>
    </table>
    <p style="font-size:10px; color:#555; margin-top:6px;">
        (Fondo Apertura + Efectivo C$ + Otros Ingresos − Gastos Activos − Retiros.
        No incluye tarjeta: ese dinero nunca entra a la gaveta. Los cambios no se
        descuentan porque el POS ya retiene el neto.)
    </p>

    <div class="linea"></div>
    <div class="centro" style="margin-top: 20px; margin-bottom: 10px;">
        <p>_______________________</p>
        <p>Firma del Cajero / Administrador</p>
    </div>

    <script>
        window.onload = function () { window.print(); };
    </script>
</body>
</html>
