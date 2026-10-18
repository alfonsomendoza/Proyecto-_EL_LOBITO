@extends('layouts.app')

@section('content')

{{--
    Panel de subcuentas de una mesa.

    Muestra únicamente las cuentas que pertenecen a la VENTA ACTIVA de la mesa
    seleccionada. No crea ventas ni cuentas: solo lista y permite abrir cada
    cuenta para trabajar con ella.

    Variables recibidas desde VentaController@panelCuentas:
    - $mesa      : datos de la mesa (tabla mesas)
    - $venta     : venta activa de la mesa (tabla ventas)
    - $cuentas   : cuentas de esa venta (se excluyen las CANCELADA)
    - $puedeCrear: true para ADMIN, GERENTE, CAJERO y MESERO (Fase 2)
    - $puedeDividir: true para ADMIN, GERENTE y CAJERO (permisos actuales)
--}}

<style>
    .grid-cuentas { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; padding: 20px; }
    .cuenta-card { background: #fff; border: 1px solid #f0f0f0; border-radius: 10px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.06); overflow: hidden; display: flex; flex-direction: column; transition: transform 0.2s, box-shadow 0.2s; }
    .cuenta-card:hover { transform: translateY(-4px); box-shadow: 0 8px 14px rgba(0, 0, 0, 0.12); }
    .cuenta-body { padding: 20px; flex: 1; }
    .cuenta-nombre { font-size: 17px; font-weight: 700; color: #191d21; margin: 0 0 6px; word-break: break-word; }
    .cuenta-total-label { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #98a6ad; font-weight: 700; }
    .cuenta-total { font-size: 26px; font-weight: 800; color: #34395e; margin: 4px 0 0; }
    .badge-estado { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; color: #fff; }
    .badge-ABIERTA { background: #47c363; }
    .badge-COBRANDO { background: #ffa426; }
    .badge-PAGADA { background: #6c757d; }
    .cuenta-footer { border-top: 1px solid #f4f6f9; padding: 12px 20px; display: flex; justify-content: flex-end; }
    .btn-abrir { background: #6777ef; color: #fff; border: none; padding: 7px 16px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-abrir:hover { opacity: .88; }
    .btn-volver { background: #6c757d; color: #fff; border: none; padding: 7px 16px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-volver:hover { opacity: .88; }
    .btn-dividir { background: transparent; color: #6777ef; border: 1px solid #6777ef; padding: 6px 14px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-dividir:hover { background: #6777ef; color: #fff; }
    .btn-crear { background: #47c363; color: #fff; border: none; padding: 7px 16px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(71,195,99,0.3); }
    .btn-crear:hover { opacity: .88; }
    .btn-trasladar { background: #fff; color: #ffa426; border: 1px solid #ffa426; padding: 6px 12px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; margin-right: 8px; }
    .btn-trasladar:hover { background: #ffa426; color: #fff; }
    .flash { padding: 12px 20px; font-size: 13px; font-weight: bold; }
    .flash-error { background: #fdecea; color: #fc544b; }
    .flash-success { background: #e6f7ea; color: #2f9e44; }
    .contenido-vacio { padding: 50px 20px; text-align: center; color: #98a6ad; }
</style>

<div id="panel-cuentas" class="monitor-card"
     style="max-width: 1200px; margin: 0 auto;"
     data-venta-id="{{ (int) $venta->id }}"
     data-mesas-destino="{{ $mesasDestino->map(fn ($m) => ['id' => $m->id, 'numero' => $m->numero])->values()->toJson() }}">

    {{-- Avisos de acciones de la Fase 2 (crear subcuenta), si los hay. --}}
    @if(session('success'))
        <div class="flash flash-success"><i class="fas fa-check-circle"></i> {{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i> {{ session('error') }}</div>
    @endif

    {{-- Encabezado del panel --}}
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; background: #fff; padding: 18px 20px;">
        <div style="display: flex; align-items: center; gap: 15px;">
            <span style="font-size: 18px; font-weight: bold; color: #34395e;">
                <i class="fas fa-layer-group"></i> Cuentas de Mesa {{ $mesa->numero }}
            </span>
            <span style="font-size: 12px; color: #98a6ad; font-weight: bold;">
                Venta #{{ $venta->id }} · {{ $venta->estado }}
            </span>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            @if($puedeCrear)
                {{-- Fase 2: los cuatro roles pueden crear subcuentas vacías. --}}
                <button type="button" id="btn-nueva-cuenta" class="btn-crear"
                        title="Crear una cuenta vacía en esta venta">
                    <i class="fas fa-plus"></i> Nueva cuenta
                </button>
            @endif
            @if($puedeDividir)
                {{-- Lleva al POS general, donde ya existe el modal de dividir cuentas. --}}
                <a href="{{ route('pos.index', $mesa->id) }}" class="btn-dividir"
                   title="Administrar la venta completa de la mesa">
                    <i class="fas fa-people-arrows"></i> Dividir cuentas
                </a>
            @endif
            @if($puedeTrasladar && $cuentaTrasladoId)
                {{--
                    Traslado de MESA COMPLETA.

                    El POS maneja UNA venta por mesa. Por eso mover una
                    subcuenta de forma individual NO es posible con el esquema
                    actual: no existe una venta independiente por subcuenta ni
                    un enlace que permita reasignar líneas entre ventas sin
                    romper totales, comandas y numeración de tickets.

                    Esta acción mueve TODA la venta (todas las cuentas y
                    subcuentas) a la mesa destino. Se etiqueta explícitamente
                    como "Trasladar mesa" para no ofrecer un traslado
                    individual que en realidad movería la mesa completa.
                --}}
                <button type="button" id="btn-trasladar-mesa" class="btn-trasladar"
                        data-cuenta-id="{{ $cuentaTrasladoId }}"
                        data-mesa-numero="{{ e($mesa->numero) }}"
                        title="Mover TODO el consumo de esta mesa a otra">
                    <i class="fas fa-right-left"></i> Trasladar mesa
                </button>
            @endif
            <a href="{{ route('mesas.index') }}" class="btn-volver">
                <i class="fas fa-arrow-left"></i> Volver a Mesas
            </a>
        </div>
    </div>

    @if($mesaVacia)
        {{--
            Venta activa SIN consumos: flujo de recuperación de mesa vacía.
            Se reutiliza liberarMesa() (mismas validaciones y permisos que el
            botón "Liberar" del POS). Nunca libera con consumos, asignaciones
            pendientes o cuentas con importe pendiente.
        --}}
        <div class="contenido-vacio">
            <i class="fas fa-inbox" style="font-size: 40px; display: block; margin-bottom: 10px;"></i>
            <p style="margin-bottom: 18px;">Esta mesa no tiene consumos registrados.</p>
            <div style="display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;">
                <a href="{{ route('pos.index', $mesa->id) }}" class="btn-abrir"
                   title="Ir al POS para registrar productos">
                    <i class="fas fa-cash-register"></i> Ir al POS
                </a>
                @if($puedeLiberar)
                    <button type="button" id="btn-liberar-vacia" class="btn-volver"
                            title="Liberar la mesa vacía y conservar el historial">
                        <i class="fas fa-ban"></i> Liberar mesa
                    </button>
                @endif
            </div>
        </div>
    @endif

    @if($cuentas->isEmpty())
        @if(!$mesaVacia)
            <div class="contenido-vacio">
                <i class="fas fa-inbox" style="font-size: 40px; display: block; margin-bottom: 10px;"></i>
                Esta mesa no tiene cuentas registradas.
            </div>
        @endif
    @else
        <div class="grid-cuentas">
            @foreach($cuentas as $cuenta)
                @php
                    // Clase de color del badge según el estado real de la cuenta.
                    $clasesEstado = [
                        'ABIERTA' => 'badge-ABIERTA',
                        'COBRANDO' => 'badge-COBRANDO',
                        'PAGADA' => 'badge-PAGADA',
                    ];
                    $claseBadge = $clasesEstado[$cuenta->estado] ?? 'badge-PAGADA';
                @endphp

                <div class="cuenta-card">
                    <div class="cuenta-body">
                        <p class="cuenta-nombre">{{ $cuenta->nombre }}</p>
                        <span class="badge-estado {{ $claseBadge }}">{{ $cuenta->estado }}</span>
                        <div style="margin-top: 14px;">
                            <span class="cuenta-total-label">Total</span>
                            <p class="cuenta-total">C${{ number_format((float) $cuenta->total, 2) }}</p>
                        </div>
                    </div>
                    <div class="cuenta-footer" style="justify-content: flex-end;">
                        {{--
                            Abre el POS acotado a esta subcuenta (?cuenta=).
                            El POS general (sin ?cuenta=) sigue sirviendo para
                            dividir y ver la mesa completa.

                            El traslado NO es por cuenta: es de la mesa completa
                            (ver el botón "Trasladar mesa" en el encabezado).
                        --}}
                        <a class="btn-abrir"
                           href="{{ route('pos.index', $mesa->id) }}?cuenta={{ $cuenta->id }}"
                           title="Abrir esta cuenta">
                            <i class="fas fa-folder-open"></i> Abrir cuenta
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

<script>
    // Fase 2: crear subcuenta vacía (ADMIN, GERENTE, CAJERO y MESERO).
    const URL_CREAR_CUENTA = '{{ route("mesas.cuentas.crear", ["mesaId" => $mesa->id]) }}';

    function crearSubcuenta() {
        Swal.fire({
            title: 'Nueva subcuenta',
            input: 'text',
            inputLabel: 'Nombre (ej. Ana)',
            inputPlaceholder: 'Escribe el nombre de la subcuenta',
            showCancelButton: true,
            confirmButtonColor: '#47c363',
            confirmButtonText: 'Crear cuenta',
            cancelButtonText: 'Cancelar',
            inputValidator: (valor) => (valor && valor.trim() !== '' ? null : 'El nombre es obligatorio.')
        }).then((result) => {
            if (!result.isConfirmed) return;

            const fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            fd.append('nombre', result.value.trim());

            fetch(URL_CREAR_CUENTA, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: fd
            })
            .then(respuesta => respuesta.json())
            .then(data => {
                if (data.exito) {
                    Swal.fire('¡Creada!', data.mensaje, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Atención', data.error || 'No se pudo crear la cuenta.', 'warning');
                }
            })
            .catch(() => Swal.fire('Error', 'Fallo de comunicación con el servidor.', 'error'));
        });
    }

    const btnNuevaCuenta = document.getElementById('btn-nueva-cuenta');
    if (btnNuevaCuenta) {
        btnNuevaCuenta.addEventListener('click', crearSubcuenta);
    }

    // Fase 6: traslado de la MESA COMPLETA entre mesas (ADMIN, GERENTE, CAJERO).
    // El backend mueve la venta completa (todas las cuentas) porque el POS usa
    // una sola venta por mesa.
    const URL_TRASLADAR = '{{ route("pos.cuentas.trasladar", ["cuentaId" => "__ID__"]) }}';
    const MESAS_DESTINO = JSON.parse(document.getElementById('panel-cuentas').dataset.mesasDestino || '[]');

    function trasladarMesaCompleta(cuentaId, numeroMesa) {
        if (!MESAS_DESTINO.length) {
            Swal.fire('Sin mesas libres', 'No hay otra mesa libre para recibir el consumo.', 'info');
            return;
        }

        const opciones = MESAS_DESTINO
            .map(m => `<option value="${m.id}">Mesa ${m.numero}</option>`)
            .join('');

        Swal.fire({
            title: 'Trasladar mesa completa',
            html: `
                <div style="text-align: left; font-size: 14px;">
                    <p style="margin-bottom: 10px;">Se moverá <strong>TODO el consumo de la mesa
                       ${numeroMesa}</strong> (todas las cuentas y subcuentas) a la mesa seleccionada.</p>
                    <p style="margin-bottom: 10px; color: #ffa426; font-weight: bold;">
                       El traslado es de la mesa completa; no es posible mover una sola subcuenta.</p>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold;">Mesa destino:</label>
                    <select id="traslado-destino" class="swal2-input" style="width: 100%; height: 40px; margin: 0;">
                        ${opciones}
                    </select>
                </div>`,
            showCancelButton: true,
            confirmButtonColor: '#ffa426',
            confirmButtonText: '<i class="fas fa-right-left"></i> Trasladar',
            cancelButtonText: 'Cancelar',
            focusConfirm: false,
            preConfirm: () => {
                const sel = document.getElementById('traslado-destino');
                return sel ? sel.value : null;
            }
        }).then((result) => {
            if (!result.isConfirmed || !result.value) return;

            const fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            fd.append('mesa_destino_id', result.value);

            fetch(URL_TRASLADAR.replace('__ID__', cuentaId), {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                body: fd
            })
            .then(respuesta => respuesta.json())
            .then(data => {
                if (data.exito) {
                    Swal.fire('¡Trasladada!', data.mensaje, 'success')
                        .then(() => window.location.href = '{{ route("mesas.index") }}');
                } else {
                    Swal.fire('Atención', data.error || 'No se pudo trasladar la mesa.', 'warning');
                }
            })
            .catch(() => Swal.fire('Error', 'Fallo de comunicación con el servidor.', 'error'));
        });
    }

    const btnTrasladarMesa = document.getElementById('btn-trasladar-mesa');
    if (btnTrasladarMesa) {
        btnTrasladarMesa.addEventListener('click', function () {
            trasladarMesaCompleta(Number(this.dataset.cuentaId), this.dataset.mesaNumero);
        });
    }

    // Fase de recuperación de mesas vacías: liberar reutilizando liberarMesa()
    // vía pos.api (accion=liberar_mesa_vacia). Mismas validaciones y permisos
    // que el botón "Liberar" del POS.
    const URL_LIBERAR_MESA = '{{ route("pos.api") }}';
    const VENTA_ID = Number(document.getElementById('panel-cuentas').dataset.ventaId);
    const URL_MESAS = '{{ route("mesas.index") }}';

    const btnLiberarVacia = document.getElementById('btn-liberar-vacia');
    if (btnLiberarVacia) {
        btnLiberarVacia.addEventListener('click', function () {
            Swal.fire({
                title: '¿Liberar esta mesa?',
                text: 'La mesa volverá a estar libre y se descartará esta apertura vacía. El historial se conserva.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#fc544b',
                confirmButtonText: 'Sí, liberar mesa',
                cancelButtonText: 'Cancelar'
            }).then(function (result) {
                if (!result.isConfirmed) return;

                const fd = new FormData();
                fd.append('_token', '{{ csrf_token() }}');
                fd.append('accion', 'liberar_mesa_vacia');
                fd.append('venta_id', VENTA_ID);

                fetch(URL_LIBERAR_MESA, { method: 'POST', body: fd })
                    .then(function (respuesta) { return respuesta.json(); })
                    .then(function (data) {
                        if (data.exito) {
                            Swal.fire('¡Mesa liberada!', data.mensaje || 'La mesa fue liberada correctamente.', 'success')
                                .then(function () { window.location.href = URL_MESAS; });
                        } else {
                            Swal.fire('Atención', data.error || 'No se pudo liberar la mesa.', 'warning');
                        }
                    })
                    .catch(function () {
                        Swal.fire('Error', 'Fallo de comunicación con el servidor.', 'error');
                    });
            });
        });
    }
</script>
@endsection
