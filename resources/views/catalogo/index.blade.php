@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 1100px; margin: 0 auto;">
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center;">
        <span><i class="fas fa-utensils"></i> Menú y Catálogo</span>
        <div>
            @if(in_array(auth()->user()->rol->nombre ?? '', ['ADMIN', 'GERENTE'], true))
                <button onclick="toggleCategorias()" style="background-color: #f8f9fa; color: #34395e; border: 1px solid #e3e6f0; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px; margin-right: 10px;">
                    <i class="fas fa-list"></i> Gestionar categorías
                </button>
                <button onclick="toggleImportarCsv()" style="background-color: #f8f9fa; color: #34395e; border: 1px solid #e3e6f0; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px; margin-right: 10px;">
                    <i class="fas fa-file-excel"></i> Importar productos
                </button>
                <button onclick="modalCategoria('crear')" style="background-color: #6777ef; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px; margin-right: 10px;">
                    <i class="fas fa-tags"></i> + Categoría
                </button>
                <button onclick="modalProducto('crear')" style="background-color: var(--color-success); color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px;">
                    <i class="fas fa-hamburger"></i> + Producto
                </button>
            @endif
        </div>
    </div>

    <div style="padding: 30px;">
        
        {{-- PANEL DE BÚSQUEDA Y FILTRO DE CATEGORÍAS --}}
        <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; gap: 15px; background: #f8f9fa; padding: 12px 15px; border-radius: 6px; border: 1px solid #e3e6f0;">
            <div style="display: flex; align-items: center; gap: 10px; flex: 1;">
                <i class="fas fa-search" style="color: #aeb4be; font-size: 16px;"></i>
                <input type="text" id="buscador-catalogo" placeholder="Buscar producto por nombre..." style="width: 100%; border: none; background: transparent; outline: none; font-size: 14px; color: #34395e;">
            </div>
            
            <div style="border-left: 1px solid #ddd; padding-left: 15px; display: flex; align-items: center; gap: 10px;">
                <i class="fas fa-filter" style="color: #aeb4be; font-size: 16px;"></i>
                <select id="filtro-categoria" style="border: none; background: transparent; outline: none; font-size: 14px; color: #34395e; cursor: pointer; font-weight: bold;">
                    <option value="">Todas las Categorías</option>
                    @foreach($categorias as $cat)
                        <option value="{{ $cat->nombre }}">{{ $cat->nombre }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div style="border-radius: 8px; border: 1px solid #e3e6f0; overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                <thead style="background-color: #f4f6f9; border-bottom: 1px solid #ddd;">
                    <tr>
                        <th style="padding: 12px 15px;">Nombre del Producto</th>
                        <th style="padding: 12px 15px;">Categoría (Grupo)</th>
                        <th style="padding: 12px 15px;">Precio (C$)</th>
                        <th style="padding: 12px 15px; text-align: center;">Cocina</th>
                        @if(in_array(auth()->user()->rol->nombre ?? '', ['ADMIN', 'GERENTE'], true))
                            <th style="padding: 12px 15px; text-align: center;">Acciones</th>
                        @endif
                    </tr>
                </thead>
                <tbody id="tabla-catalogo">
                    @foreach($productos as $p)
                    <tr style="border-bottom: 1px solid #f0f0f0;">
                        <td style="padding: 12px 15px; font-weight: 600;" class="td-nombre">{{ $p->nombre }}</td>
                        <td style="padding: 12px 15px;" class="td-categoria">
                            <span style="background: #e3eaef; padding: 3px 8px; border-radius: 12px; font-size: 11px; color: #4b5d67; font-weight: bold;">
                                {{ $p->categoria->nombre ?? 'Sin categoría' }}
                            </span>
                        </td>
                        <td style="padding: 12px 15px; color: var(--color-success); font-weight: bold;">C${{ number_format((float)$p->precio, 2) }}</td>
                        <td style="padding: 12px 15px; text-align: center;" class="td-cocina">
                            @if($p->requiere_cocina)
                                <span style="background: #e6f7ea; color: #2f9e44; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold;">Sí</span>
                            @else
                                <span style="background: #fdecea; color: #fc544b; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold;">No</span>
                            @endif
                        </td>
                        @if(in_array(auth()->user()->rol->nombre ?? '', ['ADMIN', 'GERENTE'], true))
                        <td style="padding: 12px 15px; text-align: center; white-space: nowrap;">
                            <div style="display: flex; justify-content: center; align-items: center; gap: 8px;">
                                <button class="btn-editar-producto" data-id="{{ $p->id }}" data-cat="{{ $p->categoria_id }}" data-nombre="{{ $p->nombre }}" data-precio="{{ $p->precio }}" data-rc="{{ $p->requiere_cocina }}" style="background: transparent; color: #36b9cc; border: 1px solid #36b9cc; padding: 5px 10px; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 5px; font-size: 12px;">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn-eliminar-producto" data-id="{{ $p->id }}" style="background: transparent; color: #fc544b; border: 1px solid #fc544b; padding: 5px 10px; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 5px; font-size: 12px;">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        </td>
                    @endif
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- PRODUCTOS ELIMINADOS (activo=0): se consultan aparte y se reactivan --}}
        @php $puedeGestionar = in_array(auth()->user()->rol->nombre ?? '', ['ADMIN', 'GERENTE'], true); @endphp
        <div style="margin-top: 25px;">
            <button type="button" onclick="toggleEliminados()" style="background: #f8f9fa; color: #34395e; border: 1px solid #e3e6f0; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px;">
                <i class="fas fa-trash-restore"></i> Ver productos eliminados ({{ $productosEliminados->count() }})
            </button>

            <div id="panel-eliminados" style="display: none; margin-top: 15px; border-radius: 8px; border: 1px solid #f5c2c7; overflow: hidden;">
                <div style="background: #fdecea; padding: 10px 15px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid #f5c2c7;">
                    <i class="fas fa-search" style="color: #aeb4be; font-size: 15px;"></i>
                    <input type="text" id="buscador-eliminados" placeholder="Buscar producto eliminado por nombre..." style="width: 100%; border: none; background: transparent; outline: none; font-size: 14px; color: #34395e;">
                </div>
                <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                    <thead style="background-color: #f4f6f9; border-bottom: 1px solid #ddd;">
                        <tr>
                            <th style="padding: 12px 15px;">Nombre del Producto</th>
                            <th style="padding: 12px 15px;">Categoría (Grupo)</th>
                            <th style="padding: 12px 15px;">Precio (C$)</th>
                            @if($puedeGestionar)
                                <th style="padding: 12px 15px; text-align: center;">Acciones</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody id="tabla-eliminados">
                        @forelse($productosEliminados as $p)
                        <tr style="border-bottom: 1px solid #f0f0f0; background: #fffdfd;">
                            <td style="padding: 12px 15px; font-weight: 600;" class="td-nombre-eliminado">{{ $p->nombre }}</td>
                            <td style="padding: 12px 15px;">{{ $p->categoria->nombre ?? 'Sin categoría' }}</td>
                            <td style="padding: 12px 15px; color: var(--color-success); font-weight: bold;">C${{ number_format((float)$p->precio, 2) }}</td>
                            @if($puedeGestionar)
                            <td style="padding: 12px 15px; text-align: center; white-space: nowrap;">
                                <button class="btn-reactivar-producto" data-id="{{ $p->id }}" style="background: transparent; color: #47c363; border: 1px solid #47c363; padding: 5px 10px; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; font-size: 12px;">
                                    <i class="fas fa-undo"></i> Reactivar
                                </button>
                            </td>
                            @endif
                        </tr>
                        @empty
                        <tr><td colspan="{{ $puedeGestionar ? 4 : 3 }}" style="padding: 15px; text-align: center; color: #aaa;">No hay productos eliminados.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- GESTIÓN DE CATEGORÍAS (ADMIN/GERENTE): activar o desactivar sin
             borrar la categoría ni sus productos. El estado real es la
             columna `categorias.activa`; la autorización se valida en el
             backend (CatalogoController::storeOrUpdate). --}}
        @if($puedeGestionar)
        <div style="margin-top: 25px;">
            <button type="button" onclick="toggleCategorias()" style="background: #f8f9fa; color: #34395e; border: 1px solid #e3e6f0; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px;">
                <i class="fas fa-tags"></i> Gestionar categorías ({{ $categorias->count() }})
            </button>

            <div id="panel-categorias" style="display: none; margin-top: 15px; border-radius: 8px; border: 1px solid #e3e6f0; overflow: hidden;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                    <thead style="background-color: #f4f6f9; border-bottom: 1px solid #ddd;">
                        <tr>
                            <th style="padding: 12px 15px;">Categoría</th>
                            <th style="padding: 12px 15px;">Productos</th>
                            <th style="padding: 12px 15px; text-align: center;">Estado</th>
                            <th style="padding: 12px 15px; text-align: center;">Acción</th>
                        </tr>
                    </thead>
                    <tbody id="tabla-categorias">
                        @forelse($categorias as $cat)
                        <tr style="border-bottom: 1px solid #f0f0f0;">
                            <td style="padding: 12px 15px; font-weight: 600;">{{ $cat->nombre }}</td>
                            <td style="padding: 12px 15px; color: #6c757d;">{{ $cat->productos()->count() }}</td>
                            <td style="padding: 12px 15px; text-align: center;" class="td-estado-categoria">
                                @if($cat->activa)
                                    <span style="background: #e6f7ea; color: #2f9e44; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold;">Activa</span>
                                @else
                                    <span style="background: #fdecea; color: #fc544b; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: bold;">Inactiva</span>
                                @endif
                            </td>
                            <td style="padding: 12px 15px; text-align: center; white-space: nowrap;">
                                @if($cat->activa)
                                    <button class="btn-estado-categoria" data-id="{{ $cat->id }}" data-accion="desactivar_categoria" style="background: transparent; color: #fc544b; border: 1px solid #fc544b; padding: 5px 10px; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; font-size: 12px;">
                                        <i class="fas fa-ban"></i> Desactivar
                                    </button>
                                @else
                                    <button class="btn-estado-categoria" data-id="{{ $cat->id }}" data-accion="activar_categoria" style="background: transparent; color: #47c363; border: 1px solid #47c363; padding: 5px 10px; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; font-size: 12px;">
                                        <i class="fas fa-check"></i> Activar
                                    </button>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr><td colspan="4" style="padding: 15px; text-align: center; color: #aaa;">No hay categorías.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- IMPORTACIÓN DE PRODUCTOS DESDE EXCEL O CSV. Solo ADMIN/GERENTE ven el
             panel; los endpoints también vuelven a autorizar en el servidor.
             Flujo: analizar (sin guardar) -> revisar vista previa -> confirmar. --}}
        <div style="margin-top: 25px;">
            <button type="button" onclick="toggleImportarCsv()" style="background: #f8f9fa; color: #34395e; border: 1px solid #e3e6f0; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px;">
                <i class="fas fa-file-excel"></i> Importar productos Excel/CSV
            </button>

            <div id="panel-importar-csv" style="display: none; margin-top: 15px; border-radius: 8px; border: 1px solid #e3e6f0; padding: 18px; background: #fff;">
                <h3 style="margin: 0 0 8px 0; font-size: 15px; color: #34395e;">
                    <i class="fas fa-file-excel"></i> Importar productos desde Excel o CSV
                </h3>
                <p style="font-size: 13px; color: #6c757d; margin: 0 0 10px 0;">
                    Formato recomendado: <strong>.xlsx</strong> con la plantilla oficial
                    (la primera fila útil es la 4; la fila 3 tiene los encabezados).
                    También se admiten <strong>.csv</strong> y <strong>.txt</strong> con los encabezados en la primera fila.
                    Columnas obligatorias:
                    <strong>categoria, nombre, precio, requiere_cocina</strong>.
                    La categoría debe existir y estar activa.
                    <strong>requiere_cocina</strong> acepta <code>0/1</code> o <code>SI/NO</code>.
                </p>
                <p style="font-size: 13px; margin: 0 0 10px 0;">
                    <a href="{{ route('catalogo.importar.plantilla') }}" style="font-weight: bold;">
                        <i class="fas fa-download"></i> Descargar plantilla oficial Excel (.xlsx)
                    </a>
                </p>
                <p style="font-size: 12px; color: #6c757d; margin: 0 0 12px 0;">
                    La plantilla trae dos hojas: Productos y Categorías activas. Los ejemplos están
                    fuera del área de importación y no se guardan. La importación solo CREA productos nuevos:
                    no actualiza ni duplica los existentes, no asigna existencias y no genera movimientos de inventario.
                    Primero verás una vista previa; nada se guarda hasta que confirmes.
                </p>

                <form id="form-importar-csv" onsubmit="analizarCsv(event)" enctype="multipart/form-data">
                    @csrf
                    <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
                        <input type="file" id="archivo-csv" name="archivo" accept=".xlsx,.csv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv,text/plain" required style="font-size: 13px;">
                        <button type="submit" id="btn-analizar-csv" style="background: #6777ef; color: #fff; border: 0; padding: 8px 14px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px;">
                            <i class="fas fa-search"></i> Analizar archivo
                        </button>
                    </div>
                </form>

                <div id="importacion-mensaje" style="display: none; margin-top: 12px; padding: 10px 12px; border-radius: 6px; font-size: 13px;"></div>

                <div id="importacion-resultado" style="display: none; margin-top: 15px;">
                    <div id="importacion-resumen" style="font-size: 13px; color: #34395e; margin-bottom: 10px;"></div>

                    <div style="overflow-x: auto; border: 1px solid #e3e6f0; border-radius: 6px;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 12px; text-align: left; min-width: 760px;">
                            <thead style="background: #f4f6f9; border-bottom: 1px solid #ddd;">
                                <tr>
                                    <th style="padding: 8px 10px;">Fila</th>
                                    <th style="padding: 8px 10px;">Estado</th>
                                    <th style="padding: 8px 10px;">Categoría</th>
                                    <th style="padding: 8px 10px;">Nombre</th>
                                    <th style="padding: 8px 10px;">Precio</th>
                                    <th style="padding: 8px 10px;">Cocina</th>
                                    <th style="padding: 8px 10px;">Detalle</th>
                                </tr>
                            </thead>
                            <tbody id="importacion-filas"></tbody>
                        </table>
                    </div>

                    <div style="display: flex; gap: 10px; margin-top: 12px; flex-wrap: wrap;">
                        <button type="button" id="btn-confirmar-importacion" onclick="confirmarImportacionCsv()" disabled style="background: #47c363; color: #fff; border: 0; padding: 8px 14px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px; opacity: .55;">
                            <i class="fas fa-check"></i> Confirmar importación
                        </button>
                        <button type="button" onclick="cancelarImportacionCsv()" style="background: #f8f9fa; color: #34395e; border: 1px solid #e3e6f0; padding: 8px 14px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px;">
                            Cancelar
                        </button>
                    </div>
                </div>
            </div>
        </div>
        @endif
    </div>
