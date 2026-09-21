@extends('layouts.app')

@section('content')

<meta name="csrf-token" content="{{ csrf_token() }}">

<div
    id="dividir-app"
    data-venta-id="{{ $venta->id }}"
    data-api-url="{{ route('pos.api') }}"
>

    <div class="division-header">

        <div>
            <h1>Dividir cuenta</h1>

            <p>
                Venta #{{ $venta->id }}
            </p>
        </div>

        <div>
            <a href="{{ route('pos.index', $venta->id) }}" class="btn-volver">
                Volver al POS
            </a>
        </div>

    </div>

    <div class="division-layout">

        <section class="cuentas-panel">

            <div class="panel-header">

                <div>
                    <h2>Cuentas</h2>
                    <span>
                        {{ $cuentas->count() }} cuenta(s)
                    </span>
                </div>

                <button
                    type="button"
                    id="btn-crear-cuenta"
                    class="btn-crear-cuenta">
                    + Nueva cuenta
                </button>

            </div>

            <div id="cuentas-container">

                @forelse ($cuentas as $cuenta)

                    @php
                        $itemsCuenta = $detalles->where('cuenta_id', $cuenta->id);
                        $estadoClase = 'cuenta-abierta';

                        if ($cuenta->estado === 'PAGADA') {
                            $estadoClase = 'cuenta-pagada';
                        }

                        if ($cuenta->estado === 'COBRANDO') {
                            $estadoClase = 'cuenta-cobrando';
                        }
                    @endphp

                    <div
                        class="cuenta-card {{ $estadoClase }}"
                        data-cuenta-id="{{ $cuenta->id }}"
                    >

                        <div class="cuenta-header">

                            <div>
                                <h3>
                                    {{ $cuenta->nombre }}
                                </h3>

                                <span>
                                    {{ $cuenta->estado }}
                                </span>
                            </div>

                            @if ($cuenta->estado === 'ABIERTA' && $itemsCuenta->count() === 0)

                                <button
                                    type="button"
                                    class="btn-eliminar-cuenta"
                                    data-cuenta-id="{{ $cuenta->id }}"
                                    title="Eliminar cuenta vacía">
                                    <i class="fas fa-trash"></i>
                                </button>

                            @endif

                        </div>

                        <div class="cuenta-items">

                            @forelse ($itemsCuenta as $item)

                                <div
                                    class="detalle-card"
                                    data-detalle-id="{{ $item->id }}"
                                >

                                    <div class="detalle-info">

                                        <strong>
                                            {{ $item->nombre }}
                                        </strong>

                                        <span>
                                            Cantidad:
                                            {{ $item->cantidad }}
                                        </span>

                                        <span>
                                            Precio:
                                            C$ {{ number_format((float) $item->precio_unitario, 2) }}
                                        </span>

                                        <strong>
                                            C$ {{ number_format((float) $item->subtotal, 2) }}
                                        </strong>

                                    </div>

                                </div>

                            @empty

                                <div class="cuenta-vacia">
                                    Esta cuenta está vacía.
                                </div>

                            @endforelse

                        </div>

                        <div class="cuenta-total">

                            <span>Total</span>

                            <strong>
                                C$ {{ number_format((float) $cuenta->total, 2) }}
                            </strong>

                        </div>

                    </div>

                @empty

                    <div class="sin-cuentas">
                        No existen cuentas creadas para esta venta.
                    </div>

                @endforelse

            </div>

        </section>

        <section class="productos-panel">

            <div class="panel-header">

                <div>
                    <h2>Productos de la venta</h2>

                    <span>
                        Selecciona una cantidad y muévela a otra cuenta.
                    </span>
                </div>

            </div>

            <div class="detalles-disponibles">

                @forelse ($detalles as $detalle)

                    <div
                        class="detalle-movible"
                        data-detalle-id="{{ $detalle->id }}"
                        data-cantidad="{{ $detalle->cantidad }}"
                    >

                        <div class="detalle-movible-info">

                            <strong>
                                {{ $detalle->nombre }}
                            </strong>

                            <span>
                                Cantidad actual:
                                {{ $detalle->cantidad }}
                            </span>

                            <span>
                                C$ {{ number_format((float) $detalle->subtotal, 2) }}
                            </span>

                        </div>

                        <div class="detalle-movible-actions">

                            <input
                                type="number"
                                class="cantidad-mover"
                                min="1"
                                max="{{ $detalle->cantidad }}"
                                value="{{ $detalle->cantidad }}"
                            >

                            <select class="cuenta-destino">

                                <option value="">
                                    Seleccionar cuenta
                                </option>

                                @foreach ($cuentas as $cuenta)

                                    @if ($cuenta->estado === 'ABIERTA')

                                        <option value="{{ $cuenta->id }}">
                                            {{ $cuenta->nombre }}
                                        </option>

                                    @endif

                                @endforeach

                            </select>

                            <button
                                type="button"
                                class="btn-mover-detalle"
                                data-detalle-id="{{ $detalle->id }}"
                            >
                                Mover
                            </button>

                        </div>

                    </div>

                @empty

                    <div class="sin-detalles">
                        No existen productos en esta venta.
                    </div>

                @endforelse

            </div>

        </section>

    </div>

</div>

<style>

.division-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 25px;
}

.division-header h1 {
    margin: 0;
}

.division-header p {
    margin: 5px 0 0;
}

