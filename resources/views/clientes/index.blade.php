@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 1000px; margin: 0 auto;">
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center;">
        <span><i class="fas fa-address-book"></i> Directorio de Clientes</span>
        @if (in_array(auth()->user()->rol->nombre ?? '', ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'], true))
        <button onclick="modalCliente('crear')" style="background-color: var(--color-success); color: white; border: none; padding: 6px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px;">
            <i class="fas fa-plus"></i> Nuevo Cliente
        </button>
        @endif
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

        {{-- CLIENTES ELIMINADOS (activo=0): se consultan aparte y se reactivan --}}
        @php $puedeGestionarClientes = in_array(auth()->user()->rol->nombre ?? '', ['ADMIN', 'GERENTE'], true); @endphp
        <div style="margin-top: 25px;">
            <button type="button" onclick="toggleEliminadosClientes()" style="background: #f8f9fa; color: #34395e; border: 1px solid #e3e6f0; padding: 8px 14px; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 13px;">
                <i class="fas fa-trash-restore"></i> Ver clientes eliminados ({{ $clientesEliminados->count() }})
            </button>

            <div id="panel-eliminados-clientes" style="display: none; margin-top: 15px; border-radius: 8px; border: 1px solid #f5c2c7; overflow: hidden;">
                <div style="background: #fdecea; padding: 10px 15px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid #f5c2c7;">
                    <i class="fas fa-search" style="color: #aeb4be; font-size: 15px;"></i>
                    <input type="text" id="buscador-eliminados-clientes" placeholder="Buscar cliente eliminado por nombre, teléfono o identificación..." style="width: 100%; border: none; background: transparent; outline: none; font-size: 14px; color: #34395e;">
                </div>
                <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                    <thead style="background-color: #f4f6f9; border-bottom: 1px solid #ddd;">
                        <tr>
                            <th style="padding: 12px 15px;">Nombre</th>
                            <th style="padding: 12px 15px;">Teléfono</th>
                            <th style="padding: 12px 15px;">RUC / Cédula</th>
                            <th style="padding: 12px 15px;">Dirección</th>
                            @if ($puedeGestionarClientes)
                                <th style="padding: 12px 15px; text-align: center;">Acciones</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody id="tabla-eliminados-clientes">
                        @forelse($clientesEliminados as $c)
                        <tr style="border-bottom: 1px solid #f0f0f0; background: #fffdfd;">
                            <td style="padding: 12px 15px; font-weight: 600;">{{ $c->nombre }}</td>
                            <td style="padding: 12px 15px;">{{ $c->telefono ?? '-' }}</td>
                            <td style="padding: 12px 15px;">{{ $c->identificacion ?? '-' }}</td>
                            <td style="padding: 12px 15px;">{{ $c->direccion ?? '-' }}</td>
                            @if ($puedeGestionarClientes)
                            <td style="padding: 12px 15px; text-align: center; white-space: nowrap;">
                                <button class="btn-reactivar-cliente" data-id="{{ $c->id }}" style="background: transparent; color: #47c363; border: 1px solid #47c363; padding: 5px 10px; border-radius: 4px; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; font-size: 12px;">
                                    <i class="fas fa-undo"></i> Reactivar
                                </button>
                            </td>
                            @endif
                        </tr>
                        @empty
                        <tr><td colspan="{{ $puedeGestionarClientes ? 5 : 4 }}" style="padding: 15px; text-align: center; color: #aaa;">No hay clientes eliminados.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
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

    // Buscador del listado de clientes eliminados.
    const buscadorEliminados = document.getElementById('buscador-eliminados-clientes');
    if (buscadorEliminados) {
        buscadorEliminados.addEventListener('input', function() {
            const texto = this.value.toLowerCase().trim();
            document.querySelectorAll('#tabla-eliminados-clientes tr').forEach(fila => {
                fila.style.display = fila.textContent.toLowerCase().includes(texto) ? '' : 'none';
            });
        });
    }

    document.body.addEventListener('click', function(e) {
        let btnEditar = e.target.closest('.btn-editar-cliente');
        let btnEliminar = e.target.closest('.btn-eliminar-cliente');
        let btnReactivar = e.target.closest('.btn-reactivar-cliente');

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

        if (btnReactivar) {
            reactivarCliente(btnReactivar.getAttribute('data-id'));
        }
    });
});

// Muestra u oculta el panel de clientes eliminados.
function toggleEliminadosClientes() {
    const panel = document.getElementById('panel-eliminados-clientes');
    if (panel) {
        panel.style.display = (panel.style.display === 'none' || panel.style.display === '') ? 'block' : 'none';
    }
}

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

// Reactiva un cliente eliminado (mismo registro, sin duplicar).
function reactivarCliente(id) {
    Swal.fire({
        title: '¿Reactivar cliente?',
        text: 'El cliente volverá a aparecer en el directorio.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#47c363',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Sí, reactivar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('_token', '{{ csrf_token() }}');
            formData.append('accion', 'reactivar');
            formData.append('id', id);

            fetch('{{ route("clientes.api") }}', { method: 'POST', body: formData })
            .then(res => res.json())
            .then(data => {
                if (data.exito) {
                    Swal.fire('¡Listo!', data.mensaje || 'Cliente reactivado.', 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', data.error, 'error');
                }
            });
        }
    });
}
</script>
@endsection