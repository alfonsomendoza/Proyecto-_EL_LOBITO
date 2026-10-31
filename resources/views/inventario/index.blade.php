@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 1100px; margin: 0 auto;">
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <span><i class="fas fa-boxes-stacked"></i> Inventario — Existencias por producto</span>
        <a href="{{ route('inventario.historial') }}"
           style="background-color:#34495e; color:#fff; padding:6px 12px; border-radius:4px; font-weight:bold; font-size:12px; text-decoration:none;">
            <i class="fas fa-clock-rotate-left"></i> Ver historial de movimientos
        </a>
    </div>

    <div style="padding: 25px;">

        {{-- Aviso cuando las migraciones de la Etapa 1 siguen pendientes.
             El controlador NO ejecuta migraciones por su cuenta. --}}
        @if ($pendiente)
            <div style="background:#fff7e6; border:1px solid #f5d9a8; border-radius:6px; padding:14px 16px; font-size:13px; color:#7a5a1a; line-height:1.5;">
                <i class="fas fa-exclamation-triangle"></i>
                El módulo de inventario todavía no está disponible: faltan aplicar las
                migraciones de la base de datos. Pide a un administrador que las ejecute.
            </div>
        @else

        {{-- Búsqueda y filtros por estado --}}
        <div style="border:1px solid #e3e6f0; border-radius:8px; padding:14px 16px; margin-bottom:18px;">
            <form method="GET" action="{{ route('inventario.index') }}" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                <div style="flex:2; min-width:220px; display:flex; flex-direction:column; gap:5px;">
                    <label style="font-size:0.85rem; font-weight:bold; color:#555;">Buscar producto:</label>
                    <input type="text" name="q" value="{{ $q }}" maxlength="100" placeholder="Nombre del producto..."
                           style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
                </div>

                <div style="flex:1; min-width:180px; display:flex; flex-direction:column; gap:5px;">
                    <label style="font-size:0.85rem; font-weight:bold; color:#555;">Categoría:</label>
                    <select name="categoria_id"
                            style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
                        <option value="">Todas las categorías</option>
                        @if ($haySinCategoria)
                            <option value="0" {{ $categoriaId === 0 ? 'selected' : '' }}>Sin categoría</option>
                        @endif
                        @foreach ($categorias as $cat)
                            <option value="{{ $cat->id }}" {{ $categoriaId === (int) $cat->id ? 'selected' : '' }}>
                                {{ $cat->nombre }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <input type="hidden" name="estado" value="{{ $estado }}">
                <button type="submit"
                        style="background-color:#17a2b8; color:#fff; border:none; padding:9px 18px; border-radius:5px; font-weight:bold; cursor:pointer;">
                    <i class="fas fa-search"></i> Buscar
                </button>
                <a href="{{ route('inventario.index') }}"
                   style="background-color:#e2e3e5; color:#41464b; border:none; padding:9px 18px; border-radius:5px; font-weight:bold; cursor:pointer; text-decoration:none;">
                    <i class="fas fa-eraser"></i> Limpiar filtros
                </a>
            </form>

            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:12px;">
                <span style="font-size:12px; color:#6c757d; font-weight:600;">Mostrar:</span>
                @foreach (['todos' => 'Todos', 'controlados' => 'Con control', 'sin_control' => 'Sin control', 'bajo' => 'Bajo mínimo'] as $clave => $etiqueta)
                    <a href="{{ route('inventario.index', ['estado' => $clave, 'q' => $q, 'categoria_id' => $categoriaId]) }}"
                       style="text-decoration:none; font-size:12px; font-weight:600; padding:5px 12px; border-radius:14px; border:1px solid {{ $estado === $clave ? '#6777ef' : '#ced4da' }}; background:{{ $estado === $clave ? '#6777ef' : '#fff' }}; color:{{ $estado === $clave ? '#fff' : '#495057' }};">
                        {{ $etiqueta }}
                    </a>
                @endforeach
            </div>

            {{-- Indicador de resultados: cuántos coinciden con los filtros --}}
            <div style="margin-top:10px; font-size:12px; color:#495057;">
                <i class="fas fa-filter"></i>
                Mostrando <strong>{{ $productos->count() }}</strong> de <strong>{{ $totalProductos }}</strong> producto(s) con los filtros aplicados.
            </div>
        </div>

        {{-- Tabla de productos --}}
        <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden;">
            <div style="padding:12px 16px; background:#2c3e50; color:#fff; font-weight:600; font-size:14px;">
                <i class="fas fa-list-alt"></i> Productos ({{ $totalProductos }})
            </div>

            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#f4f6f9; color:#495057; text-align:left;">
                        <th style="padding:10px 12px;">Producto</th>
                        <th style="padding:10px 12px;">Categoría</th>
                        <th style="padding:10px 12px; text-align:center;">Control</th>
                        <th style="padding:10px 12px; text-align:right;">Stock actual</th>
                        <th style="padding:10px 12px; text-align:right;">Mínimo</th>
                        <th style="padding:10px 12px;">Unidad</th>
                        <th style="padding:10px 12px; text-align:center;">Estado</th>
                        <th style="padding:10px 12px; text-align:center;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($productos as $producto)
                        <tr style="border-bottom:1px solid #f1f1f1;">
                            <td style="padding:10px 12px;"><strong>{{ $producto->nombre }}</strong></td>
                            <td style="padding:10px 12px;">{{ $producto->categoria ?? '—' }}</td>
                            <td style="padding:10px 12px; text-align:center;">
                                @if ($producto->controla_stock)
                                    <span style="background:#d4edda; color:#155724; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">SÍ</span>
                                @else
                                    <span style="background:#e2e3e5; color:#41464b; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">NO</span>
                                @endif
                            </td>
                            <td style="padding:10px 12px; text-align:right; font-weight:bold;">
                                {{ $producto->stock_actual === null ? '—' : number_format($producto->stock_actual, 3) }}
                            </td>
                            <td style="padding:10px 12px; text-align:right;">
                                {{ number_format($producto->stock_minimo, 3) }}
                            </td>
                            <td style="padding:10px 12px;">{{ $producto->unidad }}</td>
                            <td style="padding:10px 12px; text-align:center;">
                                @if ($producto->bajo)
                                    <span style="background:#f8d7da; color:#721c24; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">BAJO MÍNIMO</span>
                                @elseif ($producto->controla_stock)
                                    <span style="background:#d4edda; color:#155724; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">OK</span>
                                @else
                                    <span style="color:#adb5bd;">—</span>
                                @endif
                            </td>
                            <td style="padding:10px 12px; text-align:center; white-space:nowrap;">
                                <a href="{{ route('inventario.historial', ['producto_id' => $producto->id]) }}"
                                   style="display:inline-block; background:transparent; color:#5a6268; border:1px solid #5a6268; padding:4px 10px; border-radius:4px; font-size:12px; text-decoration:none;"
                                   title="Ver movimientos de este producto">
                                    <i class="fas fa-clock-rotate-left"></i> Historial
                                </a>
                                @if ($puedeAdministrar)
                                    <button type="button" class="btn-admin-inventario" data-target="admin-{{ $producto->id }}"
                                            style="background:transparent; color:#6777ef; border:1px solid #6777ef; padding:4px 10px; border-radius:4px; cursor:pointer; font-size:12px;">
                                        <i class="fas fa-sliders"></i> Administrar
                                    </button>
                                @endif
                            </td>
                        </tr>

                        @if ($puedeAdministrar)
                            <tr id="admin-{{ $producto->id }}" style="display:none; background:#f8f9fa;">
                                <td colspan="8" style="padding:16px;">
                                    <div style="display:flex; gap:18px; flex-wrap:wrap;">

                                        {{-- Activar / desactivar control (no borra nada) --}}
                                        <form class="form-inventario" method="POST" action="{{ route('inventario.control', $producto->id) }}"
                                              style="flex:1; min-width:220px; border:1px solid #e3e6f0; border-radius:6px; padding:12px; background:#fff;">
                                            @csrf
                                            <div style="font-weight:600; font-size:12px; color:#495057; margin-bottom:8px;">Control de inventario</div>
                                            <input type="hidden" name="activo" value="{{ $producto->controla_stock ? 0 : 1 }}">
                                            <button type="submit"
                                                    style="width:100%; background-color:{{ $producto->controla_stock ? '#e74a3b' : '#28a745' }}; color:#fff; border:none; padding:8px; border-radius:5px; font-weight:bold; cursor:pointer;">
                                                {{ $producto->controla_stock ? 'Desactivar control' : 'Activar control' }}
                                            </button>
                                        </form>

                                        {{-- Carga inicial de existencias --}}
                                        <form class="form-inventario" method="POST" action="{{ route('inventario.cargaInicial', $producto->id) }}"
                                              style="flex:1; min-width:220px; border:1px solid #e3e6f0; border-radius:6px; padding:12px; background:#fff;">
                                            @csrf
                                            <div style="font-weight:600; font-size:12px; color:#495057; margin-bottom:8px;">Carga inicial</div>
                                            <input type="number" step="0.001" min="0.001" max="999999999.999" name="cantidad" required placeholder="Cantidad"
                                                   style="width:100%; padding:7px 10px; border:1px solid #ced4da; border-radius:5px; margin-bottom:6px;">
                                            <input type="text" name="observacion" maxlength="500" placeholder="Observación (opcional)"
                                                   style="width:100%; padding:7px 10px; border:1px solid #ced4da; border-radius:5px; margin-bottom:6px;">
                                            <button type="submit"
                                                    style="width:100%; background-color:#17a2b8; color:#fff; border:none; padding:8px; border-radius:5px; font-weight:bold; cursor:pointer;">
                                                Registrar carga inicial
                                            </button>
                                        </form>

                                        {{-- Stock mínimo y unidad --}}
                                        <form class="form-inventario" method="POST" action="{{ route('inventario.minimo', $producto->id) }}"
                                              style="flex:1; min-width:220px; border:1px solid #e3e6f0; border-radius:6px; padding:12px; background:#fff;">
                                            @csrf
                                            <div style="font-weight:600; font-size:12px; color:#495057; margin-bottom:8px;">Mínimo y unidad</div>
                                            <input type="number" step="0.001" min="0" max="999999999.999" name="stock_minimo" required value="{{ $producto->stock_minimo }}" placeholder="Stock mínimo"
                                                   style="width:100%; padding:7px 10px; border:1px solid #ced4da; border-radius:5px; margin-bottom:6px;">
                                            <input type="text" name="unidad" maxlength="20" value="{{ $producto->unidad }}" placeholder="Unidad"
                                                   style="width:100%; padding:7px 10px; border:1px solid #ced4da; border-radius:5px; margin-bottom:6px;">
                                            <button type="submit"
                                                    style="width:100%; background-color:#f39c12; color:#fff; border:none; padding:8px; border-radius:5px; font-weight:bold; cursor:pointer;">
                                                Guardar mínimo
                                            </button>
                                        </form>

                                        {{-- Ajuste manual (entrada / salida) --}}
                                        <form class="form-inventario" method="POST" action="{{ route('inventario.ajuste', $producto->id) }}"
                                              style="flex:1; min-width:220px; border:1px solid #e3e6f0; border-radius:6px; padding:12px; background:#fff;">
                                            @csrf
                                            <div style="font-weight:600; font-size:12px; color:#495057; margin-bottom:8px;">Ajuste manual</div>
                                            <select name="tipo"
                                                    style="width:100%; padding:7px 10px; border:1px solid #ced4da; border-radius:5px; margin-bottom:6px;">
                                                <option value="AJUSTE_ENTRADA">Entrada (+)</option>
                                                <option value="AJUSTE_SALIDA">Salida (-)</option>
                                            </select>
                                            <input type="number" step="0.001" min="0.001" max="999999999.999" name="cantidad" required placeholder="Cantidad"
                                                   style="width:100%; padding:7px 10px; border:1px solid #ced4da; border-radius:5px; margin-bottom:6px;">
                                            <input type="text" name="observacion" maxlength="500" placeholder="Motivo (opcional)"
                                                   style="width:100%; padding:7px 10px; border:1px solid #ced4da; border-radius:5px; margin-bottom:6px;">
                                            <button type="submit"
                                                    style="width:100%; background-color:#6777ef; color:#fff; border:none; padding:8px; border-radius:5px; font-weight:bold; cursor:pointer;">
                                                Aplicar ajuste
                                            </button>
                                        </form>

                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="8" style="text-align:center; padding:20px; color:#888;">
                                @if ($q !== '')
                                    No se encontraron productos para "{{ $q }}"{{ $categoriaId !== null ? ' en los filtros seleccionados' : '' }}.
                                @elseif ($estado === 'bajo')
                                    No hay productos por debajo del stock mínimo.
                                @elseif ($estado === 'controlados')
                                    Todavía no hay productos con control de inventario activo.
                                @elseif ($categoriaId === 0)
                                    No hay productos sin categoría.
                                @elseif ($categoriaId !== null)
                                    No hay productos en la categoría seleccionada.
                                @else
                                    No hay productos registrados en el catálogo.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Paginación: conserva los filtros porque el paginador lleva la query
             de la petición actual (path + query). --}}
        @if ($productos->hasPages())
            <div style="margin-top:16px; display:flex; justify-content:center; align-items:center; gap:6px; flex-wrap:wrap;">
                @foreach ($productos->linkCollection() as $enlace)
                    @if ($enlace['url'])
                        <a href="{{ $enlace['url'] }}"
                           style="padding:6px 12px; border-radius:5px; font-size:13px; text-decoration:none; border:1px solid {{ $enlace['active'] ? '#6777ef' : '#ced4da' }}; background:{{ $enlace['active'] ? '#6777ef' : '#fff' }}; color:{{ $enlace['active'] ? '#fff' : '#495057' }}; font-weight:{{ $enlace['active'] ? 'bold' : 'normal' }};">
                            {!! $enlace['label'] !!}
                        </a>
                    @else
                        <span style="padding:6px 12px; border-radius:5px; font-size:13px; color:#adb5bd; border:1px solid #e9ecef; background:#f8f9fa;">
                            {!! $enlace['label'] !!}
                        </span>
                    @endif
                @endforeach
            </div>
            <div style="margin-top:8px; text-align:center; font-size:12px; color:#6c757d;">
                Página {{ $productos->currentPage() }} de {{ $productos->lastPage() }}
            </div>
        @endif

        @if (!$puedeAdministrar)
            <div style="margin-top:14px; font-size:12px; color:#6c757d;">
                <i class="fas fa-eye"></i> Puedes consultar el inventario, pero solo ADMIN y GERENTE pueden modificarlo.
            </div>
        @endif

        @endif
    </div>
