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
            const clientesElement = document.getElementById('clientes-data');
            if (!clientesElement) return Swal.fire('Error', 'No se encontró la lista de clientes.', 'error');

            Swal.fire({
                title: 'Abrir mesa ' + numero,
                html: `
                    <div style="text-align: left; font-size: 14px;">
                        <label style="display: block; margin-bottom: 5px; font-weight: bold;">Cliente:</label>
                        <select id="apertura-cliente" class="swal2-input" style="width: 100%; height: 40px; margin: 0;">
                            ${clientesElement.innerHTML}
                        </select>
                    </div>`,
                focusConfirm: false,
                showCancelButton: true,
                confirmButtonColor: '#47c363',
                confirmButtonText: '<i class="fas fa-door-open"></i> Abrir Mesa',
                cancelButtonText: 'Cancelar',
                preConfirm: () => {
                    const cliente = document.getElementById('apertura-cliente');
                    return cliente ? cliente.value : '1';
                }
            }).then((result) => {
                if (result.isConfirmed) abrirMesa(mesaId, result.value);
            });
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

                fetch('{{ route("mesas.api") }}', { method: 'POST', body: fd })
                .then(response => response.json())
                .then(data => {
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
    function abrirMesa(mesaId, clienteId) {
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

        fetch('{{ route("mesas.api") }}', { method: 'POST', body: formData })
        // Eliminamos la barrera de response.ok para obligarlo a leer siempre el JSON del servidor
        .then(response => response.json()) 
        .then(data => {
            if (data.exito) {
                // Tras preparar la mesa, entra al POS para registrar el primer
                // producto. La mesa no se marca OCUPADA hasta que exista consumo.
                window.location.href = URL_POS_MESA + '/' + mesaId;
            } else {
                // Muestra el mensaje real devuelto por el servidor.
                Swal.fire('Atención', data.error || 'No fue posible realizar la acción.', 'warning');
            }
        })
        .catch(error => {
            Swal.fire('Error del Servidor', 'Revisa tu conexión o los logs de Laravel.', 'error');
            console.error("Error en Fetch:", error);
        });
    }
</script>
@endsection
