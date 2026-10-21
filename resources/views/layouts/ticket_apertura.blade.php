<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Ticket Apertura</title>
    <style>
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 14px;
            width: 300px;
            margin: 0 auto;
            padding: 10px;
            color: #000;
        }

        .centro {
            text-align: center;
        }

        .linea {
            border-top: 1px dashed #000;
            margin: 10px 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        td,
        th {
            padding: 2px 0;
        }

        .derecha {
            text-align: right;
        }
    </style>
</head>

<body>
    @php
    // conteo_json puede llegar como array (si el modelo lo castea) o como texto JSON
    $cJ = $caja->conteo_json;
    $arr = null;
    if (is_array($cJ)) {
    $arr = $cJ;
    } elseif (is_string($cJ) && trim($cJ) !== '') {
    $d = json_decode(trim($cJ), true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($d)) { $arr = $d; }
    }
    @endphp

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

    @if(is_array($arr) && count($arr) > 0)
    <div class="linea"></div>
    <div class="centro">
        <p><strong>DESGLOSE DE BILLETES/MONEDAS</strong></p>
    </div>
    <table>
        <tr>
            <th style="text-align: left;">Denominación</th>
            <th class="centro">Cantidad</th>
            <th class="derecha">Subtotal</th>
        </tr>
        @php $s = 0; $t = 0; @endphp
        @foreach($arr as $denominacion => $cantidad)
        @if(is_numeric($denominacion) && is_numeric($cantidad))
        @php $cint = (int) $cantidad; @endphp
        @if($cint > 0)
        @php
        $sub = (float) $denominacion * $cint;
        $s += $sub;
        $t += $cint;
        $dec = ((float) $denominacion == floor((float) $denominacion)) ? 0 : 2;
        @endphp
        <tr>
            <td>C$ {{ number_format((float) $denominacion, $dec) }}</td>
            <td class="centro">{{ $cint }}</td>
            <td class="derecha">C$ {{ number_format($sub, 2) }}</td>
        </tr>
        @endif
        @endif
        @endforeach
        <tr>
            <td colspan="2"><strong>Total ({{ $t }} {{ $t == 1 ? 'billete/moneda' : 'billetes/monedas' }})</strong></td>
            <td class="derecha"><strong>C$ {{ number_format($s, 2) }}</strong></td>
        </tr>
    </table>
    @if(abs($s - (float) ($caja->monto_inicial ?? 0)) > 0.01)
    <p style="font-size:11px;text-align:center;margin-top:4px;">* El desglose no coincide con el fondo inicial.</p>
    @endif
    @else
    <div class="linea"></div>
    <div class="centro">
        <p style="font-size:11px;">Desglose de billetes/monedas no disponible.</p>
    </div>
    @endif

    <div class="linea"></div>
    <br><br><br>
    <div class="centro">
        <p>_______________________</p>
        <p>Firma Cajero</p>



        <p>_______________________</p>
        <p>Firma Gerente o Contador</p>
    </div>

    <script>
        window.onload = function() {
            window.print();
            setTimeout(() => {
                window.close();
            }, 500);
        };
    </script>
</body>

</html>
