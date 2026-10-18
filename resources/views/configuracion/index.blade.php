@extends('layouts.app')

@section('content')
@php
    // Solo ADMIN llega aquí (validado en ConfiguracionController).
    $cocinaDigital = $cocinaDigital ?? false;
    $inventario = $inventario ?? false;
    $tablaDisponible = $tablaDisponible ?? false;
@endphp

<div class="monitor-card" style="max-width: 780px; margin: 20px auto;">
    <div class="monitor-header">
        <span><i class="fas fa-sliders"></i> Configuración de módulos</span>
    </div>

    <div style="padding: 25px 30px;">

        <p style="color: var(--text-muted); margin-bottom: 22px;">
            Activa o desactiva funciones del sistema. Cada interruptor es
            independiente. Desactivar un módulo <strong>no borra</strong> datos,
            historiales ni configuraciones.
        </p>

        @if (! $tablaDisponible)
            <div style="background:#fdecea;color:#c0392b;padding:12px 14px;border-radius:6px;margin-bottom:18px;font-weight:600;">
                <i class="fas fa-triangle-exclamation"></i>
                Falta aplicar la migración de configuración. Los cambios no se
                podrán guardar hasta entonces.
            </div>
        @endif

        {{-- Cocina digital --}}
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;border:1px solid #e0e4e8;border-radius:8px;padding:16px 18px;margin-bottom:14px;">
            <div>
                <div style="font-size:17px;font-weight:700;color:var(--color-dark);">
                    <i class="fas fa-fire"></i> Cocina digital
                </div>
                <div style="color:var(--text-muted);font-size:13px;margin-top:4px;">
                    Monitor de cocina con estados de preparación. El POS y las
                    comandas impresas siguen funcionando sin él.
                </div>
                <div id="estado-cocina" style="margin-top:8px;font-weight:700;font-size:13px;color:{{ $cocinaDigital ? '#1e7e46' : '#9a6700' }};">
                    Estado: {{ $cocinaDigital ? 'Habilitada' : 'Deshabilitada' }}
                </div>
            </div>
            <label class="switch" style="position:relative;display:inline-block;width:60px;height:32px;flex-shrink:0;">
                <input type="checkbox" id="chk-cocina" {{ $cocinaDigital ? 'checked' : '' }}
                       {{ $tablaDisponible ? '' : 'disabled' }}
                       style="opacity:0;width:0;height:0;">
                <span style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#ccc;border-radius:32px;transition:.2s;"></span>
            </label>
        </div>

        {{-- Inventario --}}
        <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;border:1px solid #e0e4e8;border-radius:8px;padding:16px 18px;">
            <div>
                <div style="font-size:17px;font-weight:700;color:var(--color-dark);">
                    <i class="fas fa-boxes-stacked"></i> Inventario
                </div>
                <div style="color:var(--text-muted);font-size:13px;margin-top:4px;">
                    Módulo de existencias, cargas y ajustes. Desactivarlo no borra
                    existencias ni movimientos.
                </div>
                <div id="estado-inventario" style="margin-top:8px;font-weight:700;font-size:13px;color:{{ $inventario ? '#1e7e46' : '#9a6700' }};">
                    Estado: {{ $inventario ? 'Habilitado' : 'Deshabilitado' }}
                </div>
            </div>
            <label class="switch" style="position:relative;display:inline-block;width:60px;height:32px;flex-shrink:0;">
                <input type="checkbox" id="chk-inventario" {{ $inventario ? 'checked' : '' }}
                       {{ $tablaDisponible ? '' : 'disabled' }}
                       style="opacity:0;width:0;height:0;">
                <span style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#ccc;border-radius:32px;transition:.2s;"></span>
            </label>
        </div>

    </div>
</div>

<script>
    const CONFIG_URL = "{{ route('configuracion.api') }}";
    const CONFIG_CSRF = "{{ csrf_token() }}";

    document.addEventListener('DOMContentLoaded', function () {
        const estilo = document.createElement('style');
        estilo.textContent =
            '.switch input:checked + span { background:#47c363; }' +
            '.switch span:before { content:""; position:absolute; height:26px; width:26px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.2s; }' +
            '.switch input:checked + span:before { transform: translateX(28px); }';
        document.head.appendChild(estilo);

        enlazarInterruptor('chk-cocina', 'cocina', 'estado-cocina', 'Habilitada', 'Deshabilitada');
        enlazarInterruptor('chk-inventario', 'inventario', 'estado-inventario', 'Habilitado', 'Deshabilitado');
    });

    function enlazarInterruptor(idCheckbox, modulo, idEstado, textoOn, textoOff) {
        const checkbox = document.getElementById(idCheckbox);
        if (!checkbox) return;

        checkbox.addEventListener('change', async function () {
            const deseado = this.checked;
            this.disabled = true;

            try {
                const respuesta = await fetch(CONFIG_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': CONFIG_CSRF
                    },
                    body: JSON.stringify({ modulo: modulo, habilitado: deseado })
                });

                const data = await respuesta.json();

                if (!respuesta.ok || !data.exito) {
                    this.checked = !deseado;
                    Swal.fire('Error', (data && data.error) || 'No se pudo guardar el cambio.', 'error');
                } else {
                    const etiqueta = document.getElementById(idEstado);
                    etiqueta.textContent = 'Estado: ' + (data.habilitado ? textoOn : textoOff);
                    etiqueta.style.color = data.habilitado ? '#1e7e46' : '#9a6700';
                    Swal.fire(
                        'Guardado',
                        'El módulo se ' + (data.habilitado ? 'habilitó' : 'deshabilitó') + ' correctamente.',
                        'success'
                    );
                }
            } catch (e) {
                this.checked = !deseado;
                Swal.fire('Error de red', 'No se pudo contactar al servidor.', 'error');
            } finally {
                this.disabled = false;
            }
        });
    }
</script>
@endsection
