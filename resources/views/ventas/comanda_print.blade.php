<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <title>
        Comanda #{{ $comanda->numero_ticket }}
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
            /* Respeta los saltos de línea de la observación. */
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
            COMANDA #{{ $comanda->numero_ticket }}
        </div>

        <div>
            Mesa: {{ $comanda->mesa_numero }}
        </div>

        <div>
            {{ $comanda->creado_en }}
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
        FIN DE COMANDA
    </div>

    <script>
        window.addEventListener('load', function () {
            window.print();
        });
    </script>

</body>

</html>