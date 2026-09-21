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

    .historial-acciones {
        display: flex;
        flex-direction: column;
        gap: 5px;
        flex-shrink: 0;
    }

    .btn-reimprimir {
        border: none;
        border-radius: 5px;
        background: #6777ef;
        color: #ffffff;
        padding: 6px 8px;
        font-size: 9px;
        font-weight: 700;
        cursor: pointer;
        white-space: nowrap;
    }

    .btn-reimprimir:hover {
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

            <div class="ticket-header">

                <div class="ticket-header-title">
                    <i class="fas fa-receipt"></i>
                    <span>Cuenta Actual</span>
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

                        <div class="item-historial">

                            <div class="item-historial-nombre">

                                <strong>
                                    {{ $h->cantidad }}x
                                </strong>

                                {{ $h->nombre }}

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

                                @else

                                    <div class="item-historial-precio">
                                        C${{ number_format((float) $h->subtotal, 2) }}
                                    </div>

                                @endif

                                <div class="item-historial-comanda">

                                    @if($h->comanda_id)

                                        Comanda #{{ str_pad(
                                            $h->numero_ticket ?? 0,
                                            2,
                                            '0',
                                            STR_PAD_LEFT
                                        ) }}

                                    @else

                                        Barra

                                    @endif

                                </div>

                            </div>

                            @if($h->estado === 'ACTIVO' && $h->comanda_id)

                                <div class="historial-acciones">

                                    <button
                                        type="button"
                                        class="btn-reimprimir"
                                        data-comanda-id="{{ $h->comanda_id }}"
                                    >
                                        <i class="fas fa-print"></i>
                                        Reimprimir
                                    </button>

                                </div>

                            @endif

                        </div>

                    @endforeach

                @endif

            </div>

            <div class="panel-footer">

                <button
                    type="button"
                    class="btn-footer"
                    id="btn-dividir-cuenta"
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

                <button
                    type="button"
                    class="btn-footer btn-cobrar"
                    id="btn-cobrar"
                >
                    <i class="fas fa-cash-register"></i>
                    Cobrar Mesa
                </button>

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

