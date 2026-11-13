@extends('layouts.app')

@section('content')

<style>
    .grid-mesas { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 20px; padding: 20px; }
    .mesa-card { padding: 20px; border-radius: 10px; text-align: center; color: white; cursor: pointer; transition: transform 0.2s, box-shadow 0.2s; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.10); user-select: none; }
    .mesa-card:hover { transform: translateY(-5px); box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15); }
    .mesa-card h3 { margin: 0 0 10px; font-size: 24px; font-weight: 800; }
    .mesa-card p { display: inline-block; margin: 0; padding: 4px 8px; border-radius: 20px; background: rgba(0, 0, 0, 0.20); font-size: 12px; font-weight: bold; }
    .mesa-libre { background: linear-gradient(135deg, #47c363, #3ba954); }
    .mesa-ocupada { background: linear-gradient(135deg, #fc544b, #e04a42); }
    .mesa-cobrando { background: linear-gradient(135deg, #ffa426, #e69222); }
    .mesa-icon { margin-bottom: 10px; font-size: 35px; opacity: 0.8; }
    .clientes-data { display: none; }
</style>

<div id="monitor-mesas" class="monitor-card" style="max-width: 1200px; margin: 0 auto;">

    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 10px; padding: 0 20px;">
        <div style="display: flex; align-items: center; gap: 15px;">
            <span style="font-size: 18px; font-weight: bold; color: #34395e;"><i class="fas fa-th-large"></i> Monitor de Mesas</span>
            @if((auth()->user()->rol->nombre ?? 'MESERO') === 'ADMIN')
                <button onclick="crearNuevaMesa()" style="background: #6777ef; color: white; border: none; padding: 6px 12px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 12px; box-shadow: 0 2px 4px rgba(103,119,239,0.3);">
                    <i class="fas fa-plus"></i> Nueva Mesa
                </button>
            @endif
        </div>

        <div style="display: flex; gap: 15px; flex-wrap: wrap; font-size: 13px; font-weight: bold;">
            <span style="color: #47c363;"><i class="fas fa-circle"></i> Libre</span>
            <span style="color: #fc544b;"><i class="fas fa-circle"></i> Ocupada</span>
            <span style="color: #ffa426;"><i class="fas fa-circle"></i> Por Pagar</span>
        </div>
    </div>

    <div class="grid-mesas">
        @foreach($mesas as $mesa)
            @php
                $claseEstado = 'mesa-libre';$icono = 'fa-utensils';

                // Evita consulta N+1 usando la colección que ya envía el MesaController
                $ventaActiva = isset($ventasActivas) ? ($ventasActivas[$mesa->id] ?? null) : null;
                $ventaId = $ventaActiva ? $ventaActiva->id : 0;
                $abrioPor = $ventaActiva?->abrio_nombre;
                $encargadoId = $ventaActiva ? ($ventaActiva->mesero_responsable_id ?? null) : null;
                $meseroNombre = $encargadoId ? ($nombresMeseros[$encargadoId] ?? 'No registrado') : null;

                if ($mesa->estado === 'OCUPADA') {
                    $claseEstado = 'mesa-ocupada';$icono = 'fa-user-clock';
                } elseif ($mesa->estado === 'POR_PAGAR') {
                    $claseEstado = 'mesa-cobrando';$icono = 'fa-file-invoice-dollar';
                }
            @endphp

            <div class="mesa-card {{ $claseEstado }}"
                 data-mesa-id="{{ $mesa->id }}"
                 data-numero="{{ e($mesa->numero) }}"
                 data-estado="{{ e($mesa->estado) }}"
                 data-venta-id="{{ $ventaId }}">
                <i class="fas {{ $icono }} mesa-icon"></i>
                <h3>{{ $mesa->numero }}</h3>
                <p>{{ str_replace('_', ' ', $mesa->estado) }}</p>
                @if ($ventaActiva && $ventaId > 0)
                    <div style="margin-top:8px; font-size:11px; opacity:0.95; line-height:1.4; border-top:1px solid rgba(255,255,255,0.3); padding-top:6px;">
                        <div><strong>Abierta por:</strong> {{ $abrioPor ?? 'No registrado' }}</div>
                        <div><strong>Mesero encargado:</strong> {{ $meseroNombre ?? 'Pendiente de asignación' }}</div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <select id="clientes-data" class="clientes-data">
        <option value="1">Cliente general</option>
        @foreach($clientes as $cliente)
            @if($cliente->id != 1)
                <option value="{{ $cliente->id }}">{{ $cliente->nombre }}</option>
            @endif
        @endforeach
    </select>
</div>

<script>
    // Base del panel de subcuentas. Se construye con el ID de la MESA
    // (no con el ID de la venta) para no confundir los parámetros.
    const URL_CUENTAS_MESA = '{{ url('/mesas') }}';

    // Base del POS. Al abrir una mesa libre se entra directamente aquí para
    // registrar el primer producto (la mesa permanece LIBRE hasta ese momento).
    const URL_POS_MESA = '{{ url('/pos') }}';

    // Endpoint de búsqueda AJAX de clientes (reutiliza clientes.api con
    // accion=search). Limita resultados y usa debounce.
    const URL_CLIENTES_BUSCAR = '{{ route('clientes.api') }}';
    const CLIENTES_LIMIT = 20;

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.mesa-card').forEach(mesa => {
            mesa.addEventListener('click', function () {
                gestionarMesa(
                    Number(this.dataset.mesaId),
                    this.dataset.numero,
                    this.dataset.estado,
                    Number(this.dataset.ventaId)
                );
            });
        });
    });

    function gestionarMesa(mesaId, numero, estado, ventaId) {
        if (estado === 'LIBRE') {
            // Nuevo flujo: búsqueda AJAX de clientes con registro inline.
            abrirBusquedaClienteMesa(mesaId, numero);
        } else {
            if (ventaId > 0) {
                // Abre el panel de subcuentas de la mesa (usa mesaId, no ventaId).
                window.location.href = URL_CUENTAS_MESA + '/' + mesaId + '/cuentas';
            } else {
                Swal.fire('Error', 'La mesa aparece ocupada pero no tiene una venta activa.', 'error');
            }
        }
    }

   function crearNuevaMesa() {
        Swal.fire({
            title: 'Crear Nueva Mesa',
            input: 'text',
            inputPlaceholder: 'Ej. Mesa 9 o VIP 1',
            showCancelButton: true,
            confirmButtonColor: '#6777ef',
            confirmButtonText: 'Crear',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed && result.value.trim() !== '') {
                const fd = new FormData();
                fd.append('_token', '{{ csrf_token() }}');
                fd.append('accion', 'crear_mesa');
                fd.append('numero', result.value.trim());

                fetch('{{ route("mesas.api") }}', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                })
                .then(async response => {
                    const contentType = (response.headers.get('content-type') || '').toLowerCase();
                    const raw = await response.text();
                    let data = null;
                    if (contentType.includes('application/json') || contentType === '') {
                        try { data = raw ? JSON.parse(raw) : null; } catch (e) { data = null; }
                    }
                    return data;
                })
                .then(data => {
                    if (!data || typeof data.exito === 'undefined') {
                        Swal.fire({
                            title: 'No se pudo crear la mesa',
                            text: 'El servidor devolvió una respuesta no válida. Intenta de nuevo.',
                            icon: 'error',
                        });
                        return;
                    }
                    if (data.caja_cerrada) {
                        Swal.fire({
                            title: 'No hay una caja abierta',
                            text: data.error || 'No hay una caja abierta. Debes abrir una caja antes de realizar operaciones.',
                            icon: 'warning',
                            confirmButtonText: 'Ir a apertura de caja',
                            confirmButtonColor: '#6777ef',
                            showCancelButton: true,
                            cancelButtonText: 'Cerrar',
                        }).then(result => {
                            if (result.isConfirmed) {
                                window.location.href = "{{ route('caja.apertura') }}";
                            }
                        });
                        return;
                    }
                    if (data.exito) {
                        Swal.fire('¡Creada!', data.mensaje, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Atención', data.error, 'warning');
                    }
                })
                .catch(error => Swal.fire('Error', 'Fallo de comunicación con el servidor.', 'error'));
            }
        });
    }

    /**
     * Buscador AJAX de clientes al abrir una mesa LIBRE.
     *
     * - Muestra un input con debounce.
     * - Lista los clientes coincidentes (con nombre, identificación y teléfono
     *   cuando existan) y permite seleccionar uno existente.
     * - Permite registrar un cliente nuevo desde el mismo flujo (sin salir del
     *   Modal).
     * - Si no se selecciona ninguno, el cliente queda ausente y la asociación
     *   actual se conserva (no se fuerza Cliente General).
     */
    function abrirBusquedaClienteMesa(mesaId, numero) {
        Swal.fire({
            title: 'Buscar cliente - Mesa ' + numero,
            html: `
                <div style="text-align: left; font-size: 13px; padding: 4px 0;">
                    <label style="display:block; margin-bottom:5px; font-weight:bold; color:#34395e;">
                        Buscar o registrar cliente
                    </label>
                    <div style="position:relative;">
                        <i class="fas fa-search" style="position:absolute; left:10px; top:9px; color:#9aa2b1;"></i>
                        <input type="text"
                               id="cliente-buscar-input"
                               class="swal2-input"
                               placeholder="Nombre, cédula, teléfono..."
                               autocomplete="off"
                               style="padding-left:32px; height:38px; box-sizing:border-box;">
                    </div>
                    <div id="cliente-resultados" style="margin-top:8px; max-height:220px; overflow:auto; border:1px solid #e3e6f0; border-radius:6px; background:#fff; display:none;"></div>
                    <div id="cliente-info" style="margin-top:6px; color:#5a5f7d; font-size:12px;"></div>
                    <input type="hidden" id="cliente-seleccionado" value="">
                    <div id="cliente-nuevo-form" style="display:none; margin-top:6px; padding:8px; border:1px solid #e3e6f0; border-radius:6px; background:#f8f9fa;">
                        <label style="font-weight:bold; color:#34395e; display:block; margin-bottom:4px;">Registrar nuevo cliente</label>
                        <input type="text" id="cliente-nuevo-nombre" class="swal2-input" placeholder="Nombre completo (*)" style="height:34px; box-sizing:border-box;">
                        <input type="text" id="cliente-nuevo-ident" class="swal2-input" placeholder="Cédula/RUC (opcional)" style="height:34px; box-sizing:border-box; margin-top:4px;">
                        <input type="text" id="cliente-nuevo-tel" class="swal2-input" placeholder="Teléfono (opcional)" style="height:34px; box-sizing:border-box; margin-top:4px;">
                        <input type="text" id="cliente-nuevo-dir" class="swal2-input" placeholder="Dirección (opcional)" style="height:34px; box-sizing:border-box; margin-top:4px;">
                        <button type="button" id="cliente-nuevo-guardar" style="margin-top:8px; width:100%; background:#6777ef; color:#fff; border:none; padding:7px; border-radius:5px; cursor:pointer; font-weight:bold; font-size:13px;">
                            <i class="fas fa-save"></i> Guardar y seleccionar
                        </button>
                    </div>
                    <div id="cliente-aviso" style="margin-top:6px; color:#dc3545; font-size:12px;"></div>
                    <div style="border-top:1px solid #e3e6f0; margin-top:10px; padding-top:10px;">
                        <label style="display:block; margin-bottom:5px; font-weight:bold; color:#34395e;">
                            Mesero encargado (opcional)
                        </label>
                        <select id="mesero-asignado" class="swal2-input" style="height:38px; box-sizing:border-box;">
                            <option value="">— Sin asignar —</option>
                        </select>
                        <input type="hidden" id="mesero-asignado-id" value="">
                    </div>
                </div>
            `,
            focusConfirm: false,
            showCancelButton: true,
            confirmButtonColor: '#47c363',
            confirmButtonText: '<i class="fas fa-door-open"></i> Abrir Mesa',
            cancelButtonText: 'Cancelar',
            didOpen: () => {
                const contenedor = Swal.getHtmlContainer();

                // Estado del buscador.
                let debounceTimer = null;
                let clientesActuales = [];
                let clienteSeleccionadoId = null;

                const input = contenedor.querySelector('#cliente-buscar-input');
                const resultadosDiv = contenedor.querySelector('#cliente-resultados');
                const infoDiv = contenedor.querySelector('#cliente-info');
                const avisoDiv = contenedor.querySelector('#cliente-aviso');
                const nuevoForm = contenedor.querySelector('#cliente-nuevo-form');
                const meseroSelect = contenedor.querySelector('#mesero-asignado');

                // Cargar lista de meseros disponibles (para asignación
                // explícita de encargado de mesa).
                fetch('{{ route("mesas.meseros") }}', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                })
                .then(r => r.json())
                .then(data => {
                    if (data && data.exito && Array.isArray(data.meseros)) {
                        data.meseros.forEach(m => {
                            const opt = document.createElement('option');
                            opt.value = String(m.id);
                            opt.textContent = m.nombre;
                            meseroSelect.appendChild(opt);
                        });
                    }
                })
                .catch(() => { /* Sin meseros no bloquea la apertura. */ });

        // Evita inyectar HTML si un cliente tiene caracteres especiales en
        // su nombre, identificación o teléfono.
        function escaparHtml(valor) {
            return String(valor ?? '').replace(/[&<>"']/g, caracter => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[caracter]));
        }

        // Deja seleccionado un cliente (existente o recién creado) y guarda
        // su id en el campo oculto que lee preConfirm.
        function seleccionarCliente(clienteId, nombre) {
            clienteSeleccionadoId = Number(clienteId);
            const inputOculto = contenedor.querySelector('#cliente-seleccionado');
            if (inputOculto) {
                inputOculto.value = String(clienteSeleccionadoId);
            }
            infoDiv.innerHTML = '✅ Seleccionado: <strong>' + escaparHtml(nombre) + '</strong>';
        }

        function renderizarResultados(clientes) {
            if (!clientes.length) {
                resultadosDiv.style.display = 'none';
                resultadosDiv.innerHTML = '<div style="padding:8px; color:#9aa2b1; font-size:12px;">No se encontraron clientes.</div>';
                return;
            }

            resultadosDiv.style.display = 'block';
            resultadosDiv.innerHTML = clientes.map(c => {
                const nombre = c.nombre || '-';
                const ident = c.identificacion ? 'Cédula: ' + escaparHtml(c.identificacion) : '';
                const tel = c.telefono ? 'Tel: ' + escaparHtml(c.telefono) : '';
                const activo = c.activo ? '' : ' <span style="color:#dc3545;">(inactivo)</span>';
                return `
                    <div style="padding:6px 10px; border-bottom:1px solid #f0f0f0; cursor:pointer;"
                         data-id="${Number(c.id)}">
                        <strong>${ escaparHtml(nombre) }</strong>${ activo }
                        ${ ident ? '<br><small>' + ident + '</small>' : '' }
                        ${ tel ? '<br><small>' + tel + '</small>' : '' }
                    </div>
                `;
            }).join('');

            resultadosDiv.querySelectorAll('[data-id]').forEach(row => {
                row.addEventListener('click', () => {
                    const cliente = clientes.find(c => Number(c.id) === Number(row.dataset.id));

                    clientesActuales = [];
                    resultadosDiv.innerHTML = '';
                    resultadosDiv.style.display = 'none';
                    avisoDiv.innerHTML = '';
                    nuevoForm.style.display = 'none';
                    input.value = '';

                    seleccionarCliente(row.dataset.id, cliente?.nombre);
                });
            });
        }

        input.addEventListener('input', () => {
            clearTimeout(debounceTimer);

            const texto = input.value.trim();
            if (texto.length < 2) {
                resultadosDiv.style.display = 'none';
                resultadosDiv.innerHTML = '';
                infoDiv.innerHTML = '';
                avisoDiv.innerHTML = '';
                nuevoForm.style.display = 'none';
                clientesActuales = [];
                return;
            }

            debounceTimer = setTimeout(() => {
                // Búsqueda AJAX con debounce.
                const fd = new FormData();
                fd.append('_token', '{{ csrf_token() }}');
                fd.append('accion', 'search');
                fd.append('q', texto);
                fd.append('limit', String(CLIENTES_LIMIT));

                fetch(URL_CLIENTES_BUSCAR, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: fd
                })
                .then(r => r.json())
                .then(data => {
                    if (!data.exito) {
                        avisoDiv.innerHTML = '⚠️ ' + (data.error || 'Error al buscar clientes.');
                        resultadosDiv.style.display = 'none';
                        return;
                    }

                    clientesActuales = data.clientes || [];
                    renderizarResultados(clientesActuales);

                    const avisos = [];
                    const repetidosIdent = clientesActuales.filter(c => c.identificacion && /\S/.test(c.identificacion));
                    if (repetidosIdent.length > 0) {
                        avisos.push('Hay ' + repetidosIdent.length + ' cliente(s) con identificación.');
                    }
                    const inactivos = clientesActuales.filter(c => !c.activo);
                    if (inactivos.length) {
                        avisos.push('Advertencia: ' + inactivos.length + ' cliente(s) inactivo(s) detectado(s).');
                    }

                    if (avisos.length) {
                        avisoDiv.innerHTML = avisos.join(' | ');
                    }
                })
                .catch(() => {
                    avisoDiv.innerHTML = '⚠️ Error de comunicación al buscar clientes.';
                });
            }, 250);
        });

        // Mostrar formulario de nuevo cliente al hacer clic en "Registrar nuevo".
        // Botón de registrar nuevo (se crea debajo de resultados).
        function mostrarFormNuevo() {
            nuevoForm.style.display = 'block';
            resultadosDiv.style.display = 'none';
            infoDiv.innerHTML = '';
            avisoDiv.innerHTML = 'Puede registrar un cliente nuevo. Complete nombre obligatorio.';
        }

        // Insertar botón de registro debajo del div de resultados.
        const btnNuevoCliente = document.createElement('div');
        btnNuevoCliente.style.cssText = 'padding:6px 10px; font-size:12px; color:#6777ef; cursor:pointer; border-top:1px solid #f0f0f0;';
        btnNuevoCliente.textContent = '+ Registrar cliente nuevo';
        btnNuevoCliente.addEventListener('click', () => {
            mostrarFormNuevo();
        });
        resultadosDiv.parentNode.insertBefore(btnNuevoCliente, resultadosDiv.nextSibling);

        // Guardar y seleccionar un cliente nuevo sin cerrar el modal.
        contenedor.querySelector('#cliente-nuevo-guardar').addEventListener('click', function () {
            const campoNombre = contenedor.querySelector('#cliente-nuevo-nombre');
            const nombre = campoNombre.value.trim();

            if (nombre === '') {
                avisoDiv.innerHTML = '⚠️ El nombre del cliente es obligatorio.';
                campoNombre.focus();
                return;
            }

            const boton = this;
            boton.disabled = true;
            avisoDiv.innerHTML = 'Guardando cliente...';

            const fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            fd.append('accion', 'crear');
            fd.append('nombre', nombre);
            fd.append('identificacion', contenedor.querySelector('#cliente-nuevo-ident').value.trim());
            fd.append('telefono', contenedor.querySelector('#cliente-nuevo-tel').value.trim());
            fd.append('direccion', contenedor.querySelector('#cliente-nuevo-dir').value.trim());

            fetch(URL_CLIENTES_BUSCAR, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: fd
            })
            .then(r => r.json())
            .then(data => {
                if (!data.exito) {
                    avisoDiv.innerHTML = '⚠️ ' + (data.error || 'No se pudo registrar el cliente.');

                    // Identificación duplicada: el backend devuelve el cliente
                    // existente; se selecciona para no obligar a buscarlo.
                    if (data.cliente && data.cliente.id) {
                        seleccionarCliente(data.cliente.id, data.cliente.nombre);
                    }
                    return;
                }

                seleccionarCliente(data.cliente.id, data.cliente.nombre);
                nuevoForm.style.display = 'none';
                avisoDiv.innerHTML = data.alerta
                    ? '✅ Cliente creado y seleccionado. Revise posibles duplicados.'
                    : '✅ Cliente creado y seleccionado.';
            })
            .catch(() => {
                avisoDiv.innerHTML = '⚠️ Error de comunicación al registrar el cliente.';
            })
            .finally(() => {
                boton.disabled = false;
            });
        });

        // Limpiar selección al escribir.
        input.addEventListener('input', () => {
            if (input.value.trim().length < 2) {
                clienteSeleccionadoId = null;
                const inputOculto = contenedor.querySelector('#cliente-seleccionado');
                if (inputOculto) inputOculto.value = '';
                infoDiv.innerHTML = '';
            }
        });
            },
            preConfirm: () => {
                const inputOculto = document.getElementById('cliente-seleccionado');
                const clienteId = inputOculto ? Number(inputOculto.value) : 0;
                const meseroInput = document.getElementById('mesero-asignado');
                const meseroId = meseroInput ? Number(meseroInput.value) : 0;

                return {
                    clienteId: clienteId > 0 ? clienteId : null,
                    meseroId: meseroId > 0 ? meseroId : null,
                };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const datos = result.value || {};
                abrirMesa(mesaId, datos.clienteId || null, datos.meseroId || null);
            }
        });
    }

    function abrirMesa(mesaId, clienteId, meseroId) {
        Swal.fire({
            title: 'Abriendo mesa...',
            text: 'Espere un momento.',
            allowOutsideClick: false,
            allowEscapeKey: false,
            didOpen: () => Swal.showLoading()
        });

        const formData = new FormData();
        formData.append('_token', '{{ csrf_token() }}');
        formData.append('accion', 'abrir_mesa');
        formData.append('mesa_id', mesaId);
        if (clienteId) formData.append('cliente_id', clienteId);
        if (meseroId) formData.append('mesero_id', meseroId);

        fetch('{{ route("mesas.api") }}', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: formData,
        })
        // Lee el contenido crudo primero y comprueba el content-type antes de
        // intentar interpretarlo. Así un HTML (p. ej. redirección del bloqueo)
        // nunca se confunde con JSON y se muestra un mensaje claro.
        .then(async response => {
            const contentType = (response.headers.get('content-type') || '').toLowerCase();
            const raw = await response.text();
            let data = null;

            if (contentType.includes('application/json') || contentType === '') {
                try { data = raw ? JSON.parse(raw) : null; } catch (e) { data = null; }
            }

            if (!data || typeof data.exito === 'undefined') {
                Swal.fire({
                    title: 'No se pudo abrir la mesa',
                    text: 'El servidor devolvió una respuesta no válida. Intenta de nuevo.',
                    icon: 'error',
                });
                return;
            }

            if (data.caja_cerrada) {
                Swal.fire({
                    title: 'No hay una caja abierta',
                    text: data.error || 'No hay una caja abierta. Debes abrir una caja antes de realizar operaciones.',
                    icon: 'warning',
                    confirmButtonText: 'Ir a apertura de caja',
                    confirmButtonColor: '#6777ef',
                    showCancelButton: true,
                    cancelButtonText: 'Cerrar',
                }).then(result => {
                    if (result.isConfirmed) {
                        window.location.href = "{{ route('caja.apertura') }}";
                    }
                });
                return;
            }

            if (data.exito) {
                // Tras preparar la mesa, entra al POS para registrar el primer
                // producto. La mesa no se marca OCUPADA hasta que exista consumo.
                window.location.href = URL_POS_MESA + '/' + mesaId;
                return;
            }

            Swal.fire('Atención', data.error || 'No fue posible realizar la acción.', 'warning');
        })
        .catch(error => {
            Swal.fire('Error del Servidor', 'Revisa tu conexión o los logs de Laravel.', 'error');
            console.error("Error en Fetch:", error);
        });
    }
</script>
@endsection
