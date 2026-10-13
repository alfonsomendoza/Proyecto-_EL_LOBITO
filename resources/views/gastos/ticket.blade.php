<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket de Gastos del Turno</title>
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
    </style>
</head>
<body>
    <div class="centro">
        <h2>RESTAURANTE EL LOBITO</h2>
        <p>REPORTE DE EGRESOS / GASTOS</p>
        @if ($apertura)
            <p>Turno Apertura #{{ $apertura->id }}</p>
        @endif
    </div>

    <div class="linea"></div>

    @if ($apertura)
        <p><strong>Fecha Apertura:</strong> {{ \Carbon\Carbon::parse($apertura->fecha)->format('Y-m-d H:i:s') }}</p>
    @endif
    <p><strong>Fecha Impresión:</strong> {{ now()->format('Y-m-d H:i:s') }}</p>

    <div class="linea"></div>

    @if ($gastos->isEmpty())
        <div class="centro" style="padding: 15px; color: #555;">
            <p>No se registran salidas de dinero activas en este turno.</p>
        </div>
    @else
        <table>
            <thead>
                <tr style="border-bottom: 1px solid #000;">
                    <th style="text-align:left;">Concepto</th>
                    <th style="text-align:right;">Monto</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($gastos as $gasto)
                    <tr>
                        <td>
                            {{ $gasto->concepto }}
                            <br><small style="font-size: 9px; color: #444;">({{ $gasto->usuario->nombre ?? 'Cajero' }})</small>
                        </td>
                        <td class="derecha">C$ {{ number_format((float) $gasto->monto, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="linea"></div>

    <table>
        <tr>
            <td class="resaltado">TOTAL EGRESOS:</td>
            <td class="derecha resaltado">C$ {{ number_format($totalActivos, 2) }}</td>
        </tr>
    </table>

    <div class="linea"></div>
    <div class="centro" style="margin-top: 10px;">
        <p>Fin del reporte de gastos</p>
    </div>

    <script>
        window.onload = function () {
            window.print();
        };
    </script>
</body>
</html>
