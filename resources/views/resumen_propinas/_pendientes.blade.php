{{--
    Apartado «Pendientes de atribución» de propinas.

    Variables esperadas:
      $pendientes        array<int, array> líneas de propina PENDIENTE (mismas
                         fuentes que el reporte: cuentas PAGADAS + factura EMITIDA
                         + sin responsable ni único mesero en comandas). Cada línea
                         trae: cuenta_id, numero_factura, fecha, mesa_nombre,
                         cuenta_nombre, propina, motivo_pendiente, meseros_atendieron,
                         apertura_id, turno, cierre_previo (bool).
      $meserosAtribucion Collection de usuarios activos con rol MESERO (id, nombre).
      $rolPanel          string  rol actual en mayúsculas.
      $puedeCorregir     bool    ADMIN/GERENTE (para correcciones post-cierre).

    NO calcula importes ni atribuye nada por sí mismo: solo presenta y envía
    al backend (POST cuentas.propina.atribuir), que valida TODO.
--}}
@php
    $pendientes = $pendientes ?? [];
    $meserosAtribucion = $meserosAtribucion ?? collect();
    $rolPanel = strtoupper((string) ($rolPanel ?? ''));
    $puedeCorregir = (bool) ($puedeCorregir ?? false);
    $puedeAtribuir = in_array($rolPanel, ['ADMIN', 'GERENTE', 'CAJERO'], true);
    $urlAtribuirBase = route('cuentas.propina.atribuir', ['cuentaId' => 0]);
@endphp

