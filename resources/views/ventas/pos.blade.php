@extends('layouts.app')

@section('content')

<style>
    .pos-container {
        display: flex;
        gap: 18px;
        height: calc(100vh - 100px);
        padding-top: 10px;
        min-height: 600px;
    }

    .pos-catalogo {
        flex: 6;
        min-width: 0;
        display: flex;
        flex-direction: column;
        gap: 12px;
        overflow: hidden;
    }

    .pos-ticket {
        flex: 4;
        min-width: 360px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        overflow: hidden;
    }

    .panel-caja {
        background: #ffffff;
        border: 1px solid #e3e6f0;
        border-radius: 10px;
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.06);
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }

    .pos-header {
        background: #ffffff;
        border: 1px solid #e3e6f0;
        border-radius: 10px;
        padding: 12px 16px;
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.05);
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-shrink: 0;
    }

    .pos-header-title {
        display: flex;
        align-items: center;
        gap: 10px;
        color: #34395e;
        font-size: 17px;
        font-weight: 700;
    }

    .pos-header-title i {
        color: #6777ef;
        font-size: 19px;
    }

    .pos-header-actions {
        display: flex;
        gap: 8px;
        align-items: center;
        flex-wrap: wrap;
    }

    .pos-btn {
        border: none;
        border-radius: 6px;
        padding: 7px 11px;
        color: white;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        transition: 0.2s;
    }

    .pos-btn:hover {
        transform: translateY(-1px);
        opacity: 0.92;
        color: white;
        text-decoration: none;
    }

    .pos-btn-danger {
        background: #fc544b;
    }

    .pos-btn-secondary {
        background: #6c757d;
    }

    .pos-btn-primary {
        background: #6777ef;
    }

    .buscador-productos {
        position: relative;
        flex-shrink: 0;
    }

    .buscador-productos input {
        width: 100%;
        height: 40px;
        border: 1px solid #d8dce6;
        border-radius: 8px;
        padding: 0 40px 0 38px;
        font-size: 13px;
        outline: none;
        background: #ffffff;
        box-sizing: border-box;
    }

    .buscador-productos input:focus {
        border-color: #6777ef;
        box-shadow: 0 0 0 3px rgba(103, 119, 239, 0.10);
    }

    .buscador-icono {
        position: absolute;
        left: 14px;
        top: 12px;
        color: #9aa2b1;
        font-size: 14px;
    }

    .buscador-limpiar {
        position: absolute;
        right: 8px;
        top: 7px;
        width: 26px;
        height: 26px;
        border: none;
        background: transparent;
        color: #999;
        cursor: pointer;
        border-radius: 50%;
        display: none;
    }

    .buscador-limpiar:hover {
        background: #f1f1f1;
    }

    .cat-filtros {
        display: flex;
        gap: 8px;
        overflow-x: auto;
        padding: 2px 2px 8px;
        flex-shrink: 0;
    }

    .cat-filtros::-webkit-scrollbar {
        height: 5px;
    }

    .cat-filtros::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 5px;
    }

    .btn-cat {
        border: 1px solid #d8dce6;
        background: #ffffff;
        color: #475569;
        padding: 7px 16px;
        border-radius: 20px;
        cursor: pointer;
        white-space: nowrap;
        font-size: 12px;
        font-weight: 700;
        transition: 0.2s;
    }

    .btn-cat:hover,
    .btn-cat.active {
        background: #6777ef;
        border-color: #6777ef;
        color: #ffffff;
    }

    .grid-productos {
        flex: 1;
        min-height: 0;
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(145px, 1fr));
        gap: 10px;
        overflow-y: auto;
        padding: 3px 4px 10px 2px;
        align-content: start;
    }

    .grid-productos::-webkit-scrollbar,
    .ticket-body::-webkit-scrollbar {
        width: 6px;
    }

    .grid-productos::-webkit-scrollbar-thumb,
    .ticket-body::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 6px;
    }

    .card-prod {
        position: relative;
        background: #ffffff;
        border: 1px solid #e3e6f0;
        border-radius: 9px;
        padding: 12px;
        min-height: 112px;
        cursor: pointer;
        transition: 0.2s;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        box-sizing: border-box;
        user-select: none;
    }

    .card-prod:hover {
        border-color: #6777ef;
        box-shadow: 0 5px 12px rgba(103, 119, 239, 0.16);
        transform: translateY(-2px);
    }

    .card-prod:active {
        transform: scale(0.98);
    }

    .producto-icono {
        width: 30px;
        height: 30px;
        border-radius: 7px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #eef1ff;
        color: #6777ef;
        font-size: 13px;
        margin-bottom: 8px;
    }

    .producto-icono.barra {
        background: #e9f8fb;
        color: #36b9cc;
    }

    .producto-nombre {
        margin: 0;
        color: #34395e;
        font-size: 12px;
        font-weight: 700;
        line-height: 1.35;
        min-height: 32px;
        overflow: hidden;
    }

    .producto-precio {
        margin-top: 8px;
        color: #47c363;
        font-size: 14px;
        font-weight: 800;
    }

    .producto-tipo {
        position: absolute;
        top: 8px;
        right: 8px;
        font-size: 8px;
        font-weight: 700;
        padding: 3px 6px;
        border-radius: 10px;
        background: #fff1f0;
        color: #fc544b;
    }

    .producto-tipo.barra {
        background: #e9f8fb;
        color: #36b9cc;
    }

    .sin-resultados {
        grid-column: 1 / -1;
        text-align: center;
        padding: 50px 20px;
        color: #9aa2b1;
        font-size: 13px;
    }

    .ticket-header {
        min-height: 48px;
        box-sizing: border-box;
        padding: 10px 14px;
        background: #f4f6f9;
        border-bottom: 1px solid #e1e4ea;
        color: #34395e;
        font-size: 13px;
        font-weight: 700;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
    }

    .ticket-header-title {
        display: flex;
        align-items: center;
        gap: 7px;
    }

    .ticket-total {
        color: #47c363;
        font-size: 14px;
        font-weight: 800;
        white-space: nowrap;
    }

    .ticket-body {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        padding: 10px;
        background: #fafafa;
    }

    .estado-vacio {
        text-align: center;
        color: #a0a7b4;
        padding: 35px 15px;
        font-size: 12px;
    }

    .estado-vacio i {
        display: block;
        font-size: 28px;
        margin-bottom: 10px;
        color: #cbd1db;
    }

    .item-historial {
        display: flex;
        align-items: center;
        gap: 10px;
        background: #f0f3f5;
        border: 1px solid #e0e5e8;
        border-left: 3px solid #7d8790;
        border-radius: 6px;
        padding: 9px 10px;
        margin-bottom: 7px;
        color: #4b5d67;
        font-size: 11px;
    }

    .item-historial-nombre {
        flex: 1;
        min-width: 0;
    }

    .item-historial-nombre strong {
        color: #34395e;
    }

    .item-historial-info {
        text-align: right;
        white-space: nowrap;
    }

    .item-historial-precio {
        color: #34395e;
        font-weight: 800;
        font-size: 11px;
    }

    .item-historial-comanda {
        color: #8b939d;
        font-size: 9px;
        margin-top: 2px;
    }

    /* Solo el número de la comanda se agranda; "Barra" queda igual. */
    .numero-comanda {
        font-size: 15px;
        font-weight: 800;
        color: #34395e;
    }

    /* Observación mostrada en el historial de la mesa. */
    .item-historial-nota {
        color: #8a5a00;
        background: #fff6e0;
        border-left: 3px solid #f0b400;
        font-size: 10px;
        font-style: italic;
        margin-top: 4px;
        padding: 2px 6px;
        border-radius: 4px;
        white-space: pre-line;
        word-break: break-word;
        text-align: left;
    }

    /* Estado de preparación (cocina digital) en el historial. */
    .item-historial-preparacion {
        font-size: 10px;
        font-weight: 800;
        margin-top: 3px;
        color: #0b5faa;
    }

    .btn-anular {
        border: none;
        border-radius: 5px;
        background: #fc544b;
        color: #ffffff;
        padding: 8px 12px;
        font-size: 11px;
        font-weight: 700;
        min-height: 32px;
        cursor: pointer;
        white-space: nowrap;
    }

    .btn-anular:hover {
        background: #e04138;
    }

    .item-historial-anulado .item-historial-nombre,
    .item-historial-anulado .item-historial-precio {
        text-decoration: line-through;
        opacity: 0.55;
    }

    .historial-acciones {
        display: flex;
        flex-direction: column;
        gap: 5px;
        flex-shrink: 0;
    }

    .btn-reimprimir,
    .btn-reimprimir-plato {
        border: none;
        border-radius: 5px;
        background: #6777ef;
        color: #ffffff;
        padding: 8px 12px;
        font-size: 11px;
        font-weight: 700;
        min-height: 32px;
        cursor: pointer;
        white-space: nowrap;
    }

    .btn-reimprimir:hover,
    .btn-reimprimir-plato:hover {
        background: #5364dc;
    }

    .item-nuevo {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #ffffff;
        border: 1px solid #d8ddff;
        border-left: 4px solid #6777ef;
        border-radius: 7px;
        padding: 9px;
        margin-bottom: 7px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.03);
    }

    .nuevo-info {
        flex: 1;
        min-width: 0;
    }

    .nuevo-nombre {
        color: #34395e;
        font-size: 11px;
        font-weight: 700;
        line-height: 1.3;
        word-break: break-word;
    }

    .nuevo-precio {
        color: #6777ef;
        font-size: 10px;
        font-weight: 700;
        margin-top: 3px;
    }

    /* Observación por línea dentro del carrito. */
    .nuevo-nota {
        color: #8a5a00;
        background: #fff6e0;
        border-left: 3px solid #f0b400;
        font-size: 10px;
        font-style: italic;
        margin-top: 4px;
        padding: 3px 6px;
        border-radius: 4px;
        white-space: pre-line;
        word-break: break-word;
    }

    /* Botón para añadir/editar la observación de una línea. */
    .btn-nota {
        width: 24px;
        height: 24px;
        border: none;
        border-radius: 5px;
        background: #fff2cc;
        color: #8a5a00;
        cursor: pointer;
        font-size: 11px;
        padding: 0;
    }

    .btn-nota:hover {
        background: #ffe8a3;
    }

    .nuevo-controles {
        display: flex;
        align-items: center;
        gap: 4px;
        flex-shrink: 0;
    }

    .qty-btn {
        width: 24px;
        height: 24px;
        border: none;
        border-radius: 5px;
        background: #edf0f4;
        color: #34395e;
        cursor: pointer;
        font-size: 14px;
        font-weight: 700;
        line-height: 24px;
        padding: 0;
    }

    .qty-btn:hover {
        background: #dfe3ea;
    }

    .qty-number {
        width: 22px;
        text-align: center;
        color: #34395e;
        font-size: 12px;
        font-weight: 800;
    }

    .nuevo-subtotal {
        width: 68px;
        text-align: right;
        color: #34395e;
        font-size: 11px;
        font-weight: 800;
        flex-shrink: 0;
    }

    .panel-footer {
        padding: 9px;
        background: #f4f6f9;
        border-top: 1px solid #e1e4ea;
        display: flex;
        gap: 8px;
    }

    .btn-footer {
        flex: 1;
        min-height: 40px;
        border: none;
        border-radius: 6px;
        color: white;
        cursor: pointer;
        font-size: 11px;
        font-weight: 800;
        transition: 0.2s;
    }

    .btn-footer:hover {
        opacity: 0.92;
    }

    .btn-prefactura {
        background: #34395e;
    }

    .btn-cobrar {
        background: #ffa426;
    }

    /* Botón «Dividir cuentas»: fondo azul visible y estados accesibles. */
    #btn-dividir-cuenta {
        background: #3a86ff;
    }

    #btn-dividir-cuenta:hover {
        background: #2f6fdb;
        opacity: 1;
    }

    #btn-dividir-cuenta:focus-visible {
        outline: 2px solid #1b4fa0;
        outline-offset: 2px;
    }

    #btn-dividir-cuenta:disabled {
        background: #9db8e0;
        opacity: 0.6;
        cursor: not-allowed;
    }

    .btn-enviar {
        width: 100%;
        min-height: 43px;
        border: none;
        border-radius: 6px;
        background: #47c363;
        color: white;
        cursor: pointer;
        font-size: 12px;
        font-weight: 800;
        transition: 0.2s;
    }

    .btn-enviar:hover {
        background: #3cab56;
    }

    .btn-enviar:disabled {
        opacity: 0.6;
        cursor: not-allowed;
    }

    .nuevo-footer {
        padding: 9px;
        background: #ffffff;
        border-top: 1px solid #e1e4ea;
    }

    .nuevo-resumen {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
        padding: 0 2px;
    }

    .nuevo-resumen-label {
        color: #7c8492;
        font-size: 10px;
        font-weight: 700;
    }

    .nuevo-resumen-total {
        color: #6777ef;
        font-size: 15px;
        font-weight: 900;
    }

    .separador-panel {
        height: 1px;
        background: #edf0f4;
        margin: 3px 0 8px;
    }

    @media (max-width: 1000px) {
        .pos-container {
            height: auto;
            min-height: 0;
            flex-direction: column;
        }

        .pos-catalogo,
        .pos-ticket {
            min-width: 0;
            height: 650px;
        }
    }

    @media (max-width: 600px) {
        .pos-header {
            align-items: flex-start;
            flex-direction: column;
        }

        .pos-header-actions {
            width: 100%;
        }

        .pos-btn {
            flex: 1;
        }

        .grid-productos {
            grid-template-columns: repeat(2, 1fr);
        }

        .item-historial {
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .item-historial-info {
            margin-left: auto;
        }

        .historial-acciones {
            width: 100%;
            flex-direction: row;
        }

        .btn-reimprimir {
            width: 100%;
        }

        .btn-anular {
            width: 100%;
        }
    }

    /* Layout del modal "Cobrar cuenta": descuento y propina en una grilla
       de 2 columnas que pasa a 1 columna en pantallas estrechas. */
    .cobro-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        text-align: left;
        font-size: 12px;
        color: #555;
        border-top: 1px dashed #aaa;
        margin-top: 8px;
        padding-top: 8px;
    }

    .cobro-bloque {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    @media (max-width: 640px) {
        .cobro-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="pos-container">

    <div class="pos-catalogo">

        <div class="pos-header">

            <div class="pos-header-title">
                <i class="fas fa-utensils"></i>
                <span>Mesa {{ $venta->mesa_numero }}</span>
            </div>

            <div class="pos-header-actions">

                @php
                    // ¿Quedan productos de cocina activos en el historial?
                    $hayCocina = $historial->contains(
                        fn ($h) =>
                            $h->estado === 'ACTIVO'
                            && (int) $h->requiere_cocina === 1
                    );
                @endphp

                @if(count($historial) > 0)

                    <button
                        type="button"
                        id="btn-imprimir-todas"
                        class="pos-btn pos-btn-primary"
                    >
                        <i class="fas fa-print"></i>
                        Imprimir todas
                    </button>

                @endif

                @if($hayCocina)

                    <button
                        type="button"
                        id="btn-imprimir-completa"
                        class="pos-btn pos-btn-primary"
                    >
                        <i class="fas fa-layer-group"></i>
                        Comanda completa de cocina
                    </button>

                @endif

                @if(count($historial) == 0)

                    <button
                        type="button"
                        id="btn-liberar-mesa"
                        class="pos-btn pos-btn-danger"
                    >
                        <i class="fas fa-ban"></i>
                        Liberar
                    </button>

                @endif

                <a
                    href="{{ route('mesas.index') }}"
                    class="pos-btn pos-btn-secondary"
                >
                    <i class="fas fa-arrow-left"></i>
                    Mesas
                </a>

            </div>

        </div>

        <div class="buscador-productos">

            <i class="fas fa-search buscador-icono"></i>

            <input
                type="text"
                id="buscar-producto"
                placeholder="Buscar producto..."
                autocomplete="off"
            >

            <button
                type="button"
                id="limpiar-busqueda"
                class="buscador-limpiar"
            >
                <i class="fas fa-times"></i>
            </button>

        </div>

        <div class="cat-filtros">

            <button
                type="button"
                class="btn-cat active"
                data-categoria="todas"
            >
                Todas
            </button>

            @foreach($categorias as $cat)

                <button
                    type="button"
                    class="btn-cat"
                    data-categoria="{{ $cat->id }}"
                >
                    {{ $cat->nombre }}
                </button>

            @endforeach

        </div>

        <div
            class="grid-productos"
            id="contenedor-productos"
        >

            @foreach($productos as $p)

                <div
                    class="card-prod"
                    data-cat="{{ $p->categoria_id }}"
                    data-id="{{ $p->id }}"
                    data-nombre="{{ e($p->nombre) }}"
                    data-precio="{{ $p->precio }}"
                    data-cocina="{{ $p->requiere_cocina ? '1' : '0' }}"
                >

                    @if($p->requiere_cocina)

                        <span class="producto-tipo">
                            Cocina
                        </span>

                        <div class="producto-icono">
                            <i class="fas fa-fire"></i>
                        </div>

                    @else

                        <span class="producto-tipo barra">
                            Barra
                        </span>

                        <div class="producto-icono barra">
                            <i class="fas fa-glass-martini"></i>
                        </div>

                    @endif

                    <h6 class="producto-nombre">
                        {{ $p->nombre }}
                    </h6>

                    <div class="producto-precio">
                        C${{ number_format((float) $p->precio, 2) }}
                    </div>

                </div>

            @endforeach

            <div
                id="sin-resultados"
                class="sin-resultados"
                style="display: none;"
            >
                <i class="fas fa-search"></i>
                No se encontraron productos.
            </div>

        </div>

    </div>

    <div class="pos-ticket">

        <div
            class="panel-caja"
            style="flex: 1;"
        >

            @php
                $totalHistorial = $historial
                    ->where('estado', 'ACTIVO')
                    ->sum('subtotal');
            @endphp

            {{-- Cuenta seleccionada (?cuenta=): el POS queda acotado a ella.
                 Vacío = vista general de la mesa (uso para dividir). --}}
            <input
                type="hidden"
                id="cuenta-actual-id"
                value="{{ $cuentaActual->id ?? '' }}"
            >

            <div class="ticket-header">

                <div class="ticket-header-title">
                    <i class="fas fa-receipt"></i>
                    @if($cuentaActual)
                        <span>{{ $cuentaActual->nombre }}</span>
                    @else
                        <span>Cuenta Actual</span>
                    @endif
                </div>

                <span class="ticket-total">
                    C${{ number_format((float) $totalHistorial, 2) }}
                </span>

            </div>

            <div
                class="ticket-body"
                style="background: #ffffff;"
            >

                @if(count($historial) == 0)

                    <div class="estado-vacio">

                        <i class="fas fa-receipt"></i>

                        Mesa abierta

                        <br>

                        No hay pedidos enviados aún.

                    </div>

                @else

                    @foreach($historial as $h)

                        <div class="item-historial {{ $h->estado === 'ANULADO' ? 'item-historial-anulado' : '' }}">

                            <div class="item-historial-nombre">

                                <strong>
                                    {{ $h->cantidad }}x
                                </strong>

                                {{ $h->nombre }}

                                {{-- Observación de la línea (escapada por Blade). --}}
                                @if(!empty($h->notas))
                                    <div class="item-historial-nota">
                                        <i class="fas fa-note-sticky"></i> {{ $h->notas }}
                                    </div>
                                @endif

                                {{-- Progreso de preparación (cocina digital). Solo
                                     para líneas activas de cocina. --}}
                                @if($h->estado === 'ACTIVO' && $h->requiere_cocina && !empty($h->estado_preparacion))
                                    @php
                                        $etiquetasPreparacion = [
                                            'PENDIENTE' => 'Pendiente',
                                            'EN_PREPARACION' => 'En preparación',
                                            'LISTO' => 'Listo',
                                        ];
                                        $prepTexto = $etiquetasPreparacion[$h->estado_preparacion] ?? $h->estado_preparacion;
                                    @endphp
                                    <div class="item-historial-nota" style="background:#eaf3fb;border-left-color:#4a9fe0;color:#0b5faa;font-style:normal;font-weight:700;">
                                        <i class="fas fa-fire"></i> Cocina: {{ $prepTexto }}
                                    </div>
                                @endif

                                {{-- Línea que LLEGÓ por traslado individual de otra
                                     mesa ($h->trasladado = existe en
                                     traslado_detalles como destino). Aviso para no
                                     volver a mandarla a preparar. --}}
                                @if(!empty($h->trasladado) && $h->estado === 'ACTIVO')
                                    <div class="item-historial-nota" style="background:#fff7e6;border-left-color:#e0a800;color:#8a6d00;font-style:normal;font-weight:700;">
                                        <i class="fas fa-right-left"></i> Este pedido fue trasladado a otra mesa. No volver a prepararlo.
                                    </div>
                                @endif

                            </div>

                            <div class="item-historial-info">

                                @if($h->estado === 'CANCELADO')

                                    <div
                                        style="
                                            color: #fc544b;
                                            font-weight: 800;
                                            font-size: 10px;
                                        "
                                    >
                                        CANCELADO
                                    </div>

                                @elseif($h->estado === 'ANULADO')

                                    <div
                                        style="
                                            color: #fc544b;
                                            font-weight: 800;
                                            font-size: 10px;
                                        "
                                    >
                                        ANULADO
                                    </div>

                                @else

                                    <div class="item-historial-precio">
                                        C${{ number_format((float) $h->subtotal, 2) }}
                                    </div>

                                @endif

                                <div class="item-historial-comanda">

                                    @if($h->requiere_cocina && $h->comanda_id)

                                        Comanda #<span class="numero-comanda">{{ str_pad(
                                            $h->numero_ticket ?? 0,
                                            2,
                                            '0',
                                            STR_PAD_LEFT
                                        ) }}</span>

                                    @else

                                        Barra

                                    @endif

                                </div>

                            </div>

                            @if($h->estado === 'ACTIVO')

                                <div class="historial-acciones">

                                    @if($h->requiere_cocina && $h->comanda_id)

                                        <button
                                            type="button"
                                            class="btn-reimprimir"
                                            data-comanda-id="{{ $h->comanda_id }}"
                                        >
                                            <i class="fas fa-print"></i>
                                            Reimprimir
                                        </button>

                                        <button
                                            type="button"
                                            class="btn-reimprimir-plato"
                                            data-detalle-id="{{ $h->id }}"
                                        >
                                            <i class="fas fa-utensils"></i>
                                            Reimprimir plato
                                        </button>

                                    @endif

                                    @if($puedeAnular)

                                        <button
                                            type="button"
                                            class="btn-anular"
                                            data-detalle-id="{{ $h->id }}"
                                            data-cuenta-id="{{ $cuentaActual->id ?? '' }}"
                                            data-nombre="{{ $h->nombre }}"
                                            data-cantidad="{{ $h->cantidad }}"
                                            data-precio="{{ $h->precio_unitario }}"
                                            data-preparacion="{{ $h->estado_preparacion ?? '' }}"
                                        >
                                            <i class="fas fa-ban"></i>
                                            Anular
                                        </button>

                                    @endif

                                </div>

                            @endif

                        </div>

                    @endforeach

                @endif

            </div>

            <div class="panel-footer">

                @php
                    // Rol del usuario para decidir qué botones se muestran.
                    // OCULTAR NO SUSTITUYE la autorización: el backend vuelve
                    // a validar en VentaController::pagarCuenta (403).
                    $rolFooter = strtoupper((string) (auth()->user()->rol->nombre ?? ''));
                    // Cobran solo ADMIN, GERENTE y CAJERO (MESERO y COCINERO no).
                    $puedeCobrarMesa = in_array($rolFooter, ['ADMIN', 'GERENTE', 'CAJERO'], true);
                @endphp

                <button
                    type="button"
                    class="btn-footer"
                    id="btn-dividir-cuenta"
                    @if(!$puedeDividirCuenta) disabled title="La cuenta no tiene consumos propios para dividir" @endif
                >
                    <i class="fas fa-users"></i>
                    Dividir cuentas
                </button>

                <button
                    type="button"
                    class="btn-footer btn-prefactura"
                    id="btn-prefactura"
                >
                    <i class="fas fa-print"></i>
                    Pre-Factura
                </button>

                @if($puedeCobrarMesa)
                <button
                    type="button"
                    class="btn-footer btn-cobrar"
                    id="btn-cobrar"
                >
                    <i class="fas fa-cash-register"></i>
                    Cobrar Mesa
                </button>
                @endif

            </div>

        </div>

        <div
            class="panel-caja"
            style="flex: 1;"
        >

            <div
                class="ticket-header"
                style="background: #eef2f7;"
            >

                <div class="ticket-header-title">

                    <i class="fas fa-plus-circle"></i>

                    <span>
                        Nuevo Pedido
                    </span>

                </div>

                <span
                    id="total-nuevo"
                    style="color: #6777ef;"
                >
                    C$ 0.00
                </span>

            </div>

            <div
                class="ticket-body"
                id="lista-nuevo"
            >

                <div class="estado-vacio">

                    <i class="fas fa-shopping-basket"></i>

                    Agrega productos

                    <br>

                    para enviar a cocina o barra.

                </div>

            </div>

            <div class="nuevo-footer">

                <div class="nuevo-resumen">

                    <span class="nuevo-resumen-label">
                        Total nuevo pedido
                    </span>

                    <span
                        class="nuevo-resumen-total"
                        id="total-nuevo-footer"
                    >
                        C$ 0.00
                    </span>

                </div>

                <button
                    type="button"
                    id="btn-enviar-pedido"
                    class="btn-enviar"
                >
                    <i class="fas fa-paper-plane"></i>
                    Confirmar y Enviar Pedido
                </button>

            </div>

        </div>

    </div>

