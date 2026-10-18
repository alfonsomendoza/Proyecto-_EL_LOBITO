<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura {{ $factura->numero_factura }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Courier New', Courier, monospace; }
        body { width: 100%; max-width: 300px; margin: 0 auto; padding: 10px; font-size: 12px; color: #000; }
        .centro { text-align: center; }
        .derecha { text-align: right; }
        .linea { border-top: 1px dashed #000; margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 0; vertical-align: top; }
        h2 { font-size: 16px; margin-bottom: 3px; }
        p { font-size: 11px; margin-bottom: 2px; }
        .resaltado { font-weight: bold; font-size: 13px; }
        .anulada { color: #b00; font-weight: bold; }
    </style>
</head>
<body>
    <div class="centro">
        <h2>RESTAURANTE EL LOBITO</h2>
        <p>FACTURA DE VENTA</p>
        <p><strong>{{ $factura->numero_factura }}</strong></p>
        @if ($factura->estado === 'ANULADA')
            <p class="anulada">*** FACTURA ANULADA ***</p>
        @endif
    </div>

    <div class="linea"></div>

    <table>
        <tr><td><strong>Fecha:</strong></td><td class="derecha">{{ $factura->fecha_emision ? \Illuminate\Support\Carbon::parse($factura->fecha_emision)->format('d/m/Y H:i') : '--' }}</td></tr>
        <tr><td><strong>Cajero:</strong></td><td class="derecha">{{ $factura->cajero ?? '--' }}</td></tr>
        <tr><td><strong>Mesa:</strong></td><td class="derecha">{{ $factura->mesa }}</td></tr>
        <tr><td><strong>Método Pago:</strong></td><td class="derecha">{{ $factura->metodo_pago ?? 'EFECTIVO' }}@if (!empty($factura->banco)) · {{ $factura->banco }}@endif</td></tr>
    </table>

    <div class="linea"></div>

    <p><strong>Detalle del Consumo:</strong></p>
    @if (!empty($lineas))
        {{-- Facturas emitidas por el POS Laravel: detalle real por línea. --}}
        <table style="margin-top: 6px;">
            @foreach ($lineas as $linea)
                <tr>
                    <td>{{ (int) ($linea['cantidad'] ?? 0) }}x {{ $linea['producto'] ?? '--' }}</td>
                    <td class="derecha">{{ number_format((float) ($linea['subtotal'] ?? 0), 2) }}</td>
                </tr>
            @endforeach
        </table>
    @else
        {{-- Facturas históricas del PHP puro: solo traían un resumen en texto. --}}
        <p style="margin-top: 6px; font-size: 12px; line-height: 1.4;">{!! nl2br(e($textoConsumo)) !!}</p>
    @endif

    <div class="linea"></div>

    <table>
        <tr>
            <td class="resaltado">TOTAL A PAGAR:</td>
            <td class="derecha resaltado">C$ {{ number_format((float) $factura->total, 2) }}</td>
        </tr>
        @if (!empty($factura->monto_recibido) && (float) $factura->monto_recibido > 0)
            <tr>
                <td>Efectivo Recibido:</td>
                <td class="derecha">C$ {{ number_format((float) $factura->monto_recibido, 2) }}</td>
            </tr>
            <tr>
                <td>Cambio Entregado:</td>
                <td class="derecha">C$ {{ number_format((float) $factura->cambio, 2) }}</td>
            </tr>
        @endif
    </table>

    <div class="linea"></div>
    <div class="centro" style="margin-top: 10px;">
        <p>¡Gracias por su preferencia!</p>
        <p>Conserve este ticket para cualquier reclamo.</p>
    </div>

    <script>
        window.onload = function () { window.print(); };
    </script>
</body>
</html>