<div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden; margin-bottom:22px;">
    <div style="padding:12px 16px; background:#b8860b; color:#fff; font-weight:600; font-size:14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
        <span><i class="fas fa-hand-holding-dollar"></i> Pendientes de atribución</span>
        <span style="font-size:12px; font-weight:400; background:rgba(255,255,255,.25); border-radius:999px; padding:3px 10px;">
            {{ count($pendientes) }} pendiente(s)
        </span>
    </div>

    <div style="padding:14px 16px; background:#fffdf5; border-bottom:1px solid #f0e4c3; font-size:12px; color:#6b5a1a; line-height:1.6;">
        La propina ya está <strong>cobrada</strong>: aquí NO cambia su importe, ni la factura, ni los pagos.
        Solo se indica <strong>quién atendió</strong> la cuenta. La atribución es manual: nunca se atribuye
        automáticamente (ni al cajero, ni a quien abrió la mesa, ni a quien emitió la factura).
        El motivo es <strong>obligatorio</strong> y queda auditoría con el usuario, la fecha y la atribución anterior/nueva.
        Si la caja de la cuenta ya está <strong>cerrada</strong>, solo ADMIN o GERENTE pueden corregirla.
    </div>

    @if (! $puedeAtribuir)
        <div style="padding:14px 16px; background:#fdf0ef; color:#a8232d; font-size:13px;">
            <i class="fas fa-ban"></i> No tienes permiso para atribuir propinas.
        </div>
    @elseif (count($pendientes) === 0)
        <div style="padding:30px 16px; text-align:center; color:#6c757d; font-size:13px;">
            <i class="fas fa-check-circle" style="font-size:28px; display:block; margin-bottom:10px; color:#1e7e5c;"></i>
            No hay propinas pendientes de atribución. Todas las propinas cobradas tienen mesero identificado.
        </div>
    @else
        <div style="overflow-x:auto;">
        <table style="width:100%; border-collapse:collapse; font-size:13px; min-width:980px;">
            <thead>
                <tr style="background:#fdf6e0; color:#6b5a1a; text-align:left;">
                    <th style="padding:10px 12px;">Factura / Fecha</th>
                    <th style="padding:10px 12px;">Cuenta</th>
                    <th style="padding:10px 12px;">Mesa</th>
                    <th style="padding:10px 12px;">Propinas atendidas por</th>
                    <th style="padding:10px 12px; text-align:right;">Propina</th>
                    <th style="padding:10px 12px;">Atribuir a</th>
                    <th style="padding:10px 12px;">Motivo (obligatorio)</th>
                    <th style="padding:10px 12px;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($pendientes as $p)
                    @php
                        $cierre = (bool) ($p['cierre_previo'] ?? false);
                        $bloqueadoCajero = $cierre && ! $puedeCorregir;
                        $atendidos = is_array($p['meseros_atendieron'] ?? null) ? $p['meseros_atendieron'] : [];
                        $atendidosTexto = $atendidos !== [] ? implode(', ', $atendidos) : null;
                        $motivoPendiente = ($p['motivo_pendiente'] ?? '') === 'varios_meseros' ? 'varios meseros en la venta' : 'sin mesero inequívoco';
                    @endphp
                    <tr style="border-bottom:1px solid #f1f1f1; {{ $cierre ? 'background:#fffaf0;' : '' }}"
                        data-cuenta-id="{{ (int) $p['cuenta_id'] }}">
                        <td style="padding:10px 12px; white-space:nowrap;">
                            <div style="font-weight:bold;">{{ $p['numero_factura'] ?? '—' }}</div>
                            <div style="color:#6c757d; font-size:11px;">{{ $p['fecha'] ? \Illuminate\Support\Carbon::parse($p['fecha'])->format('d/m/Y H:i') : '—' }}</div>
                            @if ($cierre)
                                <span style="display:inline-block; margin-top:4px; background:#f5e6c8; color:#7a5a1a; border-radius:4px; padding:1px 6px; font-size:10px; font-weight:700; text-transform:uppercase;">Caja cerrada</span>
                            @endif
                        </td>
                        <td style="padding:10px 12px;">
                            {{ $p['cuenta_nombre'] }}
                            <span style="color:#8a94a6; font-size:11px;">#{{ (int) $p['cuenta_id'] }}</span>
                            @if (! empty($p['turno']))
                                <div style="color:#8a94a6; font-size:11px;">{{ $p['turno'] }}</div>
                            @endif
                        </td>
                        <td style="padding:10px 12px;">{{ $p['mesa_nombre'] ?? $p['mesa_numero'] ?? '—' }}</td>
                        <td style="padding:10px 12px; font-size:12px; color:#555;">
                            @if ($atendidosTexto)
                                {{ $atendidosTexto }}
                            @else
                                Sin mesero en comandas ({{ $motivoPendiente }})
                            @endif
                        </td>
                        <td style="padding:10px 12px; text-align:right; font-weight:bold; color:#b8860b;">C$ {{ number_format((float) $p['propina'], 2) }}</td>
                        <td style="padding:10px 12px;">
                            <select class="pend-mesero" style="padding:6px 8px; border:1px solid #ced4da; border-radius:5px; min-width:150px;"
                                    @if ($bloqueadoCajero) disabled @endif>
                                <option value="">Seleccionar mesero…</option>
                                @foreach ($meserosAtribucion as $m)
                                    <option value="{{ (int) $m->id }}">{{ $m->nombre }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td style="padding:10px 12px;">
                            <input type="text" class="pend-motivo" maxlength="500"
                                   placeholder="Ej.: atendió la mesa 12 durante todo el turno"
                                   style="width:100%; min-width:180px; box-sizing:border-box; padding:6px 8px; border:1px solid #ced4da; border-radius:5px;"
                                   @if ($bloqueadoCajero) disabled @endif>
                        </td>
                        <td style="padding:10px 12px; white-space:nowrap;">
                            @if ($bloqueadoCajero)
                                <span style="font-size:11px; color:#a8232d; display:inline-block; max-width:140px; line-height:1.4;">
                                    <i class="fas fa-lock"></i> Post-cierre: requiere ADMIN/GERENTE
                                </span>
                            @else
                                <button type="button" class="btn-atribuir-propina"
                                        data-cuenta-id="{{ (int) $p['cuenta_id'] }}"
                                        data-url="{{ $urlAtribuirBase }}"
                                        style="background:#16a085; color:#fff; border:none; padding:8px 12px; border-radius:5px; font-weight:bold; font-size:12px; cursor:pointer; min-height:36px;">
                                    <i class="fas fa-check"></i> Atribuir
                                </button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        <div style="padding:8px 14px; font-size:11px; color:#8a94a6; border-top:1px solid #f1f1f1;">
            Atribuir mueve el importe de «pendientes» al mesero elegido (reporte individual y Operaciones
            se actualizan solos). Factura, consumo, pagos e importe de propina no cambian; todo queda auditado.
        </div>
    @endif
</div>

@if ($puedeAtribuir && count($pendientes) > 0)
<script>
    (function () {
        // Delegación única (evita duplicar listeners por fila).
        document.querySelectorAll('.btn-atribuir-propina').forEach(function (boton) {
            boton.addEventListener('click', function () {
                var fila = boton.closest('tr[data-cuenta-id]');
                if (!fila) { return; }

                var cuentaId = boton.getAttribute('data-cuenta-id');
                var urlBase = boton.getAttribute('data-url');
                var sel = fila.querySelector('.pend-mesero');
                var motivo = fila.querySelector('.pend-motivo');

                var meseroId = sel ? Number(sel.value) : 0;
                var motivoTexto = motivo ? String(motivo.value || '').trim() : '';

                if (!meseroId || meseroId <= 0) {
                    Swal.fire('Falta el mesero', 'Selecciona el mesero que atendió la cuenta.', 'warning');
                    return;
                }
                if (motivoTexto.length < 3) {
                    Swal.fire('Falta el motivo', 'Indica el motivo de la atribución (mínimo 3 caracteres).', 'warning');
                    return;
                }

                boton.disabled = true;
                boton.innerHTML = 'Guardando…';

                var fd = new FormData();
                fd.append('_token', '{{ csrf_token() }}');
                fd.append('mesero_id', String(meseroId));
                fd.append('motivo', motivoTexto);

                fetch(urlBase.replace('/0', '/' + cuentaId), {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                })
                    .then(function (resp) { return resp.json().catch(function () { return null; }).then(function (data) { return { ok: resp.ok, data: data }; }); })
                    .then(function (r) {
                        if (r.ok && r.data && r.data.exito) {
                            Swal.fire({
                                title: r.data.correccion_postcierre ? 'Corrección registrada' : 'Propina atribuida',
                                text: r.data.mensaje || 'La propina quedó atribuida al mesero.',
                                icon: 'success',
                                timer: 1600,
                                showConfirmButton: false,
                            }).then(function () { window.location.reload(); });
                            return;
                        }
                        Swal.fire('No se pudo atribuir', (r.data && r.data.error) ? r.data.error : 'Revisa los datos e inténtalo de nuevo.', 'error');
                        boton.disabled = false;
                        boton.innerHTML = '<i class="fas fa-check"></i> Atribuir';
                    })
                    .catch(function () {
                        Swal.fire('Error', 'Fallo de comunicación con el servidor.', 'error');
                        boton.disabled = false;
                        boton.innerHTML = '<i class="fas fa-check"></i> Atribuir';
                    });
            });
        });
    })();
</script>
@endif