</div>

@if ($puedeAdministrar && !$pendiente)
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Mostrar/ocultar el panel de administración de cada producto.
    document.querySelectorAll('.btn-admin-inventario').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const fila = document.getElementById(btn.getAttribute('data-target'));
            if (fila) {
                fila.style.display = (fila.style.display === 'none' || fila.style.display === '') ? 'table-row' : 'none';
            }
        });
    });

    // Envío por AJAX de todos los formularios de inventario. El backend vuelve
    // a validar el rol: ocultar el panel no sustituye la autorización.
    document.querySelectorAll('.form-inventario').forEach(function (form) {
        form.addEventListener('submit', function (evento) {
            evento.preventDefault();

            const fd = new FormData(form);

            fetch(form.action, {
                method: 'POST',
                body: fd,
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
                .then(r => r.json().then(d => ({ status: r.status, data: d })))
                .then(function (res) {
                    if (res.data && res.data.exito) {
                        Swal.fire('Éxito', 'Operación realizada correctamente.', 'success')
                            .then(() => location.reload());
                    } else if (res.status === 422) {
                        // Error de validación del backend: mostrar el primer mensaje
                        // concreto (errors[0]); "message" como respaldo y, si no hay
                        // ninguno, el "error" específico del endpoint (p. ej. un
                        // producto sin control de stock también responde 422).
                        let mensaje = (res.data && res.data.error) || 'No se pudo completar la operación.';
                        if (res.data && res.data.errors) {
                            const primero = Object.values(res.data.errors)[0];
                            mensaje = Array.isArray(primero) ? primero[0] : primero;
                        } else if (res.data && res.data.message) {
                            mensaje = res.data.message;
                        }
                        Swal.fire('Error', mensaje, 'error');
                    } else {
                        Swal.fire('Error', (res.data && res.data.error) || 'No se pudo completar la operación.', 'error');
                    }
                })
                .catch(() => Swal.fire('Error', 'Error de conexión con el servidor.', 'error'));
        });
    });
});
</script>
@endif
@endsection
