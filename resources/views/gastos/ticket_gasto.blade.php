<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Comprobante de Gasto</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Courier New', Courier, monospace; }
        body { width: 100%; max-width: 300px; margin: 0 auto; padding: 10px; font-size: 12px; color: #000; }
        .centro { text-align: center; }
        .derecha { text-align: right; }
        .linea { border-top: 1px dashed #000; margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 2px; vertical-align: top; font-size: 11px; }
        h2 { font-size: 15px; margin-bottom: 3px; }
        p { font-size: 11px; margin-bottom: 2px; }
        .resaltado { font-weight: bold; font-size: 12px; }
        .estado-anulado { color: #a00; font-weight: bold; font-size: 13px; letter-spacing: 1px; }
        .estado-activo { color: #060; font-weight: bold; font-size: 13px; letter-spacing: 1px; }
    </style>
</head>
<body>
    <div class="centro">
        <h2>RESTAURANTE EL LOBITO</h2>
        <p>COMPROBANTE DE GASTO</p>
    </div>

    <div class="linea"></div>

    @if ($apertura)
        <p><strong>Turno Apertura #</strong>{{ $apertura->id }}</p>
    @endif
    <p><strong>Gasto N.º</strong> {{ $gasto->id }}</p>
    <p><strong>Fecha Impresión:</strong> {{ now()->format('Y-m-d H:i:s') }}</p>

    <div class="linea"></div>

    <table>
        <tr>
            <td><strong>Concepto:</strong></td>
            <td class="derecha">{{ $gasto->concepto }}</td>
        </tr>
        <tr>
            <td><strong>Monto:</strong></td>
            <td class="derecha">C$ {{ number_format((float) $gasto->monto, 2) }}</td>
        </tr>
        <tr>
            <td><strong>Fecha:</strong></td>
            <td class="derecha">{{ $gasto->fecha ? $gasto->fecha->format('Y-m-d H:i:s') : '--' }}</td>
        </tr>
        <tr>
            <td><strong>Registrado por:</strong></td>
            <td class="derecha">{{ $gasto->usuario->nombre ?? 'Cajero' }}</td>
        </tr>
        <tr>
            <td><strong>Estado:</strong></td>
            <td class="derecha">
                <span class="{{ $gasto->estado === 'ANULADO' ? 'estado-anulado' : 'estado-activo' }}">
                    {{ $gasto->estado }}
                </span>
            </td>
        </tr>
    </table>

    <div class="linea"></div>

    @if ($gasto->estado === 'ANULADO')
        <div class="centro" style="padding: 8px 0;">
            <span class="estado-anulado">*** GASTO ANULADO ***</span>
            <p style="margin-top: 6px; color: #555;">Documento solo de consulta. No afecta los totales de caja.</p>
        </div>
    @else
        <div class="centro" style="padding: 8px 0;">
            <span class="estado-activo">GASTO ACTIVO (vigente)</span>
        </div>
    @endif

    <div class="linea"></div>
    <div class="centro" style="margin-top: 10px;">
        <p>Fin del comprobante</p>
    </div>

    <script>
        window.onload = function () {
            window.print();
        };
    </script>
</body>
</html>
