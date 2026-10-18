<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <title>
        Comanda completa - Mesa {{ $venta->mesa_numero }}
    </title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 10px;
            width: 80mm;
            font-family: Arial, sans-serif;
            font-size: 14px;
        }

        .center {
            text-align: center;
        }

        .linea {
            border-top: 1px dashed #000;
            margin: 8px 0;
        }

        .producto {
            margin-bottom: 8px;
        }

        .cantidad {
            font-weight: bold;
            font-size: 18px;
        }

        .nombre {
            font-weight: bold;
        }

        .nota {
            font-size: 12px;
            margin-top: 3px;
            white-space: pre-line;
        }

        @media print {

            @page {
                size: 80mm auto;
                margin: 0;
            }

            body {
                width: 80mm;
            }
        }

    </style>

</head>

<body>

    <div class="center">

        <strong>
            EL LOBITO
        </strong>

        <div>
            COMANDA COMPLETA DE COCINA
        </div>

        <div>
            Mesa: {{ $venta->mesa_numero }}
        </div>

        <div>
            Venta #{{ $venta->id }}
        </div>

        <div>
            Impreso: {{ now()->format('d/m/Y H:i') }}
        </div>

    </div>

    <div class="linea"></div>

    @foreach ($detalles as $detalle)

        <div class="producto">

            <span class="cantidad">
                {{ $detalle->cantidad }}x
            </span>

            <span class="nombre">
                {{ $detalle->nombre }}
            </span>

            @if (!empty($detalle->notas))

                <div class="nota">
                    Nota: {{ $detalle->notas }}
                </div>

            @endif

        </div>

    @endforeach

    <div class="linea"></div>

    <div class="center">
        FIN DE COMANDA COMPLETA
    </div>

    <script>
        window.addEventListener('load', function () {
            window.print();
        });
    </script>

</body>

</html>