</div>

<script id="categorias-json" type="application/json">
    {!! $categorias->toJson() !!}
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const categoriasData = JSON.parse(document.getElementById('categorias-json').textContent);
    window.categoriasData = categoriasData;

    const buscador = document.getElementById('buscador-catalogo');
    const filtroCategoria = document.getElementById('filtro-categoria');
    const filas = document.querySelectorAll('#tabla-catalogo tr');

    function filtrarTabla() {
        const textoBusqueda = buscador.value.toLowerCase().trim();
        const categoriaSeleccionada = filtroCategoria.value.toLowerCase().trim();

        filas.forEach(fila => {
            const nombreFila = fila.querySelector('.td-nombre').textContent.toLowerCase();
            const categoriaFila = fila.querySelector('.td-categoria').textContent.toLowerCase();

            const coincideTexto = nombreFila.includes(textoBusqueda);
            const coincideCategoria = categoriaSeleccionada === "" || categoriaFila.includes(categoriaSeleccionada);

            if (coincideTexto && coincideCategoria) {
                fila.style.display = '';
            } else {
                fila.style.display = 'none';
            }
        });
    }

    buscador.addEventListener('input', filtrarTabla);
    filtroCategoria.addEventListener('change', filtrarTabla);

    // Buscador del listado de productos eliminados.
    const buscadorEliminados = document.getElementById('buscador-eliminados');
    if (buscadorEliminados) {
        buscadorEliminados.addEventListener('input', function() {
            const texto = this.value.toLowerCase().trim();
            document.querySelectorAll('#tabla-eliminados tr').forEach(fila => {
                const celda = fila.querySelector('.td-nombre-eliminado');
                if (!celda) return; // fila de "no hay eliminados"
                fila.style.display = celda.textContent.toLowerCase().includes(texto) ? '' : 'none';
            });
        });
    }

    document.body.addEventListener('click', function(e) {
        let btnEditar = e.target.closest('.btn-editar-producto');
        let btnEliminar = e.target.closest('.btn-eliminar-producto');
        let btnReactivar = e.target.closest('.btn-reactivar-producto');
        let btnEstadoCategoria = e.target.closest('.btn-estado-categoria');

        if (btnEditar) {
            modalProducto('editar', btnEditar.getAttribute('data-id'), btnEditar.getAttribute('data-cat'), btnEditar.getAttribute('data-nombre'), btnEditar.getAttribute('data-precio'), btnEditar.getAttribute('data-rc'));
        }
        if (btnEliminar) {
            eliminarRegistro('eliminar_producto', btnEliminar.getAttribute('data-id'));
        }
        if (btnReactivar) {
            reactivarProducto(btnReactivar.getAttribute('data-id'));
        }
        if (btnEstadoCategoria) {
            cambiarEstadoCategoria(btnEstadoCategoria.getAttribute('data-id'), btnEstadoCategoria.getAttribute('data-accion'));
        }
    });
});

