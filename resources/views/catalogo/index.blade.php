@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 1100px; margin: 0 auto;">
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center;">
        <span><i class="fas fa-utensils"></i> Menú y Catálogo</span>
        <div>
            <button onclick="modalCategoria('crear')" style="background-color: #6777ef; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px; margin-right: 10px;">
                <i class="fas fa-tags"></i> + Categoría
            </button>
            <button onclick="modalProducto('crear')" style="background-color: var(--color-success); color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px;">
                <i class="fas fa-hamburger"></i> + Producto
            </button>
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
                        <th style="padding: 12px 15px; text-align: center;">Acciones</th>
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
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
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

    document.body.addEventListener('click', function(e) {
        let btnEditar = e.target.closest('.btn-editar-producto');
        let btnEliminar = e.target.closest('.btn-eliminar-producto');

        if (btnEditar) {
            modalProducto('editar', btnEditar.getAttribute('data-id'), btnEditar.getAttribute('data-cat'), btnEditar.getAttribute('data-nombre'), btnEditar.getAttribute('data-precio'), btnEditar.getAttribute('data-rc'));
        }
        if (btnEliminar) {
            eliminarRegistro('eliminar_producto', btnEliminar.getAttribute('data-id'));
        }
    });
});

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
            .then(data => data.exito ? location.reload() : Swal.fire('Error', data.error, 'error'));
        }
    });
}
</script>
@endsection