<script>
    let nuevoPedido = [];
    let categoriaActual = 'todas';

    document.addEventListener('DOMContentLoaded', function () {
        inicializarProductos();
        inicializarCategorias();
        inicializarBusqueda();
        inicializarBotones();
        inicializarReimpresion();
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

        const itemExistente =
            nuevoPedido.find(function (item) {
                return item.id === productoId;
            });

        if (itemExistente) {
            itemExistente.cantidad++;
        } else {
            nuevoPedido.push({
                id: productoId,
                nombre: productoNombre,
                precio: productoPrecio,
                cantidad: 1,
                requiere_cocina: requiereCocina
            });
        }

        renderizarNuevo();
    }

    function modQty(id, cambio) {
        const productoId = Number(id);

        const item =
            nuevoPedido.find(function (producto) {
                return producto.id === productoId;
            });

        if (!item) {
            return;
        }

        item.cantidad += Number(cambio);

        if (item.cantidad <= 0) {
            nuevoPedido =
                nuevoPedido.filter(function (producto) {
                    return producto.id !== productoId;
                });
        }

        renderizarNuevo();
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

                    '</div>' +

                    '<div class="nuevo-controles">' +

                        '<button ' +
                            'type="button" ' +
                            'class="qty-btn" ' +
                            'data-id="' +
                            item.id +
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
                            'data-id="' +
                            item.id +
                            '" ' +
                            'data-cambio="1"' +
                        '>' +
                            '+' +
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

                    const id =
                        Number(this.dataset.id);

                    const cambio =
                        Number(this.dataset.cambio);

                    modQty(id, cambio);
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

        const url =
            '/pos/comanda/' +
            comandaId +
            '?reimpresion=1';

        window.open(
            url,
            '_blank',
            'width=450,height=700'
        );
    }

    function imprimirTodas() {

        const url =
            '/pos/comandas/{{ $venta->id }}?reimpresion=1';

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
                function () {

                    Swal.fire(
                        'Pre-Factura',
                        'Esta función será habilitada en el módulo de facturación.',
                        'info'
                    );
                }
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
            btnDividir.addEventListener('click', gestionarCuentas);
        }
    }

    function gestionarCuentas() {
        fetch('{{ route("pos.cuentas", ["mesaId" => $mesa->id]) }}')
            .then(response => response.json())
            .then(data => {
                if (!data.exito) throw new Error(data.error || 'No se pudieron cargar las cuentas.');

                const abiertas = data.cuentas.filter(cuenta => cuenta.estado === 'ABIERTA');
                if (abiertas.length === 0) {
                    Swal.fire('Sin cuentas abiertas', 'Todas las cuentas de esta mesa ya fueron pagadas.', 'info');
                    return;
                }

                const asignadas = {};
                data.asignaciones.forEach(item => {
                    if (!asignadas[item.venta_detalle_id]) asignadas[item.venta_detalle_id] = item.cuenta_id;
                });

                const opciones = abiertas.map(cuenta =>
                    `<option value="${cuenta.id}">${escaparHtml(cuenta.nombre)}</option>`
                ).join('');

                const filas = data.detalles.map(detalle => `
                    <div style="display:grid;grid-template-columns:1fr 100px;gap:8px;align-items:center;margin:8px 0;text-align:left;">
                        <label>${escaparHtml(detalle.nombre)} <small>(${detalle.cantidad} u.)</small></label>
                        <select class="swal2-select asignacion-cuenta" data-detalle="${detalle.id}" style="margin:0;width:100%;">
                            ${opciones}
                        </select>
                    </div>`
                ).join('');

                Swal.fire({
                    title: 'Dividir cuentas',
                    html: `<p style="font-size:13px">Asigna cada línea a una cuenta. Para crear otra cuenta, usa el botón inferior.</p>${filas}`,
                    showCancelButton: true,
                    showDenyButton: true,
                    denyButtonText: 'Nueva cuenta',
                    confirmButtonText: 'Guardar división',
                    cancelButtonText: 'Cancelar',
                    didOpen: () => {
                        document.querySelectorAll('.asignacion-cuenta').forEach(select => {
                            const cuentaId = asignadas[select.dataset.detalle];
                            if (cuentaId && abiertas.some(cuenta => String(cuenta.id) === String(cuentaId))) select.value = cuentaId;
                        });
                    },
                    preConfirm: () => ({
                        asignaciones: [...document.querySelectorAll('.asignacion-cuenta')].map(select => ({
                            cuenta_id: Number(select.value),
                            venta_detalle_id: Number(select.dataset.detalle),
                            cantidad: Number(data.detalles.find(detalle => String(detalle.id) === select.dataset.detalle).cantidad)
                        }))
                    })
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
                const abierta = data.cuentas.find(cuenta => cuenta.estado === 'ABIERTA');
                const asignaciones = data.detalles.map(detalle => ({ cuenta_id: abierta.id, venta_detalle_id: detalle.id, cantidad: detalle.cantidad }));
                guardarDivision({ cuentas_nuevas: [result.value.trim()], asignaciones }, gestionarCuentas);
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
        fetch('{{ route("pos.cuentas.dividir", ["mesaId" => $mesa->id]) }}', { method: 'POST', body: form })
            .then(response => response.json())
            .then(data => {
                if (!data.exito) throw new Error(data.error || 'No se pudo guardar.');
                Swal.fire('Listo', data.mensaje, 'success').then(() => despues ? despues() : location.reload());
            })
            .catch(error => Swal.fire('Error', error.message, 'error'));
    }

    function cobrarCuenta() {
        fetch('{{ route("pos.cuentas", ["mesaId" => $mesa->id]) }}')
            .then(response => response.json())
            .then(data => {
                const abiertas = (data.cuentas || []).filter(cuenta => cuenta.estado === 'ABIERTA' && Number(cuenta.total) > 0);
                if (!abiertas.length) throw new Error('Primero divide y guarda las cuentas con productos asignados.');
                const opciones = abiertas.map(cuenta => `<option value="${cuenta.id}" data-total="${cuenta.total}">${escaparHtml(cuenta.nombre)} — C$ ${Number(cuenta.total).toFixed(2)}</option>`).join('');
                Swal.fire({
                    title: 'Cobrar cuenta',
                    html: `<select id="cuenta-cobro" class="swal2-select">${opciones}</select><select id="metodo-cobro" class="swal2-select"><option value="EFECTIVO">Efectivo</option><option value="TARJETA">Tarjeta</option><option value="TRANSFERENCIA">Transferencia</option></select><input id="recibido-cobro" class="swal2-input" type="number" min="0" step="0.01" placeholder="Monto recibido">`,
                    showCancelButton: true,
                    confirmButtonText: 'Confirmar pago',
                    preConfirm: () => ({
                        cuentaId: Number(document.getElementById('cuenta-cobro').value),
                        metodo: document.getElementById('metodo-cobro').value,
                        recibido: Number(document.getElementById('recibido-cobro').value)
                    })
                }).then(result => {
                    if (!result.isConfirmed) return;
                    const form = new FormData();
                    form.append('_token', '{{ csrf_token() }}');
                    form.append('metodo_pago', result.value.metodo);
                    form.append('monto_recibido', result.value.recibido);
                    fetch('/pos/cuentas/' + result.value.cuentaId + '/pagar', { method: 'POST', body: form })
                        .then(response => response.json())
                        .then(respuesta => {
                            if (!respuesta.exito) throw new Error(respuesta.error || 'No se pudo cobrar.');
                            Swal.fire('Pago registrado', respuesta.mensaje + ' Cambio: C$ ' + Number(respuesta.cambio).toFixed(2), 'success')
                                .then(() => respuesta.mesa_liberada ? window.location.href = '{{ route("mesas.index") }}' : location.reload());
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
