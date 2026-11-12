@extends('layouts.app')

@section('content')
@php
    $tablaDisponible = $tablaDisponible ?? false;
    $tasas = $tasas ?? [];
@endphp

<div class="monitor-card" style="max-width: 820px; margin: 20px auto;">
    <div class="monitor-header">
        <span><i class="fas fa-file-invoice-dollar"></i> Configuración fiscal y comercial</span>
    </div>

    <div style="padding: 25px 30px;">
        <p style="color: var(--text-muted); margin-bottom: 22px;">
            Activa o desactiva los conceptos fiscales y comerciales. Cada interruptor
            es independiente. Los cambios no afectan facturas históricas.
        </p>

        @if (! $tablaDisponible)
            <div style="background:#fdecea;color:#c0392b;padding:12px 14px;border-radius:6px;margin-bottom:18px;font-weight:600;">
                Falta aplicar la migración de configuración. Los cambios no se podrán guardar.
            </div>
        @endif

        @php
            $conceptos = [
                ['key'=>'descuento','nombre'=>'Descuento','desc'=>'Descuento por importe o porcentaje.','tasa'=>null,'estado'=>$descuento],
                ['key'=>'propina','nombre'=>'Propina','desc'=>'Propina por importe o porcentaje. Tasa sugerida 10 %.','tasa'=>$tasas['propina'] ?? 0.10,'estado'=>$propina],
                ['key'=>'iva','nombre'=>'IVA','desc'=>'Impuesto al valor agregado.','tasa'=>$tasas['iva'] ?? 0.15,'estado'=>$iva],
                ['key'=>'retencion_dgi','nombre'=>'Retención DGI / Renta','desc'=>'Retención de renta.','tasa'=>$tasas['retencion_dgi'] ?? 0.02,'estado'=>$retencionDgi],
                ['key'=>'retencion_alcaldia','nombre'=>'Retención Alcaldía','desc'=>'Retención municipal.','tasa'=>$tasas['retencion_alcaldia'] ?? 0.01,'estado'=>$retencionAlcaldia],
            ];
        @endphp

        @foreach ($conceptos as $concepto)
            <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;border:1px solid #e0e4e8;border-radius:8px;padding:16px 18px;margin-bottom:14px;">
                <div>
                    <div style="font-size:17px;font-weight:700;color:var(--color-dark);">
                        {{ $concepto['nombre'] }}
                        @if ($concepto['tasa'] !== null)
                            <span style="font-size:13px;color:var(--text-muted);font-weight:400;">
                                ({{ number_format($concepto['tasa'] * 100, 0) }} %)
                            </span>
                        @endif
                    </div>
                    <div style="color:var(--text-muted);font-size:13px;margin-top:4px;">
                        {{ $concepto['desc'] }}
                    </div>
                    <div id="estado-{{ $concepto['key'] }}" style="margin-top:8px;font-weight:700;font-size:13px;color:{{ $concepto['estado'] ? '#1e7e46' : '#9a6700' }};">
                        Estado: {{ $concepto['estado'] ? 'Activado' : 'Desactivado' }}
                    </div>
                </div>
                <label class="switch-fiscal" style="position:relative;display:inline-block;width:60px;height:32px;flex-shrink:0;">
                    <input type="checkbox" class="chk-fiscal" data-concepto="{{ $concepto['key'] }}"
                           {{ $concepto['estado'] ? 'checked' : '' }}
                           {{ $tablaDisponible ? '' : 'disabled' }}
                           style="opacity:0;width:0;height:0;">
                    <span style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#ccc;border-radius:32px;transition:.2s;"></span>
                </label>
            </div>
        @endforeach

    </div>
</div>

<style>
    .switch-fiscal input:checked + span { background: #47c363; }
    .switch-fiscal input:checked + span::before { transform: translateX(28px); }
    .switch-fiscal span { position: relative; display: block; width: 60px; height: 32px; background: #ccc; border-radius: 32px; transition: .2s; cursor: pointer; }
    .switch-fiscal span::before { content: ""; position: absolute; width: 24px; height: 24px; left: 4px; top: 4px; background: #fff; border-radius: 50%; transition: .2s; }
</style>

<script>
    const FISCAL_URL = "{{ route('configuracion.fiscal.api') }}";
    const CSRF = "{{ csrf_token() }}";

    document.querySelectorAll('.chk-fiscal').forEach(chk => {
        chk.addEventListener('change', function () {
            const concepto = this.dataset.concepto;
            const habilitado = this.checked;
            const fd = new FormData();
            fd.append('_token', CSRF);
            fd.append('concepto', concepto);
            fd.append('habilitado', habilitado ? '1' : '0');

            fetch(FISCAL_URL, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: fd
            })
            .then(r => r.json())
            .then(data => {
                if (data.exito) {
                    const estado = document.getElementById('estado-' + concepto);
                    if (estado) {
                        estado.textContent = 'Estado: ' + (habilitado ? 'Activado' : 'Desactivado');
                        estado.style.color = habilitado ? '#1e7e46' : '#9a6700';
                    }
                } else {
                    alert(data.error || 'No se pudo guardar.');
                    this.checked = !habilitado;
                    const estado = document.getElementById('estado-' + concepto);
                    if (estado) {
                        estado.textContent = 'Estado: ' + (this.checked ? 'Activado' : 'Desactivado');
                        estado.style.color = this.checked ? '#1e7e46' : '#9a6700';
                    }
                }
            })
            .catch(() => {
                alert('Fallo de comunicación.');
                this.checked = !habilitado;
                const estado = document.getElementById('estado-' + concepto);
                if (estado) {
                    estado.textContent = 'Estado: ' + (this.checked ? 'Activado' : 'Desactivado');
                    estado.style.color = this.checked ? '#1e7e46' : '#9a6700';
                }
            });
        });
    });
</script>
@endsection