</div>

{{-- Permisos de ajustes del cobro: solo ADMIN y GERENTE aplican descuentos
     o propinas (el rol CONTADOR NO existe en `roles`, no se agrega en
     silencio). El backend VUELVE A VALIDARLO: ocultar el input aquí no
     sustituye la autorización. --}}
@php
    $rolPos = strtoupper((string) (auth()->user()->rol->nombre ?? ''));
    $puedeAjustarCobro = in_array($rolPos, ['ADMIN', 'GERENTE', 'CAJERO'], true);
@endphp

@include('partials.modal-prefactura', [
    'sujetoRetencion' => $sujetoRetencion,
    'urlCuentas' => route('pos.cuentas', ['mesaId' => $mesa->id]),
])

<script>
    // ¿Puede este usuario aplicar descuentos/propinas en el cobro? (si no,
    // el modal ni siquiera pide estos campos).
    const PUEDE_AJUSTAR_COBRO = {{ $puedeAjustarCobro ? 'true' : 'false' }};
    const DESCUENTO_FISCAL_HABILITADO = {{ $descuentoFiscalHabilitado ? 'true' : 'false' }};
    const PROPINA_FISCAL_HABILITADA = {{ $propinaFiscalHabilitada ? 'true' : 'false' }};
    const IVA_HABILITADO = {{ $ivaHabilitado ? 'true' : 'false' }};
    const RET_DGI_HABILITADO = {{ $retencionDgiHabilitada ? 'true' : 'false' }};
    const RET_ALC_HABILITADO = {{ $retencionAlcaldiaHabilitada ? 'true' : 'false' }};
    const SUJETO_RETENCION = {{ $sujetoRetencion ? 'true' : 'false' }};
    const ROL_USUARIO = '{{ $rolPos }}';
    const PUEDE_EXCEPCION_FISCAL = ROL_USUARIO === 'ADMIN' || ROL_USUARIO === 'GERENTE';

    // Productos de cocina que bloquean el cobro, por cuenta ABIERTA
    // (mapa cuenta_id => [{producto, cantidad, estado, etiqueta, comanda}]).
    // El backend vuelve a validarlo dentro de la transacción del cobro.
    const COCINA_PENDIENTES = @json($cocinaPendientesPorCuenta ?? []);

    // ¿La cuenta indicada aún tiene productos de cocina sin TERMINAR?
    function cocinaPendienteDe(cuentaId) {
        const clave = String(cuentaId);
        const lista = (COCINA_PENDIENTES || {})[clave];
        return Array.isArray(lista) ? lista : [];
    }

    // Texto corto y legible de la lista de pendientes (para avisos y mensajes).
    function cocinaPendienteTexto(pendientes) {
        return pendientes.map(p => `${p.cantidad}× ${p.producto} (${p.etiqueta || p.estado})`).join(', ');
    }

    // Aviso en el modal "Cobrar cuenta": pinta la lista de productos de cocina
    // que bloquean el cobro de la cuenta seleccionada. Devuelve true si se
    // puede cobrar. La autorización real la hace el servidor en pagarCuenta.
    function renderAvisoCocina() {
        const caja = document.getElementById('aviso-cocina');
        const sel = document.getElementById('cuenta-cobro');
        if (!caja || !sel) { return true; }
        const pendientes = cocinaPendienteDe(sel.value);
        if (pendientes.length === 0) {
            caja.style.display = 'none';
            caja.innerHTML = '';
            return true;
        }
        const items = pendientes.map(p => '<li>' + escaparHtml(p.cantidad) + '× ' + escaparHtml(p.producto) + ' — <strong>' + escaparHtml(p.etiqueta || p.estado) + '</strong></li>').join('');
        caja.innerHTML = '<strong><i class="fas fa-kitchen-set"></i> Cocina aún no ha marcado como TERMINADO:</strong>'
            + '<ul style="margin:6px 0 0 18px; padding:0;">' + items + '</ul>'
            + '<div style="margin-top:4px;">El cobro se habilita cuando todos los productos de cocina estén TERMINADOS (LISTO).</div>';
        caja.style.display = 'block';
        return false;
    }

    // Estado activo por concepto (inicia desde la configuración global;
    // ADMIN/GERENTE puede cambiarlo por excepción por factura).
    let descuentoActivo = DESCUENTO_FISCAL_HABILITADO;
    let propinaActiva = PROPINA_FISCAL_HABILITADA;
    let ivaActivo = IVA_HABILITADO;
    let retDgiActivo = RET_DGI_HABILITADO && SUJETO_RETENCION;
    let retAlcActivo = RET_ALC_HABILITADO && SUJETO_RETENCION;

    let nuevoPedido = [];
    let categoriaActual = 'todas';

    // Identificador único por línea del carrito. Permite que dos líneas del
    // MISMO producto con observaciones distintas convivan sin fusionarse.
    let nuevoPedidoSeq = 1;

    function siguienteUid() {
        return nuevoPedidoSeq++;
    }

    document.addEventListener('DOMContentLoaded', function () {
        inicializarProductos();
        inicializarCategorias();
        inicializarBusqueda();
        inicializarBotones();
        inicializarReimpresion();
        inicializarReimpresionPlato();
        inicializarAnulacion();
    });

    function inicializarProductos() {
        const tarjetas = document.querySelectorAll('.card-prod');

        tarjetas.forEach(function (card) {
            card.addEventListener('click', function () {
                const id = Number(this.dataset.id);
                const nombre = this.dataset.nombre;
                const precio = Number(this.dataset.precio);
                const requiereCocina = this.dataset.cocina === '1';

                agregarPedido(
                    id,
                    nombre,
                    precio,
                    requiereCocina
                );
            });
        });
    }

    function inicializarCategorias() {
        const botones = document.querySelectorAll('.btn-cat');

        botones.forEach(function (boton) {
            boton.addEventListener('click', function () {

                botones.forEach(function (b) {
                    b.classList.remove('active');
                });

                this.classList.add('active');

                categoriaActual = this.dataset.categoria;

                aplicarFiltros();
            });
        });
    }

    function inicializarBusqueda() {
        const input = document.getElementById('buscar-producto');
        const limpiar = document.getElementById('limpiar-busqueda');

        input.addEventListener('input', function () {
            limpiar.style.display =
                this.value.trim() !== ''
                    ? 'block'
                    : 'none';

            aplicarFiltros();
        });

        limpiar.addEventListener('click', function () {
            input.value = '';
            this.style.display = 'none';

            aplicarFiltros();
            input.focus();
        });
    }

    function aplicarFiltros() {
        const input = document.getElementById('buscar-producto');

        const texto = input.value
            .trim()
            .toLowerCase();

        const tarjetas =
            document.querySelectorAll('.card-prod');

        const sinResultados =
            document.getElementById('sin-resultados');

        let encontrados = 0;

        tarjetas.forEach(function (card) {

            const categoria = card.dataset.cat;

            const nombre =
                card.dataset.nombre.toLowerCase();

            const coincideCategoria =
                categoriaActual === 'todas' ||
                String(categoria) === String(categoriaActual);

            const coincideBusqueda =
                texto === '' ||
                nombre.includes(texto);

            if (
                coincideCategoria &&
                coincideBusqueda
            ) {
                card.style.display = 'flex';
                encontrados++;
            } else {
                card.style.display = 'none';
            }
        });

        sinResultados.style.display =
            encontrados === 0
                ? 'block'
                : 'none';
    }

    function agregarPedido(
        id,
        nombre,
        precio,
        requiereCocina
    ) {
        const productoId = Number(id);
        const productoPrecio = Number(precio);
        const productoNombre = String(nombre).trim();

        if (
            !Number.isInteger(productoId) ||
            productoId <= 0
        ) {
            Swal.fire(
                'Error',
                'El producto seleccionado no es válido.',
                'error'
            );

            return;
        }

        if (!productoNombre) {
            Swal.fire(
                'Error',
                'El producto no tiene un nombre válido.',
                'error'
            );

            return;
        }

        if (
            !Number.isFinite(productoPrecio) ||
            productoPrecio < 0
        ) {
            Swal.fire(
                'Error',
                'El precio del producto no es válido.',
                'error'
            );

            return;
        }

        // Se fusiona únicamente si coincide el producto Y la línea no tiene
        // observación. Así una línea con nota ("sin cebolla") permanece
        // separada de la línea normal del mismo producto.
        const itemExistente =
            nuevoPedido.find(function (item) {
                return item.id === productoId &&
                    (item.notas === null || item.notas === '');
            });

        if (itemExistente) {
            itemExistente.cantidad++;
        } else {
            nuevoPedido.push({
                uid: siguienteUid(),
                id: productoId,
                nombre: productoNombre,
                precio: productoPrecio,
                cantidad: 1,
                requiere_cocina: requiereCocina,
                notas: null
            });
        }

        renderizarNuevo();
    }

    function modQty(uid, cambio) {
        const lineaUid = Number(uid);

        const item =
            nuevoPedido.find(function (producto) {
                return producto.uid === lineaUid;
            });

        if (!item) {
            return;
        }

        item.cantidad += Number(cambio);

        if (item.cantidad <= 0) {
            nuevoPedido =
                nuevoPedido.filter(function (producto) {
                    return producto.uid !== lineaUid;
                });
        }

        renderizarNuevo();
    }

    // Abre un cuadro para escribir/editar la observación de UNA línea.
    function editarNota(uid) {
        const lineaUid = Number(uid);
        const item = nuevoPedido.find(function (producto) {
            return producto.uid === lineaUid;
        });

        if (!item) {
            return;
        }

        Swal.fire({
            title: 'Observación del producto',
            input: 'textarea',
            inputValue: item.notas || '',
            inputAttributes: {
                maxlength: 500,
                placeholder: 'Ej.: sin cebolla, bien cocido, para llevar'
            },
            inputLabel: item.nombre,
            showCancelButton: true,
            confirmButtonText: 'Guardar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#47c363',
            inputValidator: function (valor) {
                // Se valida con el helper real del archivo (longitudTexto).
                // Si supera el límite se rechaza; el servidor vuelve a validar.
                if (valor && longitudTexto(valor) > 500) {
                    return 'La observación no puede superar los 500 caracteres.';
                }
                return false;
            }
        }).then(function (result) {
            if (!result.isConfirmed) {
                return;
            }

            // No se trunca: el textarea ya limita a 500 y el servidor
            // rechaza cualquier nota superior al límite.
            const texto = String(result.value || '').trim();
            item.notas = texto === '' ? null : texto;

            renderizarNuevo();
        });
    }

    function longitudTexto(texto) {
        return texto == null ? 0 : String(texto).length;
    }

    function escaparHtml(texto) {
        const elemento =
            document.createElement('div');

        elemento.textContent = texto;

        return elemento.innerHTML;
    }

    function renderizarNuevo() {
        const contenedor =
            document.getElementById('lista-nuevo');

        const spanTotal =
            document.getElementById('total-nuevo');

        const spanTotalFooter =
            document.getElementById('total-nuevo-footer');

        if (nuevoPedido.length === 0) {

            contenedor.innerHTML =
                '<div class="estado-vacio">' +
                    '<i class="fas fa-shopping-basket"></i>' +
                    'Agrega productos' +
                    '<br>' +
                    'para enviar a cocina o barra.' +
                '</div>';

            spanTotal.innerText = 'C$ 0.00';
            spanTotalFooter.innerText = 'C$ 0.00';

            return;
        }

        let html = '';
        let total = 0;

        nuevoPedido.forEach(function (item) {

            const subtotal =
                item.precio * item.cantidad;

            total += subtotal;

            const nombreSeguro =
                escaparHtml(item.nombre);

            const notaSegura =
                item.notas ? escaparHtml(item.notas) : '';

            const icono =
                item.requiere_cocina
                    ? '<i class="fas fa-fire" style="color:#fc544b;"></i>'
                    : '<i class="fas fa-glass-martini" style="color:#36b9cc;"></i>';

            html +=
                '<div class="item-nuevo">' +

                    '<div class="nuevo-info">' +

                        '<div class="nuevo-nombre">' +
                            icono +
                            ' ' +
                            nombreSeguro +
                        '</div>' +

                        '<div class="nuevo-precio">' +
                            'C$ ' +
                            item.precio.toFixed(2) +
                        '</div>' +

                        (notaSegura
                            ? '<div class="nuevo-nota">' +
                                '<i class="fas fa-note-sticky"></i> ' +
                                notaSegura +
                              '</div>'
                            : '') +

                    '</div>' +

                    '<div class="nuevo-controles">' +

                        '<button ' +
                            'type="button" ' +
                            'class="qty-btn" ' +
                            'data-uid="' +
                            item.uid +
                            '" ' +
                            'data-cambio="-1"' +
                        '>' +
                            '-' +
                        '</button>' +

                        '<span class="qty-number">' +
                            item.cantidad +
                        '</span>' +

                        '<button ' +
                            'type="button" ' +
                            'class="qty-btn" ' +
                            'data-uid="' +
                            item.uid +
                            '" ' +
                            'data-cambio="1"' +
                        '>' +
                            '+' +
                        '</button>' +

                        '<button ' +
                            'type="button" ' +
                            'class="btn-nota" ' +
                            'data-uid="' +
                            item.uid +
                            '" ' +
                            'title="Añadir o editar observación"' +
                        '>' +
                            '<i class="fas fa-note-sticky"></i>' +
                        '</button>' +

                    '</div>' +

                    '<div class="nuevo-subtotal">' +
                        'C$ ' +
                        subtotal.toFixed(2) +
                    '</div>' +

                '</div>';
        });

        contenedor.innerHTML = html;

        spanTotal.innerText =
            'C$ ' + total.toFixed(2);

        spanTotalFooter.innerText =
            'C$ ' + total.toFixed(2);

        const botonesCantidad =
            contenedor.querySelectorAll('.qty-btn');

        botonesCantidad.forEach(function (boton) {

            boton.addEventListener(
                'click',
                function (event) {

                    event.stopPropagation();

                    const uid =
                        Number(this.dataset.uid);

                    const cambio =
                        Number(this.dataset.cambio);

                    modQty(uid, cambio);
                }
            );
        });

        const botonesNota =
            contenedor.querySelectorAll('.btn-nota');

        botonesNota.forEach(function (boton) {

            boton.addEventListener(
                'click',
                function (event) {

                    event.stopPropagation();

                    editarNota(Number(this.dataset.uid));
                }
            );
        });

        contenedor.scrollTop =
            contenedor.scrollHeight;
    }

    function inicializarReimpresion() {

        const botones =
            document.querySelectorAll('.btn-reimprimir');

        botones.forEach(function (boton) {

            boton.addEventListener(
                'click',
                function () {

                    const comandaId =
                        Number(this.dataset.comandaId);

                    reimprimirComanda(
                        comandaId
                    );
                }
            );
        });
    }

    function reimprimirComanda(comandaId) {

        if (!comandaId || comandaId <= 0) {

            Swal.fire(
                'Error',
                'La comanda no es válida.',
                'error'
            );

            return;
        }

        // Si el POS está acotado a una subcuenta, la reimpresión muestra el
        // pedido ACTUAL de esa cuenta; sin cuenta, el histórico original.
        const cuentaActualId =
            document.getElementById('cuenta-actual-id');

        const cuentaQuery =
            cuentaActualId && cuentaActualId.value
                ? '&cuenta=' + cuentaActualId.value
                : '';

        const url =
            '/pos/comanda/' +
            comandaId +
            '?reimpresion=1' +
            cuentaQuery;

        window.open(
            url,
            '_blank',
            'width=450,height=700'
        );
    }

    function inicializarReimpresionPlato() {

        const botones =
            document.querySelectorAll('.btn-reimprimir-plato');

        botones.forEach(function (boton) {

            boton.addEventListener(
                'click',
                function () {

                    const detalleId =
                        Number(this.dataset.detalleId);

                    reimprimirPlato(
                        detalleId
                    );
                }
            );
        });
    }

    function reimprimirPlato(detalleId) {

        if (!detalleId || detalleId <= 0) {

            Swal.fire(
                'Error',
                'El plato no es válido.',
                'error'
            );

            return;
        }

        // Con cuenta activa, la reimpresión del plato usa sus unidades
        // actuales; sin cuenta, la cantidad histórica original.
        const cuentaActualId =
            document.getElementById('cuenta-actual-id');

        const cuentaQuery =
            cuentaActualId && cuentaActualId.value
                ? '&cuenta=' + cuentaActualId.value
                : '';

        const url =
            '/pos/comanda-detalle/' +
            detalleId +
            '?reimpresion=1' +
            cuentaQuery;

        window.open(
            url,
            '_blank',
            'width=450,height=700'
        );
    }

    function inicializarAnulacion() {

        const botones =
            document.querySelectorAll('.btn-anular');

        botones.forEach(function (boton) {

            boton.addEventListener(
                'click',
                function () {

                    anularDetalle(this);
                }
            );
        });
    }

    function escapeHtml(texto) {

        return String(texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function anularDetalle(boton) {

        const detalleId =
            Number(boton.dataset.detalleId);

        const nombre =
            boton.dataset.nombre || 'Producto';

        const cantidadMax =
            Number(boton.dataset.cantidad) || 0;

        const precio =
            Number(boton.dataset.precio) || 0;

        const preparacion =
            (boton.dataset.preparacion || '').toUpperCase();

        if (!detalleId || detalleId <= 0) {

            Swal.fire(
                'Error',
                'El producto no es válido.',
                'error'
            );

            return;
        }

        // Aviso cuando la cocina ya empezó: la anulación sigue siendo posible
        // para ADMIN/GERENTE, pero no debe parecer que nunca se preparó.
        let avisoPreparacion = '';

        if (preparacion === 'EN_PREPARACION' || preparacion === 'LISTO') {
            avisoPreparacion =
                '<div style="background:#fdecea;color:#c0392b;padding:8px;border-radius:6px;margin:0 0 10px;font-size:12px;font-weight:700;">' +
                    '<i class="fas fa-triangle-exclamation"></i> ' +
                    (preparacion === 'LISTO'
                        ? 'Este plato ya fue marcado como LISTO en cocina.'
                        : 'La preparación de este plato ya comenzó en cocina.') +
                '</div>';
        }

        Swal.fire({

            title: '¿Anular producto?',

            // Cantidad (1..disponible), motivo obligatorio e importe que
            // se anulará calculado con el precio unitario.
            html:
                '<div style="text-align:left;">' +
                    avisoPreparacion +
                    '<p style="margin:0 0 10px;"><strong>' +
                        escapeHtml(nombre) +
                    '</strong></p>' +
                    '<label for="anular-cantidad" style="font-size:12px;">' +
                        'Cantidad a anular (1 a ' + cantidadMax + ')' +
                    '</label>' +
                    '<input id="anular-cantidad" type="number" ' +
                        'min="1" max="' + cantidadMax + '" step="1" value="1" ' +
                        'class="swal2-input" style="margin:6px 0 12px;">' +
                    '<label for="anular-motivo" style="font-size:12px;">' +
                        'Motivo de la anulación (obligatorio)' +
                    '</label>' +
                    '<textarea id="anular-motivo" class="swal2-textarea" ' +
                        'maxlength="500" placeholder="Escribe el motivo (obligatorio)">' +
                    '</textarea>' +
                    '<div style="margin-top:10px;font-size:13px;">' +
                        'Importe a anular: ' +
                        '<strong id="anular-importe">C$0.00</strong>' +
                    '</div>' +
                '</div>',

            focusConfirm: false,

            showCancelButton: true,

            confirmButtonColor: '#fc544b',

            confirmButtonText: 'Sí, anular',

            cancelButtonText: 'Cancelar',

            didOpen: function () {

                const inputCantidad =
                    document.getElementById('anular-cantidad');

                const importe =
                    document.getElementById('anular-importe');

                function actualizarImporte() {

                    const cantidad =
                        Number(inputCantidad.value) || 0;

                    importe.textContent =
                        'C$' + (cantidad * precio).toFixed(2);
                }

                inputCantidad.addEventListener(
                    'input',
                    actualizarImporte
                );

                actualizarImporte();
            },

            preConfirm: function () {

                const inputCantidad =
                    document.getElementById('anular-cantidad');

                const inputMotivo =
                    document.getElementById('anular-motivo');

                const cantidad = Number(inputCantidad.value);

                const motivo = (inputMotivo.value || '').trim();

                if (
                    !Number.isInteger(cantidad) ||
                    cantidad < 1 ||
                    cantidad > cantidadMax
                ) {

                    Swal.showValidationMessage(
                        'La cantidad debe estar entre 1 y ' +
                        cantidadMax +
                        '.'
                    );

                    return false;
                }

                if (motivo.length < 3) {

                    Swal.showValidationMessage(
                        'Escribe un motivo de al menos 3 caracteres.'
                    );

                    return false;
                }

                return { cantidad: cantidad, motivo: motivo };
            }

        }).then(function (result) {

            if (!result.isConfirmed) {
                return;
            }

            const form = new FormData();

            form.append(
                '_token',
                '{{ csrf_token() }}'
            );

            form.append(
                'cantidad',
                result.value.cantidad
            );

            form.append(
                'motivo',
                result.value.motivo
            );

            // Cuenta desde la que se anula (?cuenta=). Sin cuenta, el backend
            // mantiene el reparto global anterior.
            const cuentaId =
                boton.dataset.cuentaId;

            if (cuentaId) {

                form.append(
                    'cuenta_id',
                    cuentaId
                );
            }

            fetch(
                '/pos/detalle/' + detalleId + '/anular',
                {
                    method: 'POST',
                    body: form
                }
            )
            .then(function (response) {

                return response.json();
            })
            .then(function (data) {

                if (!data.exito) {

                    throw new Error(
                        data.error ||
                        'No se pudo anular el producto.'
                    );
                }

                Swal.fire(
                    'Producto anulado',
                    data.mensaje ||
                    'El producto fue anulado correctamente.',
                    'success'
                ).then(function () {

                    location.reload();
                });
            })
            .catch(function (error) {

                Swal.fire(
                    'Error',
                    error.message,
                    'error'
                );
            });
        });
    }

    function imprimirTodas() {

        // Si el POS está acotado a una cuenta, la impresión masiva muestra
        // solo las comandas con productos de esa cuenta.
        const cuentaActualId =
            document.getElementById('cuenta-actual-id');

        const cuentaQuery =
            cuentaActualId && cuentaActualId.value
                ? '&cuenta=' + cuentaActualId.value
                : '';

        const url =
            '/pos/comandas/{{ $venta->id }}?reimpresion=1' + cuentaQuery;

        window.open(
            url,
            '_blank',
            'width=450,height=700'
        );
    }

    function imprimirComandaCompleta() {

        const cuentaActualId =
            document.getElementById('cuenta-actual-id');

        const cuentaQuery =
            cuentaActualId && cuentaActualId.value
                ? '?cuenta=' + cuentaActualId.value
                : '';

        const url =
            '/pos/comanda-completa/{{ $venta->id }}' + cuentaQuery;

        window.open(
            url,
            '_blank',
            'width=450,height=700'
        );
    }

    function inicializarBotones() {

        const btnEnviar =
            document.getElementById(
                'btn-enviar-pedido'
            );

        if (btnEnviar) {
            btnEnviar.addEventListener(
                'click',
                enviarCocina
            );
        }

        const btnImprimirTodas =
            document.getElementById(
                'btn-imprimir-todas'
            );

        if (btnImprimirTodas) {
            btnImprimirTodas.addEventListener(
                'click',
                imprimirTodas
            );
        }

        const btnImprimirCompleta =
            document.getElementById(
                'btn-imprimir-completa'
            );

        if (btnImprimirCompleta) {
            btnImprimirCompleta.addEventListener(
                'click',
                imprimirComandaCompleta
            );
        }

        const btnLiberar =
            document.getElementById(
                'btn-liberar-mesa'
            );

        if (btnLiberar) {
            btnLiberar.addEventListener(
                'click',
                liberarMesaVacia
            );
        }

        const btnPrefactura =
            document.getElementById(
                'btn-prefactura'
            );

        if (btnPrefactura) {

            btnPrefactura.addEventListener(
                'click',
                abrirPrefactura
            );
        }

        const btnCobrar =
            document.getElementById(
                'btn-cobrar'
            );

        if (btnCobrar) {

            btnCobrar.addEventListener(
                'click',
                cobrarCuenta
            );
        }

        const btnDividir = document.getElementById('btn-dividir-cuenta');
        if (btnDividir) {
            btnDividir.addEventListener('click', function () {
                // Si el POS está acotado a una cuenta (?cuenta=), la división se
                // hace SOLO sobre esa cuenta (subdivisión). En la vista general
                // (sin cuenta) se conserva la división a nivel de mesa.
                const campoCuenta =
                    document.getElementById(
                        'cuenta-actual-id'
                    );
                const origen =
                    campoCuenta && campoCuenta.value
                        ? Number(campoCuenta.value)
                        : null;

                gestionarCuentas(origen);
            });
        }
    }

    // Alcance actual de la división: id de la cuenta que se está subdividiendo
    // o null cuando se divide a nivel de mesa (vista general del POS).
    let origenDivisionId = null;

    function aplicarAlcanceSubdivision(data, origenId) {
        // AISLAMIENTO ESTRICTO: la subdivisión trabaja SOLO con los consumos
        // propios del origen. Las subcuentas que YA tienen consumo quedan
        // fuera del alcance (no se muestran ni se tocan). Las subcuentas
        // VACÍAS sí pueden recibir unidades, igual que valida el backend.
        const conConsumo = new Set(
            data.asignaciones
                .filter(asig => Number(asig.cantidad) > 0)
                .map(asig => String(asig.cuenta_id))
        );

        const alcance = data.cuentas.filter(cuenta => {
            if (String(cuenta.id) === String(origenId)) return true;
            const esHija = String(cuenta.cuenta_origen_id) === String(origenId);
            return esHija && !conConsumo.has(String(cuenta.id));
        });

        const origen = alcance.find(cuenta => String(cuenta.id) === String(origenId));

        if (!origen || origen.estado !== 'ABIERTA') {
            Swal.fire('Cuenta no disponible', 'Esta cuenta no puede dividirse porque no está abierta.', 'info');
            return false;
        }

        // Aislamiento: solo se divide una cuenta con consumos PROPIOS activos.
        // Una cuenta vacía no puede usar los consumos de sus subcuentas.
        const unidadesOrigen = data.asignaciones
            .filter(asig => String(asig.cuenta_id) === String(origenId))
            .reduce((suma, asig) => suma + Number(asig.cantidad), 0);

        if (unidadesOrigen <= 0) {
            Swal.fire('Sin consumos', 'Esta cuenta no tiene consumos propios para dividir.', 'info');
            return false;
        }

        const ids = new Set(alcance.map(cuenta => String(cuenta.id)));
        // Solo las asignaciones del origen y de las subcuentas vacías incluidas.
        const asignaciones = data.asignaciones.filter(asig =>
            ids.has(String(asig.cuenta_id)) && String(asig.cuenta_id) === String(origenId)
        );

        // Unidades por línea: solo las propias del origen (las subcuentas
        // vacías aportan 0). Las líneas sin unidades propias no son divisibles.
        const porLinea = {};
        asignaciones.forEach(asig => {
            const clave = String(asig.venta_detalle_id);
            porLinea[clave] = porLinea[clave] || { abierta: 0, pagada: 0 };
            if (origen.estado === 'ABIERTA') porLinea[clave].abierta += Number(asig.cantidad);
            else if (origen.estado === 'PAGADA') porLinea[clave].pagada += Number(asig.cantidad);
        });

        // Se acota el POS al origen (y subcuentas vacías como destino).
        data.cuentas = alcance;
        data.asignaciones = asignaciones;
        data.detalles = data.detalles
            .filter(detalle => porLinea[String(detalle.id)] && porLinea[String(detalle.id)].abierta > 0)
            .map(detalle => Object.assign({}, detalle, {
                cantidad: porLinea[String(detalle.id)].abierta + porLinea[String(detalle.id)].pagada
            }));

        if (!data.detalles.length) {
            Swal.fire('Sin consumos', 'Esta cuenta no tiene consumos pendientes para dividir.', 'info');
            return false;
        }

        return true;
    }

    function gestionarCuentas(origenId) {
        origenId = origenId || null;
        origenDivisionId = origenId;
        fetch('{{ route("pos.cuentas", ["mesaId" => $mesa->id]) }}')
            .then(response => response.json())
            .then(data => {
                if (!data.exito) throw new Error(data.error || 'No se pudieron cargar las cuentas.');

                // Si el POS está acotado a una cuenta, la división solo abarca
                // el origen y sus subcuentas: las cuentas hermanas no se tocan.
                if (origenId && !aplicarAlcanceSubdivision(data, origenId)) {
                    return;
                }

                const abiertas = data.cuentas.filter(cuenta => cuenta.estado === 'ABIERTA');
                if (abiertas.length === 0) {
                    Swal.fire('Sin cuentas abiertas', 'Todas las cuentas de esta mesa ya fueron pagadas.', 'info');
                    return;
                }
                const abiertaIds = new Set(abiertas.map(cuenta => String(cuenta.id)));

                // Unidades ya pagadas por línea: no son reasignables.
                const pagadasPorLinea = {};
                data.cuentas.forEach(cuenta => {
                    if (cuenta.estado !== 'PAGADA') return;
                    data.asignaciones.forEach(asig => {
                        if (String(asig.cuenta_id) !== String(cuenta.id)) return;
                        const clave = String(asig.venta_detalle_id);
                        pagadasPorLinea[clave] = (pagadasPorLinea[clave] || 0) + Number(asig.cantidad);
                    });
                });

                // Asignaciones actuales solo de cuentas abiertas: (detalle, cuenta).
                const previas = {};
                data.asignaciones.forEach(asig => {
                    if (!abiertaIds.has(String(asig.cuenta_id))) return;
                    previas[asig.venta_detalle_id + ':' + asig.cuenta_id] = Number(asig.cantidad);
                });

                function valorEntero(inp) {
                    // Devuelve 0 si está vacío; -1 si no es un entero >= 0; o la cantidad válida.
                    if (inp.value.trim() === '') return 0;
                    const n = Number(inp.value);
                    if (!Number.isInteger(n) || n < 0) return -1;
                    return n;
                }

                const claveCampo = inp => String(inp.dataset.detalle) + ':' + String(inp.dataset.cuenta);

                // Último valor conocido de cada campo (detalle:cuenta). Sirve para
                // saber el delta al editar y compensarlo automáticamente.
                const previos = {};

                function repartiblesDe(detalle) {
                    return Number(detalle.cantidad) - Number(pagadasPorLinea[String(detalle.id)] || 0);
                }

                function balancear(inp) {
                    // Al editar un campo, la línea conserva SIEMPRE el total repartible:
                    // lo que se aumenta se quita de las demás cuentas y lo que se suelta
                    // se devuelve a ellas. El campo editado NUNCA se modifica por su cuenta.
                    const detalle = data.detalles.find(d => String(d.id) === String(inp.dataset.detalle));
                    if (!detalle) return recalcular();
                    const clave = claveCampo(inp);
                    const nuevo = valorEntero(inp);
                    const antes = (previos[clave] !== undefined) ? previos[clave] : nuevo;

                    if (nuevo < 0) return recalcular(); // Valor inválido: solo se marca en rojo.

                    if (nuevo !== antes) {
                        const repartibles = repartiblesDe(detalle);
                        const campos = Array.from(document.querySelectorAll('.asig-cantidad[data-detalle="' + detalle.id + '"]'));
                        const resto = campos.filter(c => c !== inp);

                        if (nuevo <= repartibles) {
                            // Lo que deben sumar las demás cuentas para cubrir la línea.
                            const objetivoOtros = repartibles - nuevo;
                            const actuales = resto.map(c => ({ c: c, v: valorEntero(c) }));
                            let dif = objetivoOtros - actuales.reduce((acc, f) => acc + Math.max(f.v, 0), 0);
                            // Orden desc: se ajusta primero la cuenta con más unidades
                            // (empates: la primera del DOM, normalmente la principal).
                            const mayores = actuales.slice().sort((a, b) => b.v - a.v);

                            if (dif > 0) {
                                // Sobran unidades: se devuelven a las demás cuentas.
                                for (const f of mayores) {
                                    if (dif <= 0) break;
                                    f.c.value = String(Math.max(f.v, 0) + dif);
                                    previos[claveCampo(f.c)] = Math.max(f.v, 0) + dif;
                                    dif = 0;
                                }
                            } else if (dif < 0) {
                                // Faltan unidades: se quitan de las demás cuentas.
                                let porQuitar = -dif;
                                for (const f of mayores) {
                                    if (porQuitar <= 0) break;
                                    const disp = Math.max(f.v, 0);
                                    const quitar = Math.min(disp, porQuitar);
                                    if (quitar > 0) f.c.value = String(disp - quitar);
                                    previos[claveCampo(f.c)] = disp - quitar;
                                    porQuitar -= quitar;
                                }
                            }
                        } else {
                            // nuevo > repartibles: no hay forma de cubrirlo. No se toca
                            // nada del campo editado; recalcular() lo marca en rojo.
                        }
                        previos[clave] = nuevo;
                    }

                    recalcular();
                }

                function recalcular() {
                    // Por producto: la suma de las cuentas abiertas debe ser exactamente
                    // repartibles (= cantidad - unidades ya pagadas).
                    data.detalles.forEach(detalle => {
                        const repartibles = repartiblesDe(detalle);
                        const campos = Array.from(document.querySelectorAll('.asig-cantidad[data-detalle="' + detalle.id + '"]'));
                        const valores = campos.map(c => valorEntero(c));
                        const algunInvalido = valores.some(v => v < 0);
                        const suma = valores.reduce((acc, v) => acc + Math.max(v, 0), 0);
                        const exceso = suma - repartibles;

                        const el = document.querySelector('.linea-suma[data-detalle="' + detalle.id + '"]');
                        let texto = 'Asignadas: ' + suma + ' / ' + repartibles + ' u.';
                        if (algunInvalido) {
                            texto = 'Cantidad inválida (entero ≥ 0). ' + texto;
                        } else if (exceso > 0) {
                            texto = 'Excede en ' + exceso + ' u. ' + texto;
                        } else if (exceso < 0) {
                            texto = 'Faltan ' + (-exceso) + ' u. ' + texto;
                        }
                        el.textContent = texto;
                        el.style.color = (!algunInvalido && exceso === 0) ? '#2e7d32' : '#c62828';
                    });

                    // Subtotales por cuenta: cantidad × precio_unitario, recalculados desde cero.
                    const totales = {};
                    document.querySelectorAll('.asig-cantidad').forEach(inp => {
                        const v = valorEntero(inp);
                        if (v <= 0) return;
                        const detalle = data.detalles.find(d => String(d.id) === inp.dataset.detalle);
                        if (!detalle) return;
                        const claveCuenta = String(inp.dataset.cuenta);
                        totales[claveCuenta] = (totales[claveCuenta] || 0) + v * Number(detalle.precio_unitario);
                    });
                    abiertas.forEach(cuenta => {
                        const el = document.querySelector('.resumen-cuenta[data-cuenta="' + cuenta.id + '"]');
                        if (el) el.textContent = 'C$ ' + (totales[String(cuenta.id)] || 0).toFixed(2);
                    });
                }

                const filas = data.detalles.map(detalle => {
                    const disponibles = Number(detalle.cantidad);
                    const enPagadas = Number(pagadasPorLinea[String(detalle.id)] || 0);
                    const inputs = abiertas.map(cuenta => {
                        const pre = Number(previas[detalle.id + ':' + cuenta.id] || 0);
                        return (
                            '<div style="display:flex;align-items:center;gap:6px;margin:3px 0 0 8px;text-align:left;">' +
                                '<label style="flex:1;font-size:12px;color:#555;">' + escaparHtml(cuenta.nombre) + '</label>' +
                                '<input type="number" min="0" max="' + (disponibles - enPagadas) + '" step="1" value="' + pre + '" ' +
                                    'class="swal2-input asig-cantidad" data-detalle="' + detalle.id + '" data-cuenta="' + cuenta.id + '" ' +
                                    'style="width:80px;margin:0;padding:4px 8px;font-size:13px;">' +
                            '</div>'
                        );
                    }).join('');
                    return (
                        '<div style="border-top:1px solid #eee;padding:8px 0;">' +
                            '<div style="display:flex;justify-content:space-between;align-items:center;text-align:left;">' +
                                '<strong>' + escaparHtml(detalle.nombre) + '</strong>' +
                                '<span style="font-size:12px;color:#777;">' +
                                    (enPagadas > 0 ? enPagadas + ' pagada(s) · ' : '') +
                                    disponibles + ' u. · C$ ' + Number(detalle.precio_unitario).toFixed(2) + ' c/u' +
                                '</span>' +
                            '</div>' +
                            inputs +
                            '<div class="linea-suma" data-detalle="' + detalle.id + '" style="font-size:12px;margin-top:4px;text-align:left;"></div>' +
                        '</div>'
                    );
                }).join('');

                const resumen = abiertas.map(cuenta =>
                    '<div style="text-align:left;font-size:13px;margin:2px 0;">' +
                        escaparHtml(cuenta.nombre) + ': <strong class="resumen-cuenta" data-cuenta="' + cuenta.id + '">C$ 0.00</strong>' +
                    '</div>'
                ).join('');

                Swal.fire({
                    title: 'Dividir cuentas',
                    html:
                        '<p style="font-size:13px">Indica cuántas unidades de cada producto van a cada cuenta abierta. ' +
                        'La suma por línea debe coincidir con las unidades disponibles.</p>' +
                        filas +
                        '<hr style="margin:8px 0;">' + resumen,
                    showCancelButton: true,
                    showDenyButton: true,
                    denyButtonText: 'Nueva cuenta',
                    confirmButtonText: 'Guardar división',
                    cancelButtonText: 'Cancelar',
                    didOpen: () => {
                        document.querySelectorAll('.asig-cantidad').forEach(input => {
                            previos[claveCampo(input)] = valorEntero(input);
                            input.addEventListener('input', () => balancear(input));
                        });
                        recalcular();
                    },
                    preConfirm: () => {
                        const errores = [];
                        const asignaciones = [];
                        document.querySelectorAll('.asig-cantidad').forEach(inp => {
                            const valor = inp.value.trim() === '' ? 0 : Number(inp.value);
                            if (!Number.isInteger(valor) || valor < 0) {
                                errores.push('Cantidad inválida en una línea.');
                                return;
                            }
                            if (valor > Number(inp.max)) {
                                errores.push('Una línea supera lo disponible.');
                                return;
                            }
                            if (valor > 0) {
                                asignaciones.push({
                                    cuenta_id: Number(inp.dataset.cuenta),
                                    venta_detalle_id: Number(inp.dataset.detalle),
                                    cantidad: valor
                                });
                            }
                        });
                        data.detalles.forEach(detalle => {
                            let suma = 0;
                            asignaciones.forEach(asig => {
                                if (asig.venta_detalle_id === detalle.id) suma += asig.cantidad;
                            });
                            const repartibles = Number(detalle.cantidad) - Number(pagadasPorLinea[String(detalle.id)] || 0);
                            if (suma !== repartibles) {
                                errores.push('"' + detalle.nombre + '" reparte ' + suma + ' de ' + repartibles + ' unidades.');
                            }
                        });
                        if (errores.length) {
                            Swal.showValidationMessage(errores.join(' '));
                            return false;
                        }
                        if (!asignaciones.length) {
                            Swal.showValidationMessage('Asigna al menos una unidad.');
                            return false;
                        }
                        return { asignaciones: asignaciones };
                    }
                }).then(result => {
                    if (result.isDenied) return crearCuentaParaDivision(data);
                    if (!result.isConfirmed) return;
                    guardarDivision(result.value);
                });
            })
            .catch(error => Swal.fire('Error', error.message, 'error'));
    }

    function crearCuentaParaDivision(data) {
        Swal.fire({ title: 'Nueva cuenta', input: 'text', inputLabel: 'Nombre (ej. Ana)', inputValidator: valor => !valor.trim() && 'Escribe un nombre.' })
            .then(result => {
                if (!result.isConfirmed) return;
                const abiertas = data.cuentas.filter(cuenta => cuenta.estado === 'ABIERTA');
                if (!abiertas.length) return;
                const abiertaIds = new Set(abiertas.map(cuenta => String(cuenta.id)));

                // Se reconstruye la distribución actual SOLO entre cuentas abiertas,
                // para que al crear la cuenta nueva no se muevan líneas por error.
                // Las asignaciones de cuentas pagadas no se envían (no son editables).
                const mapa = {};
                data.asignaciones.forEach(asig => {
                    if (!abiertaIds.has(String(asig.cuenta_id))) return;
                    const clave = String(asig.venta_detalle_id);
                    (mapa[clave] = mapa[clave] || []).push({
                        cuenta_id: asig.cuenta_id,
                        cantidad: asig.cantidad
                    });
                });

                const asignaciones = [];
                data.detalles.forEach(detalle => {
                    (mapa[String(detalle.id)] || []).forEach(fila => {
                        asignaciones.push({
                            cuenta_id: fila.cuenta_id,
                            venta_detalle_id: detalle.id,
                            cantidad: fila.cantidad
                        });
                    });
                });

                if (!asignaciones.length) {
                    Swal.fire('Sin asignaciones', 'No hay unidades abiertas para repartir.', 'info');
                    return;
                }

                guardarDivision({ cuentas_nuevas: [result.value.trim()], asignaciones }, function () {
                    // Se reabre el mismo alcance (la subdivisión si el POS está
                    // acotado a una cuenta, o la mesa si no lo está).
                    gestionarCuentas(origenDivisionId);
                });
            });
    }

    function guardarDivision(payload, despues) {
        const form = new FormData();
        form.append('_token', '{{ csrf_token() }}');
        form.append('asignaciones', JSON.stringify(payload.asignaciones));
        // Laravel reconoce los índices del arreglo enviados como campos de formulario.
        payload.asignaciones.forEach((item, indice) => {
            form.append(`asignaciones[${indice}][cuenta_id]`, item.cuenta_id);
            form.append(`asignaciones[${indice}][venta_detalle_id]`, item.venta_detalle_id);
            form.append(`asignaciones[${indice}][cantidad]`, item.cantidad);
        });
        (payload.cuentas_nuevas || []).forEach((nombre, indice) => form.append(`cuentas_nuevas[${indice}]`, nombre));
        // Si estamos subdividiendo una cuenta concreta, se envía el origen para
        // que el backend reparta SOLO esa cuenta y no toque las demás.
        if (origenDivisionId) form.append('cuenta_origen_id', origenDivisionId);
        fetch('{{ route("pos.cuentas.dividir", ["mesaId" => $mesa->id]) }}', { method: 'POST', body: form })
            .then(response => response.json())
            .then(data => {
                if (!data.exito) throw new Error(data.error || 'No se pudo guardar.');
                Swal.fire('Listo', data.mensaje, 'success').then(() => despues ? despues() : location.reload());
            })
            .catch(error => Swal.fire('Error', error.message, 'error'));
    }

    /**
     * Clave de operación de una línea de pago: el navegador genera un id
     * único por línea y el backend lo guarda en `pagos_cuenta.clave_operacion`
     * (columna única). Un reintento con la misma clave nunca duplica pagos.
     */
    function claveOperacionPago() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        return 'pago-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    }


    function abrirPrefactura() {
        const campoCuenta = document.getElementById('cuenta-actual-id');
        if (campoCuenta && campoCuenta.value) {
            fetch('{{ route("pos.cuentas", ["mesaId" => $mesa->id]) }}')
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    const c = (data.cuentas || []).find(function (x) { return String(x.id) === String(campoCuenta.value); });
                    window.abrirPrefacturaModal(campoCuenta.value, c ? c.total : 0);
                })
                .catch(function () {
                    window.abrirPrefacturaModal(campoCuenta.value, 0);
                });
            return;
        }
        window.abrirPrefacturaModal(null, 0);
    }

    /**
     * Mantiene la referencia esperada por el POS y las pruebas: abre el modal
     * de configuración fiscal de la prefactura para una cuenta concreta. La
     * lógica reside en la función compartida window.abrirPrefacturaModal.
     */
    function abrirPrefacturaModalCuenta(cuentaId, consumo) {
        window.abrirPrefacturaModal(cuentaId, consumo);
    }

    function cobrarCuenta() {
        fetch('{{ route("pos.cuentas", ["mesaId" => $mesa->id]) }}')
            .then(response => response.json())
            .then(data => {
                const abiertas = (data.cuentas || []).filter(cuenta => cuenta.estado === 'ABIERTA' && Number(cuenta.total) > 0);
                if (!abiertas.length) throw new Error('Primero divide y guarda las cuentas con productos asignados.');

                // POS acotado a una cuenta (?cuenta=): esa queda preseleccionada.
                const campoCuenta = document.getElementById('cuenta-actual-id');
                const cuentaAcotada = campoCuenta && campoCuenta.value ? String(campoCuenta.value) : '';

                const opciones = abiertas.map(cuenta => {
                    const marcada = String(cuenta.id) === cuentaAcotada ? ' selected' : '';
                    return `<option value="${cuenta.id}" data-total="${cuenta.total}"${marcada}>${escaparHtml(cuenta.nombre)} — C$ ${Number(cuenta.total).toFixed(2)}</option>`;
                }).join('');

                // Ajustes (descuento/propina): ADMIN, GERENTE y CAJERO pueden aplicarlos.
                // Si el rol no puede aplicarlos, el modal ni siquiera los muestra.
                // El descuento y la propina se pueden introducir como PORCENTAJE
                // (%) o como IMPORTE (C$): el servidor recalcula siempre.
                const htmlFiscales = `
                    <div style="text-align:left;font-size:12px;color:#555;border-top:1px dashed #aaa;margin-top:8px;padding-top:8px;">
                        <strong>Conceptos fiscales</strong>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
                            <button type="button" class="fiscal-toggle" data-concepto="descuento" data-active="${descuentoActivo ? 'true' : 'false'}" aria-pressed="${descuentoActivo}" ${!descuentoActivo && !PUEDE_EXCEPCION_FISCAL ? 'disabled' : ''} style="min-height:44px;border:1px solid ${descuentoActivo ? '#47c363' : '#ccc'};background:${descuentoActivo ? '#e7f7ed' : '#f5f5f5'};color:${descuentoActivo ? '#1e7e46' : '#999'};border-radius:6px;cursor:${descuentoActivo || PUEDE_EXCEPCION_FISCAL ? 'pointer' : 'not-allowed'};font-weight:700;">Descuento${!descuentoActivo && !PUEDE_EXCEPCION_FISCAL ? '<br><small>Descuento desactivado globalmente</small>' : ''}</button>
                            <button type="button" class="fiscal-toggle" data-concepto="propina" data-active="${propinaActiva ? 'true' : 'false'}" aria-pressed="${propinaActiva}" ${!propinaActiva && !PUEDE_EXCEPCION_FISCAL ? 'disabled' : ''} style="min-height:44px;border:1px solid ${propinaActiva ? '#47c363' : '#ccc'};background:${propinaActiva ? '#e7f7ed' : '#f5f5f5'};color:${propinaActiva ? '#1e7e46' : '#999'};border-radius:6px;cursor:${propinaActiva || PUEDE_EXCEPCION_FISCAL ? 'pointer' : 'not-allowed'};font-weight:700;">Propina${!propinaActiva && !PUEDE_EXCEPCION_FISCAL ? '<br><small>Propina desactivada globalmente</small>' : ''}</button>
                            <button type="button" class="fiscal-toggle" data-concepto="iva" data-active="${ivaActivo ? 'true' : 'false'}" aria-pressed="${ivaActivo}" ${!ivaActivo && !PUEDE_EXCEPCION_FISCAL ? 'disabled' : ''} style="min-height:44px;border:1px solid ${ivaActivo ? '#47c363' : '#ccc'};background:${ivaActivo ? '#e7f7ed' : '#f5f5f5'};color:${ivaActivo ? '#1e7e46' : '#999'};border-radius:6px;cursor:${ivaActivo || PUEDE_EXCEPCION_FISCAL ? 'pointer' : 'not-allowed'};font-weight:700;">IVA 15%${!ivaActivo && !PUEDE_EXCEPCION_FISCAL ? '<br><small>IVA desactivado en la configuración</small>' : ''}</button>
                            <button type="button" class="fiscal-toggle" data-concepto="dgi" data-active="${retDgiActivo ? 'true' : 'false'}" aria-pressed="${retDgiActivo}" ${!retDgiActivo && !PUEDE_EXCEPCION_FISCAL ? 'disabled' : ''} style="min-height:44px;border:1px solid ${retDgiActivo ? '#47c363' : '#ccc'};background:${retDgiActivo ? '#e7f7ed' : '#f5f5f5'};color:${retDgiActivo ? '#1e7e46' : '#999'};border-radius:6px;cursor:${retDgiActivo || PUEDE_EXCEPCION_FISCAL ? 'pointer' : 'not-allowed'};font-weight:700;">Retención DGI 2%${!retDgiActivo && !PUEDE_EXCEPCION_FISCAL ? '<br><small>' + (!RET_DGI_HABILITADO ? 'Desactivada en la configuración' : 'Cliente no sujeto a retención') + '</small>' : ''}</button>
                            <button type="button" class="fiscal-toggle" data-concepto="alc" data-active="${retAlcActivo ? 'true' : 'false'}" aria-pressed="${retAlcActivo}" ${!retAlcActivo && !PUEDE_EXCEPCION_FISCAL ? 'disabled' : ''} style="min-height:44px;border:1px solid ${retAlcActivo ? '#47c363' : '#ccc'};background:${retAlcActivo ? '#e7f7ed' : '#f5f5f5'};color:${retAlcActivo ? '#1e7e46' : '#999'};border-radius:6px;cursor:${retAlcActivo || PUEDE_EXCEPCION_FISCAL ? 'pointer' : 'not-allowed'};font-weight:700;">Retención Alcaldía 1%${!retAlcActivo && !PUEDE_EXCEPCION_FISCAL ? '<br><small>' + (!RET_ALC_HABILITADO ? 'Desactivada en la configuración' : 'Cliente no sujeto a retención') + '</small>' : ''}</button>
                        </div>
                    </div>
                `;

                const htmlAjustes = PUEDE_AJUSTAR_COBRO ? `
                    <div class="cobro-grid">
                        <div class="cobro-bloque" id="bloque-descuento" style="${descuentoActivo ? 'display:flex;' : 'display:none;'}">
                            <strong>Descuento</strong>
                            <label for="descuento-porcentaje-cobro" style="display:block;margin:4px 0 2px;">Porcentaje (%)</label>
                            <input id="descuento-porcentaje-cobro" class="swal2-input" type="number" min="0" max="100" step="0.01" inputmode="decimal" placeholder="0.00" aria-describedby="error-descuento-cobro" style="min-height:44px;">
                            <label for="descuento-importe-cobro" style="display:block;margin:4px 0 2px;">Importe fijo (C$)</label>
                            <input id="descuento-importe-cobro" class="swal2-input" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00" aria-describedby="error-descuento-cobro" style="min-height:44px;">
                            <input id="motivo-cobro" class="swal2-input" type="text" maxlength="255" placeholder="Motivo del descuento (opcional)">
                            <div id="error-descuento-cobro" style="color:#c0392b;font-size:11px;min-height:12px;margin-top:2px;"></div>
                        </div>
                        <div class="cobro-bloque" id="bloque-propina" style="${propinaActiva ? 'display:flex;' : 'display:none;'}">
                            <strong>Propina</strong>
                            <label for="propina-porcentaje-cobro" style="display:block;margin:4px 0 2px;">Porcentaje (%)</label>
                            <input id="propina-porcentaje-cobro" class="swal2-input" type="number" min="0" max="100" step="0.01" inputmode="decimal" placeholder="0.00" aria-describedby="error-propina-cobro" style="min-height:44px;">
                            <label for="propina-importe-cobro" style="display:block;margin:4px 0 2px;">Importe fijo (C$)</label>
                            <input id="propina-importe-cobro" class="swal2-input" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00" aria-describedby="error-propina-cobro" style="min-height:44px;">
                            <div style="display:flex;gap:6px;">
                                <button type="button" id="propina-sugerir" style="flex:1;background:#6777ef;color:#fff;border:0;border-radius:4px;padding:8px;cursor:pointer;font-size:11px;min-height:44px;">Propina 15%</button>
                                <button type="button" id="propina-quitar" style="flex:1;background:#c0392b;color:#fff;border:0;border-radius:4px;padding:8px;cursor:pointer;font-size:11px;min-height:44px;">Quitar</button>
                            </div>
                            <div id="error-propina-cobro" style="color:#c0392b;font-size:11px;min-height:12px;margin-top:2px;"></div>
                        </div>
                    </div>
                ` : '';

                // Catálogo de métodos (idéntico a VentaController::METODOS_PAGO).
                const etiquetasMetodo = {
                    EFECTIVO_CORDOBA: 'Efectivo C$',
                    EFECTIVO_USD: 'Efectivo USD',
                    TARJETA_DEBITO: 'Tarjeta débito',
                    TARJETA_CREDITO: 'Tarjeta crédito',
                    TRANSFERENCIA: 'Transferencia bancaria'
                };
                const esEfectivo = metodo => metodo === 'EFECTIVO_CORDOBA' || metodo === 'EFECTIVO_USD';
                const esTarjeta = metodo => metodo === 'TARJETA_DEBITO' || metodo === 'TARJETA_CREDITO';
                const esTransferencia = metodo => metodo === 'TRANSFERENCIA';

                // Estado del modal: las líneas de pago que se van agregando.
                let lineasPago = [];

                // División de pagos: null (sin división), 'AUTOMATICA' o
                // 'MANUAL'. `totalDivisionAplicado` sirve para bloquear el
                // cobro si el total cambia después de aplicar la división.
                let modoDivision = null;
                let totalDivisionAplicado = null;

                // Métodos disponibles en la división. Son SOLO los métodos
                // reales del sistema. El efectivo USD se sigue agregando como
                // línea de pago normal (requiere tasa y no permite garantizar
                // centavos exactos en una división en córdobas).
                const metodosDivision = ['EFECTIVO_CORDOBA', 'TARJETA_DEBITO', 'TARJETA_CREDITO', 'TRANSFERENCIA'];
                const bancosDivision = ['Banpro', 'Ficohsa', 'BAC', 'Lafise'];

                const redondearCentavos = valor => Math.round((Number(valor) + Number.EPSILON) * 100) / 100;

                // Reparte un total en `cantidad` partes iguales resolviendo los
                // centavos: las últimas partes reciben 1 centavo adicional.
                // La suma SIEMPRE coincide exactamente con el total.
                function repartirPartes(total, cantidad) {
                    const centavos = Math.round(Number(total) * 100);
                    const base = Math.floor(centavos / cantidad);
                    const resto = centavos - (base * cantidad);

                    return Array.from({ length: cantidad }, function (_, indice) {
                        return (base + (indice >= cantidad - resto ? 1 : 0)) / 100;
                    });
                }

                function filaDivisionHtml(indice, metodo, monto, soloLectura) {
                    const opciones = metodosDivision.map(function (m) {
                        return `<option value="${m}"${m === metodo ? ' selected' : ''}>${etiquetasMetodo[m]}</option>`;
                    }).join('');

                    const requiereBanco = esTarjeta(metodo) || esTransferencia(metodo);
                    const bancos = bancosDivision.map(function (b) {
                        return `<option value="${b}">${b}</option>`;
                    }).join('');

                    return `<div class="division-fila" data-indice="${indice}" style="border:1px solid #e3e6f0;border-radius:6px;padding:6px;margin-bottom:6px;">
                        <div style="display:flex;gap:6px;align-items:center;">
                            <span style="font-size:11px;color:#777;min-width:18px;">${indice + 1}.</span>
                            <select class="division-metodo swal2-select" style="margin:0;flex:1;min-height:40px;font-size:12px;" aria-label="Método de la forma de pago ${indice + 1}">${opciones}</select>
                            <input class="division-monto swal2-input" type="number" min="0" step="0.01" inputmode="decimal" value="${Number(monto).toFixed(2)}" ${soloLectura ? 'readonly' : ''} style="margin:0;width:110px;min-height:40px;font-size:12px;background:${soloLectura ? '#f5f5f5' : '#fff'};" aria-label="Importe de la forma de pago ${indice + 1}">
                        </div>
                        <select class="division-banco swal2-select" style="margin:4px 0 0;display:${requiereBanco ? 'block' : 'none'};min-height:40px;font-size:12px;" aria-label="Banco de la forma de pago ${indice + 1}">
                            <option value="">Seleccionar banco…</option>
                            ${bancos}
                        </select>
                        <input class="division-numero swal2-input" type="text" placeholder="Número de transferencia (solo dígitos)" maxlength="64" style="margin:4px 0 0;display:${esTransferencia(metodo) ? 'block' : 'none'};min-height:40px;font-size:12px;" aria-label="Número de transferencia de la forma de pago ${indice + 1}">
                    </div>`;
                }

                function leerFilasDivision(contenedor) {
                    if (!contenedor) return [];

                    return Array.from(contenedor.querySelectorAll('.division-fila')).map(function (fila) {
                        return {
                            metodo: fila.querySelector('.division-metodo').value,
                            monto: numeroEstricto(fila.querySelector('.division-monto').value, 2),
                            banco: fila.querySelector('.division-banco').value,
                            numeroTransferencia: fila.querySelector('.division-numero').value.trim()
                        };
                    });
                }

                function contenedorDivision() {
                    if (modoDivision === 'AUTOMATICA') return document.getElementById('division-filas-auto');
                    if (modoDivision === 'MANUAL') return document.getElementById('division-filas-manual');
                    return null;
                }

                function totalDistribuidoDivision() {
                    const filas = leerFilasDivision(contenedorDivision());
                    return redondearCentavos(filas.reduce(function (suma, fila) {
                        return suma + (Number(fila.monto) || 0);
                    }, 0));
                }

                function construirFilasAutomaticas() {
                    const cont = document.getElementById('division-filas-auto');
                    const campoCantidad = document.getElementById('division-cantidad');
                    if (!cont || !campoCantidad) return;

                    const cantidad = Math.max(2, Math.min(10, parseInt(campoCantidad.value, 10) || 2));
                    campoCantidad.value = cantidad;

                    // Se conservan los métodos ya elegidos por posición.
                    const metodosPrevios = Array.from(cont.querySelectorAll('.division-metodo')).map(function (s) { return s.value; });
                    const bancosPrevios = Array.from(cont.querySelectorAll('.division-banco')).map(function (s) { return s.value; });
                    const numerosPrevios = Array.from(cont.querySelectorAll('.division-numero')).map(function (i) { return i.value; });

                    const partes = repartirPartes(leerTotales().total, cantidad);

                    cont.innerHTML = partes.map(function (monto, indice) {
                        return filaDivisionHtml(indice, metodosPrevios[indice] || 'EFECTIVO_CORDOBA', monto, true);
                    }).join('');

                    Array.from(cont.querySelectorAll('.division-fila')).forEach(function (fila, indice) {
                        const banco = fila.querySelector('.division-banco');
                        const numero = fila.querySelector('.division-numero');
                        if (banco && bancosPrevios[indice]) banco.value = bancosPrevios[indice];
                        if (numero && numerosPrevios[indice]) numero.value = numerosPrevios[indice];
                    });

                    actualizarResumenDivision();
                }

                function agregarFilaManual() {
                    const cont = document.getElementById('division-filas-manual');
                    if (!cont) return;
                    const indice = cont.querySelectorAll('.division-fila').length;
                    cont.insertAdjacentHTML('beforeend', filaDivisionHtml(indice, 'EFECTIVO_CORDOBA', 0, false));
                    actualizarResumenDivision();
                }

                function sincronizarImportesAutomaticos() {
                    if (modoDivision !== 'AUTOMATICA') return;
                    const cont = document.getElementById('division-filas-auto');
                    if (!cont) return;

                    const partes = repartirPartes(leerTotales().total, cont.querySelectorAll('.division-fila').length || 2);
                    cont.querySelectorAll('.division-fila').forEach(function (fila, indice) {
                        const campo = fila.querySelector('.division-monto');
                        if (campo && partes[indice] !== undefined) campo.value = partes[indice].toFixed(2);
                    });
                }

                function actualizarResumenDivision(totales) {
                    const cont = document.getElementById('division-resumen');
                    if (!cont) return;

                    const t = totales || leerTotales();
                    const distribuido = totalDistribuidoDivision();
                    const diferencia = redondearCentavos(distribuido - t.total);
                    const exacto = Math.abs(diferencia) < 0.005;
                    const filas = leerFilasDivision(contenedorDivision());
                    const cantidadFilas = filas.length;

                    let texto = `Total de la cuenta: <strong>C$ ${t.total.toFixed(2)}</strong>`
                        + ` · Distribuido: <strong>C$ ${distribuido.toFixed(2)}</strong>`;

                    if (exacto) {
                        texto += ' · <strong style="color:#1e7e46;">Coincide ✓</strong>';
                    } else if (diferencia < 0) {
                        texto += ` · <strong style="color:#c0392b;">Saldo pendiente: C$ ${Math.abs(diferencia).toFixed(2)}</strong>`;
                    } else {
                        texto += ` · <strong style="color:#c0392b;">Exceso: C$ ${diferencia.toFixed(2)}</strong>`;
                    }

                    if (modoDivision && totalDivisionAplicado !== null && Math.abs(t.total - totalDivisionAplicado) > 0.005) {
                        texto += '<br><strong style="color:#c0392b;">El total cambió: vuelva a aplicar la división.</strong>';
                    }

                    if (modoDivision && cantidadFilas < 2) {
                        texto += '<br><span style="color:#c0392b;">Se requieren al menos dos formas de pago.</span>';
                    }

                    cont.innerHTML = texto;

                    const boton = document.getElementById('division-aplicar');
                    if (boton) {
                        const habilitado = !!modoDivision && exacto && distribuido > 0 && cantidadFilas >= 2;
                        boton.disabled = !habilitado;
                        boton.style.opacity = habilitado ? '1' : '0.5';
                        boton.style.cursor = habilitado ? 'pointer' : 'not-allowed';
                    }
                }

                function refrescarDivision(totales) {
                    if (modoDivision === 'AUTOMATICA') sincronizarImportesAutomaticos();
                    actualizarResumenDivision(totales);
                }

                function aplicarDivision() {
                    const error = document.getElementById('division-error');
                    if (error) error.textContent = '';

                    if (!modoDivision) {
                        if (error) error.textContent = 'Seleccione una modalidad de división.';
                        return;
                    }

                    const cont = contenedorDivision();
                    const filas = leerFilasDivision(cont);
                    const t = leerTotales();
                    const suma = redondearCentavos(filas.reduce(function (total, fila) {
                        return total + (Number(fila.monto) || 0);
                    }, 0));

                    if (filas.length < 2) {
                        if (error) error.textContent = 'La división requiere al menos dos formas de pago.';
                        return;
                    }

                    if (Math.abs(redondearCentavos(suma - t.total)) >= 0.005) {
                        if (error) error.textContent = `La división debe coincidir exactamente con el total (C$ ${t.total.toFixed(2)}). Distribuido: C$ ${suma.toFixed(2)}.`;
                        return;
                    }

                    const nuevasLineas = [];

                    for (let indice = 0; indice < filas.length; indice++) {
                        const fila = filas[indice];
                        const monto = redondearCentavos(fila.monto);

                        if (!Number.isFinite(monto) || !(monto > 0)) {
                            if (error) error.textContent = `La forma de pago ${indice + 1} debe tener un importe mayor que cero.`;
                            return;
                        }

                        if ((esTarjeta(fila.metodo) || esTransferencia(fila.metodo)) && !fila.banco) {
                            if (error) error.textContent = `Seleccione el banco de la forma de pago ${indice + 1}.`;
                            return;
                        }

                        if (esTransferencia(fila.metodo)) {
                            if (fila.numeroTransferencia === '') {
                                if (error) error.textContent = `Ingrese el número de transferencia de la forma de pago ${indice + 1}.`;
                                return;
                            }
                            if (!/^\d+$/.test(fila.numeroTransferencia)) {
                                if (error) error.textContent = `El número de transferencia de la forma de pago ${indice + 1} debe contener solo dígitos.`;
                                return;
                            }
                        }

                        nuevasLineas.push({
                            claveOperacion: claveOperacionPago(),
                            metodoPago: fila.metodo,
                            moneda: 'NIO',
                            banco: (esTarjeta(fila.metodo) || esTransferencia(fila.metodo)) ? fila.banco : null,
                            monto: monto,
                            tasaCambio: null,
                            montoCordobas: monto,
                            numeroTransferencia: esTransferencia(fila.metodo) ? fila.numeroTransferencia : null
                        });
                    }

                    // La división reemplaza las líneas para no duplicar cobros.
                    lineasPago = nuevasLineas;
                    totalDivisionAplicado = t.total;
                    pintarCobro();
                    actualizarResumenDivision();
                }

                const leerConsumo = () => {
                    const sel = document.getElementById('cuenta-cobro');
                    const opt = sel && sel.selectedOptions[0];
                    return opt ? Number(opt.dataset.total || 0) : 0;
                };

                const leerAjustes = () => {
                    if (!PUEDE_AJUSTAR_COBRO) return { descuento: 0, motivo: '', propina: 0, descuentoPorcentaje: 0, propinaPorcentaje: 0 };
                    const descPct = document.getElementById('descuento-porcentaje-cobro');
                    const descImp = document.getElementById('descuento-importe-cobro');
                    const propPct = document.getElementById('propina-porcentaje-cobro');
                    const propImp = document.getElementById('propina-importe-cobro');
                    const motivo = document.getElementById('motivo-cobro');
                    const descPctValor = (descPct && descPct.value !== '') ? numeroPorcentaje(descPct.value, 2) : 0;
                    const descImpValor = (descImp && descImp.value !== '') ? numeroEstricto(descImp.value, 2) : 0;
                    const propPctValor = (propPct && propPct.value !== '') ? numeroPorcentaje(propPct.value, 2) : 0;
                    const propImpValor = (propImp && propImp.value !== '') ? numeroEstricto(propImp.value, 2) : 0;
                    return {
                        descuentoPorcentaje: descPctValor,
                        descuentoImporte: descImpValor,
                        motivo: motivo ? motivo.value.trim() : '',
                        propinaPorcentaje: propPctValor,
                        propinaImporte: propImpValor
                    };
                };

                // Consumo → descuento → BASE (para la propina) → propina → total.
                // La MISMA fórmula que aplica el servidor: así lo que se ve es lo
                // que se cobra. El servidor vuelve a validar y recalcular.
                const leerTotales = () => {
                    const consumo = leerConsumo();
                    const ajustes = leerAjustes();

                    // Estado activo por concepto (leído de los toggles del DOM;
                    // si no existe el botón, se usa la variable inicial).
                    const descBtn = document.querySelector('.fiscal-toggle[data-concepto="descuento"]');
                    const propBtn = document.querySelector('.fiscal-toggle[data-concepto="propina"]');
                    const ivaBtn = document.querySelector('.fiscal-toggle[data-concepto="iva"]');
                    const retDgiBtn = document.querySelector('.fiscal-toggle[data-concepto="dgi"]');
                    const retAlcBtn = document.querySelector('.fiscal-toggle[data-concepto="alc"]');
                    const descToggle = descBtn ? descBtn.dataset.active !== 'false' : descuentoActivo;
                    const propToggle = propBtn ? propBtn.dataset.active !== 'false' : propinaActiva;
                    const ivaToggle = ivaBtn ? ivaBtn.dataset.active !== 'false' : ivaActivo;
                    const retDgiToggle = retDgiBtn ? retDgiBtn.dataset.active !== 'false' : retDgiActivo;
                    const retAlcToggle = retAlcBtn ? retAlcBtn.dataset.active !== 'false' : retAlcActivo;

                    // Descuento solo se aplica si su concepto está activo.
                    const descuentoAplicado = descToggle
                        ? ((Number.isFinite(ajustes.descuentoPorcentaje) && ajustes.descuentoPorcentaje > 0)
                            ? Math.round(consumo * ajustes.descuentoPorcentaje) / 100
                            : Math.round(ajustes.descuentoImporte * 100) / 100)
                        : 0;

                    const base = Math.max(0, Math.round((consumo - descuentoAplicado) * 100) / 100);

                    // Propina solo se aplica si su concepto está activo.
                    const propinaAplicada = propToggle
                        ? ((Number.isFinite(ajustes.propinaPorcentaje) && ajustes.propinaPorcentaje > 0)
                            ? Math.round(base * ajustes.propinaPorcentaje) / 100
                            : Math.round(ajustes.propinaImporte * 100) / 100)
                        : 0;

                    const iva = (ivaToggle !== false) ? Math.round(consumo * 0.15 * 100) / 100 : 0;
                    const retDgi = (retDgiToggle !== false) ? Math.round(consumo * 0.02 * 100) / 100 : 0;
                    const retAlc = (retAlcToggle !== false) ? Math.round(consumo * 0.01 * 100) / 100 : 0;
                    const totalRet = Math.round((retDgi + retAlc) * 100) / 100;
                    const totalFactura = Math.round((base + iva + propinaAplicada) * 100) / 100;
                    const neto = Math.round((totalFactura - totalRet) * 100) / 100;
                    return {
                        consumo: consumo,
                        descuento: descuentoAplicado,
                        motivo: ajustes.motivo,
                        base: base,
                        iva: iva,
                        propina: propinaAplicada,
                        retDgi: retDgi,
                        retAlc: retAlc,
                        totalRet: totalRet,
                        totalFactura: totalFactura,
                        total: neto,
                        ivaHabilitado: iva > 0,
                        retDgiHabilitada: retDgi > 0,
                        retAlcHabilitada: retAlc > 0,
                        descuentoActivo: descToggle,
                        propinaActiva: propToggle,
                        descuentoPorcentaje: ajustes.descuentoPorcentaje,
                        propinaPorcentaje: ajustes.propinaPorcentaje
                    };
                };

                const sumaLineas = () => lineasPago.reduce((suma, linea) => suma + linea.montoCordobas, 0);

                const pintarCobro = () => {
                    const t = leerTotales();
                    const pagado = sumaLineas();
                    const saldo = pagado - t.total;
                    // Validaciones en vivo: descuento y propina. El descuento no
                    // puede superar el consumo ni exceder el 100 %; la propina no
                    // puede ser negativa. El cobro se bloquea hasta corregir.
                    const errorDescuento = document.getElementById('error-descuento-cobro');
                    const errorPropina = document.getElementById('error-propina-cobro');
                    const descuentoInvalido = !Number.isFinite(t.descuento) || t.descuento < 0 || t.descuento > t.consumo || t.descuentoPorcentaje > 100;
                    const propinaInvalida = !Number.isFinite(t.propina) || t.propina < 0;
                    if (errorDescuento) { errorDescuento.textContent = descuentoInvalido ? 'Descuento inválido: no puede superar el consumo ni exceder el 100%.' : ''; }
                    if (errorPropina) { errorPropina.textContent = propinaInvalida ? 'Propina inválida: no puede ser negativa.' : ''; }

                    // Mostrar/ocultar bloques de descuento y propina según su toggle activo.
                    const bloqueDescuento = document.getElementById('bloque-descuento');
                    const bloquePropina = document.getElementById('bloque-propina');
                    if (bloqueDescuento) { bloqueDescuento.style.display = t.descuentoActivo ? 'flex' : 'none'; }
                    if (bloquePropina) { bloquePropina.style.display = t.propinaActiva ? 'flex' : 'none'; }


                    document.getElementById('resumen-cobro').innerHTML =
                        `Consumo: <strong>C$ ${t.consumo.toFixed(2)}</strong>` +
                        (t.descuento > 0
                            ? ` · Descuento${t.descuentoPorcentaje !== null ? ` (${t.descuentoPorcentaje}%)` : ''}: <strong>− C$ ${t.descuento.toFixed(2)}</strong>`
                            : '') +
                        ` · Base: C$ ${t.base.toFixed(2)}` +
                        (t.iva > 0
                            ? ` · IVA (15%): <strong>+ C$ ${t.iva.toFixed(2)}</strong>`
                            : '') +
                        (t.propina > 0
                            ? ` · Propina${t.propinaPorcentaje !== null ? ` (${t.propinaPorcentaje}%)` : ''}: <strong>+ C$ ${t.propina.toFixed(2)}</strong>`
                            : '') +
                        (t.retDgi > 0
                            ? ` · Ret. DGI (2%): <strong>− C$ ${t.retDgi.toFixed(2)}</strong>`
                            : '') +
                        (t.retAlc > 0
                            ? ` · Ret. Alcaldía (1%): <strong>− C$ ${t.retAlc.toFixed(2)}</strong>`
                            : '') +
                        `<br>Total por cobrar: <strong>C$ ${t.total.toFixed(2)}</strong>`;

                    document.getElementById('lista-lineas').innerHTML = lineasPago.length === 0
                        ? '<span style="color:#999;">Sin líneas de pago agregadas.</span>'
                        : lineasPago.map((linea, indice) => {
                            const importe = linea.moneda === 'USD'
                                ? `USD ${linea.monto.toFixed(2)} × ${Number(linea.tasaCambio).toFixed(4)} = C$ ${linea.montoCordobas.toFixed(2)}`
                                : `C$ ${linea.montoCordobas.toFixed(2)}`;
                            const banco = linea.banco ? ` · ${escaparHtml(linea.banco)}` : '';
                            return `<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;">
                                <span>${etiquetasMetodo[linea.metodoPago]}: ${importe}${banco}</span>
                                <button type="button" data-quitar-linea="${indice}" title="Quitar línea" style="background:#c0392b;color:#fff;border:0;border-radius:4px;padding:1px 7px;cursor:pointer;">✕</button>
                            </div>`;
                        }).join('');

                    document.querySelectorAll('#lista-lineas [data-quitar-linea]').forEach(boton => {
                        boton.addEventListener('click', function () {
                            lineasPago.splice(Number(this.dataset.quitarLinea), 1);
                            pintarCobro();
                        });
                    });

                    document.getElementById('saldo-pendiente').innerHTML = saldo > 0.009
                        ? `Saldo pendiente: <strong style="color:#c0392b;">C$ ${saldo.toFixed(2)}</strong>`
                        : (saldo < -0.009
                            ? `Excedente (se devuelve en efectivo): C$ ${(-saldo).toFixed(2)}`
                            : 'Total cubierto ✓');

                    // La división se recalcula con el total vigente: en
                    // automática vuelve a repartir las partes y en manual
                    // muestra el saldo pendiente o el exceso en vivo.
                    refrescarDivision(t);

                    // Enlace de prefactura: se abre el modal de configuración
                    // fiscal primero y, solo al confirmar, se genera la
                    // prefactura (no se genera directamente desde el cobro).
                    // La vinculación del clic se establece en didOpen.
                };

                Swal.fire({
                    title: 'Cobrar cuenta',
                    width: 560,
                    html: `
                        <select id="cuenta-cobro" class="swal2-select">${opciones}</select>
                        <div id="aviso-cocina" role="alert" style="display:none; border:1px solid #f0b4bd; background:#fdf0ef; color:#a8232d; border-radius:6px; padding:8px 10px; font-size:12px; margin:6px 0 0; text-align:left;"></div>
                        <div id="resumen-cobro" style="font-size:13px;color:#333;margin:6px 0;"></div>
                        ${htmlAjustes}
                        ${htmlFiscales}
                        
                        <div id="division-pagos" style="border-top:1px dashed #aaa;margin:8px 0 0;padding-top:8px;text-align:left;font-size:12px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                                <strong style="font-size:13px;">División de pagos</strong>
                                <select id="modo-division" class="swal2-select" style="margin:0;max-width:230px;min-height:40px;font-size:12px;" aria-label="Modalidad de división de pagos">
                                    <option value="">Sin división</option>
                                    <option value="AUTOMATICA">Automática (partes iguales)</option>
                                    <option value="MANUAL">Manual (importe por método)</option>
                                </select>
                            </div>

                            <div id="panel-division-automatica" style="display:none;margin-top:6px;">
                                <label for="division-cantidad" style="font-size:12px;font-weight:bold;">Cantidad de formas de pago</label>
                                <input id="division-cantidad" class="swal2-input" type="number" min="2" max="10" step="1" value="2" style="margin:4px 0;min-height:40px;" aria-label="Cantidad de formas de pago">
                                <div id="division-filas-auto"></div>
                            </div>

                            <div id="panel-division-manual" style="display:none;margin-top:6px;">
                                <div id="division-filas-manual"></div>
                                <button type="button" id="division-agregar-fila" style="background:#6777ef;color:#fff;border:0;border-radius:5px;padding:6px 10px;font-size:12px;cursor:pointer;margin-top:4px;">+ Agregar método</button>
                            </div>

                            <div id="division-resumen" style="margin-top:6px;font-size:12px;color:#333;"></div>

                            <div style="display:flex;gap:8px;margin-top:6px;align-items:center;flex-wrap:wrap;">
                                <button type="button" id="division-aplicar" style="background:#17a2b8;color:#fff;border:0;border-radius:5px;padding:8px 12px;font-size:12px;font-weight:bold;cursor:pointer;">Aplicar división al cobro</button>
                                <span style="color:#777;font-size:11px;">La división no cambia el total de la cuenta.</span>
                            </div>
                            <div id="division-error" style="color:#c0392b;font-size:12px;min-height:14px;"></div>
                        </div>

                        <div style="border-top:1px dashed #aaa;margin:8px 0 0;padding-top:8px;text-align:left;font-size:13px;">
                            <strong>Líneas de pago</strong> <span style="color:#777;font-weight:normal;">(varias permitidas)</span>
                            <select id="metodo-linea" class="swal2-select">
                                <option value="EFECTIVO_CORDOBA">Efectivo (córdobas)</option>
                                <option value="EFECTIVO_USD">Efectivo (dólares USD)</option>
                                <option value="TARJETA_DEBITO">Tarjeta de débito</option>
                                <option value="TARJETA_CREDITO">Tarjeta de crédito</option>
                                <option value="TRANSFERENCIA">Transferencia bancaria</option>
                            </select>
                            <select id="banco-linea" class="swal2-select" style="display:none;">
                                <option value="">Seleccionar banco…</option>
                                <option value="Banpro">Banpro</option>
                                <option value="Ficohsa">Ficohsa</option>
                                <option value="BAC">BAC</option>
                                <option value="Lafise">Lafise</option>
                            </select>
                            <select id="moneda-linea" class="swal2-select" style="display:none;">
                                <option value="NIO">Córdobas (C$)</option>
                                <option value="USD">Dólares (US$)</option>
                            </select>
                            <input id="numero-transferencia-linea" class="swal2-input" type="text" placeholder="Número de transferencia (solo dígitos)" style="display:none;" maxlength="64">
                            <input id="tasa-linea" class="swal2-input" type="number" min="0" step="0.0001" placeholder="Tasa USD del día" style="display:none;">
                            <input id="monto-linea" class="swal2-input" type="number" min="0" max="9999999999.99" step="0.01" placeholder="Monto de la línea">
                            <button type="button" id="agregar-linea" style="display:block;width:100%;background:#47c363;color:#fff;border:0;border-radius:6px;padding:8px;font-weight:bold;cursor:pointer;">+ Agregar línea de pago</button>
                            <div id="error-linea" style="color:#c0392b;font-size:12px;min-height:14px;"></div>
                            <div id="lista-lineas" style="margin:4px 0;"></div>
                            <div id="saldo-pendiente" style="font-size:13px;margin-top:4px;"></div>
                        </div>
                        <a id="enlace-prefactura" href="#" target="_blank" rel="noopener" style="font-size:12px;display:inline-block;margin-top:6px;">Imprimir prefactura (no es factura fiscal)</a>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Confirmar pago',
                    cancelButtonText: 'Cancelar',
                    didOpen: () => {
                        // ----------------------------------------------------
                        // LÍNEAS DE PAGO: alta, método, importe y moneda.
                        // El botón "+ Agregar línea de pago" NO tenía listener
                        // (se perdió en un refactor), por eso el cobro no
                        // avanzaba. Aquí se vuelve a enlazar y se valida en el
                        // navegador con las MISMAS reglas del backend; el
                        // servidor vuelve a validar y recalcular siempre.
                        // ----------------------------------------------------
                        const campoMetodoLinea = document.getElementById('metodo-linea');
                        const campoBancoLinea = document.getElementById('banco-linea');
                        const campoMonedaLinea = document.getElementById('moneda-linea');
                        const campoNumeroTransferencia = document.getElementById('numero-transferencia-linea');
                        const campoTasaLinea = document.getElementById('tasa-linea');

                        const esLineaDolares = function (metodo, moneda) {
                            return metodo === 'EFECTIVO_USD' || (esTransferencia(metodo) && moneda === 'USD');
                        };

                        if (campoMetodoLinea) {
                            campoMetodoLinea.addEventListener('change', function () {
                                const m = this.value;
                                const requiereBanco = esTarjeta(m) || esTransferencia(m);
                                if (campoBancoLinea) campoBancoLinea.style.display = requiereBanco ? '' : 'none';
                                if (campoMonedaLinea) campoMonedaLinea.style.display = esTransferencia(m) ? '' : 'none';
                                if (campoNumeroTransferencia) campoNumeroTransferencia.style.display = esTransferencia(m) ? '' : 'none';
                                if (campoTasaLinea) {
                                    const moneda = campoMonedaLinea ? campoMonedaLinea.value : 'NIO';
                                    campoTasaLinea.style.display = esLineaDolares(m, moneda) ? '' : 'none';
                                }
                            });
                        }

                        if (campoMonedaLinea) {
                            campoMonedaLinea.addEventListener('change', function () {
                                if (!campoTasaLinea || !campoMetodoLinea) return;
                                const m = campoMetodoLinea.value;
                                if (esTransferencia(m)) {
                                    campoTasaLinea.style.display = this.value === 'USD' ? '' : 'none';
                                }
                            });
                        }

                        // Tasa USD SUGERIDA por el servidor (configuraciones o
                        // último cierre de caja). Si no hay ninguna, el cajero
                        // la captura: el código nunca inventa un valor fijo.
                        fetch('{{ route("pos.tipoCambio") }}')
                            .then(respuesta => respuesta.json())
                            .then(detalle => {
                                if (campoTasaLinea && detalle.exito && detalle.tasa) {
                                    campoTasaLinea.value = Number(detalle.tasa).toFixed(4);
                                }
                            })
                            .catch(() => { /* sin sugerencia: el cajero captura la tasa */ });

                        const btnAgregarLinea = document.getElementById('agregar-linea');
                        if (btnAgregarLinea) {
                            btnAgregarLinea.addEventListener('click', () => {
                                const error = document.getElementById('error-linea');
                                const metodo = document.getElementById('metodo-linea').value;
                                const montoTexto = document.getElementById('monto-linea').value;
                                const monto = numeroEstricto(montoTexto, 2);
                                const requiereBanco = esTarjeta(metodo) || esTransferencia(metodo);
                                const banco = requiereBanco ? document.getElementById('banco-linea').value : '';
                                const monedaSeleccionada = campoMonedaLinea ? campoMonedaLinea.value : 'NIO';
                                // El efectivo USD siempre se guarda como USD.
                                const moneda = metodo === 'EFECTIVO_USD' ? 'USD' : monedaSeleccionada;
                                const usaDolares = esLineaDolares(metodo, moneda);
                                const tasaTexto = campoTasaLinea ? campoTasaLinea.value : '';
                                const tasa = usaDolares ? numeroEstricto(tasaTexto, 4) : 0;
                                const numeroTransferencia = campoNumeroTransferencia
                                    ? campoNumeroTransferencia.value.trim() : '';

                                if (error) error.textContent = '';

                                if (monto === null || !(monto > 0)) {
                                    if (error) error.textContent = 'Ingrese un monto válido mayor que cero (máximo 2 decimales).';
                                    return;
                                }
                                if (monto > 9999999999.99) {
                                    if (error) error.textContent = 'El monto de la línea supera el máximo permitido.';
                                    return;
                                }
                                if (requiereBanco && !banco) {
                                    if (error) error.textContent = 'Seleccione el banco.';
                                    return;
                                }
                                if (esTransferencia(metodo) && !(monedaSeleccionada === 'NIO' || monedaSeleccionada === 'USD')) {
                                    if (error) error.textContent = 'Seleccione la moneda de la transferencia.';
                                    return;
                                }
                                if (esTransferencia(metodo) && numeroTransferencia === '') {
                                    if (error) error.textContent = 'Ingrese el número de transferencia.';
                                    return;
                                }
                                if (esTransferencia(metodo) && !/^\d+$/.test(numeroTransferencia)) {
                                    if (error) error.textContent = 'El número de transferencia debe contener exclusivamente dígitos.';
                                    return;
                                }
                                if (usaDolares && (tasa === null || !(tasa > 0))) {
                                    if (error) error.textContent = 'Capture la tasa de cambio USD del día (el sistema no la inventa).';
                                    return;
                                }

                                // Vista previa: el servidor recalcula siempre.
                                const montoCordobas = usaDolares
                                    ? Math.round(monto * tasa * 100) / 100
                                    : monto;

                                lineasPago.push({
                                    claveOperacion: claveOperacionPago(),
                                    metodoPago: metodo,
                                    moneda: moneda,
                                    banco: banco || null,
                                    monto: monto,
                                    tasaCambio: usaDolares ? tasa : null,
                                    montoCordobas: montoCordobas,
                                    numeroTransferencia: esTransferencia(metodo) ? numeroTransferencia : null
                                });

                                document.getElementById('monto-linea').value = '';
                                if (campoNumeroTransferencia) campoNumeroTransferencia.value = '';
                                pintarCobro();
                            });
                        }

                        // Botoneras fiscales (IVA / Retención DGI / Retención
                        // Alcaldía): SIEMPRE visibles. Si el interruptor global está
                        // apagado o el cliente no está sujeto a retención, el control
                        // queda deshabilitado con una nota corta.
                        document.querySelectorAll('.fiscal-toggle').forEach(boton => {
                            boton.addEventListener('click', () => {
                                if (boton.disabled) return;
                                const concepto = boton.dataset.concepto;
                                const activo = boton.dataset.active !== 'false';
                                boton.dataset.active = activo ? 'false' : 'true';
                                boton.setAttribute('aria-pressed', activo ? 'false' : 'true');
                                boton.style.background = activo ? '#fff' : '#e7f7ed';
                                boton.style.color = activo ? '#9a6700' : '#1e7e46';
                                boton.style.borderColor = activo ? '#ccc' : '#47c363';
                                // Mantener sincronizadas las variables de estado JS.
                                if (concepto === 'descuento') descuentoActivo = !activo;
                                if (concepto === 'propina') propinaActiva = !activo;
                                if (concepto === 'iva') ivaActivo = !activo;
                                if (concepto === 'dgi') retDgiActivo = !activo;
                                if (concepto === 'alc') retAlcActivo = !activo;
                                pintarCobro();
                            });
                        });

                        // Enlace de prefactura: abre el modal de configuración
                        // fiscal primero. Solo al confirmar se genera la
                        // prefactura (no se abre directamente desde el cobro).
                        const enlacePrefactura = document.getElementById('enlace-prefactura');
                        if (enlacePrefactura && !enlacePrefactura.dataset.prefacturaVinculado) {
                            enlacePrefactura.dataset.prefacturaVinculado = '1';
                            enlacePrefactura.addEventListener('click', function (e) {
                                e.preventDefault();
                                const sel = document.getElementById('cuenta-cobro');
                                const cuentaId = Number(sel.value);
                                const opt = sel.selectedOptions[0];
                                const consumo = opt ? Number(opt.dataset.total || 0) : 0;
                                // SweetAlert2 v11: Swal.close() NO devuelve una
                                // promesa. Encadenar .then() lanzaba un
                                // TypeError y el clic no avanzaba nunca.
                                Swal.close();
                                // Se espera el cierre del modal antes de abrir
                                // el de la prefactura (no se encadena .then()).
                                setTimeout(function () {
                                    window.abrirPrefacturaModal(cuentaId, consumo);
                                }, 150);
                            });
                        }

                        // Sincronización descuento % <-> importe fijo y propina % <-> importe.
                        const descPct = document.getElementById('descuento-porcentaje-cobro');
                        const descImp = document.getElementById('descuento-importe-cobro');
                        const propPct = document.getElementById('propina-porcentaje-cobro');
                        const propImp = document.getElementById('propina-importe-cobro');

                        const leerBaseConDescuento = () => {
                            const consumo = leerConsumo();
                            const pct = Number((descPct && descPct.value) || 0);
                            const imp = Number((descImp && descImp.value) || 0);
                            const descuento = (pct > 0) ? (consumo * pct) / 100 : imp;
                            return Math.max(0, consumo - descuento);
                        };

                        const sincronizarDescuento = () => {
                            const consumo = leerConsumo();
                            const pct = Number((descPct && descPct.value) || 0);
                            if (!descImp) { return; }
                            if (pct > 0 && consumo > 0) {
                                descImp.value = (consumo * pct / 100).toFixed(2);
                            } else {
                                descImp.value = '';
                            }
                            pintarCobro();
                        };

                        const sincronizarPropina = () => {
                            const base = leerBaseConDescuento();
                            const pct = Number((propPct && propPct.value) || 0);
                            if (!propImp) { return; }
                            if (pct > 0 && base > 0) {
                                propImp.value = (base * pct / 100).toFixed(2);
                            } else {
                                propImp.value = '';
                            }
                            pintarCobro();
                        };

                        if (descPct && descImp) {
                            descPct.addEventListener('input', sincronizarDescuento);
                            descImp.addEventListener('input', function () {
                                const consumo = leerConsumo();
                                const imp = Number(descImp.value || 0);
                                if (imp > 0 && consumo > 0) {
                                    descPct.value = (imp / consumo * 100).toFixed(2);
                                } else {
                                    descPct.value = '';
                                }
                                if (imp <= 0) { descImp.value = ''; }
                                pintarCobro();
                            });
                        }

                        if (propPct && propImp) {
                            propPct.addEventListener('input', function () {
                                const base = leerBaseConDescuento();
                                const pct = Number(propPct.value || 0);
                                if (pct > 0 && base > 0) {
                                    propImp.value = (base * pct / 100).toFixed(2);
                                } else {
                                    propImp.value = '';
                                }
                                pintarCobro();
                            });
                            propImp.addEventListener('input', function () {
                                const base = leerBaseConDescuento();
                                const imp = Number(propImp.value || 0);
                                if (imp > 0 && base > 0) {
                                    propPct.value = (imp / base * 100).toFixed(2);
                                } else {
                                    propPct.value = '';
                                }
                                pintarCobro();
                            });
                        }

                        ['cuenta-cobro', 'motivo-cobro'].forEach(function (id) {
                            const campo = document.getElementById(id);
                            if (!campo) { return; }
                            const avisar = (id === 'cuenta-cobro') ? renderAvisoCocina : function () {};
                            campo.addEventListener('input', pintarCobro);
                            campo.addEventListener('input', avisar);
                            campo.addEventListener('change', pintarCobro);
                            campo.addEventListener('change', avisar);
                        });
                        renderAvisoCocina();

                        if (PUEDE_AJUSTAR_COBRO) {
                            const btnSugerir = document.getElementById('propina-sugerir');
                            const btnQuitar = document.getElementById('propina-quitar');

                            // Atajos de propina: "Propina 15%" fija la propina al 15% de
                            // la base (consumo menos descuento); "Quitar" la deja en 0.
                            if (btnSugerir) btnSugerir.addEventListener('click', () => {
                                const base = leerBaseConDescuento();
                                propPct.value = '15';
                                propImp.value = (base > 0) ? (base * 0.15).toFixed(2) : '';
                                pintarCobro();
                            });

                            if (btnQuitar) btnQuitar.addEventListener('click', () => {
                                propPct.value = '';
                                propImp.value = '';
                                pintarCobro();
                            });
                        }

                        // ----------------------------------------------------
                        // DIVISIÓN DE PAGOS (automática o manual).
                        // Todos los importes se validan otra vez en el servidor;
                        // aquí solo se arma la interfaz y se exige que la suma
                        // de las formas de pago coincida exactamente con el
                        // total antes de permitir el cobro.
                        // ----------------------------------------------------
                        const selectorModoDivision = document.getElementById('modo-division');

                        const actualizarPanelesDivision = () => {
                            const valor = selectorModoDivision ? selectorModoDivision.value : '';
                            modoDivision = (valor === 'AUTOMATICA' || valor === 'MANUAL') ? valor : null;
                            totalDivisionAplicado = null;

                            const panelAuto = document.getElementById('panel-division-automatica');
                            const panelManual = document.getElementById('panel-division-manual');
                            const filasAuto = document.getElementById('division-filas-auto');
                            const filasManual = document.getElementById('division-filas-manual');
                            const errorDivision = document.getElementById('division-error');

                            if (panelAuto) panelAuto.style.display = modoDivision === 'AUTOMATICA' ? 'block' : 'none';
                            if (panelManual) panelManual.style.display = modoDivision === 'MANUAL' ? 'block' : 'none';
                            if (filasAuto) filasAuto.innerHTML = '';
                            if (filasManual) filasManual.innerHTML = '';
                            if (errorDivision) errorDivision.textContent = '';

                            if (modoDivision === 'AUTOMATICA') {
                                construirFilasAutomaticas();
                            } else if (modoDivision === 'MANUAL') {
                                // Se arranca con dos métodos (mínimo exigido).
                                agregarFilaManual();
                                agregarFilaManual();
                            }

                            actualizarResumenDivision();
                        };

                        if (selectorModoDivision) {
                            selectorModoDivision.addEventListener('change', actualizarPanelesDivision);
                        }

                        const campoCantidadDivision = document.getElementById('division-cantidad');
                        if (campoCantidadDivision) {
                            campoCantidadDivision.addEventListener('input', construirFilasAutomaticas);
                            campoCantidadDivision.addEventListener('change', construirFilasAutomaticas);
                        }

                        const btnAgregarFilaDivision = document.getElementById('division-agregar-fila');
                        if (btnAgregarFilaDivision) {
                            btnAgregarFilaDivision.addEventListener('click', agregarFilaManual);
                        }

                        // Delegación: las filas se crean y se destruyen
                        // dinámicamente, así que el listener vive en el panel.
                        const panelDivision = document.getElementById('division-pagos');
                        if (panelDivision) {
                            panelDivision.addEventListener('change', function (evento) {
                                const fila = evento.target.closest('.division-fila');
                                if (!fila) return;

                                if (evento.target.classList.contains('division-metodo')) {
                                    const metodo = evento.target.value;
                                    const banco = fila.querySelector('.division-banco');
                                    const numero = fila.querySelector('.division-numero');
                                    if (banco) banco.style.display = (esTarjeta(metodo) || esTransferencia(metodo)) ? 'block' : 'none';
                                    if (numero) numero.style.display = esTransferencia(metodo) ? 'block' : 'none';
                                }

                                actualizarResumenDivision();
                            });

                            panelDivision.addEventListener('input', function (evento) {
                                if (evento.target.classList.contains('division-monto') && modoDivision !== 'AUTOMATICA') {
                                    actualizarResumenDivision();
                                }
                            });
                        }

                        const btnAplicarDivision = document.getElementById('division-aplicar');
                        if (btnAplicarDivision) {
                            btnAplicarDivision.addEventListener('click', aplicarDivision);
                        }

                        pintarCobro();
                    },
                    preConfirm: () => {
                        // Regla de negocio (backend la repite dentro de la transacción):
                        // no se puede cobrar mientras cocina no marque TERMINADO.
                        const cuentaSel = document.getElementById('cuenta-cobro');
                        if (cuentaSel) {
                            const pendientes = cocinaPendienteDe(cuentaSel.value);
                            if (pendientes.length > 0) {
                                renderAvisoCocina();
                                return Swal.showValidationMessage('Cocina aún no ha marcado como TERMINADO: ' + cocinaPendienteTexto(pendientes) + '. El cobro se habilita cuando todos los productos de cocina estén TERMINADOS.');
                            }
                        }

                        const t = leerTotales();

                        if (lineasPago.length === 0) {
                            return Swal.showValidationMessage('Agregue al menos una línea de pago.');
                        }

                        if (t.descuentoPorcentaje !== null && !Number.isFinite(t.descuentoPorcentaje)) {
                            return Swal.showValidationMessage('El descuento debe ser un número válido (sin letras, sin notación científica y con hasta 2 decimales).');
                        }

                        if (t.propinaPorcentaje !== null && !Number.isFinite(t.propinaPorcentaje)) {
                            return Swal.showValidationMessage('La propina debe ser un número válido (sin letras, sin notación científica y con hasta 2 decimales).');
                        }

                        if (t.descuentoPorcentaje !== null && (t.descuentoPorcentaje < 0 || t.descuentoPorcentaje > 100)) {
                            return Swal.showValidationMessage('El porcentaje de descuento debe estar entre 0 y 100.');
                        }

                        if (t.propinaPorcentaje !== null && t.propinaPorcentaje < 0) {
                            return Swal.showValidationMessage('El porcentaje de propina no puede ser negativo.');
                        }

                        if (t.descuento > t.consumo) {
                            return Swal.showValidationMessage('El descuento no puede superar el consumo de la cuenta.');
                        }

                        if (!Number.isFinite(t.descuento) || !Number.isFinite(t.propina) || t.descuento < 0 || t.propina < 0) {
                            return Swal.showValidationMessage('El descuento y la propina no pueden ser negativos.');
                        }

                        const pagado = sumaLineas();

                        if (pagado < t.total - 0.009) {
                            return Swal.showValidationMessage(`Faltan C$ ${(t.total - pagado).toFixed(2)} por cubrir.`);
                        }

                        // Misma regla que el servidor: el excedente solo viene
                        // del efectivo (no se devuelve cambio por tarjeta).
                        const otros = lineasPago
                            .filter(linea => !esEfectivo(linea.metodoPago))
                            .reduce((suma, linea) => suma + linea.montoCordobas, 0);

                        if (otros > t.total + 0.009) {
                            return Swal.showValidationMessage('Los pagos con tarjeta no pueden exceder el total: el excedente solo se admite en efectivo.');
                        }

                        // ----------------------------------------------------
                        // DIVISIÓN APLICADA: debe coincidir al centavo.
                        // No se admite cambio ni exceso: la división reparte
                        // exactamente el total entre las formas de pago.
                        // ----------------------------------------------------
                        if (modoDivision) {
                            if (lineasPago.length < 2) {
                                return Swal.showValidationMessage('La división requiere al menos dos formas de pago.');
                            }

                            const centavosLineas = Math.round(pagado * 100);
                            const centavosTotal = Math.round(t.total * 100);

                            if (centavosLineas !== centavosTotal) {
                                const diferencia = (centavosLineas - centavosTotal) / 100;
                                return Swal.showValidationMessage(
                                    'La división debe coincidir exactamente con el total (C$ '
                                    + t.total.toFixed(2) + '): '
                                    + (diferencia > 0
                                        ? 'sobran C$ ' + diferencia.toFixed(2)
                                        : 'faltan C$ ' + Math.abs(diferencia).toFixed(2))
                                    + '.'
                                );
                            }

                            if (totalDivisionAplicado === null || Math.round(totalDivisionAplicado * 100) !== centavosTotal) {
                                return Swal.showValidationMessage('El total cambió después de aplicar la división: vuelva a aplicarla antes de cobrar.');
                            }

                            if (modoDivision === 'AUTOMATICA') {
                                const centavosPartes = lineasPago.map(linea => Math.round(linea.montoCordobas * 100));
                                if (Math.max.apply(null, centavosPartes) - Math.min.apply(null, centavosPartes) > 1) {
                                    return Swal.showValidationMessage('La división automática debe repartir el total en partes iguales (solo se admite 1 centavo de diferencia por redondeo).');
                                }
                            }
                        }

                        // Se envían AMBAS formas: el porcentaje (si el modo es %)
                        // y el importe resultante. El servidor prioriza el
                        // porcentaje y recalcula el importe él mismo, así un
                        // navegador manipulado no puede fijar el total.
                        return {
                            cuentaId: Number(document.getElementById('cuenta-cobro').value),
                            descuento: t.descuento,
                            descuentoPorcentaje: t.descuentoPorcentaje,
                            descuentoActivo: t.descuentoActivo,
                            motivo: t.motivo,
                            propina: t.propina,
                            propinaPorcentaje: t.propinaPorcentaje,
                            propinaActiva: t.propinaActiva,
                            ivaHabilitado: t.ivaHabilitado,
                            retDgiHabilitada: t.retDgiHabilitada,
                            retAlcHabilitada: t.retAlcHabilitada,
                            modoDivision: modoDivision,
                            lineas: lineasPago
                        };
                    }
                }).then(resultado => {
                    if (!resultado.isConfirmed) return;

                    const cobro = resultado.value;
                    const form = new FormData();
                    form.append('_token', '{{ csrf_token() }}');
                    form.append('descuento', cobro.descuento.toFixed(2));
                    form.append('propina', cobro.propina.toFixed(2));
                    if (cobro.descuentoPorcentaje > 0) {
                        form.append('descuento_porcentaje', String(cobro.descuentoPorcentaje));
                    }
                    if (cobro.propinaPorcentaje > 0) {
                        form.append('propina_porcentaje', String(cobro.propinaPorcentaje));
                    }
                    if (cobro.motivo) form.append('descuento_motivo', cobro.motivo);
                    form.append('iva_habilitado', cobro.ivaHabilitado ? '1' : '0');
                    form.append('retencion_dgi_habilitada', cobro.retDgiHabilitada ? '1' : '0');
                    form.append('retencion_alcaldia_habilitada', cobro.retAlcHabilitada ? '1' : '0');
                    form.append('descuento_activo', cobro.descuentoActivo ? '1' : '0');
                    form.append('propina_activa', cobro.propinaActiva ? '1' : '0');
                    if (cobro.modoDivision) form.append('modo_division', cobro.modoDivision);

                    // Una fila en pagos_cuenta POR LÍNEA (nunca concatenados).
                    cobro.lineas.forEach((linea, indice) => {
                        form.append(`pagos[${indice}][clave_operacion]`, linea.claveOperacion);
                        form.append(`pagos[${indice}][metodo_pago]`, linea.metodoPago);
                        form.append(`pagos[${indice}][monto]`, linea.monto.toFixed(2));
                        form.append(`pagos[${indice}][moneda]`, linea.moneda || 'NIO');
                        if (linea.banco) form.append(`pagos[${indice}][banco]`, linea.banco);
                        if (linea.numeroTransferencia) form.append(`pagos[${indice}][numero_transferencia]`, linea.numeroTransferencia);
                        if (linea.tasaCambio) form.append(`pagos[${indice}][tasa_cambio]`, linea.tasaCambio);
                    });

                    fetch('/pos/cuentas/' + cobro.cuentaId + '/pagar', { method: 'POST', body: form })
                        .then(response => response.json())
                        .then(respuesta => {
                            if (!respuesta.exito) {
                                // El servidor bloqueó por cocina: se muestran los
                                // productos y sus estados para el cajero.
                                if (Array.isArray(respuesta.productos_pendientes) && respuesta.productos_pendientes.length > 0) {
                                    const lista = respuesta.productos_pendientes
                                        .map(p => '<li>' + escaparHtml(p.cantidad) + '× ' + escaparHtml(p.producto) + ' — <strong>' + escaparHtml(p.etiqueta || p.estado) + '</strong></li>')
                                        .join('');
                                    Swal.fire({
                                        title: 'No se puede cobrar',
                                        icon: 'warning',
                                        html: '<div style="text-align:left; font-size:13px;">Cocina aún no ha marcado como TERMINADO:<ul style="margin:8px 0 0 18px; padding:0;">' + lista + '</ul></div>',
                                    });
                                    return;
                                }
                                throw new Error(respuesta.error || 'No se pudo cobrar.');
                            }

                            const totales = respuesta.totales || {};
                            let texto = respuesta.mensaje;

                            if (totales.total !== undefined) {
                                texto += `<br>Consumo: C$ ${Number(totales.consumo).toFixed(2)}`;
                                if (Number(totales.descuento) > 0) texto += ` · Descuento: −C$ ${Number(totales.descuento).toFixed(2)}`;
                                texto += ` · Base: C$ ${Number(totales.base).toFixed(2)}`;
                                if (Number(totales.propina) > 0) texto += ` · Propina: +C$ ${Number(totales.propina).toFixed(2)}`;
                                texto += `<br><strong>Total cobrado: C$ ${Number(totales.total).toFixed(2)}</strong>`;
                            }

                            texto += '<br>Cambio: C$ ' + Number(respuesta.cambio).toFixed(2);
                            if (respuesta.factura) texto += '<br><strong>Factura: ' + escaparHtml(respuesta.factura) + '</strong>';

                            const despuesDeCobrar = () => respuesta.mesa_liberada ? window.location.href = '{{ route("mesas.index") }}' : location.reload();

                            // La factura ya fue emitida en el backend dentro de la
                            // misma transacción del cobro; aquí solo se ofrece
                            // imprimirla. Si no vino el id, se cierra normalmente.
                            if (!respuesta.factura_id) {
                                Swal.fire('Pago registrado', texto, 'success').then(despuesDeCobrar);
                                return;
                            }

                            Swal.fire({
                                title: 'Pago registrado',
                                html: texto,
                                icon: 'success',
                                showDenyButton: true,
                                confirmButtonText: 'Imprimir factura',
                                denyButtonText: 'Cerrar'
                            }).then(resultadoImpresion => {
                                if (resultadoImpresion.isConfirmed) {
                                    window.open('{{ route("facturas.ticket") }}?id=' + encodeURIComponent(respuesta.factura_id), '_blank');
                                }
                                despuesDeCobrar();
                            });
                        })
                        .catch(error => Swal.fire('Error', error.message, 'error'));
                });
            })
            .catch(error => Swal.fire('Cobrar cuenta', error.message, 'warning'));
    }

    function enviarCocina() {

        if (nuevoPedido.length === 0) {

            Swal.fire(
                'Pedido vacío',
                'Agrega productos al nuevo pedido.',
                'warning'
            );

            return;
        }

        Swal.fire({

            title: '¿Confirmar pedido?',

            text: 'Los productos de cocina y barra se guardarán en la cuenta.',

            icon: 'question',

            showCancelButton: true,

            confirmButtonColor: '#47c363',

            confirmButtonText: 'Sí, enviar',

            cancelButtonText: 'Cancelar'

        }).then(function (result) {

            if (!result.isConfirmed) {
                return;
            }

            const boton =
                document.getElementById(
                    'btn-enviar-pedido'
                );

            boton.disabled = true;

            boton.innerHTML =
                '<i class="fas fa-spinner fa-spin"></i> Enviando...';

            const fd =
                new FormData();

            fd.append(
                '_token',
                '{{ csrf_token() }}'
            );

            fd.append(
                'accion',
                'enviar_cocina'
            );

            fd.append(
                'venta_id',
                '{{ $venta->id }}'
            );

            // Cuenta destino: si el POS está acotado (?cuenta=) el pedido se
            // guarda en ESA subcuenta. Sin cuenta, el backend usa la primera.
            const cuentaActualId =
                document.getElementById('cuenta-actual-id');

            if (
                cuentaActualId &&
                cuentaActualId.value
            ) {

                fd.append(
                    'cuenta_id',
                    cuentaActualId.value
                );
            }

            fd.append(
                'carrito',
                JSON.stringify(nuevoPedido)
            );

            fetch(
                '{{ route("pos.api") }}',
                {
                    method: 'POST',
                    body: fd
                }
            )
            .then(function (response) {

                if (!response.ok) {

                    throw new Error(
                        'HTTP ' +
                        response.status
                    );
                }

                return response.json();
            })
            .then(function (data) {

                if (data.exito) {

                    if (data.comanda_id) {

                        window.open(
                            '/pos/comanda/' +
                            data.comanda_id,
                            '_blank',
                            'width=450,height=700'
                        );

                    }

                    Swal.fire(
                        '¡Pedido enviado!',
                        data.mensaje ||
                        'El pedido fue enviado correctamente.',
                        'success'
                    ).then(function () {

                        location.reload();
                    });

                    return;
                }

                Swal.fire(
                    'Error',
                    data.error ||
                    'No se pudo enviar el pedido.',
                    'error'
                );
            })
            .catch(function (error) {

                console.error(
                    'Error al enviar pedido:',
                    error
                );

                Swal.fire(
                    'Error',
                    'Ocurrió un problema al comunicarse con el servidor.',
                    'error'
                );
            })
            .finally(function () {

                boton.disabled = false;

                boton.innerHTML =
                    '<i class="fas fa-paper-plane"></i> ' +
                    'Confirmar y Enviar Pedido';
            });

        });
    }

    function liberarMesaVacia() {

        Swal.fire({

            title: '¿Liberar esta mesa?',

            text: 'La mesa volverá a estar libre y se descartará esta apertura.',

            icon: 'warning',

            showCancelButton: true,

            confirmButtonColor: '#fc544b',

            confirmButtonText: 'Sí, liberar mesa',

            cancelButtonText: 'Cancelar'

        }).then(function (result) {

            if (!result.isConfirmed) {
                return;
            }

            const fd =
                new FormData();

            fd.append(
                '_token',
                '{{ csrf_token() }}'
            );

            fd.append(
                'accion',
                'liberar_mesa_vacia'
            );

            fd.append(
                'venta_id',
                '{{ $venta->id }}'
            );

            fetch(
                '{{ route("pos.api") }}',
                {
                    method: 'POST',
                    body: fd
                }
            )
            .then(function (response) {

                // Se lee el cuerpo JSON tanto en éxito (2xx) como en errores
                // (404/409/500) para mostrar el mensaje real del backend en
                // lugar de un error genérico. Si el cuerpo no fuera JSON, se
                // cae a un mensaje basado en el código HTTP.
                return response.json().catch(function () {

                    return {
                        exito: false,
                        error: 'HTTP ' +
                            response.status
                    };
                });
            })
            .then(function (data) {

                if (data.exito) {

                    Swal.fire(
                        '¡Mesa liberada!',
                        data.mensaje ||
                        'La mesa fue liberada correctamente.',
                        'success'
                    ).then(function () {

                        window.location.href =
                            '{{ route("mesas.index") }}';
                    });

                    return;
                }

                Swal.fire(
                    'Error',
                    data.error ||
                    'No se pudo liberar la mesa.',
                    'error'
                );
            })
            .catch(function (error) {

                console.error(
                    'Error al liberar mesa:',
                    error
                );

                Swal.fire(
                    'Error',
                    'Ocurrió un problema al comunicarse con el servidor.',
                    'error'
                );
            });
        });
    }
</script>

@endsection
