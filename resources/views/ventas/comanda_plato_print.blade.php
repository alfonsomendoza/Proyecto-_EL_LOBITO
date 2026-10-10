<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <title>
        Reimpresión de plato - Comanda #{{ $comanda->numero_ticket }}
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

        .reimpresion {
            border: 2px dashed #000;
            padding: 4px 6px;
            margin: 6px 0;
            text-align: center;
            font-weight: bold;
            font-size: 13px;
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

    <div class="reimpresion">
        REIMPRESIÓN – PLATO
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

        </div>

    @endforeach

    <div class="linea"></div>

    <div class="center">
        FIN DE REIMPRESIÓN
    </div>

    <script>
        window.addEventListener('load', function () {
            window.print();
        });
    </script>

</body>

</html>
