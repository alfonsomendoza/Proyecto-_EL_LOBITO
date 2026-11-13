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

    Dos formas de traslado (ambas con permisos ADMIN, GERENTE y CAJERO):
    - Por CUENTA: botón "Trasladar" en cada tarjeta. Mueve SOLO el consumo
      de esa subcuenta a otra mesa (trasladarConsumoCuenta).
    - Mesa COMPLETA: botón del encabezado. Mueve TODA la venta
      (trasladarCuenta).
--}}

<style>
    /* --- Rejilla responsiva de tarjetas --- */
    .grid-cuentas {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr));
        gap: 16px;
        padding: 16px;
        align-items: stretch;
    }

    /* --- Tarjeta de cuenta --- */
    .cuenta-card {
        background: #fff;
        border: 1px solid #eceff3;
        border-radius: 12px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        height: 100%;
        transition: transform 0.18s ease, box-shadow 0.18s ease;
    }
    .cuenta-card:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(0, 0, 0, 0.10); }

    .cuenta-body { padding: 18px; flex: 1; display: flex; flex-direction: column; gap: 14px; }

    /* Nombre + estado en la misma fila; si no caben, el estado baja de línea. */
    .cuenta-cabecera { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; flex-wrap: wrap; }
    .cuenta-nombre { font-size: 17px; font-weight: 700; color: #191d21; margin: 0; word-break: break-word; line-height: 1.25; }

    /* Línea de contexto: mesa y venta a las que pertenece la cuenta. */
    .cuenta-meta { font-size: 11px; font-weight: 700; color: #98a6ad; margin: 0; letter-spacing: .3px; }

    /* Importe */
    .cuenta-total-label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #98a6ad; font-weight: 700; }
    .cuenta-total { font-size: 26px; font-weight: 800; color: #34395e; margin: 2px 0 0; word-break: break-word; }

    /* Etiquetas de estado: pintan el estado REAL que entrega el backend. */
    .badge-estado { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; color: #fff; white-space: nowrap; flex-shrink: 0; }
    .badge-ABIERTA { background: #47c363; }
    .badge-COBRANDO { background: #ffa426; }
    .badge-PAGADA { background: #6c757d; }
    .badge-CANCELADA { background: #fc544b; }
    /* Estado no previsto: se muestra neutro para no aparentar "PAGADA". */
    .badge-neutral { background: #adb5bd; }

    /* Pie de acciones: los botones nunca se recortan; bajan a otra fila si no caben. */
    .cuenta-footer {
        border-top: 1px solid #f4f6f9;
        padding: 12px 16px;
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        align-items: center;
        gap: 8px;
    }

    /* Botones: tamaño y estilo consistentes en toda la pantalla. */
    .btn-accion {
        border: none; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 12px;
        text-decoration: none; display: inline-flex; align-items: center; justify-content: center;
        gap: 6px; padding: 8px 14px; min-height: 34px; line-height: 1; white-space: nowrap;
        transition: opacity .15s ease, background-color .15s ease, color .15s ease;
    }
    .btn-accion:hover { opacity: .88; }

    .btn-abrir { background: #6777ef; color: #fff; }
    .btn-volver { background: #6c757d; color: #fff; }
    .btn-dividir { background: transparent; color: #6777ef; border: 1px solid #6777ef; }
    .btn-dividir:hover { background: #6777ef; color: #fff; opacity: 1; }
    .btn-crear { background: #47c363; color: #fff; box-shadow: 0 2px 4px rgba(71,195,99,0.3); }
    .btn-trasladar { background: #fff; color: #ffa426; border: 1px solid #ffa426; }
    .btn-trasladar:hover { background: #ffa426; color: #fff; opacity: 1; }
    .btn-trasladar-cuenta { background: transparent; color: #e8590c; border: 1px solid #e8590c; }
    .btn-trasladar-cuenta:hover { background: #e8590c; color: #fff; opacity: 1; }

    /* Prefactura (solo lectura, abre en pestaña nueva para imprimir). */
    .btn-prefactura-cuenta { background: transparent; color: #5a5f7d; border: 1px solid #5a5f7d; }
    .btn-prefactura-cuenta:hover { background: #5a5f7d; color: #fff; opacity: 1; }

    .flash { padding: 12px 20px; font-size: 13px; font-weight: bold; }
    .flash-error { background: #fdecea; color: #fc544b; }
    .flash-success { background: #e6f7ea; color: #2f9e44; }
    .contenido-vacio { padding: 50px 20px; text-align: center; color: #98a6ad; }

    /* --- Responsivo (escritorio, tablet y móvil) --- */
    @media (max-width: 640px) {
        .grid-cuentas { gap: 12px; padding: 12px; }
        .cuenta-body { padding: 14px; gap: 12px; }
        .cuenta-total { font-size: 22px; }
        .cuenta-footer { padding: 10px 12px; }
        /* En pantallas pequeñas los botones ocupan el ancho disponible y siguen legibles. */
        .cuenta-footer .btn-accion { flex: 1 1 auto; }
    }
</style>

<div id="panel-cuentas" class="monitor-card"
     style="max-width: 1200px; margin: 0 auto;"
     data-venta-id="{{ (int) $venta->id }}"
     data-mesero-actual="{{ (int) ($meseroIdActual ?? 0) }}"
     data-cuenta-id="{{ (int) ($cuentas->first()?->id ?? 0) }}"
     data-mesas-destino="{{ $mesasDestino->map(fn ($m) => ['id' => $m->id, 'numero' => $m->numero])->values()->toJson() }}"
     data-mesas-individual="{{ $mesasDestinoIndividual->map(fn ($m) => ['id' => $m->id, 'numero' => $m->numero, 'estado' => $m->estado])->values()->toJson() }}">

    {{-- Avisos de acciones de la Fase 2 (crear subcuenta), si los hay. --}}
    @if(session('success'))
        <div class="flash flash-success"><i class="fas fa-check-circle"></i> {{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash flash-error"><i class="fas fa-exclamation-circle"></i> {{ session('error') }}</div>
    @endif

    {{-- Encabezado del panel --}}
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; background: #fff; padding: 18px 20px;">
        <div style="display: flex; flex-direction: column; gap: 6px; min-width: 260px;">
            <div style="font-size: 12px; color: #5a5f7d;">
                <strong>Abierta por:</strong> {{ $abrioPor ?? 'No registrado' }}
            </div>
            <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 12px; color: #5a5f7d;">
                <strong>Mesero encargado:</strong>
                <span id="lbl-mesero-encargado">{{ $meseroEncargado ?? 'Sin mesero asignado' }}</span>

                @if($puedeEditarMesero)
                    <select id="sel-mesero-encargado" style="padding: 3px 8px; border: 1px solid #ddd; border-radius: 4px; max-width: 220px;">
                        <option value="">Sin asignar</option>
                        @foreach($meserosActivos as $m)
                            <option value="{{ (int) $m->id }}" @selected((int) ($meseroIdActual ?? 0) === (int) $m->id)>{{ $m->nombre }}</option>
                        @endforeach
                    </select>
                    <button type="button" id="btn-asignar-mesero" class="btn-accion" style="background:#28a745; color:#fff; border:none; padding:4px 10px; border-radius:4px; font-weight:bold; cursor:pointer;">
                        Asignar
                    </button>
                @endif
            </div>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            @if($puedeCrear)
                {{-- Fase 2: los cuatro roles pueden crear subcuentas vacías. --}}
                <button type="button" id="btn-nueva-cuenta" class="btn-accion btn-crear"
                        title="Crear una cuenta vacía en esta venta">
                    <i class="fas fa-plus"></i> Nueva cuenta
                </button>
            @endif
            @if($puedeDividir)
                {{-- Lleva al POS general, donde ya existe el modal de dividir cuentas. --}}
                <a href="{{ route('pos.index', $mesa->id) }}" class="btn-accion btn-dividir"
                   title="Administrar la venta completa de la mesa">
                    <i class="fas fa-people-arrows"></i> Dividir cuentas
                </a>
            @endif
            @if($puedeTrasladar && $cuentaTrasladoId)
                {{--
                    Traslado de MESA COMPLETA: mueve TODA la venta (todas
                    las cuentas y subcuentas) a una mesa LIBRE.

                    Para mover SOLO el consumo de una subcuenta existe el
                    botón "Trasladar" de cada tarjeta (abajo), que usa
                    pos.cuentas.trasladoConsumo.
                --}}
                <button type="button" id="btn-trasladar-mesa" class="btn-accion btn-trasladar"
                        data-cuenta-id="{{ $cuentaTrasladoId }}"
                        data-mesa-numero="{{ e($mesa->numero) }}"
                        title="Mover TODO el consumo de esta mesa a otra">
                    <i class="fas fa-right-left"></i> Trasladar mesa
                </button>
            @endif
            <a href="{{ route('mesas.index') }}" class="btn-accion btn-volver">
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
                <a href="{{ route('pos.index', $mesa->id) }}" class="btn-accion btn-abrir"
                   title="Ir al POS para registrar productos">
                    <i class="fas fa-cash-register"></i> Ir al POS
                </a>
                @if($puedeLiberar)
                    <button type="button" id="btn-liberar-vacia" class="btn-accion btn-volver"
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
                    // Clase de color del badge según el estado REAL que entrega el
                    // backend. No se inventan estados: si llegara uno no previsto,
                    // se pinta con estilo neutro para no aparentar "PAGADA".
                    $clasesEstado = [
                        'ABIERTA' => 'badge-ABIERTA',
                        'COBRANDO' => 'badge-COBRANDO',
                        'PAGADA' => 'badge-PAGADA',
                        'CANCELADA' => 'badge-CANCELADA',
                    ];
                    $claseBadge = $clasesEstado[$cuenta->estado] ?? 'badge-neutral';
                @endphp

                <div class="cuenta-card">
                    <div class="cuenta-body">
                        <div class="cuenta-cabecera">
                            <p class="cuenta-nombre">{{ $cuenta->nombre }}</p>
                            <span class="badge-estado {{ $claseBadge }}">{{ $cuenta->estado }}</span>
                        </div>
                        <p class="cuenta-meta">
                            Mesa {{ $mesa->numero }}
                            <span aria-hidden="true">·</span>
                            Venta #{{ $venta->id }}
                        </p>
                        @php
                            $clienteEfectivoId = $cuenta->cliente_id ?: $venta->cliente_id;
                        @endphp
                        <p class="cuenta-meta" style="margin-top:4px;">
                            @if($cuenta->cliente_id)
                                Cliente de la subcuenta: {{ $clientesMap[$cuenta->cliente_id] ?? 'Sin nombre' }}
                            @else
                                Sin cliente propio (usa cliente de la venta: {{ $clientesMap[$venta->cliente_id] ?? 'Sin cliente' }})
                            @endif
                        </p>
                        @if(($cuenta->fiscal->iva ?? 0) > 0 || ($cuenta->fiscal->retencion_dgi ?? 0) > 0 || ($cuenta->fiscal->retencion_alcaldia ?? 0) > 0)
                        <div style="margin-top:6px; font-size:12px; color:#34395e;">
                            @if(($cuenta->fiscal->iva ?? 0) > 0)
                                <div>IVA 15%: C$ {{ number_format($cuenta->fiscal->iva, 2) }}</div>
                            @endif
                            @if(($cuenta->fiscal->retencion_dgi ?? 0) > 0)
                                <div>Retención DGI 2%: −C$ {{ number_format($cuenta->fiscal->retencion_dgi, 2) }}</div>
                            @endif
                            @if(($cuenta->fiscal->retencion_alcaldia ?? 0) > 0)
                                <div>Retención Alcaldía 1%: −C$ {{ number_format($cuenta->fiscal->retencion_alcaldia, 2) }}</div>
                            @endif
                            <div><strong>Neto: C$ {{ number_format($cuenta->fiscal->neto, 2) }}</strong></div>
                        </div>
                        @endif
                        <div>
                            <span class="cuenta-total-label">Total</span>
                            <p class="cuenta-total">C$ {{ number_format((float) $cuenta->total, 2) }}</p>
                            {{--
                                Cuenta cobrada CON ajustes: `total` guarda el
                                CONSUMO bruto (así lo define la migración
                                2026_10_18_000002); lo REALMENTE cobrado es
                                total − descuento + propina. Se muestra aparte
                                para no confundir al leer el panel.
                            --}}
                            @if($cuenta->estado === 'PAGADA'
                                && ((float) ($cuenta->descuento ?? 0) > 0
                                    || (float) ($cuenta->propina ?? 0) > 0))
                                @php
                                    $netoCobrado = (float) $cuenta->total
                                        - (float) ($cuenta->descuento ?? 0)
                                        + (float) ($cuenta->propina ?? 0);
                                @endphp
                                <p class="cuenta-meta" style="margin-top:4px;">
                                    Cobrado: C$ {{ number_format($netoCobrado, 2) }}
                                    @if((float) ($cuenta->descuento ?? 0) > 0)
                                        (con descuento de C$ {{ number_format((float) $cuenta->descuento, 2) }})
                                    @endif
                                    @if((float) ($cuenta->propina ?? 0) > 0)
                                        (con propina de C$ {{ number_format((float) $cuenta->propina, 2) }})
                                    @endif
                                </p>
                            @endif
                        </div>
                    </div>
                    <div class="cuenta-footer">
                        {{--
                            Cambiar el cliente asociado a esta cuenta ABIERTA.
                            Solo ADMIN, GERENTE y CAJERO; el backend vuelve a
                            validar el rol, el estado y que la cuenta pertenezca
                            a la mesa.
                        --}}
                        @if($puedeCambiarCliente && $cuenta->estado === 'ABIERTA')
                            <div style="margin-bottom:8px;">
                                <label style="display:block; font-size:12px; font-weight:bold; color:#34395e; margin-bottom:4px;">
                                    Buscar y seleccionar cliente
                                </label>
                                <input type="text" class="cc-buscar-cliente" data-cuenta-id="{{ $cuenta->id }}"
                                       placeholder="Buscar por nombre o identificación..."
                                       style="width:100%; height:32px; box-sizing:border-box; font-size:12px; margin-bottom:6px;">
                                <select class="cc-select-cliente" data-cuenta-id="{{ $cuenta->id }}"
                                        style="width:100%; height:34px; box-sizing:border-box; font-size:12px;">
                                    <option value="">Sin cliente (usa el de la venta)</option>
                                    @foreach($clientes as $cl)
                                        <option value="{{ $cl->id }}"
                                            data-nombre="{{ \Illuminate\Support\Str::lower($cl->nombre ?? '') }}"
                                            data-ident="{{ \Illuminate\Support\Str::lower($cl->identificacion ?? '') }}"
                                            {{ (int)$cuenta->cliente_id === (int)$cl->id ? 'selected' : '' }}>
                                            {{ $cl->nombre }}
                                        </option>
                                    @endforeach
                                </select>
                                <button type="button" class="btn-accion btn-guardar-cliente"
                                        data-cuenta-id="{{ $cuenta->id }}"
                                        style="margin-top:6px;">
                                    <i class="fas fa-save"></i> Guardar cliente
                                </button>
                            </div>
                        @endif
                        @if($puedeCambiarCliente && $cuenta->estado === 'ABIERTA')
                            <button type="button"
                                    class="btn-accion btn-cambiar-cliente"
                                    data-cuenta-id="{{ $cuenta->id }}"
                                    data-cuenta-nombre="{{ $cuenta->nombre }}"
                                    title="Cambiar el cliente de esta cuenta">
                                <i class="fas fa-user-edit"></i> Cambiar cliente
                            </button>
                        @endif
                        @if($puedeCambiarCliente && $cuenta->estado === 'ABIERTA')
                            <button type="button"
                                    class="btn-accion btn-quitar-cliente"
                                    data-cuenta-id="{{ $cuenta->id }}"
                                    title="Quitar el cliente propio de esta cuenta">
                                <i class="fas fa-user-times"></i> Quitar cliente
                            </button>
                        @endif

                        {{--
                            Traslado INDIVIDUAL: mueve SOLO el consumo de esta
                            subcuenta a otra mesa (trasladarConsumoCuenta).
                            Solo cuentas ABIERTAS: una PAGADA o CANCELADA ya
                            no representa consumo por cobrar.
                        --}}
                        @if($puedeTrasladar && $cuenta->estado === 'ABIERTA' && (float) $cuenta->total > 0)
                            <button type="button"
                                    class="btn-accion btn-trasladar-cuenta"
                                    data-cuenta-id="{{ $cuenta->id }}"
                                    data-cuenta-nombre="{{ $cuenta->nombre }}"
                                    title="Mover solo el consumo de esta cuenta a otra mesa">
                                <i class="fas fa-right-left"></i> Trasladar
                            </button>
                        @endif

                        {{--
                            Prefactura de ESTA cuenta (solo lectura): la imprime
                            antes de cobrar, sin marcar nada como pagado y sin
                            emitir factura (VentaController::prefactura).
                            Visible para cualquier rol: no modifica datos.
                        --}}
                        @if($cuenta->estado === 'ABIERTA' && (float) $cuenta->total > 0)
                            <button type="button"
                                    class="btn-accion btn-prefactura-cuenta"
                                    data-cuenta-id="{{ $cuenta->id }}"
                                    data-total="{{ $cuenta->total }}"
                                    title="Imprimir prefactura de esta cuenta (NO es factura fiscal)">
                                <i class="fas fa-file-invoice"></i> Prefactura
                            </button>
                        @endif

                        {{--
                            Abre el POS acotado a esta subcuenta (?cuenta=).
                            El POS general (sin ?cuenta=) sigue sirviendo para
                            dividir y ver la mesa completa.
                        --}}
                        <a class="btn-accion btn-abrir"
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

@php
    use Illuminate\Support\Facades\Schema;
    use Illuminate\Support\Facades\DB;
    $cfgCuentas = app(\App\Services\ConfiguracionService::class);
    $rolCuentas = strtoupper((string) (auth()->user()->rol->nombre ?? ''));
    $sujetoCuentas = false;
    if (Schema::hasColumn('clientes', 'sujeto_retenciones') && isset($venta) && $venta->cliente_id) {
        $sujetoCuentas = (bool) DB::table('clientes')->where('id', $venta->cliente_id)->value('sujeto_retenciones');
    }
    $urlCuentasCuentas = route('pos.cuentas', ['mesaId' => $mesa->id]);
@endphp

@include('partials.modal-prefactura', [
    'sujetoRetencion' => $sujetoCuentas,
    'urlCuentas' => $urlCuentasCuentas,
])

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
                       Para mover solo una subcuenta usa el botón "Trasladar" de su tarjeta.</p>
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

    // Traslado INDIVIDUAL de una subcuenta (ADMIN, GERENTE y CAJERO).
    // Mueve SOLO el consumo de la cuenta elegida a otra mesa (libre u
    // ocupada). El backend valida permisos, cocina, totales y estados, y
    // registra la trazabilidad. `clave_operacion` da idempotencia: si la
    // petición se reenviara, no se duplica el movimiento.
    const URL_TRASLADO_CONSUMO = '{{ route("pos.cuentas.trasladoConsumo", ["cuentaId" => "__ID__"]) }}';
    const MESAS_INDIVIDUAL = JSON.parse(document.getElementById('panel-cuentas').dataset.mesasIndividual || '[]');

    function trasladarConsumo(cuentaId, nombreCuenta) {
        if (!MESAS_INDIVIDUAL.length) {
            Swal.fire('Sin mesas destino', 'No hay otra mesa disponible para recibir esta cuenta.', 'info');
            return;
        }

        const opciones = MESAS_INDIVIDUAL
            .map(m => `<option value="${m.id}">Mesa ${m.numero} (${String(m.estado).toLowerCase()})</option>`)
            .join('');

        Swal.fire({
            title: 'Trasladar la cuenta',
            html: `
                <div style="text-align: left; font-size: 14px;">
                    <p style="margin-bottom: 10px;">Se moverá SOLO el consumo de
                       <strong>${nombreCuenta}</strong> a la mesa seleccionada.</p>
                    <p style="margin-bottom: 10px; font-size: 12px; color: #98a6ad;">
                       Si la mesa destino está ocupada, la cuenta se añade a su venta activa.
                       Los platos ya en preparación o listos en cocina bloquean el traslado.</p>
                    <label style="display: block; margin-bottom: 5px; font-weight: bold;">Mesa destino:</label>
                    <select id="traslado-consumo-destino" class="swal2-input" style="width: 100%; height: 40px; margin: 0;">
                        ${opciones}
                    </select>
                </div>`,
            showCancelButton: true,
            confirmButtonColor: '#e8590c',
            confirmButtonText: '<i class="fas fa-right-left"></i> Trasladar',
            cancelButtonText: 'Cancelar',
            focusConfirm: false,
            preConfirm: () => {
                const sel = document.getElementById('traslado-consumo-destino');
                return sel ? sel.value : null;
            }
        }).then((result) => {
            if (!result.isConfirmed || !result.value) return;

            const fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            fd.append('mesa_destino_id', result.value);
            fd.append('clave_operacion',
                'panel-' + cuentaId + '-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10));

            fetch(URL_TRASLADO_CONSUMO.replace('__ID__', cuentaId), {
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
                        .then(() => location.reload());
                } else {
                    Swal.fire('Atención', data.error || 'No se pudo trasladar la cuenta.', 'warning');
                }
            })
            .catch(() => Swal.fire('Error', 'Fallo de comunicación con el servidor.', 'error'));
        });
    }

    document.querySelectorAll('.btn-trasladar-cuenta').forEach(function (boton) {
        boton.addEventListener('click', function () {
            trasladarConsumo(Number(this.dataset.cuentaId), this.dataset.cuentaNombre);
        });
    });

    // Prefactura de una cuenta específica: abre el modal de configuración fiscal
    // y, al confirmar, genera la prefactura (no la genera directamente).
    document.querySelectorAll('.btn-prefactura-cuenta').forEach(function (boton) {
        boton.addEventListener('click', function () {
            const cuentaId = Number(this.dataset.cuentaId);
            const consumo = Number(this.dataset.total || 0);
            window.abrirPrefacturaModal(cuentaId, consumo);
        });
    });

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

<script>
    // Cambiar el cliente de una cuenta ABIERTA desde el panel de subcuentas.
    // Reutiliza clientes.api (búsqueda y alta) y la ruta mesas.cuentas.cliente.
    const URL_CLIENTES_BUSCAR = '{{ route('clientes.api') }}';
    const URL_CAMBIAR_CLIENTE = '{{ route('mesas.cuentas.cliente', ['mesaId' => $mesa->id, 'cuentaId' => '__CUENTA__']) }}';
    const CSRF_TOKEN = '{{ csrf_token() }}';

    function abrirCambiarCliente(cuentaId) {
        let clienteSeleccionado = null;
        let debounce = null;

        Swal.fire({
            title: 'Cambiar cliente',
            html: `
                <div style="text-align: left; font-size: 13px;">
                    <label style="display:block; margin-bottom:5px; font-weight:bold; color:#34395e;">
                        Buscar cliente existente
                    </label>
                    <input type="text" id="cc-buscar" class="swal2-input"
                           placeholder="Nombre, cédula o teléfono..."
                           autocomplete="off"
                           style="height:38px; box-sizing:border-box; width:100%; margin:0;">
                    <div id="cc-resultados" style="display:none; margin-top:8px; max-height:200px; overflow:auto; border:1px solid #e3e6f0; border-radius:6px; background:#fff;"></div>
                    <div id="cc-info" style="margin-top:6px; color:#2f9e44; font-size:12px;"></div>
                    <div style="margin-top:10px;">
                        <button type="button" id="cc-mostrar-nuevo"
                                style="background:none; border:none; color:#6777ef; cursor:pointer; font-size:12px; padding:0;">
                            + Registrar cliente nuevo
                        </button>
                    </div>
                    <div id="cc-nuevo" style="display:none; margin-top:8px; padding:8px; border:1px solid #e3e6f0; border-radius:6px; background:#f8f9fa;">
                        <input type="text" id="cc-nombre" class="swal2-input" placeholder="Nombre completo (*)" style="height:34px; box-sizing:border-box; width:100%; margin:0 0 4px 0;">
                        <input type="text" id="cc-ident" class="swal2-input" placeholder="Cédula/RUC (opcional)" style="height:34px; box-sizing:border-box; width:100%; margin:0 0 4px 0;">
                        <input type="text" id="cc-tel" class="swal2-input" placeholder="Teléfono (opcional)" style="height:34px; box-sizing:border-box; width:100%; margin:0;">
                    </div>
                </div>`,
            showCancelButton: true,
            confirmButtonColor: '#47c363',
            confirmButtonText: 'Actualizar cliente',
            cancelButtonText: 'Cancelar',
            focusConfirm: false,
            didOpen: () => {
                const input = document.getElementById('cc-buscar');
                const resultados = document.getElementById('cc-resultados');
                const info = document.getElementById('cc-info');
                const nuevo = document.getElementById('cc-nuevo');

                document.getElementById('cc-mostrar-nuevo').addEventListener('click', () => {
                    nuevo.style.display = 'block';
                    resultados.style.display = 'none';
                    info.textContent = '';
                    document.getElementById('cc-nombre').focus();
                });

                input.addEventListener('input', () => {
                    clearTimeout(debounce);
                    const texto = input.value.trim();
                    clienteSeleccionado = null;
                    info.textContent = '';

                    if (texto.length < 2) {
                        resultados.style.display = 'none';
                        resultados.innerHTML = '';
                        return;
                    }

                    debounce = setTimeout(() => {
                        const fd = new FormData();
                        fd.append('_token', CSRF_TOKEN);
                        fd.append('accion', 'search');
                        fd.append('q', texto);
                        fd.append('limit', '20');

                        fetch(URL_CLIENTES_BUSCAR, {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                            body: fd
                        })
                        .then(r => r.json())
                        .then(data => {
                            const clientes = data.exito ? (data.clientes || []) : [];
                            if (!clientes.length) {
                                resultados.innerHTML = '<div style="padding:8px; color:#9aa2b1; font-size:12px;">Sin resultados.</div>';
                                resultados.style.display = 'block';
                                return;
                            }

                            // Renderizado seguro: los datos del cliente se insertan
                            // como texto (textContent/setAttribute), nunca como HTML,
                            // para evitar XSS almacenado/reflejado en la búsqueda.
                            resultados.textContent = '';
                            clientes.forEach(c => {
                                const fila = document.createElement('div');
                                fila.dataset.id = String(c.id ?? '');
                                fila.dataset.nombre = String(c.nombre || '');
                                fila.style.cssText = 'padding:7px 10px; border-bottom:1px solid #f0f0f0; cursor:pointer; font-size:12px;';

                                const strong = document.createElement('strong');
                                strong.textContent = c.nombre || '-';
                                fila.appendChild(strong);

                                if (!c.activo) {
                                    const inactivo = document.createElement('span');
                                    inactivo.style.color = '#dc3545';
                                    inactivo.textContent = ' (inactivo)';
                                    fila.appendChild(inactivo);
                                }

                                if (c.identificacion) {
                                    fila.appendChild(document.createElement('br'));
                                    const small = document.createElement('small');
                                    small.textContent = 'Cédula: ' + c.identificacion;
                                    fila.appendChild(small);
                                }

                                if (c.telefono) {
                                    fila.appendChild(document.createElement('br'));
                                    const small = document.createElement('small');
                                    small.textContent = 'Tel: ' + c.telefono;
                                    fila.appendChild(small);
                                }

                                fila.addEventListener('click', () => {
                                    clienteSeleccionado = {
                                        id: Number(c.id),
                                        nombre: String(c.nombre || ''),
                                    };
                                    resultados.style.display = 'none';
                                    resultados.textContent = '';
                                    input.value = clienteSeleccionado.nombre;
                                    info.textContent = 'Seleccionado: ' + clienteSeleccionado.nombre;
                                    nuevo.style.display = 'none';
                                });

                                resultados.appendChild(fila);
                            });
                            resultados.style.display = 'block';
                        })
                        .catch(() => {
                            resultados.innerHTML = '<div style="padding:8px; color:#dc3545; font-size:12px;">Error al buscar clientes.</div>';
                            resultados.style.display = 'block';
                        });
                    }, 250);
                });
            },
            preConfirm: async () => {
                if (clienteSeleccionado) {
                    return clienteSeleccionado.id;
                }

                const nombre = document.getElementById('cc-nombre').value.trim();
                if (!nombre) {
                    Swal.showValidationMessage('Seleccione un cliente o registre uno nuevo.');
                    return false;
                }

                const fd = new FormData();
                fd.append('_token', CSRF_TOKEN);
                fd.append('accion', 'crear');
                fd.append('nombre', nombre);
                fd.append('identificacion', document.getElementById('cc-ident').value.trim());
                fd.append('telefono', document.getElementById('cc-tel').value.trim());

                try {
                    const respuesta = await fetch(URL_CLIENTES_BUSCAR, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                        body: fd
                    });
                    const data = await respuesta.json();
                    if (!data.exito || !data.cliente) {
                        Swal.showValidationMessage(data.error || 'No se pudo registrar el cliente.');
                        return false;
                    }
                    return data.cliente.id;
                } catch (e) {
                    Swal.showValidationMessage('Error de comunicación al registrar el cliente.');
                    return false;
                }
            }
        }).then(result => {
            if (!result.isConfirmed || !result.value) {
                return;
            }

            const fd = new FormData();
            fd.append('_token', CSRF_TOKEN);
            fd.append('cliente_id', result.value);

            fetch(URL_CAMBIAR_CLIENTE.replace('__CUENTA__', cuentaId), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: fd
            })
            .then(r => r.json())
            .then(data => {
                if (data.exito) {
                    Swal.fire('¡Actualizado!', data.mensaje, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Atención', data.error || 'No se pudo cambiar el cliente.', 'warning');
                }
            })
            .catch(() => Swal.fire('Error', 'Fallo de comunicación con el servidor.', 'error'));
        });
    }

    document.querySelectorAll('.btn-cambiar-cliente').forEach(boton => {
        boton.addEventListener('click', function () {
            abrirCambiarCliente(Number(this.dataset.cuentaId));
        });
    });

    document.querySelectorAll('.btn-guardar-cliente').forEach(boton => {
        boton.addEventListener('click', function () {
            const cuentaId = Number(this.dataset.cuentaId);
            const select = document.querySelector('.cc-select-cliente[data-cuenta-id="' + cuentaId + '"]');
            const clienteId = select ? select.value : '';
            const fd = new FormData();
            fd.append('_token', CSRF_TOKEN);
            if (clienteId !== '') {
                fd.append('cliente_id', clienteId);
            } else {
                fd.append('quitar_cliente', '1');
            }
            fetch(URL_CAMBIAR_CLIENTE.replace('__CUENTA__', cuentaId), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: fd
            })
            .then(r => r.json())
            .then(data => {
                if (data.exito) {
                    Swal.fire('Actualizado', data.mensaje, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Atención', data.error || 'No se pudo guardar el cliente.', 'warning');
                }
            })
            .catch(() => Swal.fire('Error', 'Fallo de comunicación con el servidor.', 'error'));
        });
    });

    document.querySelectorAll('.cc-buscar-cliente').forEach(input => {
        input.addEventListener('input', function () {
            const cuentaId = this.dataset.cuentaId;
            const texto = this.value.trim().toLowerCase();
            const select = document.querySelector('.cc-select-cliente[data-cuenta-id="' + cuentaId + '"]');
            if (!select) return;
            select.querySelectorAll('option').forEach(opt => {
                if (opt.value === '') { opt.hidden = false; return; }
                const nombre = opt.dataset.nombre || '';
                const ident = opt.dataset.ident || '';
                opt.hidden = texto !== '' && !nombre.includes(texto) && !ident.includes(texto);
            });
        });
    });

    document.querySelectorAll('.btn-quitar-cliente').forEach(boton => {
        boton.addEventListener('click', function () {
            const cuentaId = Number(this.dataset.cuentaId);
            const fd = new FormData();
            fd.append('_token', CSRF_TOKEN);
            fd.append('quitar_cliente', '1');
            fetch(URL_CAMBIAR_CLIENTE.replace('__CUENTA__', cuentaId), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: fd
            })
            .then(r => r.json())
            .then(data => {
                if (data.exito) {
                    Swal.fire('Actualizado', data.mensaje, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Atención', data.error || 'No se pudo quitar el cliente.', 'warning');
                }
            })
            .catch(() => Swal.fire('Error', 'Fallo de comunicación con el servidor.', 'error'));
        });
    });
</script>

{{-- Asignación/reasignación de mesero encargado desde el panel de la mesa abierta. --}}
@if($puedeEditarMesero)
<script>
    (function () {
        const btn = document.getElementById('btn-asignar-mesero');
        const sel = document.getElementById('sel-mesero-encargado');
        const lbl = document.getElementById('lbl-mesero-encargado');
        const panel = document.getElementById('panel-cuentas');
        const cuentaId = panel ? Number(panel.dataset.cuentaId) : 0;

        if (!btn || !sel || cuentaId <= 0) {
            return;
        }

        btn.addEventListener('click', async function () {
            const meseroId = Number(sel.value);

            if (meseroId <= 0) {
                lbl.textContent = 'Sin mesero asignado';
                btn.textContent = 'Asignar';
                return;
            }

            const fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            fd.append('mesero_id', String(meseroId));

            btn.disabled = true;
            btn.textContent = 'Guardando…';

            try {
                const resp = await fetch('{{ route('cuentas.mesero.asignar', ['cuentaId' => 0]) }}'.replace('/0', '/' + cuentaId), {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                });
                const data = await resp.json().catch(() => null);

                if (resp.ok && data && data.exito) {
                    lbl.textContent = sel.options[sel.selectedIndex].textContent;
                    btn.textContent = 'Guardado';
                    Swal.fire('Mesero asignado', data.mensaje || 'El encargado se actualizó.', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', (data && data.error) ? data.error : 'No se pudo asignar el mesero encargado.', 'error');
                    btn.textContent = 'Asignar';
                }
            } catch (e) {
                Swal.fire('Error', 'Fallo de comunicación con el servidor.', 'error');
                btn.textContent = 'Asignar';
            } finally {
                btn.disabled = false;
            }
        });
    })();
</script>
@endif
@endsection
