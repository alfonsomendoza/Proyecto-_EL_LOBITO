<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        Comandas - Mesa {{ $mesa->numero ?? $venta->mesa_id }}
    </title>

    <style>
        @page {
            size: 80mm auto;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            width: 80mm;
            background: #ffffff;
            color: #000000;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
        }

        .ticket {
            width: 80mm;
            padding: 3mm;
        }

        .comanda {
            width: 100%;
            page-break-after: always;
            break-after: page;
        }

        .comanda:last-child {
            page-break-after: auto;
            break-after: auto;
        }

        .centrado {
            text-align: center;
        }

        .titulo {
            font-size: 18px;
            font-weight: bold;
            line-height: 1;
            margin-bottom: 2mm;
        }

        .numero {
            font-size: 30px;
            font-weight: bold;
            line-height: 1;
            margin-bottom: 3mm;
        }

        .area {
            width: 100%;
            border: 2px solid #000000;
            padding: 2mm 1mm;
            margin-bottom: 3mm;
            text-align: center;
            font-size: 20px;
            font-weight: bold;
            line-height: 1;
        }

        .reimpresion {
            width: 100%;
            border: 2px dashed #000000;
            padding: 2mm 1mm;
            margin-bottom: 3mm;
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            line-height: 1;
        }

        .datos {
            width: 100%;
            border-top: 1px solid #000000;
            border-bottom: 1px solid #000000;
            padding: 2mm 0;
            margin-bottom: 2mm;
        }

        .dato {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            width: 100%;
            margin-bottom: 1mm;
            line-height: 1.2;
        }

        .dato:last-child {
            margin-bottom: 0;
        }

        .dato-label {
            font-weight: bold;
            white-space: nowrap;
            padding-right: 2mm;
        }

        .dato-valor {
            text-align: right;
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        .productos {
            width: 100%;
        }

        .producto {
            width: 100%;
            padding: 2.5mm 0;
            border-bottom: 1px dashed #000000;
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .producto-linea {
            display: flex;
            align-items: flex-start;
            width: 100%;
        }

        .cantidad {
            width: 15mm;
            min-width: 15mm;
            padding-right: 1mm;
            font-size: 20px;
            font-weight: bold;
            line-height: 1.1;
        }

        .nombre {
            flex: 1;
            min-width: 0;
            font-size: 17px;
            font-weight: bold;
            line-height: 1.2;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .pie {
            width: 100%;
            margin-top: 3mm;
            padding-top: 2mm;
            border-top: 1px solid #000000;
            text-align: center;
            font-size: 10px;
            font-weight: bold;
            line-height: 1.4;
        }

        .acciones {
            width: 80mm;
            padding: 5mm 3mm;
            text-align: center;
        }

        .boton {
            display: inline-block;
            padding: 8px 15px;
            margin: 3px;
            border: 1px solid #000000;
            border-radius: 4px;
            background: #ffffff;
            color: #000000;
            font-size: 13px;
            cursor: pointer;
        }

        .boton:hover {
            background: #eeeeee;
        }

        @media print {
            html,
            body {
                width: 80mm;
                margin: 0;
                padding: 0;
            }

            .ticket {
                width: 80mm;
                padding: 3mm;
            }

            .acciones {
                display: none;
            }
        }
    </style>
</head>

<body>

<div class="ticket">

    @foreach($comandas as $comanda)

        <div class="comanda">

            <div class="centrado titulo">
                COMANDA
            </div>

            <div class="centrado numero">
                #{{ str_pad((string) $comanda->numero_ticket, 2, '0', STR_PAD_LEFT) }}
            </div>

            <div class="area">
                {{ $area }}
            </div>

            @if(request()->boolean('reimpresion'))
                <div class="reimpresion">
                    REIMPRESIÓN
                </div>
            @endif

            <div class="datos">

                <div class="dato">
                    <div class="dato-label">
                        MESA
                    </div>

                    <div class="dato-valor">
                        {{ $mesa->numero ?? $comanda->mesa_id }}
                    </div>
                </div>

                <div class="dato">
                    <div class="dato-label">
                        FECHA
                    </div>

                    <div class="dato-valor">
                        {{ \Carbon\Carbon::parse($comanda->creado_en)->format('d/m/Y H:i') }}
                    </div>
                </div>

            </div>

            <div class="productos">

                @foreach($detalles as $detalle)

                    @if((int) $detalle->comanda_id === (int) $comanda->id)

                        <div class="producto">

                            <div class="producto-linea">

                                <div class="cantidad">
                                    {{ $detalle->cantidad }}x
                                </div>

                                <div class="nombre">
                                    {{ $detalle->nombre }}
                                </div>

                            </div>

                        </div>

                    @endif

                @endforeach

            </div>

            <div class="pie">

                COMANDA #{{ str_pad((string) $comanda->numero_ticket, 2, '0', STR_PAD_LEFT) }}

                <br>

                {{ $area }}

                <br>

                IMPRESIÓN #{{ $comanda->conteo_impresiones }}

            </div>

        </div>

    @endforeach

</div>

<div class="acciones">

    <button
        type="button"
        class="boton"
        onclick="window.print()"
    >
        Imprimir
    </button>

    <button
        type="button"
        class="boton"
        onclick="window.close()"
    >
        Cerrar
    </button>

</div>

<script>
    window.addEventListener('load', function () {
        setTimeout(function () {
            window.print();
        }, 300);
    });
</script>

</body>
</html>