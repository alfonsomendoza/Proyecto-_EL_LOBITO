@extends('layouts.app')

@section('content')
@php
    // Valores de referencia para el cálculo en vivo (el servidor recalcula y
    // es la fuente de verdad; esto solo da retroalimentación al cajero).
    $montoApertura = $apertura ? (float) $apertura->monto_inicial : 0.0;
    $ventasEfectivo = $resumen['efectivo'] ?? 0.0;
@endphp

<div class="monitor-card" style="max-width: 980px; margin: 0 auto;">
    <div class="monitor-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <span><i class="fas fa-cash-register"></i> Arqueo de Caja y Cierre</span>
        @if ($apertura)
            <a href="{{ route('caja.cierre.ticket') }}" target="_blank"
               style="background:#5a6268; color:#fff; padding:6px 12px; border-radius:4px; font-weight:bold; font-size:12px; text-decoration:none;">
                <i class="fas fa-print"></i> Imprimir último cierre
            </a>
        @endif
    </div>

    <div style="padding: 25px;">

        @if (!$apertura)
            <div style="background:#fff7e6; border:1px solid #f5d9a8; border-radius:6px; padding:14px 16px; font-size:13px; color:#7a5a1a; line-height:1.5; margin-bottom:22px;">
                <i class="fas fa-exclamation-triangle"></i>
                No hay una caja abierta. Para cerrar un turno primero debes
                <a href="{{ route('caja.apertura') }}" style="color:#7a5a1a; font-weight:bold;">abrir caja</a>.
            </div>
        @else

            {{-- Datos del turno --}}
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:10px; margin-bottom:18px;">
                <div style="background:#f4f6f9; border-radius:6px; padding:10px 12px;">
                    <div style="font-size:11px; color:#6c757d; font-weight:600;">CAJERO</div>
                    <div style="font-size:14px; font-weight:bold;">{{ $apertura->cajero ?? '--' }}</div>
                </div>
                <div style="background:#f4f6f9; border-radius:6px; padding:10px 12px;">
                    <div style="font-size:11px; color:#6c757d; font-weight:600;">TURNO</div>
                    <div style="font-size:14px; font-weight:bold;">{{ $apertura->turno ?? '--' }}</div>
                </div>
                <div style="background:#f4f6f9; border-radius:6px; padding:10px 12px;">
                    <div style="font-size:11px; color:#6c757d; font-weight:600;">APERTURA</div>
                    <div style="font-size:14px; font-weight:bold;">{{ $apertura->fecha ? $apertura->fecha->format('d/m/Y H:i') : '--' }}</div>
                </div>
                <div style="background:#f4f6f9; border-radius:6px; padding:10px 12px;">
                    <div style="font-size:11px; color:#6c757d; font-weight:600;">FONDO INICIAL</div>
                    <div style="font-size:14px; font-weight:bold;">C$ {{ number_format($montoApertura, 2) }}</div>
                </div>
            </div>

            @if ($mesasPendientes > 0)
                <div style="background:#fdf2f2; border:1px solid #f5c6cb; border-radius:6px; padding:12px 14px; font-size:13px; color:#721c24; margin-bottom:18px;">
                    <i class="fas fa-triangle-exclamation"></i>
                    No se puede cerrar la caja. Hay <strong>{{ $mesasPendientes }}</strong>
                    {{ $mesasPendientes === 1 ? 'mesa ocupada' : 'mesas ocupadas' }} con consumos pendientes de cobro.
                    Cobra o resuelve las cuentas pendientes antes de intentar el cierre.
                </div>
            @endif

            {{-- Resumen de ventas del turno --}}
            <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden; margin-bottom:18px;">
                <div style="padding:10px 16px; background:#34495e; color:#fff; font-weight:600; font-size:13px;">
                    <i class="fas fa-chart-line"></i> Resumen del Turno ({{ $resumen['cantidad'] ?? 0 }} cuentas cobradas)
                </div>
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <tbody>
                        <tr style="border-bottom:1px solid #f1f1f1;">
                            <td style="padding:8px 12px;">Ventas en Efectivo (C$)</td>
                            <td style="padding:8px 12px; text-align:right; font-weight:bold;">C$ {{ number_format($resumen['efectivo'] ?? 0, 2) }}</td>
                        </tr>
                        @if (($resumen['tarjeta'] ?? 0) > 0)
                            @foreach (($resumen['tarjetas_por_banco'] ?? []) as $banco => $montoBanco)
                                <tr style="border-bottom:1px solid #f1f1f1;">
                                    <td style="padding:8px 12px; color:#555;">&nbsp;&nbsp;Tarjeta · {{ $banco }} <em>(no suma al efectivo)</em></td>
                                    <td style="padding:8px 12px; text-align:right; color:#555;">C$ {{ number_format($montoBanco, 2) }}</td>
                                </tr>
                            @endforeach
                        @endif
                        @if (($resumen['transferencia'] ?? 0) > 0)
                            <tr style="border-bottom:1px solid #f1f1f1;">
                                <td style="padding:8px 12px; color:#555;">Transferencias <em>(no suman al efectivo)</em></td>
                                <td style="padding:8px 12px; text-align:right; color:#555;">C$ {{ number_format($resumen['transferencia'], 2) }}</td>
                            </tr>
                        @endif
                        @if (($resumen['otro'] ?? 0) > 0)
                            <tr style="border-bottom:1px solid #f1f1f1;">
                                <td style="padding:8px 12px; color:#555;">Otros métodos <em>(no suman al efectivo)</em></td>
                                <td style="padding:8px 12px; text-align:right; color:#555;">C$ {{ number_format($resumen['otro'], 2) }}</td>
                            </tr>
                        @endif
                        <tr style="border-bottom:1px solid #f1f1f1; background:#f8f9fa;">
                            <td style="padding:8px 12px; font-weight:600;">Egresos / Gastos activos del turno</td>
                            <td style="padding:8px 12px; text-align:right; font-weight:bold; color:#c0392b;">- C$ {{ number_format($totalEgresos, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
                @if ($gastos->isNotEmpty())
                    <div style="padding:8px 12px; border-top:1px solid #f1f1f1;">
                        <a href="{{ route('gastos.index') }}" style="font-size:12px; color:#34495e;">Ver detalle de gastos del turno &rarr;</a>
                    </div>
                @endif
            </div>

            {{-- Formulario de cierre --}}
            <form id="formCierre">
                @csrf

                <h4 style="color:var(--color-primary, #34495e); border-bottom:1px solid #eee; padding-bottom:8px; margin-bottom:14px; font-size:14px;">Moneda Extranjera (USD)</h4>
                <div style="display:flex; gap:10px; align-items:center; margin-bottom:12px;">
                    <label style="font-weight:bold; font-size:13px; width:130px;">Tasa de Cambio:</label>
                    <input type="number" id="tasa_cambio" name="tasa_cambio" value="36.50" step="0.0001" min="0.0001" max="999999.9999"
                           class="calc-cierre" style="flex:1; padding:8px; border:1px solid #ddd; border-radius:4px; text-align:right;">
                </div>
                @foreach (\App\Services\CajaCierreService::DENOMINACIONES_DOLAR as $den)
                    <div style="display:flex; justify-content:space-between; margin-bottom:8px; align-items:center;">
                        <label style="width:110px; font-size:13px;">$ {{ number_format($den, 2) }}:</label>
                        <input type="number" data-den="{{ $den }}" data-moneda="dolar" name="conteo_dolar[{{ $den }}]" value="0"
                               class="calc-cierre" min="0" style="flex:1; padding:8px; border:1px solid #ddd; border-radius:4px;">
                    </div>
                @endforeach

                <h4 style="color:var(--color-primary, #34495e); border-bottom:1px solid #eee; padding-bottom:8px; margin:25px 0 14px; font-size:14px;">Moneda Local (C$)</h4>
                @foreach (\App\Services\CajaCierreService::DENOMINACIONES_LOCAL as $den)
                    @php $formato = $den < 1 ? '0.50' : number_format($den, 2); @endphp
                    <div style="display:flex; justify-content:space-between; margin-bottom:8px; align-items:center;">
                        <label style="width:110px; font-size:13px;">C$ {{ $formato }}:</label>
                        <input type="number" data-den="{{ $den }}" data-moneda="local" name="conteo_local[{{ $den }}]" value="0"
                               class="calc-cierre" min="0" style="flex:1; padding:8px; border:1px solid #ddd; border-radius:4px;">
                    </div>
                @endforeach

                <h4 style="color:var(--color-primary, #34495e); border-bottom:1px solid #eee; padding-bottom:8px; margin:25px 0 14px; font-size:14px;">Ajustes del Turno</h4>
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
                    <div style="flex:1; min-width:180px;">
                        <label style="display:block; margin-bottom:5px; font-weight:bold; font-size:13px;">Otros ingresos (C$):</label>
                        <input type="number" id="otros_ingresos" name="otros_ingresos" value="0" step="0.01" min="0" max="9999999999.99" class="calc-cierre"
                               style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;">
                    </div>
                    <div style="flex:1; min-width:180px;">
                        <label style="display:block; margin-bottom:5px; font-weight:bold; font-size:13px;">Retiros de efectivo (C$):</label>
                        <input type="number" id="retiros" name="retiros" value="0" step="0.01" min="0" max="9999999999.99" class="calc-cierre"
                               style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;">
                    </div>
                </div>
                <div style="margin-bottom:18px;">
                    <label style="display:block; margin-bottom:5px; font-weight:bold; font-size:13px;">Observaciones (opcional):</label>
                    <textarea name="observaciones" rows="2" maxlength="1000"
                              style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;"></textarea>
                </div>

                {{-- Panel de cuadre en vivo --}}
                <div style="background:#f8f9fa; border-left:4px solid #34495e; padding:14px 16px; border-radius:4px; font-size:14px;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                        <span>Total físico contado:</span>
                        <strong>C$ <span id="lbl_contado">0.00</span></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                        <span>Total esperado:</span>
                        <strong>C$ <span id="lbl_esperado">0.00</span></strong>
                    </div>
                    <div style="display:flex; justify-content:space-between;">
                        <span>Diferencia:</span>
                        <strong id="lbl_diferencia">C$ 0.00</strong>
                    </div>
                    <div id="lbl_estado" style="text-align:center; margin-top:8px; font-weight:bold;"></div>
                </div>

                <button type="submit" @if ($mesasPendientes > 0) disabled title="Cobra o resuelve las cuentas pendientes antes de cerrar la caja." @endif
                        style="width:100%; margin-top:20px; background-color:#28a745; color:#fff; border:none; padding:14px; border-radius:4px; cursor:{{ $mesasPendientes > 0 ? 'not-allowed' : 'pointer' }}; font-weight:bold; font-size:15px; opacity:{{ $mesasPendientes > 0 ? '0.55' : '1' }};">
                    <i class="fas fa-lock"></i> Realizar Cierre de Caja
                </button>
            </form>
        @endif

        {{-- Historial de cierres --}}
        <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden; margin-top:26px;">
            <div style="padding:10px 16px; background:#2c3e50; color:#fff; font-weight:600; font-size:13px;">
                <i class="fas fa-clock-rotate-left"></i> Historial de Cierres
            </div>
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#f4f6f9; color:#495057; text-align:left;">
                        <th style="padding:9px 12px;">#</th>
                        <th style="padding:9px 12px;">Fecha</th>
                        <th style="padding:9px 12px;">Turno</th>
                        <th style="padding:9px 12px; text-align:right;">Esperado</th>
                        <th style="padding:9px 12px; text-align:right;">Contado</th>
                        <th style="padding:9px 12px; text-align:right;">Diferencia</th>
                        <th style="padding:9px 12px; text-align:center;">Estado</th>
                        <th style="padding:9px 12px; text-align:center;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($cierres as $c)
                        <tr style="border-bottom:1px solid #f1f1f1;">
                            <td style="padding:9px 12px;">{{ $c->id }}</td>
                            <td style="padding:9px 12px;">{{ $c->fecha ? $c->fecha->format('d/m/Y H:i') : '--' }}</td>
                            <td style="padding:9px 12px;">{{ $c->turno ?? '--' }}</td>
                            <td style="padding:9px 12px; text-align:right;">C$ {{ number_format((float) $c->total_esperado, 2) }}</td>
                            <td style="padding:9px 12px; text-align:right;">C$ {{ number_format((float) $c->total_contado, 2) }}</td>
                            <td style="padding:9px 12px; text-align:right; color:{{ (float) $c->diferencia < 0 ? '#c0392b' : '#155724' }};">
                                C$ {{ number_format((float) $c->diferencia, 2) }}
                            </td>
                            <td style="padding:9px 12px; text-align:center;">
                                @if ($c->estado === 'ANULADO')
                                    <span style="background:#e2e3e5; color:#383d41; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">ANULADO</span>
                                @elseif ($c->estado === 'CUADRA')
                                    <span style="background:#d4edda; color:#155724; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">CUADRA</span>
                                @else
                                    <span style="background:#f8d7da; color:#721c24; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">DESCUADRE</span>
                                @endif
                            </td>
                            <td style="padding:9px 12px; text-align:center; white-space:nowrap;">
                                <a href="{{ route('caja.cierre.ticket', ['cierre_id' => $c->id]) }}" target="_blank"
                                   style="display:inline-block; color:#5a6268; border:1px solid #5a6268; padding:4px 10px; border-radius:4px; font-size:12px; text-decoration:none;">
                                    <i class="fas fa-print"></i>
                                </a>
                                @if ($puedeAnular && $c->estado !== 'ANULADO')
                                    <button type="button" class="btn-anular-cierre" data-id="{{ $c->id }}"
                                            style="background:transparent; color:#fc544b; border:1px solid #fc544b; padding:4px 10px; border-radius:4px; cursor:pointer; font-size:12px;">
                                        <i class="fas fa-ban"></i>
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="text-align:center; padding:18px; color:#888;">Todavía no hay cierres registrados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

@if ($apertura)
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('formCierre');
    if (!form) return;

    const inputs = document.querySelectorAll('.calc-cierre');
    const montoApertura = {{ $montoApertura }};
    const ventasEfectivo = {{ $ventasEfectivo }};
    const totalEgresos = {{ $totalEgresos }};

    const num = (el, decimales = 2) => {
        if (!el) return 0;
        const valor = numeroEstricto(el.value === '' ? '0' : el.value, decimales);
        return valor === null ? 0 : valor;
    };

    function cantidadConteo(input) {
        return numeroEstricto(input.value === '' ? '0' : input.value, 0) ?? 0;
    }

    function calcular() {
        let contado = 0;
        inputs.forEach(input => {
            const cantidad = cantidadConteo(input);
            if (input.getAttribute('data-moneda') === 'local') {
                contado += cantidad * Number(input.getAttribute('data-den'));
            }
        });

        // Dólares convertidos a córdobas con la tasa indicada.
        const tasa = num(document.getElementById('tasa_cambio'), 4);
        let dolares = 0;
        inputs.forEach(input => {
            if (input.getAttribute('data-moneda') === 'dolar') {
                const cantidad = cantidadConteo(input);
                dolares += cantidad * Number(input.getAttribute('data-den'));
            }
        });
        contado += dolares * tasa;

        const otros = num(document.getElementById('otros_ingresos'));
        const retiros = num(document.getElementById('retiros'));
        const esperado = montoApertura + ventasEfectivo + otros - totalEgresos - retiros;
        const diferencia = contado - esperado;

        document.getElementById('lbl_contado').textContent = contado.toFixed(2);
        document.getElementById('lbl_esperado').textContent = esperado.toFixed(2);

        const lblDif = document.getElementById('lbl_diferencia');
        lblDif.textContent = 'C$ ' + diferencia.toFixed(2);

        const lblEstado = document.getElementById('lbl_estado');
        if (diferencia >= 0) {
            lblDif.style.color = '#155724';
            lblEstado.textContent = 'CUADRA';
            lblEstado.style.color = '#155724';
        } else {
            lblDif.style.color = '#c0392b';
            lblEstado.textContent = 'DESCUADRE (falta efectivo)';
            lblEstado.style.color = '#c0392b';
        }
    }

    inputs.forEach(input => input.addEventListener('input', calcular));
    calcular();

    function validarNumeros() {
        for (const input of inputs) {
            if (input.getAttribute('data-moneda') && numeroEstricto(input.value === '' ? '0' : input.value, 0) === null) {
                return 'Las cantidades del conteo deben ser números enteros, sin letras ni notación científica.';
            }
        }

        const tasa = document.getElementById('tasa_cambio');
        if (tasa && (numeroMaximo(tasa.value, 4, 999999.9999) === null || Number(tasa.value) <= 0)) {
            return 'La tasa de cambio debe ser un número mayor que cero, con hasta 4 decimales y como máximo 999,999.9999.';
        }

        for (const id of ['otros_ingresos', 'retiros']) {
            const campo = document.getElementById(id);
            if (campo && numeroMaximo(campo.value === '' ? '0' : campo.value, 2, 9999999999.99) === null) {
                return 'Los otros ingresos y los retiros deben ser números, con hasta 2 decimales y un máximo de C$ 9,999,999,999.99.';
            }
        }

        return null;
    }

    form.addEventListener('submit', function (evento) {
        evento.preventDefault();

        const errorNumerico = validarNumeros();
        if (errorNumerico) {
            Swal.fire('Atención', errorNumerico, 'warning');
            return;
        }

        Swal.fire({ title: 'Cerrando caja...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

        fetch('{{ route("caja.cierre.registrar") }}', {
            method: 'POST',
            body: new FormData(form),
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(r => r.json())
            .then(d => {
                if (d.exito) {
                    Swal.fire('Cierre registrado', 'Estado: ' + d.estado, 'success').then(() => {
                        window.open('{{ route("caja.cierre.ticket") }}?cierre_id=' + d.cierre_id, '_blank');
                        window.location.reload();
                    });
                } else {
                    Swal.fire('Error', d.error || 'No se pudo cerrar la caja.', 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'Error de conexión con el servidor.', 'error'));
    });
});
</script>
@endif

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.btn-anular-cierre').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = btn.getAttribute('data-id');

            Swal.fire({
                title: '¿Anular este cierre?',
                text: 'El cierre quedará ANULADO y el turno volverá a quedar abierto.',
                icon: 'warning',
                input: 'textarea',
                inputLabel: 'Motivo de la anulación (obligatorio)',
                inputPlaceholder: 'Explica por qué se anula este cierre...',
                inputAttributes: { maxlength: 500 },
                inputValidator: function (valor) {
                    if (!valor || valor.trim() === '') {
                        return 'Debes escribir el motivo de la anulación.';
                    }
                    return null;
                },
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Sí, anular cierre',
                cancelButtonText: 'Cancelar'
            }).then(function (res) {
                if (!res.isConfirmed) return;

                const fd = new FormData();
                fd.append('_token', '{{ csrf_token() }}');
                // El backend exige `motivo` (CajaCierreController::anular); sin
                // él responde 422 y no anula nada.
                fd.append('motivo', (res.value || '').trim());

                fetch('{{ route("caja.cierre.anular", "ID") }}'.replace('ID', id), {
                    method: 'POST',
                    body: fd,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(r => r.json())
                    .then(d => {
                        if (d.exito) {
                            Swal.fire('Anulado', d.mensaje || 'Cierre anulado.', 'success').then(() => location.reload());
                        } else {
                            Swal.fire('Error', d.error || 'No se pudo anular el cierre.', 'error');
                        }
                    })
                    .catch(() => Swal.fire('Error', 'Error de conexión con el servidor.', 'error'));
            });
        });
    });
});
</script>
@endsection
