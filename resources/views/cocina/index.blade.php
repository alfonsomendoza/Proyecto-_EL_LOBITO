@extends('layouts.app')

@section('content')
@php
    // El rol y el estado del módulo se validan en el backend (CocinaController).
    // Aquí solo se decide qué botones mostrar.
    $puedeCorregir = $puedeCorregir ?? false;
@endphp

<style>
    .cocina-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 14px;
    }

    .cocina-titulo {
        font-size: 22px;
        font-weight: 800;
        color: var(--color-dark, #34395e);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .cocina-estado-conexion {
        font-size: 13px;
        font-weight: 700;
        padding: 6px 12px;
        border-radius: 20px;
        background: #e8f7ee;
        color: #1e7e46;
    }

    .cocina-estado-conexion.error {
        background: #fdecea;
        color: #c0392b;
    }

    .cocina-aviso {
        display: none;
        padding: 10px 14px;
        border-radius: 6px;
        margin-bottom: 12px;
        font-weight: 600;
    }

    .cocina-aviso.visible {
        display: block;
    }

    .cocina-aviso.error {
        background: #fdecea;
        color: #c0392b;
    }

    .cocina-vacio {
        text-align: center;
        padding: 60px 20px;
        color: #7a7a7a;
        font-size: 17px;
    }

    .cocina-vacio i {
        font-size: 46px;
        display: block;
        margin-bottom: 12px;
        color: #b8c0c8;
    }

    .cocina-mesa {
        margin-bottom: 26px;
    }

    .cocina-mesa-titulo {
        font-size: 17px;
        font-weight: 800;
        color: #ffffff;
        background: var(--color-primary, #47c363);
        padding: 8px 14px;
        border-radius: 8px 8px 0 0;
        display: inline-block;
        min-width: 160px;
    }

    .cocina-comanda {
        border: 1px solid #d7dce2;
        border-top: none;
        border-radius: 0 8px 8px 8px;
        padding: 12px;
        background: #ffffff;
        margin-bottom: 14px;
    }

    .cocina-comanda-ref {
        font-size: 12px;
        color: #7a7a7a;
        margin-bottom: 8px;
        font-weight: 700;
    }

    .cocina-linea {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        padding: 10px 8px;
        border-bottom: 1px solid #eef1f4;
    }

    .cocina-linea:last-child {
        border-bottom: none;
    }

    .cocina-producto {
        font-size: 18px;
        font-weight: 700;
        color: #2f3640;
    }

    .cocina-cantidad {
        display: inline-block;
        min-width: 34px;
        font-size: 20px;
        font-weight: 800;
        color: var(--color-primary, #47c363);
        margin-right: 6px;
    }

    .cocina-nota {
        margin-top: 5px;
        font-size: 14px;
        font-weight: 700;
        color: #8a5a00;
        background: #fff6d9;
        border-left: 4px solid #f0b400;
        padding: 5px 9px;
        border-radius: 4px;
        white-space: pre-line;
        max-width: 520px;
    }

    .cocina-acciones {
        display: flex;
        gap: 8px;
        flex-shrink: 0;
        flex-wrap: wrap;
        justify-content: flex-end;
    }

    .cocina-badge {
        display: inline-block;
        font-size: 12px;
        font-weight: 800;
        padding: 3px 10px;
        border-radius: 20px;
        margin-top: 5px;
        text-transform: uppercase;
        letter-spacing: .5px;
    }

    .estado-pendiente {
        background: #fff2d6;
        color: #9a6700;
        border: 1px solid #f0b400;
    }

    .estado-en_preparacion {
        background: #dceeff;
        color: #0b5faa;
        border: 1px solid #4a9fe0;
    }

    .estado-listo {
        background: #e2f6e8;
        color: #1e7e46;
        border: 1px solid #47c363;
    }

    .cocina-btn {
        border: none;
        border-radius: 6px;
        padding: 9px 14px;
        font-size: 14px;
        font-weight: 700;
        cursor: pointer;
        color: #fff;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }

    .cocina-btn:disabled {
        opacity: .6;
        cursor: not-allowed;
    }

    .btn-iniciar {
        background: #0b5faa;
    }

    .btn-terminar {
        background: #1e7e46;
    }

    .btn-regresar {
        background: #8a5a00;
    }

    /* Sección plegable para los pedidos ya LISTOS: se conservan por
       trazabilidad, pero separados de lo que aún hay que preparar. */
    .cocina-listas {
        margin-top: 22px;
        border-top: 2px dashed #d7dce2;
        padding-top: 14px;
    }

    .cocina-listas > summary {
        cursor: pointer;
        font-size: 16px;
        font-weight: 800;
        color: #1e7e46;
        list-style: none;
        outline: none;
        user-select: none;
    }

    .cocina-listas > summary::-webkit-details-marker {
        display: none;
    }

    .cocina-listas > summary::before {
        content: "\25B6";
        display: inline-block;
        margin-right: 8px;
        font-size: 12px;
        transition: transform .15s ease;
    }

    .cocina-listas[open] > summary::before {
        transform: rotate(90deg);
    }

    .cocina-listas-cuerpo {
        margin-top: 14px;
        opacity: .88;
    }
</style>

<div class="cocina-header">
    <div class="cocina-titulo">
        <i class="fas fa-fire"></i> Monitor de Cocina
    </div>
    <div id="cocina-conexion" class="cocina-estado-conexion">
        <i class="fas fa-circle-check"></i> En línea
    </div>
</div>

<div id="cocina-aviso" class="cocina-aviso error" role="alert"></div>

<div id="cocina-tablero">
    <div class="cocina-vacio">
        <i class="fas fa-spinner fa-spin"></i>
        Cargando pedidos…
    </div>
</div>

<script>
    const COCINA_URL_PANEL = "{{ route('cocina.api.panel') }}";
    const COCINA_URL_BASE = "{{ url('/cocina/api/detalles') }}";
    const COCINA_CSRF = "{{ csrf_token() }}";
    const COCINA_PUEDE_CORREGIR = {{ $puedeCorregir ? 'true' : 'false' }};
    const COCINA_INTERVALO = 7000;

    let cocinaDatos = [];
    let cocinaLocalTs = {};
    let cocinaEnProceso = {};
    let cocinaTimeout = null;

    document.addEventListener('DOMContentLoaded', function () {
        cargarCocina();
    });

    function escaparCocina(texto) {
        const div = document.createElement('div');
        div.textContent = texto == null ? '' : String(texto);
        return div.innerHTML;
    }

    function etiquetaEstado(estado) {
        if (estado === 'PENDIENTE') return 'Pendiente';
        if (estado === 'EN_PREPARACION') return 'En preparación';
        if (estado === 'LISTO') return 'Listo';
        return estado || '';
    }

    function formatearHora(valor) {
        if (!valor) return '';
        const fecha = new Date(String(valor).replace(' ', 'T'));
        if (isNaN(fecha.getTime())) return String(valor);

        const hh = String(fecha.getHours()).padStart(2, '0');
        const mm = String(fecha.getMinutes()).padStart(2, '0');

        return hh + ':' + mm;
    }

    function minutosTranscurridos(valor) {
        if (!valor) return '';
        const fecha = new Date(String(valor).replace(' ', 'T'));
        if (isNaN(fecha.getTime())) return '';

        const minutos = Math.max(0, Math.floor((Date.now() - fecha.getTime()) / 60000));

        if (minutos < 1) return 'hace instantes';
        if (minutos === 1) return 'hace 1 min';
        return 'hace ' + minutos + ' min';
    }

    function mostrarAviso(mensaje) {
        const aviso = document.getElementById('cocina-aviso');
        aviso.textContent = mensaje;
        aviso.classList.add('visible');

        const conexion = document.getElementById('cocina-conexion');
        conexion.classList.add('error');
        conexion.innerHTML = '<i class="fas fa-triangle-exclamation"></i> Sin conexión';
    }

    function ocultarAviso() {
        const aviso = document.getElementById('cocina-aviso');
        aviso.classList.remove('visible');
        aviso.textContent = '';

        const conexion = document.getElementById('cocina-conexion');
        conexion.classList.remove('error');
        conexion.innerHTML = '<i class="fas fa-circle-check"></i> En línea';
    }

    async function cargarCocina() {
        clearTimeout(cocinaTimeout);
        const inicio = Date.now();

        try {
            const respuesta = await fetch(COCINA_URL_PANEL, {
                headers: { 'Accept': 'application/json' }
            });

            if (respuesta.status === 403) {
                mostrarAviso('La cocina digital está deshabilitada o no tienes permiso.');
                document.getElementById('cocina-tablero').innerHTML =
                    '<div class="cocina-vacio"><i class="fas fa-lock"></i>Acceso no disponible.</div>';
                return;
            }

            const data = await respuesta.json();

            if (!data.exito) {
                mostrarAviso(data.error || 'No se pudo cargar el panel.');
                return;
            }

            ocultarAviso();

            // Evita que una respuesta antigua del sondeo pise una actualización
            // local más reciente (el usuario acaba de cambiar un estado).
            const nuevas = [];
            for (const linea of data.lineas) {
                if (cocinaLocalTs[linea.id] && cocinaLocalTs[linea.id] > inicio) {
                    const previa = cocinaDatos.find(d => d.id === linea.id);
                    nuevas.push(previa ? Object.assign({}, linea, { estado: previa.estado }) : linea);
                } else {
                    nuevas.push(linea);
                }
            }

            cocinaDatos = nuevas;
            renderCocina();
        } catch (e) {
            mostrarAviso('Error de red. Reintentando automáticamente…');
        } finally {
            cocinaTimeout = setTimeout(cargarCocina, COCINA_INTERVALO);
        }
    }

    async function cambiarEstadoCocina(id, destino) {
        if (cocinaEnProceso[id]) return;

        cocinaEnProceso[id] = true;
        renderCocina();

        try {
            const respuesta = await fetch(COCINA_URL_BASE + '/' + id + '/estado', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': COCINA_CSRF
                },
                body: JSON.stringify({ estado: destino })
            });

            const data = await respuesta.json();

            if (!respuesta.ok || !data.exito) {
                mostrarAviso(data.error || 'No se pudo actualizar el estado.');
            } else {
                const linea = cocinaDatos.find(d => d.id === id);
                if (linea) linea.estado = data.estado;
                cocinaLocalTs[id] = Date.now();
                ocultarAviso();
            }
        } catch (e) {
            mostrarAviso('Error de red al actualizar. Se recargará el panel.');
        } finally {
            cocinaEnProceso[id] = false;
            renderCocina();
            // Reconcilia con el servidor tras la acción.
            cargarCocina();
        }
    }

    function botonesLinea(linea, enProceso) {
        const id = linea.id;
        const bloquear = enProceso ? ' disabled' : '';
        let botones = '';

        if (linea.estado === 'PENDIENTE') {
            botones += '<button type="button" class="cocina-btn btn-iniciar" data-id="' + id +
                '" data-estado="EN_PREPARACION"' + bloquear + '>' +
                '<i class="fas fa-play"></i> Iniciar</button>';
        }

        if (linea.estado === 'EN_PREPARACION') {
            botones += '<button type="button" class="cocina-btn btn-terminar" data-id="' + id +
                '" data-estado="LISTO"' + bloquear + '>' +
                '<i class="fas fa-check"></i> Terminar</button>';
        }

        // Retrocesos: solo ADMIN/GERENTE.
        if (COCINA_PUEDE_CORREGIR && linea.estado === 'EN_PREPARACION') {
            botones += '<button type="button" class="cocina-btn btn-regresar" data-id="' + id +
                '" data-estado="PENDIENTE"' + bloquear + '>' +
                '<i class="fas fa-rotate-left"></i> Regresar</button>';
        }

        if (COCINA_PUEDE_CORREGIR && linea.estado === 'LISTO') {
            botones += '<button type="button" class="cocina-btn btn-regresar" data-id="' + id +
                '" data-estado="EN_PREPARACION"' + bloquear + '>' +
                '<i class="fas fa-rotate-left"></i> Reabrir</button>';
        }

        if (enProceso) {
            botones = '<span class="cocina-badge estado-en_preparacion"><i class="fas fa-spinner fa-spin"></i> Guardando…</span>';
        }

        return botones;
    }

    // Agrupa un conjunto de líneas por mesa y luego por comanda.
    function agruparPorMesa(lineas) {
        const mesas = [];
        const mapaMesas = {};
        const mapaComandas = {};

        for (const linea of lineas) {
            const claveMesa = String(linea.mesa);
            if (!mapaMesas[claveMesa]) {
                mapaMesas[claveMesa] = { mesa: linea.mesa, comandas: [] };
                mesas.push(mapaMesas[claveMesa]);
            }

            const claveComanda = claveMesa + '-' + linea.comanda;
            if (!mapaComandas[claveComanda]) {
                mapaComandas[claveComanda] = { comanda: linea.comanda, lineas: [] };
                mapaMesas[claveMesa].comandas.push(mapaComandas[claveComanda]);
            }

            mapaComandas[claveComanda].lineas.push(linea);
        }

        return mesas;
    }

    // Dibuja un conjunto de líneas agrupadas por mesa y comanda.
    function renderGrupos(lineas) {
        let html = '';

        for (const mesa of agruparPorMesa(lineas)) {
            html += '<div class="cocina-mesa">';
            html += '<div class="cocina-mesa-titulo"><i class="fas fa-chair"></i> Mesa ' +
                escaparCocina(mesa.mesa) + '</div>';

            for (const comanda of mesa.comandas) {
                html += '<div class="cocina-comanda">';
                html += '<div class="cocina-comanda-ref">Comanda #' +
                    escaparCocina(String(comanda.comanda).padStart(2, '0')) + '</div>';

                for (const linea of comanda.lineas) {
                    const enProceso = !!cocinaEnProceso[linea.id];

                    html += '<div class="cocina-linea">';

                    html += '<div>';
                    html += '<div class="cocina-producto">' +
                        '<span class="cocina-cantidad">' + escaparCocina(linea.cantidad) + 'x</span>' +
                        escaparCocina(linea.producto) + '</div>';

                    if (linea.notas !== null && String(linea.notas).trim() !== '') {
                        html += '<div class="cocina-nota">' +
                            '<i class="fas fa-note-sticky"></i> ' +
                            escaparCocina(linea.notas) + '</div>';
                    }

                    html += '<span class="cocina-badge estado-' +
                        escaparCocina(String(linea.estado).toLowerCase()) + '">' +
                        etiquetaEstado(linea.estado) + '</span>';

                    html += ' <span style="font-size:12px;color:#7a7a7a;">' +
                        escaparCocina(formatearHora(linea.hora)) + ' · ' +
                        escaparCocina(minutosTranscurridos(linea.hora)) + '</span>';

                    html += '</div>';

                    html += '<div class="cocina-acciones">' +
                        botonesLinea(linea, enProceso) + '</div>';

                    html += '</div>';
                }

                html += '</div>';
            }

            html += '</div>';
        }

        return html;
    }

    function renderCocina() {
        const tablero = document.getElementById('cocina-tablero');

        // Los LISTO se conservan por trazabilidad, pero en una sección aparte
        // y plegable. Pendientes y en preparación quedan arriba, más visibles.
        const pendientes = cocinaDatos.filter(l => l.estado !== 'LISTO');
        const listos = cocinaDatos.filter(l => l.estado === 'LISTO');

        let html = '';

        if (pendientes.length) {
            html += renderGrupos(pendientes);
        } else if (!listos.length) {
            html += '<div class="cocina-vacio"><i class="fas fa-utensils"></i>' +
                'No hay pedidos pendientes.<br>Todo al día.</div>';
        } else {
            html += '<div class="cocina-vacio"><i class="fas fa-circle-check"></i>' +
                'No hay pedidos pendientes.<br>Todo lo demás está listo.</div>';
        }

        if (listos.length) {
            html += '<details class="cocina-listas">' +
                '<summary><i class="fas fa-clipboard-check"></i> Pedidos listos (' +
                listos.length + ')</summary>' +
                '<div class="cocina-listas-cuerpo">' + renderGrupos(listos) + '</div>' +
                '</details>';
        }

        tablero.innerHTML = html;

        document.querySelectorAll('.cocina-btn[data-id]').forEach(function (boton) {
            boton.addEventListener('click', function () {
                cambiarEstadoCocina(Number(this.dataset.id), this.dataset.estado);
            });
        });
    }
</script>
@endsection
