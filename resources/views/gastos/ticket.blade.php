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
        <p>Filtro: {{ ['activos' => 'Activos', 'anulados' => 'Anulados', 'todos' => 'Todos'][$filtro] ?? 'Activos' }}</p>
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

    @php
        // Para el reporte "Todos" separamos visualmente activos y anulados.
        $gastosActivos = $gastos->where('estado', 'ACTIVO')->values();
        $gastosAnulados = $gastos->where('estado', 'ANULADO')->values();
    @endphp

    @if ($gastos->isEmpty())
        <div class="centro" style="padding: 15px; color: #555;">
            @if ($filtro === 'anulados')
                <p>No se registran salidas de dinero anuladas en este turno.</p>
            @elseif ($filtro === 'todos')
                <p>No se registran salidas de dinero en este turno.</p>
            @else
                <p>No se registran salidas de dinero activas en este turno.</p>
            @endif
        </div>
    @elseif ($filtro === 'todos')
        {{-- Reporte completo: activos y anulados claramente separados. --}}
        <div class="centro" style="margin: 6px 0;"><strong>--- GASTOS ACTIVOS ---</strong></div>
        @if ($gastosActivos->isEmpty())
            <p class="centro" style="color:#555;">(No hay gastos activos)</p>
        @else
            <table>
                <tbody>
                    @foreach ($gastosActivos as $gasto)
                        <tr>
                            <td>
                                {{ $gasto->concepto }}
                                <br><small style="font-size: 9px; color: #060;">{{ $gasto->usuario->nombre ?? 'Cajero' }} · {{ $gasto->fecha ? $gasto->fecha->format('d/m H:i') : '--' }} · [ACTIVO]</small>
                            </td>
                            <td class="derecha">C$ {{ number_format((float) $gasto->monto, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="linea"></div>
        <div class="centro" style="margin: 6px 0; color:#a00;"><strong>--- GASTOS ANULADOS ---</strong></div>
        @if ($gastosAnulados->isEmpty())
            <p class="centro" style="color:#555;">(No hay gastos anulados)</p>
        @else
            <table>
                <tbody>
                    @foreach ($gastosAnulados as $gasto)
                        <tr>
                            <td>
                                {{ $gasto->concepto }}
                                <br><small style="font-size: 9px; color: #a00;">{{ $gasto->usuario->nombre ?? 'Cajero' }} · {{ $gasto->fecha ? $gasto->fecha->format('d/m H:i') : '--' }} · [ANULADO]</small>
                            </td>
                            <td class="derecha">C$ {{ number_format((float) $gasto->monto, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @else
        <table>
            <thead>
                <tr style="border-bottom: 1px solid #000;">
                    <th style="text-align:left;">Concepto</th>
                    @if ($filtro !== 'activos')
                        <th style="text-align:center;">Estado</th>
                    @endif
                    <th style="text-align:right;">Monto</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($gastos as $gasto)
                    <tr>
                        <td>
                            {{ $gasto->concepto }}
                            <br><small style="font-size: 9px; color: #444;">({{ $gasto->usuario->nombre ?? 'Cajero' }} · {{ $gasto->fecha ? $gasto->fecha->format('d/m H:i') : '--' }})</small>
                        </td>
                        @if ($filtro !== 'activos')
                            <td style="text-align:center; font-size:10px; font-weight:bold; color:{{ $gasto->estado === 'ANULADO' ? '#a00' : '#060' }};">{{ $gasto->estado }}</td>
                        @endif
                        <td class="derecha">C$ {{ number_format((float) $gasto->monto, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="linea"></div>

    <table>
        <tr>
            <td class="resaltado">TOTAL EGRESOS (activos):</td>
            <td class="derecha resaltado">C$ {{ number_format($totalActivos, 2) }}</td>
        </tr>
        @if ($filtro !== 'activos')
            <tr>
                <td class="resaltado" style="color:#a00;">SUBTOTAL ANULADOS (informativo):</td>
                <td class="derecha resaltado" style="color:#a00;">C$ {{ number_format($totalAnulados, 2) }}</td>
            </tr>
        @endif
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
