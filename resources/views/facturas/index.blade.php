@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 1000px; margin: 0 auto;">
    <div class="monitor-header">
        <span><i class="fas fa-file-invoice"></i> Historial de Facturas</span>
    </div>

    <div style="padding: 25px;">

        {{-- Filtros del historial. Son de solo lectura. --}}
        <form method="GET" action="{{ route('facturas.index') }}"
              style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; border:1px solid #e3e6f0; border-radius:8px; padding:14px 16px; margin-bottom:22px; background:#fbfbfd;">
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="numero" style="font-size:0.85rem; font-weight:bold; color:#555;">N° Factura:</label>
                <input type="text" id="numero" name="numero" value="{{ $filtros['numero'] }}" placeholder="FAC-0001"
                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="cliente" style="font-size:0.85rem; font-weight:bold; color:#555;">Cliente:</label>
                <input type="text" id="cliente" name="cliente" value="{{ $filtros['cliente'] }}" placeholder="Nombre o identificación"
                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="mesa" style="font-size:0.85rem; font-weight:bold; color:#555;">Mesa:</label>
                <input type="text" id="mesa" name="mesa" value="{{ $filtros['mesa'] }}" placeholder="Mesa 1"
                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="estado" style="font-size:0.85rem; font-weight:bold; color:#555;">Estado:</label>
                <select id="estado" name="estado" style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
                    <option value="">Todos</option>
                    <option value="EMITIDA" {{ $filtros['estado'] === 'EMITIDA' ? 'selected' : '' }}>Emitidas</option>
                    <option value="ANULADA" {{ $filtros['estado'] === 'ANULADA' ? 'selected' : '' }}>Anuladas</option>
                </select>
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="cajero" style="font-size:0.85rem; font-weight:bold; color:#555;">Cajero:</label>
                <select id="cajero" name="cajero" style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
                    <option value="">Todos los cajeros</option>
                    @foreach ($cajeros as $cajero)
                        <option value="{{ $cajero->id }}" {{ (string) $filtros['cajero'] === (string) $cajero->id ? 'selected' : '' }}>
                            {{ $cajero->nombre }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="desde" style="font-size:0.85rem; font-weight:bold; color:#555;">Desde:</label>
                <input type="date" id="desde" name="desde" value="{{ $filtros['desde'] }}"
                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="hasta" style="font-size:0.85rem; font-weight:bold; color:#555;">Hasta:</label>
                <input type="date" id="hasta" name="hasta" value="{{ $filtros['hasta'] }}"
                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <button type="submit"
                    style="background-color:#6777ef; color:#fff; border:none; padding:9px 18px; border-radius:5px; font-weight:bold; cursor:pointer;">
                <i class="fas fa-search"></i> Buscar
            </button>
            <a href="{{ route('facturas.index') }}"
               style="background-color:#adb5bd; color:#fff; padding:9px 18px; border-radius:5px; font-weight:bold; text-decoration:none;">
                Limpiar
            </a>
        </form>

        <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#f4f6f9; color:#495057; text-align:left;">
                        <th style="padding:10px 12px;">N° Factura</th>
                        <th style="padding:10px 12px;">Fecha</th>
                        <th style="padding:10px 12px;">Mesa</th>
                        <th style="padding:10px 12px;">Cuenta</th>
                        <th style="padding:10px 12px;">Cliente</th>
                        <th style="padding:10px 12px;">Cajero</th>
                        <th style="padding:10px 12px;">Método</th>
                        <th style="padding:10px 12px; text-align:right;">Total</th>
                        <th style="padding:10px 12px; text-align:center;">Estado</th>
                        <th style="padding:10px 12px; text-align:center;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($facturas as $factura)
                        <tr style="border-bottom:1px solid #f1f1f1;">
                            <td style="padding:10px 12px;"><strong>{{ $factura->numero_factura }}</strong></td>
                            <td style="padding:10px 12px;">{{ $factura->fecha_emision ? \Illuminate\Support\Carbon::parse($factura->fecha_emision)->format('d/m/Y H:i') : '--' }}</td>
                            <td style="padding:10px 12px;">{{ $factura->mesa }}</td>
                            <td style="padding:10px 12px;">
                                @if (!empty($factura->cuenta_id))
                                    {{ $factura->cuenta_nombre ?? 'Cuenta' }} <small style="color:#6c757d;">(#{{ $factura->cuenta_id }})</small>
                                @else
                                    <span style="color:#aaa;">--</span>
                                @endif
                            </td>
                            <td style="padding:10px 12px;">{{ $factura->cliente ?? '--' }}</td>
                            <td style="padding:10px 12px;">{{ $factura->cajero ?? '--' }}</td>
                            <td style="padding:10px 12px;">
                                {{ $factura->metodo_pago ?? '--' }}
                                @if (!empty($factura->banco))<small style="color:#6c757d;"> · {{ $factura->banco }}</small>@endif
                            </td>
                            <td style="padding:10px 12px; text-align:right; font-weight:bold;">C$ {{ number_format((float) $factura->total, 2) }}</td>
                            <td style="padding:10px 12px; text-align:center;">
                                @if ($factura->estado === 'ANULADA')
                                    <span style="background:#f8d7da; color:#721c24; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">ANULADA</span>
                                    @php $anulacion = $anulaciones[$factura->id] ?? null; @endphp
                                    @if ($anulacion)
                                        <div style="font-size:10px;color:#721c24;margin-top:4px;max-width:150px;white-space:normal;line-height:1.3;"
                                             title="{{ $anulacion->motivo }}">
                                            {{ \Illuminate\Support\Str::limit($anulacion->motivo, 45) }}
                                            <br>
                                            {{ $anulacion->usuario_nombre ?? '—' }}
                                            @if ($anulacion->fecha_anulacion)
                                                · {{ \Illuminate\Support\Carbon::parse($anulacion->fecha_anulacion)->format('d/m/Y H:i') }}
                                            @endif
                                        </div>
                                    @endif
                                @else
                                    <span style="background:#d4edda; color:#155724; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">EMITIDA</span>
                                @endif
                            </td>
                            <td style="padding:10px 12px; text-align:center; white-space:nowrap;">
                                <a href="{{ route('facturas.ticket', ['id' => $factura->id]) }}" target="_blank"
                                   style="display:inline-block; background:transparent; color:#5a6268; border:1px solid #5a6268; padding:4px 10px; border-radius:4px; font-size:12px; text-decoration:none;"
                                   title="Imprimir esta factura">
                                    <i class="fas fa-print"></i> Imprimir
                                </a>
                                @if ($factura->estado === 'EMITIDA' && $factura->fecha_emision && \Illuminate\Support\Carbon::parse($factura->fecha_emision)->isToday())
                                    <button type="button" class="btn-anular-factura"
                                            data-factura-id="{{ $factura->id }}"
                                            style="display:inline-block; background:transparent; color:#c0392b; border:1px solid #c0392b; padding:4px 10px; border-radius:4px; font-size:12px; cursor:pointer;"
                                            title="Anular esta factura (solo el mismo día de emisión)">
                                        <i class="fas fa-ban"></i> Anular
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" style="text-align:center; padding:24px; color:#888;">
                                No hay facturas que coincidan con los filtros.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($facturas->total() > 0)
            {{-- Paginación del historial. Los enlaces conservan TODOS los
                 filtros activos (fecha, estado, búsqueda y cajero). --}}
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-top:16px; font-size:13px; color:#6c757d;">
                <div>
                    Mostrando {{ $facturas->firstItem() }}–{{ $facturas->lastItem() }} de {{ $facturas->total() }} factura(s).
                </div>
                <div style="display:flex; gap:8px; align-items:center;">
                    @if ($facturas->onFirstPage())
                        <span style="background:#e9ecef; color:#adb5bd; padding:6px 12px; border-radius:5px; font-weight:bold;">« Anterior</span>
                    @else
                        <a href="{{ $facturas->previousPageUrl() }}"
                           style="background:#6777ef; color:#fff; padding:6px 12px; border-radius:5px; font-weight:bold; text-decoration:none;">« Anterior</a>
                    @endif
                    <span>Página {{ $facturas->currentPage() }} de {{ $facturas->lastPage() }}</span>
                    @if ($facturas->hasMorePages())
                        <a href="{{ $facturas->nextPageUrl() }}"
                           style="background:#6777ef; color:#fff; padding:6px 12px; border-radius:5px; font-weight:bold; text-decoration:none;">Siguiente »</a>
                    @else
                        <span style="background:#e9ecef; color:#adb5bd; padding:6px 12px; border-radius:5px; font-weight:bold;">Siguiente »</span>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Anulación controlada desde el historial. El backend vuelve a validar
    // TODO (rol, mismo día, mesa cerrada, cierre, observación): este modal
    // solo pide la confirmación y la observación obligatoria.
    const urlAnular = '{{ route('facturas.anular', ['factura' => '__ID__']) }}';

    document.querySelectorAll('.btn-anular-factura').forEach(function (boton) {
        boton.addEventListener('click', function () {
            if (boton.dataset.anulando === '1') {
                return;
            }

            const facturaId = boton.dataset.facturaId;
            // El número se lee de la propia fila para no duplicarlo en el HTML
            // (y no romper las comprobaciones de filas repetidas).
            const fila = boton.closest('tr');
            const primeraCelda = fila ? fila.querySelector('td') : null;
            const numero = primeraCelda ? primeraCelda.textContent.trim() : '';

            Swal.fire({
                title: 'Anular factura ' + numero,
                html: '<div style="text-align:left;font-size:13px;color:#555;">'
                    + '<p>La factura <strong>no se elimina</strong>: queda marcada como ANULADA y conserva su número, importe, fecha, cajero y pagos.</p>'
                     + '<p>Solo se permite el <strong>mismo día de emisión</strong>. Si pertenece a un cierre de caja finalizado, no se podrá anular. Si otra venta activa ocupa la mesa, la reconstrucción se detiene.</p>'
                     + '<p>Los pagos originales se conservan y quedan marcados como <strong>REVERSADOS</strong>; la devolución física del dinero queda pendiente. La cuenta vuelve a estar pendiente de cobro, la venta queda ACTIVA y la mesa OCUPADA: se puede recobrar y emitir una nueva factura.</p>'
                    + '</div>',
                input: 'textarea',
                inputLabel: 'Observación obligatoria (motivo de la anulación)',
                inputPlaceholder: 'Ej.: error de cobro, método de pago equivocado…',
                inputAttributes: { maxlength: 1000, 'aria-label': 'Observación obligatoria' },
                showCancelButton: true,
                confirmButtonText: 'Sí, anular factura',
                confirmButtonColor: '#c0392b',
                cancelButtonText: 'Cancelar',
                preConfirm: function (valor) {
                    const motivo = (valor || '').trim();

                    if (motivo.length < 5) {
                        Swal.showValidationMessage('La observación es obligatoria (mínimo 5 caracteres).');
                        return false;
                    }

                    return motivo;
                }
            }).then(function (resultado) {
                if (!resultado.isConfirmed) {
                    return;
                }

                // Evita doble clic / doble solicitud mientras se procesa.
                boton.dataset.anulando = '1';
                boton.disabled = true;
                boton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Anulando…';

                const datos = new FormData();
                datos.append('_token', '{{ csrf_token() }}');
                datos.append('motivo', resultado.value);

                fetch(urlAnular.replace('__ID__', encodeURIComponent(facturaId)), {
                    method: 'POST',
                    body: datos,
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                    .then(function (respuesta) {
                        return respuesta.json().then(function (cuerpo) {
                            return { ok: respuesta.ok, cuerpo: cuerpo };
                        });
                    })
                    .then(function (r) {
                        if (!r.ok || !r.cuerpo.exito) {
                            throw new Error(r.cuerpo.error || 'No se pudo anular la factura.');
                        }

                        return Swal.fire('Factura anulada', r.cuerpo.mensaje || 'La factura fue anulada.', 'success');
                    })
                    .then(function () {
                        window.location.reload();
                    })
                    .catch(function (error) {
                        Swal.fire('No se pudo anular', error.message, 'error');
                        boton.dataset.anulando = '';
                        boton.disabled = false;
                        boton.innerHTML = '<i class="fas fa-ban"></i> Anular';
                    });
            });
        });
    });
});
</script>
@endsection
