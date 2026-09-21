@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 1000px; margin: 0 auto;">
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center;">
        <span><i class="fas fa-address-book"></i> Directorio de Clientes</span>
        <button onclick="modalCliente('crear')" style="background-color: var(--color-success); color: white; border: none; padding: 6px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px;">
            <i class="fas fa-plus"></i> Nuevo Cliente
        </button>
    </div>
    <div style="padding: 30px;">
        
        {{-- BARRA DE BÚSQUEDA RÁPIDA DE CLIENTES --}}
        <div style="margin-bottom: 20px; display: flex; align-items: center; gap: 10px; background: #f8f9fa; padding: 12px 15px; border-radius: 6px; border: 1px solid #e3e6f0;">
            <i class="fas fa-search" style="color: #aeb4be; font-size: 16px;"></i>
            <input type="text" id="buscador-clientes" placeholder="Buscar por nombre, teléfono o identificación..." style="width: 100%; border: none; background: transparent; outline: none; font-size: 14px; color: #34395e;">
        </div>

        <div style="border-radius: 8px; border: 1px solid #e3e6f0; overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                <thead style="background-color: #f4f6f9; border-bottom: 1px solid #ddd;">
                    <tr>
                        <th style="padding: 12px 15px;">Nombre</th>
                        <th style="padding: 12px 15px;">Teléfono</th>
                        <th style="padding: 12px 15px;">RUC / Cédula</th>
                        <th style="padding: 12px 15px;">Dirección</th>
                        @if (in_array(auth()->user()->rol->nombre ?? '', ['ADMIN', 'GERENTE', 'CAJERO']))
                            <th style="padding: 12px 15px; text-align: center;">Acciones</th>
                        @endif
                    </tr>
                </thead>
                <tbody id="tabla-clientes">
                    @foreach($clientes as $c)
                    <tr style="border-bottom: 1px solid #f0f0f0;">
                        <td style="padding: 12px 15px; font-weight: 600;">{{ $c->nombre }}</td>
                        <td style="padding: 12px 15px;">{{ $c->telefono ?? '-' }}</td>
                        <td style="padding: 12px 15px;">{{ $c->identificacion ?? '-' }}</td>
                        <td style="padding: 12px 15px;">{{ $c->direccion ?? '-' }}</td>
                        
                        @if (in_array(auth()->user()->rol->nombre ?? '', ['ADMIN', 'GERENTE', 'CAJERO']))
                        <td style="padding: 12px 15px; text-align: center; white-space: nowrap;">
                            @if ($c->id != 1)
                                <div style="display: flex; justify-content: center; align-items: center; gap: 8px;">
                                    <button class="btn-editar-cliente" data-id="{{ $c->id }}" data-nombre="{{ $c->nombre }}" data-tel="{{ $c->telefono ?? '' }}" data-ident="{{ $c->identificacion ?? '' }}" data-dir="{{ $c->direccion ?? '' }}" style="background: transparent; color: #36b9cc; border: 1px solid #36b9cc; padding: 5px 10px; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 5px; font-size: 12px;">
                                        <i class="fas fa-edit"></i> Editar
                                    </button>
                                    
                                    <button class="btn-eliminar-cliente" data-id="{{ $c->id }}" style="background: transparent; color: #fc544b; border: 1px solid #fc544b; padding: 5px 10px; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 5px; font-size: 12px;">
                                        <i class="fas fa-trash-alt"></i> Eliminar
                                    </button>
                                </div>
                            @else
                                <span style="font-size: 11px; color: #aaa; font-weight: bold;">Fijo de Sistema</span>
                            @endif
                        </td>
                        @endif
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Buscador de Clientes en tiempo real
    const buscador = document.getElementById('buscador-clientes');
    const filas = document.querySelectorAll('#tabla-clientes tr');

    if (buscador) {
        buscador.addEventListener('input', function() {
            const textoBusqueda = this.value.toLowerCase().trim();
            filas.forEach(fila => {
                const contenidoFila = fila.textContent.toLowerCase();
                fila.style.display = contenidoFila.includes(textoBusqueda) ? '' : 'none';
            });
        });
    }

    document.body.addEventListener('click', function(e) {
        let btnEditar = e.target.closest('.btn-editar-cliente');
        let btnEliminar = e.target.closest('.btn-eliminar-cliente');

        if (btnEditar) {
            modalCliente(
                'editar',
                btnEditar.getAttribute('data-id'),
                btnEditar.getAttribute('data-nombre'),
                btnEditar.getAttribute('data-tel'),
                btnEditar.getAttribute('data-ident'),
                btnEditar.getAttribute('data-dir')
            );
        }

        if (btnEliminar) {
            eliminarCliente(btnEliminar.getAttribute('data-id'));
        }
    });
});

