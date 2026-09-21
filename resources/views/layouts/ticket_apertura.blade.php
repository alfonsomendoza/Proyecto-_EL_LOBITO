<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket Apertura</title>
    <style>
        body { font-family: 'Courier New', Courier, monospace; font-size: 14px; width: 300px; margin: 0 auto; padding: 10px; color: #000; }
        .centro { text-align: center; }
        .linea { border-top: 1px dashed #000; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 2px 0; }
        .derecha { text-align: right; }
    </style>
</head>
<body>
    <div class="centro">
        <h2>RESTAURANTE</h2>
        <p>TICKET DE APERTURA</p>
    </div>
    <div class="linea"></div>
    <table>
        <tr>
            <td><strong>Fecha:</strong></td>
            <td class="derecha">{{ \Carbon\Carbon::parse($caja->fecha)->format('Y-m-d H:i:s') }}</td>
        </tr>
        <tr>
            <td><strong>Cajero:</strong></td>
            <td class="derecha">{{ $caja->cajero }}</td>
        </tr>
        <tr>
            <td><strong>Turno:</strong></td>
            <td class="derecha">{{ $caja->turno }}</td>
        </tr>
        <tr>
            <td><strong>Caja N°:</strong></td>
            <td class="derecha">{{ $caja->caja_id }}</td>
        </tr>
    </table>
    <div class="linea"></div>
    <div class="centro">
        <h3>FONDO INICIAL</h3>
        <h2>C$ {{ number_format($caja->monto_inicial, 2) }}</h2>
    </div>
    
    {{-- Nueva sección para desglosar el JSON de monedas y billetes --}}
    @if(is_array($caja->conteo_json) && count($caja->conteo_json) > 0)
        <div class="linea"></div>
        <div class="centro">
            <p><strong>DETALLE DE EFECTIVO</strong></p>
        </div>
        <table>
            <tr>
                <th style="text-align: left;">Billete/Moneda</th>
                <th class="centro">Cant.</th>
                <th class="derecha">Total</th>
            </tr>
            @foreach($caja->conteo_json as $denominacion => $cantidad)
                @if($cantidad > 0)
                    <tr>
                        <td>C$ {{ $denominacion }}</td>
                        <td class="centro">{{ $cantidad }}</td>
                        <td class="derecha">C$ {{ number_format((float)$denominacion * (int)$cantidad, 2) }}</td>
                    </tr>
                @endif
            @endforeach
        </table>
    @endif

    <div class="linea"></div>
    <br><br><br>
    <div class="centro">
        <p>_______________________</p>
        <p>Firma Cajero</p>
    </div>
    
    <script>
        window.onload = function() {
            window.print();
            setTimeout(() => { window.close(); }, 500); 
        };
    </script>
</body>
</html>