// Muestra u oculta el panel de gestión de categorías.
function toggleCategorias() {
    const panel = document.getElementById('panel-categorias');
    if (panel) {
        panel.style.display = (panel.style.display === 'none' || panel.style.display === '') ? 'block' : 'none';
    }
}

// Activa o desactiva una categoría. NO borra la categoría ni sus productos:
// solo cambia la columna real `categorias.activa` (validado en el backend).
function cambiarEstadoCategoria(id, accion) {
    const desactivar = accion === 'desactivar_categoria';

    Swal.fire({
        title: desactivar ? '¿Desactivar categoría?' : '¿Activar categoría?',
        text: desactivar
            ? 'La categoría quedará inactiva. Sus productos NO se eliminan ni se desactivan.'
            : 'La categoría volverá a estar activa.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: desactivar ? '#fc544b' : '#47c363',
        cancelButtonColor: '#6c757d',
        confirmButtonText: desactivar ? 'Sí, desactivar' : 'Sí, activar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (!result.isConfirmed) return;

        const fd = new FormData();
        fd.append('_token', '{{ csrf_token() }}');
        fd.append('accion', accion);
        fd.append('id', id);

        fetch('{{ route("catalogo.api") }}', { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if (data.exito) {
                Swal.fire('¡Listo!', data.mensaje || 'Categoría actualizada.', 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', data.error, 'error');
            }
        });
    });
}

// Muestra u oculta el panel de productos eliminados.
function toggleEliminados() {
    const panel = document.getElementById('panel-eliminados');
    if (panel) {
        panel.style.display = (panel.style.display === 'none' || panel.style.display === '') ? 'block' : 'none';
    }
}

function modalProducto(accion, id = 0, catId = '', nombre = '', precio = '', rc = 0) {
    let options = '<option value="">-- Seleccionar Categoría --</option>';
    window.categoriasData.forEach(c => {
        options += `<option value="${c.id}" ${c.id == catId ? 'selected' : ''}>${c.nombre}</option>`;
    });

    Swal.fire({
        title: accion === 'crear' ? 'Nuevo Producto' : 'Editar Producto',
        html: `
            <div style="text-align: left; font-size: 13px; padding: 10px 0;">
                <div style="margin-bottom: 15px;">
                    <label style="font-weight:bold; display:block; margin-bottom: 5px;">Categoría:</label>
                    <select id="prod-cat" class="swal2-input" style="width: 100%; margin: 0; height: 38px; box-sizing: border-box;">${options}</select>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight:bold; display:block; margin-bottom: 5px;">Nombre del Producto:</label>
                    <input id="prod-nombre" class="swal2-input" value="${nombre}" style="width: 100%; margin: 0; height: 38px; box-sizing: border-box;">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight:bold; display:block; margin-bottom: 5px;">Precio (C$):</label>
                    <input id="prod-precio" type="number" step="0.01" class="swal2-input" value="${precio}" style="width: 100%; margin: 0; height: 38px; box-sizing: border-box;">
                </div>

                <div style="margin-bottom: 15px;">
                    <label style="font-weight:bold; display:block; margin-bottom: 5px;">¿Requiere cocina?</label>
                    <input id="prod-requiere-cocina" type="checkbox" ${Number(rc) === 1 ? 'checked' : ''} style="width: 18px; height: 18px; vertical-align: middle; cursor: pointer;">
                    <span style="font-size: 12px; color: #6c757d; margin-left: 6px;">Marcar solo para alimentos o preparaciones que van a cocina.</span>
                </div>
            </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonColor: '#47c363',
        confirmButtonText: 'Guardar',
        preConfirm: () => {
            return {
                categoria_id: document.getElementById('prod-cat').value,
                nombre: document.getElementById('prod-nombre').value,
                precio: document.getElementById('prod-precio').value,
                requiere_cocina: document.getElementById('prod-requiere-cocina').checked
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            fd.append('accion', accion + '_producto');
            if (id > 0) fd.append('id', id);
            fd.append('categoria_id', result.value.categoria_id);
            fd.append('nombre', result.value.nombre);
            fd.append('precio', result.value.precio);
            fd.append('requiere_cocina', result.value.requiere_cocina ? 1 : 0);

            fetch('{{ route("catalogo.api") }}', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => data.exito ? location.reload() : Swal.fire('Error', data.error, 'error'));
        }
    });
}

function modalCategoria(accion) {
    Swal.fire({
        title: 'Nueva Categoría',
        input: 'text',
        inputPlaceholder: 'Ej. Postres',
        showCancelButton: true,
        confirmButtonColor: '#6777ef',
        confirmButtonText: 'Guardar Categoría'
    }).then((result) => {
        if (result.isConfirmed && result.value) {
            const fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            fd.append('accion', accion + '_categoria');
            fd.append('nombre', result.value);
            fetch('{{ route("catalogo.api") }}', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => data.exito ? location.reload() : Swal.fire('Error', data.error, 'error'));
        }
    });
}

function eliminarRegistro(accion, id) {
    Swal.fire({
        title: '¿Estás seguro?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#fc544b',
        confirmButtonText: 'Sí, eliminar'
    }).then((result) => {
        if (result.isConfirmed) {
            const fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            fd.append('accion', accion);
            fd.append('id', id);
            fetch('{{ route("catalogo.api") }}', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    Swal.fire('¡Listo!', data.mensaje || 'Producto eliminado.', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.error, 'error');
                }
            });
        }
    });
}

// Reactiva un producto eliminado (mismo registro, sin duplicar).
function reactivarProducto(id) {
    Swal.fire({
        title: '¿Reactivar producto?',
        text: 'El producto volverá a aparecer en el catálogo normal.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#47c363',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, reactivar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            const fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            fd.append('accion', 'reactivar_producto');
            fd.append('id', id);
            fetch('{{ route("catalogo.api") }}', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    Swal.fire('¡Listo!', data.mensaje || 'Producto reactivado.', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.error, 'error');
                }
            });
        }
    });
}

/* ------------------------------------------------------------------
 | IMPORTACIÓN DE PRODUCTOS EXCEL/CSV (vista previa + confirmación)
 | ------------------------------------------------------------------ */

let importacionCsvToken = null;
let importacionCsvValidas = 0;

function toggleImportarCsv() {
    const panel = document.getElementById('panel-importar-csv');
    if (!panel) return;

    const mostrar = (panel.style.display === 'none' || panel.style.display === '');
    panel.style.display = mostrar ? 'block' : 'none';

    // El catálogo puede tener cientos de productos y el panel queda al final
    // de la página. Sin este desplazamiento, pulsar el acceso de importación
    // de la cabecera parece no hacer nada: el panel se abre fuera de la vista.
    if (mostrar) {
        panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

        const archivo = document.getElementById('archivo-csv');
        if (archivo) {
            // Evita que el foco anule el desplazamiento suave.
            setTimeout(() => archivo.focus({ preventScroll: true }), 350);
        }
    }
}

function mostrarMensajeImportacion(texto, tipo = 'warning') {
    const caja = document.getElementById('importacion-mensaje');
    if (!caja) return;

    const estilos = {
        error:   { fondo: '#fdecea', texto: '#b42318', borde: '#f5c2c7' },
        warning: { fondo: '#fff8e1', texto: '#8a6100', borde: '#ffe08a' },
        success: { fondo: '#e6f7ea', texto: '#2f9e44', borde: '#b7e4c7' }
    };
    const estilo = estilos[tipo] || estilos.warning;

    caja.textContent = texto;
    caja.style.display = 'block';
    caja.style.background = estilo.fondo;
    caja.style.color = estilo.texto;
    caja.style.border = '1px solid ' + estilo.borde;
}

function ocultarMensajeImportacion() {
    const caja = document.getElementById('importacion-mensaje');
    if (caja) caja.style.display = 'none';
}

function cancelarImportacionCsv() {
    importacionCsvToken = null;
    importacionCsvValidas = 0;

    const form = document.getElementById('form-importar-csv');
    if (form) form.reset();

    const resultado = document.getElementById('importacion-resultado');
    if (resultado) resultado.style.display = 'none';

    ocultarMensajeImportacion();

    const boton = document.getElementById('btn-confirmar-importacion');
    if (boton) {
        boton.disabled = true;
        boton.style.opacity = '.55';
    }
}

function analizarCsv(event) {
    event.preventDefault();

    const form = document.getElementById('form-importar-csv');
    const archivo = document.getElementById('archivo-csv');
    const boton = document.getElementById('btn-analizar-csv');
    const resultado = document.getElementById('importacion-resultado');

    if (!form || !archivo || !archivo.files.length) {
        mostrarMensajeImportacion('Selecciona un archivo Excel o CSV antes de analizarlo.', 'error');
        return;
    }

    importacionCsvToken = null;
    importacionCsvValidas = 0;
    if (resultado) resultado.style.display = 'none';
    ocultarMensajeImportacion();

    if (boton) {
        boton.disabled = true;
        boton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Analizando...';
    }

    fetch('{{ route("catalogo.importar.previsualizar") }}', {
        method: 'POST',
        body: new FormData(form),
        headers: { 'Accept': 'application/json' }
    })
    .then(async (res) => {
        const data = await res.json().catch(() => ({}));
        return { status: res.status, data };
    })
    .then(({ status, data }) => {
        if (status >= 400 || !data.exito) {
            const primerError = data.error
                || (data.errors ? Object.values(data.errors)[0]?.[0] : null)
                || data.message
                || 'No se pudo analizar el archivo.';
            throw new Error(primerError);
        }

        importacionCsvToken = data.token;
        pintarResultadoImportacion(data);
    })
    .catch((error) => {
        mostrarMensajeImportacion(error.message || 'No se pudo analizar el archivo.', 'error');
    })
    .finally(() => {
        if (boton) {
            boton.disabled = false;
            boton.innerHTML = '<i class="fas fa-search"></i> Analizar archivo';
        }
    });
}

function pintarResultadoImportacion(data) {
    const resumen = data.resumen || {};
    const filas = data.filas || [];
    const total = Number(resumen.total || 0);
    const validas = Number(resumen.validas || 0);
    const errores = Number(resumen.errores || 0);
    const duplicados = Number(resumen.duplicados || 0);

    importacionCsvValidas = validas;

    const cajaResumen = document.getElementById('importacion-resumen');
    if (cajaResumen) {
        cajaResumen.textContent = '';
        const partes = [
            ['Total de filas', total],
            ['Válidas', validas],
            ['Con errores', errores],
            ['Duplicadas / omitidas', duplicados]
        ];

        partes.forEach(([etiqueta, valor], indice) => {
            if (indice > 0) cajaResumen.appendChild(document.createTextNode('  ·  '));
            const strong = document.createElement('strong');
            strong.textContent = etiqueta + ': ';
            cajaResumen.appendChild(strong);
            cajaResumen.appendChild(document.createTextNode(String(valor)));
        });
    }

    const cuerpo = document.getElementById('importacion-filas');
    if (cuerpo) {
        cuerpo.textContent = '';

        filas.forEach((fila) => {
            const tr = document.createElement('tr');
            tr.style.borderBottom = '1px solid #f0f0f0';

            const estado = String(fila.estado || 'error');
            const etiquetas = { valida: 'Válida', error: 'Error', duplicado: 'Duplicado' };
            const colores = {
                valida: { fondo: '#e6f7ea', texto: '#2f9e44' },
                error: { fondo: '#fdecea', texto: '#b42318' },
                duplicado: { fondo: '#fff8e1', texto: '#8a6100' }
            };
            const color = colores[estado] || colores.error;

            const celdas = [
                String(fila.numero ?? ''),
                null,
                String(fila.categoria || '—'),
                String(fila.nombre || '—'),
                fila.precio !== null && fila.precio !== undefined
                    ? 'C$ ' + Number(fila.precio).toFixed(2)
                    : (fila.precio_texto || '—'),
                fila.requiere_cocina === 1
                    ? 'Sí'
                    : (fila.requiere_cocina === 0
                        ? 'No'
                        : (fila.requiere_cocina_texto || '—')),
                (Array.isArray(fila.mensajes) && fila.mensajes.length)
                    ? fila.mensajes.join(' ')
                    : '—'
            ];

            celdas.forEach((contenido, indice) => {
                const td = document.createElement('td');
                td.style.padding = '8px 10px';
                td.style.verticalAlign = 'top';

                if (indice === 1) {
                    const badge = document.createElement('span');
                    badge.textContent = etiquetas[estado] || estado;
                    badge.style.background = color.fondo;
                    badge.style.color = color.texto;
                    badge.style.padding = '3px 8px';
                    badge.style.borderRadius = '10px';
                    badge.style.fontSize = '11px';
                    badge.style.fontWeight = 'bold';
                    td.appendChild(badge);
                } else {
                    td.textContent = contenido;
                }

                tr.appendChild(td);
            });

            cuerpo.appendChild(tr);
        });
    }

    const resultado = document.getElementById('importacion-resultado');
    if (resultado) resultado.style.display = 'block';

    const botonConfirmar = document.getElementById('btn-confirmar-importacion');
    if (botonConfirmar) {
        botonConfirmar.disabled = validas <= 0;
        botonConfirmar.style.opacity = validas > 0 ? '1' : '.55';
    }

    if (validas === 0) {
        mostrarMensajeImportacion(
            'No hay filas válidas para importar. Corrige el archivo y vuelve a analizarlo.',
            'error'
        );
    } else if (errores > 0 || duplicados > 0) {
        mostrarMensajeImportacion(
            'La importación será PARCIAL: se crearán ' + validas
            + ' producto(s) válido(s) y se omitirán ' + (errores + duplicados)
            + ' fila(s) con error o duplicadas. Revisa el detalle antes de confirmar.',
            'warning'
        );
    } else {
        mostrarMensajeImportacion(
            'Todas las filas son válidas. Revisa el detalle y confirma para crear los productos.',
            'success'
        );
    }
}

function confirmarImportacionCsv() {
    if (!importacionCsvToken || importacionCsvValidas <= 0) {
        mostrarMensajeImportacion('No hay una vista previa válida para confirmar.', 'error');
        return;
    }

    Swal.fire({
        title: '¿Confirmar importación?',
        text: 'Se crearán ' + importacionCsvValidas + ' producto(s) nuevo(s). Los existentes no se modifican.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#47c363',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, importar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (!result.isConfirmed) return;

        const token = importacionCsvToken;
        const boton = document.getElementById('btn-confirmar-importacion');

        if (boton) {
            boton.disabled = true;
            boton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importando...';
        }

        const fd = new FormData();
        fd.append('_token', '{{ csrf_token() }}');
        fd.append('token', token);

        fetch('{{ route("catalogo.importar.confirmar") }}', {
            method: 'POST',
            body: fd,
            headers: { 'Accept': 'application/json' }
        })
        .then(async (res) => {
            const data = await res.json().catch(() => ({}));
            return { status: res.status, data };
        })
        .then(({ status, data }) => {
            if (status >= 400 || !data.exito) {
                const primerError = data.error
                    || (data.errors ? Object.values(data.errors)[0]?.[0] : null)
                    || data.message
                    || 'No se pudo guardar la importación.';
                throw new Error(primerError);
            }

            // El token ya fue consumido: no permitir un segundo envío.
            importacionCsvToken = null;
            importacionCsvValidas = 0;
            if (boton) {
                boton.disabled = true;
                boton.style.opacity = '.55';
                boton.innerHTML = '<i class="fas fa-check"></i> Importación procesada';
            }

            const importados = Number(data.importados || 0);
            const omitidos = Number(data.omitidos || 0);

            if (omitidos > 0) {
                mostrarMensajeImportacion(
                    'Importación parcial: ' + importados + ' producto(s) creado(s), '
                    + omitidos + ' fila(s) omitida(s). Revisa el detalle de cada fila.',
                    'warning'
                );
            } else {
                mostrarMensajeImportacion(
                    'Importación completada: ' + importados + ' producto(s) creado(s).',
                    'success'
                );
            }

            Swal.fire({
                title: importados > 0 ? '¡Importación completada!' : 'Sin productos importados',
                text: importados + ' producto(s) creado(s); ' + omitidos + ' fila(s) omitida(s).',
                icon: omitidos > 0 ? 'warning' : 'success'
            }).then(() => {
                if (importados > 0) {
                    location.reload();
                }
            });
        })
        .catch((error) => {
            // La vista previa es de un solo uso: si el servidor rechazó o no
            // pudo completar la confirmación, hay que analizar de nuevo antes
            // de reintentar. Así no se reenvía un token ya consumido.
            importacionCsvToken = null;
            importacionCsvValidas = 0;

            mostrarMensajeImportacion(
                (error.message || 'No se pudo guardar la importación.') + ' Analiza el archivo de nuevo.',
                'error'
            );

            if (boton) {
                boton.disabled = true;
                boton.style.opacity = '.55';
                boton.innerHTML = '<i class="fas fa-check"></i> Confirmar importación';
            }
        });
    });
}
</script>
@endsection