function modalCliente(accion, id = 0, nombre = '', tel = '', ident = '', dir = '') {
    if(tel === 'null') tel = '';
    if(ident === 'null') ident = '';
    if(dir === 'null') dir = '';

    Swal.fire({
        title: accion === 'crear' ? 'Nuevo Cliente' : 'Editar Cliente',
        html: `
            <div style="text-align: left; font-size: 13px; padding: 10px 0;">
                <div style="margin-bottom: 15px;">
                    <label style="font-weight:bold; color: var(--color-dark); display: block; margin-bottom: 5px;">Nombre Completo (*):</label>
                    <input id="cli-nombre" class="swal2-input" value="${nombre}" placeholder="Ej. Juan Pérez" style="width: 100%; margin: 0; height: 38px; box-sizing: border-box;">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight:bold; color: var(--color-dark); display: block; margin-bottom: 5px;">Teléfono:</label>
                    <input id="cli-tel" class="swal2-input" value="${tel}" placeholder="Ej. 8888-8888" style="width: 100%; margin: 0; height: 38px; box-sizing: border-box;">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight:bold; color: var(--color-dark); display: block; margin-bottom: 5px;">RUC / Cédula:</label>
                    <input id="cli-ident" class="swal2-input" value="${ident}" style="width: 100%; margin: 0; height: 38px; box-sizing: border-box;">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="font-weight:bold; color: var(--color-dark); display: block; margin-bottom: 5px;">Dirección:</label>
                    <input id="cli-dir" class="swal2-input" value="${dir}" style="width: 100%; margin: 0; height: 38px; box-sizing: border-box;">
                </div>
            </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonColor: '#47c363',
        confirmButtonText: '<i class="fas fa-save"></i> Guardar',
        cancelButtonText: 'Cancelar',
        preConfirm: () => {
            const nombreVal = document.getElementById('cli-nombre').value.trim();
            if(!nombreVal) {
                Swal.showValidationMessage('El nombre es obligatorio');
                return false;
            }
            return {
                nombre: nombreVal,
                telefono: document.getElementById('cli-tel').value,
                identificacion: document.getElementById('cli-ident').value,
                direccion: document.getElementById('cli-dir').value
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('_token', '{{ csrf_token() }}');
            formData.append('accion', accion);
            if (id > 0) formData.append('id', id);
            formData.append('nombre', result.value.nombre);
            formData.append('telefono', result.value.telefono);
            formData.append('identificacion', result.value.identificacion);
            formData.append('direccion', result.value.direccion);

            fetch('{{ route("clientes.api") }}', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    Swal.fire('¡Listo!', data.mensaje, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.error, 'error');
                }
            });
        }
    });
}

function eliminarCliente(id) {
    Swal.fire({
        title: '¿Eliminar cliente?',
        text: "El cliente se desactivará.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#fc544b',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('_token', '{{ csrf_token() }}');
            formData.append('accion', 'eliminar');
            formData.append('id', id);

            fetch('{{ route("clientes.api") }}', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.exito) location.reload();
                else Swal.fire('Error', data.error, 'error');
            });
        }
    });
}
</script>
@endsection