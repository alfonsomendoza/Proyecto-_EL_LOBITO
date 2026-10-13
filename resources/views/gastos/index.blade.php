@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 900px; margin: 0 auto;">
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <span><i class="fas fa-file-invoice-dollar"></i> Gastos — Salidas de Dinero</span>
        @if ($apertura)
            <button type="button" id="btn-imprimir-gastos"
                    style="background-color:#5a6268; color:white; border:none; padding:6px 15px; border-radius:4px; cursor:pointer; font-weight:bold; font-size:13px;">
                <i class="fas fa-print"></i> Imprimir Gastos del Turno
            </button>
        @endif
    </div>

    <div style="padding: 25px;">

        {{-- Aviso cuando no hay caja abierta --}}
        @if (!$apertura)
            <div style="background:#fff7e6; border:1px solid #f5d9a8; border-radius:6px; padding:14px 16px; font-size:13px; color:#7a5a1a; line-height:1.5;">
                <i class="fas fa-exclamation-triangle"></i>
                No hay una caja abierta. Para registrar gastos primero debes
                <a href="{{ route('caja.apertura') }}" style="color:#7a5a1a; font-weight:bold;">abrir caja</a>.
            </div>
        @else

        {{-- Formulario de registro --}}
        <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden; margin-bottom:22px;">
            <div style="padding:12px 16px; background:#2c3e50; color:#fff; font-weight:600; font-size:14px;">
                <i class="fas fa-money-bill-wave"></i> Registrar Salida de Dinero
            </div>
            <div style="padding:18px;">
                <form id="formGastoNuevo">
                    @csrf
                    <div id="contenedor-filas-gastos">
                        <div class="fila-gasto" style="display:flex; gap:12px; align-items:flex-end; margin-bottom:12px;">
                            <div style="flex:2; display:flex; flex-direction:column; gap:5px;">
                                <label style="font-size:0.85rem; font-weight:bold; color:#555;">Concepto:</label>
                                <input type="text" name="conceptos[]" class="form-control-gasto" maxlength="255"
                                       placeholder="Ej. Pago de agua" required
                                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
                            </div>
                            <div style="flex:1; display:flex; flex-direction:column; gap:5px;">
                                <label style="font-size:0.85rem; font-weight:bold; color:#555;">Monto (C$):</label>
                                <input type="number" step="0.01" min="0.01" name="montos[]" class="form-control-gasto" value="0" required
                                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
                            </div>
                            <button type="button" class="btn-quitar-fila"
                                    style="background-color:#e74a3b; color:#fff; border:none; border-radius:5px; height:38px; width:42px; cursor:pointer;"
                                    title="Quitar fila">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>

                    <button type="button" id="btn-agregar-gasto"
                            style="background-color:#17a2b8; color:#fff; border:none; padding:8px 16px; border-radius:5px; font-weight:bold; cursor:pointer; margin-bottom:15px;">
                        <i class="fas fa-plus"></i> Añadir otra fila
                    </button>

                    <button type="submit"
                            style="width:100%; background-color:#f39c12; color:#fff; border:none; padding:12px; border-radius:5px; font-size:1rem; font-weight:bold; cursor:pointer;">
                        Guardar Todos los Gastos
                    </button>
                </form>
            </div>
        </div>
        @endif

        {{-- Historial del turno --}}
        <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden;">
            <div style="padding:12px 16px; background:#34495e; color:#fff; font-weight:600; font-size:14px;">
                <i class="fas fa-list-alt"></i> Gastos Registrados en este Turno
            </div>

            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#f4f6f9; color:#495057; text-align:left;">
                        <th style="padding:10px 12px;">Hora</th>
                        <th style="padding:10px 12px;">Concepto</th>
                        <th style="padding:10px 12px;">Registrado por</th>
                        <th style="padding:10px 12px; text-align:right;">Monto</th>
                        <th style="padding:10px 12px; text-align:center;">Estado</th>
                        <th style="padding:10px 12px; text-align:center;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($gastos as $gasto)
                        <tr style="border-bottom:1px solid #f1f1f1;">
                            <td style="padding:10px 12px;">{{ $gasto->fecha ? $gasto->fecha->format('H:i') : '--:--' }}</td>
                            <td style="padding:10px 12px;"><strong>{{ $gasto->concepto }}</strong></td>
                            <td style="padding:10px 12px;">{{ $gasto->usuario->nombre ?? 'Cajero' }}</td>
                            <td style="padding:10px 12px; text-align:right; font-weight:bold;">C$ {{ number_format((float) $gasto->monto, 2) }}</td>
                            <td style="padding:10px 12px; text-align:center;">
                                <span style="background:#d4edda; color:#155724; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">ACTIVO</span>
                            </td>
                            <td style="padding:10px 12px; text-align:center;">
                                @if ($puedeAnular)
                                    <button type="button" class="btn-anular-gasto" data-id="{{ $gasto->id }}"
                                            style="background:transparent; color:#fc544b; border:1px solid #fc544b; padding:4px 10px; border-radius:4px; cursor:pointer; font-size:12px;">
                                        <i class="fas fa-ban"></i> Anular
                                    </button>
                                @else
                                    <span style="font-size:11px; color:#aaa;">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align:center; padding:20px; color:#888;">
                                No hay gastos activos registrados en este turno.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($gastos->isNotEmpty())
                    <tfoot>
                        <tr style="background:#f8f9fa; font-weight:bold;">
                            <td colspan="3" style="padding:10px 12px; text-align:right;">TOTAL EGRESOS (activos):</td>
                            <td style="padding:10px 12px; text-align:right; color:#c0392b;">C$ {{ number_format($totalActivos, 2) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const contenedor = document.getElementById('contenedor-filas-gastos');
    const btnAgregar = document.getElementById('btn-agregar-gasto');
    const form = document.getElementById('formGastoNuevo');

    // Quitar una fila (dejando siempre al menos una).
    function enlazarQuitar(fila) {
        const btn = fila.querySelector('.btn-quitar-fila');
        if (!btn) return;
        btn.addEventListener('click', function () {
            const filas = contenedor.querySelectorAll('.fila-gasto');
            if (filas.length > 1) {
                fila.remove();
            } else {
                Swal.fire('Atención', 'Debe haber al menos una fila de gasto.', 'warning');
            }
        });
    }

    if (contenedor) {
        contenedor.querySelectorAll('.fila-gasto').forEach(enlazarQuitar);
    }

    if (btnAgregar) {
        btnAgregar.addEventListener('click', function () {
            const primera = contenedor.querySelector('.fila-gasto');
            const nueva = primera.cloneNode(true);
            nueva.querySelector('input[name="conceptos[]"]').value = '';
            nueva.querySelector('input[name="montos[]"]').value = '0';
            enlazarQuitar(nueva);
            contenedor.appendChild(nueva);
        });
    }

    // Registro por AJAX (conceptos[] y montos[] como arrays).
    if (form) {
        form.addEventListener('submit', function (evento) {
            evento.preventDefault();

            const conceptos = [];
            const montos = [];
            const filas = contenedor.querySelectorAll('.fila-gasto');

            for (const fila of filas) {
                const concepto = fila.querySelector('input[name="conceptos[]"]').value.trim();
                const monto = parseFloat(fila.querySelector('input[name="montos[]"]').value);

                if (concepto === '') {
                    Swal.fire('Atención', 'Falta ingresar el concepto en una de las filas.', 'warning');
                    return;
                }
                if (isNaN(monto) || monto <= 0) {
                    Swal.fire('Atención', 'Ingresa un monto válido mayor a 0 en cada fila.', 'warning');
                    return;
                }
                conceptos.push(concepto);
                montos.push(monto);
            }

            const fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            conceptos.forEach(c => fd.append('conceptos[]', c));
            montos.forEach(m => fd.append('montos[]', m));

            Swal.fire({ title: 'Registrando gastos...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            fetch('{{ route("gastos.store") }}', {
                method: 'POST',
                body: fd,
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(r => r.json())
                .then(d => {
                    if (d.exito) {
                        Swal.fire('Éxito', d.mensaje || 'Gastos registrados.', 'success')
                            .then(() => location.reload());
                    } else {
                        Swal.fire('Error', d.error || 'No se pudieron registrar los gastos.', 'error');
                    }
                })
                .catch(() => Swal.fire('Error', 'Error de conexión con el servidor.', 'error'));
        });
    }

    // Imprimir ticket del turno.
    const btnImprimir = document.getElementById('btn-imprimir-gastos');
    if (btnImprimir) {
        btnImprimir.addEventListener('click', function () {
            window.open('{{ route("gastos.ticket") }}', '_blank', 'width=400,height=650');
        });
    }

    // Anulación lógica (el backend vuelve a validar el rol).
    document.querySelectorAll('.btn-anular-gasto').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = btn.getAttribute('data-id');

            Swal.fire({
                title: '¿Anular este gasto?',
                text: 'El registro quedará marcado como ANULADO y dejará de afectar los totales.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Sí, anular gasto',
                cancelButtonText: 'Cancelar'
            }).then(function (res) {
                if (!res.isConfirmed) return;

                const fd = new FormData();
                fd.append('_token', '{{ csrf_token() }}');

                fetch('{{ route("gastos.anular", "ID") }}'.replace('ID', id), {
                    method: 'POST',
                    body: fd,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(r => r.json())
                    .then(d => {
                        if (d.exito) {
                            Swal.fire('Anulado', d.mensaje || 'Gasto anulado.', 'success')
                                .then(() => location.reload());
                        } else {
                            Swal.fire('Error', d.error || 'No se pudo anular el gasto.', 'error');
                        }
                    })
                    .catch(() => Swal.fire('Error', 'Error de conexión con el servidor.', 'error'));
            });
        });
    });
});
</script>
@endsection