.division-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 25px;
}

.cuentas-panel,
.productos-panel {
    background: #ffffff;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
}

.panel-header h2 {
    margin: 0;
}

.panel-header span {
    color: #777;
    font-size: 14px;
}

.cuenta-card {
    border: 1px solid #ddd;
    border-radius: 10px;
    margin-bottom: 15px;
    overflow: hidden;
}

.cuenta-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: #f5f5f5;
}

.cuenta-header h3 {
    margin: 0;
}

.cuenta-header span {
    font-size: 12px;
    color: #777;
}

.cuenta-items {
    padding: 15px;
}

.detalle-card {
    border-bottom: 1px solid #eee;
    padding: 10px 0;
}

.detalle-card:last-child {
    border-bottom: 0;
}

.detalle-info {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.detalle-info span {
    font-size: 13px;
    color: #777;
}

.cuenta-total {
    display: flex;
    justify-content: space-between;
    padding: 15px;
    background: #fafafa;
    font-size: 18px;
}

.btn-eliminar-cuenta {
    border: 0;
    background: transparent;
    color: #c62828;
    cursor: pointer;
    font-size: 16px;
}

.btn-crear-cuenta,
.btn-mover-detalle,
.btn-volver {
    border: 0;
    border-radius: 7px;
    padding: 10px 15px;
    cursor: pointer;
    text-decoration: none;
}

.btn-crear-cuenta {
    background: #1976d2;
    color: white;
}

.btn-mover-detalle {
    background: #2e7d32;
    color: white;
}

.btn-volver {
    display: inline-block;
    background: #555;
    color: white;
}

.detalle-movible {
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 12px;
}

.detalle-movible-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-bottom: 12px;
}

.detalle-movible-actions {
    display: flex;
    gap: 10px;
}

.cantidad-mover {
    width: 80px;
    padding: 8px;
}

.cuenta-destino {
    flex: 1;
    padding: 8px;
}

.cuenta-vacia,
.sin-cuentas,
.sin-detalles {
    padding: 20px;
    text-align: center;
    color: #777;
}

@media (max-width: 900px) {

    .division-layout {
        grid-template-columns: 1fr;
    }

    .detalle-movible-actions {
        flex-direction: column;
    }

    .cantidad-mover {
        width: 100%;
    }
}

</style>

<script>

document.addEventListener('DOMContentLoaded', function () {

    const app = document.getElementById('dividir-app');

    const ventaId = app.dataset.ventaId;
    const apiUrl = app.dataset.apiUrl;

    const csrfToken = document
        .querySelector('meta[name="csrf-token"]')
        .getAttribute('content');

    function enviarPeticion(datos) {

        return fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json'
            },
            body: JSON.stringify(datos)
        })
        .then(function (response) {
            return response.json();
        });
    }

    document
        .getElementById('btn-crear-cuenta')
        .addEventListener('click', function () {

            enviarPeticion({
                accion: 'crear_cuenta',
                venta_id: ventaId
            })
            .then(function (data) {

                if (!data.exito) {
                    alert(data.mensaje || 'No se pudo crear la cuenta.');
                    return;
                }

                window.location.reload();
            })
            .catch(function (error) {

                console.error(error);

                alert('Ocurrió un error al crear la cuenta.');
            });
        });

    document
        .querySelectorAll('.btn-eliminar-cuenta')
        .forEach(function (boton) {

            boton.addEventListener('click', function () {

                const cuentaId = this.dataset.cuentaId;

                if (!confirm('¿Deseas eliminar esta cuenta vacía?')) {
                    return;
                }

                enviarPeticion({
                    accion: 'eliminar_cuenta',
                    cuenta_id: cuentaId
                })
                .then(function (data) {

                    if (!data.exito) {
                        alert(data.mensaje || 'No se pudo eliminar la cuenta.');
                        return;
                    }

                    window.location.reload();
                })
                .catch(function (error) {

                    console.error(error);

                    alert('Ocurrió un error al eliminar la cuenta.');
                });
            });
        });

    document
        .querySelectorAll('.btn-mover-detalle')
        .forEach(function (boton) {

            boton.addEventListener('click', function () {

                const detalleId = this.dataset.detalleId;

                const detalle = this.closest('.detalle-movible');

                const cantidadInput =
                    detalle.querySelector('.cantidad-mover');

                const cuentaSelect =
                    detalle.querySelector('.cuenta-destino');

                const cantidad = parseInt(
                    cantidadInput.value,
                    10
                );

                const cuentaId = parseInt(
                    cuentaSelect.value,
                    10
                );

                if (!cuentaId) {

                    alert('Selecciona una cuenta destino.');

                    return;
                }

                if (!cantidad || cantidad <= 0) {

                    alert('Ingresa una cantidad válida.');

                    return;
                }

                enviarPeticion({
                    accion: 'mover_detalle',
                    venta_id: ventaId,
                    detalle_id: detalleId,
                    cuenta_id: cuentaId,
                    cantidad: cantidad
                })
                .then(function (data) {

                    if (!data.exito) {

                        alert(
                            data.mensaje ||
                            'No se pudo mover el producto.'
                        );

                        return;
                    }

                    window.location.reload();
                })
                .catch(function (error) {

                    console.error(error);

                    alert(
                        'Ocurrió un error al mover el producto.'
                    );
                });
            });
        });

});

</script>

@endsection