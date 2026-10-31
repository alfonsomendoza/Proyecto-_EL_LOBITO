@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 900px; margin: 0 auto;">
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <span><i class="fas fa-file-invoice-dollar"></i> Gastos — Salidas de Dinero</span>
        @if ($apertura)
            {{-- Tres impresiones independientes: cada botón aplica su propio
                 criterio de estado, sin depender del filtro en pantalla. --}}
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a href="{{ route('gastos.ticket', ['estado' => 'todos']) }}" target="_blank"
                   style="background-color:#5a6268; color:#fff; padding:6px 12px; border-radius:4px; font-weight:bold; font-size:12px; text-decoration:none;">
                    <i class="fas fa-print"></i> Imprimir todos los gastos
                </a>
                <a href="{{ route('gastos.ticket', ['estado' => 'activos']) }}" target="_blank"
                   style="background-color:#28a745; color:#fff; padding:6px 12px; border-radius:4px; font-weight:bold; font-size:12px; text-decoration:none;">
                    <i class="fas fa-print"></i> Imprimir gastos activos
                </a>
                <a href="{{ route('gastos.ticket', ['estado' => 'anulados']) }}" target="_blank"
                   style="background-color:#c0392b; color:#fff; padding:6px 12px; border-radius:4px; font-weight:bold; font-size:12px; text-decoration:none;">
                    <i class="fas fa-print"></i> Imprimir gastos anulados
                </a>
            </div>
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
                                <input type="number" step="0.01" min="0.01" max="9999999999.99" name="montos[]" class="form-control-gasto" value="0" required
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

            {{-- Filtros por estado. El comportamiento predeterminado es "Activos". --}}
            <div style="padding:10px 16px; display:flex; gap:8px; flex-wrap:wrap; align-items:center; border-bottom:1px solid #f1f1f1;">
                <span style="font-size:12px; color:#6c757d; font-weight:600;">Mostrar:</span>
                @foreach (['activos' => 'Activos', 'anulados' => 'Anulados', 'todos' => 'Todos'] as $clave => $etiqueta)
                    <a href="{{ route('gastos.index', ['estado' => $clave]) }}"
                       style="text-decoration:none; font-size:12px; font-weight:600; padding:5px 12px; border-radius:14px; border:1px solid {{ $filtro === $clave ? '#6777ef' : '#ced4da' }}; background:{{ $filtro === $clave ? '#6777ef' : '#fff' }}; color:{{ $filtro === $clave ? '#fff' : '#495057' }};">
                        {{ $etiqueta }}
                    </a>
                @endforeach
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
                                @if ($gasto->estado === 'ANULADO')
                                    <span style="background:#f8d7da; color:#721c24; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">ANULADO</span>
                                @else
                                    <span style="background:#d4edda; color:#155724; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">ACTIVO</span>
                                @endif
                            </td>
                            <td style="padding:10px 12px; text-align:center; white-space:nowrap;">
                                <a href="{{ route('gastos.ticket.gasto', $gasto->id) }}" target="_blank"
                                   style="display:inline-block; background:transparent; color:#5a6268; border:1px solid #5a6268; padding:4px 10px; border-radius:4px; font-size:12px; text-decoration:none;"
                                   title="Imprimir este gasto">
                                    <i class="fas fa-print"></i> Imprimir
                                </a>
                                @if ($puedeAnular && $gasto->estado === 'ACTIVO')
                                    <button type="button" class="btn-anular-gasto" data-id="{{ $gasto->id }}"
                                            style="background:transparent; color:#fc544b; border:1px solid #fc544b; padding:4px 10px; border-radius:4px; cursor:pointer; font-size:12px;">
                                        <i class="fas fa-ban"></i> Anular
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align:center; padding:20px; color:#888;">
                                @if ($filtro === 'anulados')
                                    No hay gastos anulados en este turno.
                                @elseif ($filtro === 'todos')
                                    No hay gastos registrados en este turno.
                                @else
                                    No hay gastos activos en este turno.
                                @endif
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
                        @if ($filtro !== 'activos')
                            <tr style="background:#fdf2f2; font-weight:bold;">
                                <td colspan="3" style="padding:10px 12px; text-align:right; color:#721c24;">SUBTOTAL ANULADOS (informativo, no afecta caja):</td>
                                <td style="padding:10px 12px; text-align:right; color:#721c24;">C$ {{ number_format($totalAnulados, 2) }}</td>
                                <td colspan="2"></td>
                            </tr>
                        @endif
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
                const monto = numeroMaximo(fila.querySelector('input[name="montos[]"]').value, 2, 9999999999.99);

                if (concepto === '') {
                    Swal.fire('Atención', 'Falta ingresar el concepto en una de las filas.', 'warning');
                    return;
                }
                if (monto === null || monto <= 0) {
                    Swal.fire('Atención', 'Ingresa un monto válido mayor a 0 en cada fila (solo números, máximo 2 decimales y hasta C$ 9,999,999,999.99).', 'warning');
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
