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
    @php
        // Etiquetas legibles para los métodos reales de `pagos_cuenta`.
        $etiquetasPago = [
            'EFECTIVO' => 'Efectivo',
            'EFECTIVO_CORDOBA' => 'Efectivo C$',
            'EFECTIVO_USD' => 'Efectivo USD',
            'TARJETA_DEBITO' => 'Tarjeta de débito',
            'TARJETA_CREDITO' => 'Tarjeta de crédito',
        ];

        // Desglose persistido por FacturaService al cobrar. Son los mismos
        // conceptos que muestra la prefactura (consumo, descuento, base,
        // propina y total); aquí NO se recalculan.
        $tieneAjustes = $ajustes !== [];
        $consumo = (float) ($ajustes['consumo'] ?? 0);
        $descuento = (float) ($ajustes['descuento'] ?? 0);
        $base = (float) ($ajustes['base'] ?? 0);
        $propina = (float) ($ajustes['propina'] ?? 0);

        // Importe recibido y cambio: salen de `cuentas` (cobro del POS Laravel)
        // o de `ventas` (facturas históricas), nunca se recalculan aquí.
        $recibido = (float) ($factura->monto_recibido ?? 0);
        $cambio = (float) ($factura->cambio ?? 0);
    @endphp

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
        @if ($cuentaNombre || !empty($factura->cuenta_id))
            <tr>
                <td><strong>Cuenta:</strong></td>
                <td class="derecha">
                    {{ $cuentaNombre ?? 'Cuenta' }}@if (!empty($factura->cuenta_id)) (#{{ $factura->cuenta_id }})@endif
                </td>
            </tr>
        @endif
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

    {{-- Mismos conceptos que la prefactura: consumo, descuento, base usada
         para la propina, propina y total final. --}}
    <table>
        @if ($tieneAjustes)
            <tr>
                <td>Consumo:</td>
                <td class="derecha">C$ {{ number_format($consumo, 2) }}</td>
            </tr>
            @if ($descuento > 0)
                <tr>
                    <td>Descuento:</td>
                    <td class="derecha">− C$ {{ number_format($descuento, 2) }}</td>
                </tr>
            @endif
            @if ($descuento > 0 || $propina > 0)
                <tr>
                    <td>Base (consumo − descuento):</td>
                    <td class="derecha">C$ {{ number_format($base, 2) }}</td>
                </tr>
            @endif
            @if ($propina > 0)
                <tr>
                    <td>Propina:</td>
                    <td class="derecha">+ C$ {{ number_format($propina, 2) }}</td>
                </tr>
            @endif
        @endif
        <tr>
            <td class="resaltado">TOTAL A PAGAR:</td>
            <td class="derecha resaltado">C$ {{ number_format((float) $factura->total, 2) }}</td>
        </tr>
    </table>

    @if ($pagos->isNotEmpty())
        <div class="linea"></div>
        <p><strong>Pagos registrados:</strong></p>
        <table style="margin-top: 4px;">
            @foreach ($pagos as $pago)
                <tr>
                    <td>
                        {{ $etiquetasPago[$pago->metodo_pago] ?? $pago->metodo_pago }}@if (!empty($pago->banco)) · {{ $pago->banco }}@endif:
                    </td>
                    <td class="derecha">
                        @if (($pago->moneda ?? 'NIO') === 'USD')
                            USD {{ number_format((float) $pago->monto, 2) }} × {{ number_format((float) $pago->tasa_cambio, 4) }} = C$ {{ number_format((float) $pago->monto_cordobas, 2) }}
                        @else
                            C$ {{ number_format((float) $pago->monto_cordobas, 2) }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($pagos->isNotEmpty() || $recibido > 0)
        <table style="margin-top: 4px;">
            <tr>
                <td>Importe recibido:</td>
                <td class="derecha">C$ {{ number_format($recibido, 2) }}</td>
            </tr>
            <tr>
                <td>Cambio entregado:</td>
                <td class="derecha">C$ {{ number_format($cambio, 2) }}</td>
            </tr>
        </table>
    @endif

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
