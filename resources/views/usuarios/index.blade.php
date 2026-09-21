@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 1000px; margin: 0 auto;">
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center;">
        <span><i class="fas fa-users"></i> Gestión de Usuarios del Sistema</span>
        <button onclick="modalUsuario()" style="background-color: var(--color-success); color: white; border: none; padding: 6px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px;">
            <i class="fas fa-user-plus"></i> Nuevo Usuario
        </button>
    </div>
    
    <div style="padding: 30px;">
        {{-- Buscador rápido --}}
        <div style="margin-bottom: 20px; display: flex; align-items: center; gap: 10px; background: #f8f9fa; padding: 12px 15px; border-radius: 6px; border: 1px solid #e3e6f0;">
            <i class="fas fa-search" style="color: #aeb4be; font-size: 16px;"></i>
            <input type="text" id="buscador-usuarios" placeholder="Buscar por nombre, usuario o rol..." style="width: 100%; border: none; background: transparent; outline: none; font-size: 14px; color: #34395e;">
        </div>

        <div style="border-radius: 8px; border: 1px solid #e3e6f0; overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                <thead style="background-color: #f4f6f9; border-bottom: 1px solid #ddd;">
                    <tr>
                        <th style="padding: 12px 15px;">Nombre Completo</th>
                        <th style="padding: 12px 15px;">Usuario (Login)</th>
                        <th style="padding: 12px 15px;">Rol de Sistema</th>
                        <th style="padding: 12px 15px; text-align: center;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="tabla-usuarios">
                    @foreach($usuarios as $u)
                    <tr style="border-bottom: 1px solid #f0f0f0;">
                        <td style="padding: 12px 15px; font-weight: 600;">{{ $u->nombre }}</td>
                        <td style="padding: 12px 15px;">{{ $u->username }}</td>
                        <td style="padding: 12px 15px;">
                            <span style="background: #e3eaef; padding: 3px 8px; border-radius: 12px; font-size: 11px; color: #4b5d67; font-weight: bold;">
                                {{ $u->rol }}
                            </span>
                        </td>
                        <td style="padding: 12px 15px; text-align: center; white-space: nowrap;">
                            @if ($u->id != auth()->id())
                                <button class="btn-eliminar-usuario" data-id="{{ $u->id }}" style="background: transparent; color: #fc544b; border: 1px solid #fc544b; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                    <i class="fas fa-trash-alt"></i> Eliminar
                                </button>
                            @else
                                <span style="font-size: 11px; color: #aaa; font-weight: bold;">Tu Sesión</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<script id="roles-json" type="application/json">
    {!! json_encode($roles) !!}
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const rolesData = JSON.parse(document.getElementById('roles-json').textContent);
    
    // Buscador en tiempo real
    const buscador = document.getElementById('buscador-usuarios');
    const filas = document.querySelectorAll('#tabla-usuarios tr');
    if (buscador) {
        buscador.addEventListener('input', function() {
            const texto = this.value.toLowerCase().trim();
            filas.forEach(f => {
                f.style.display = f.textContent.toLowerCase().includes(texto) ? '' : 'none';
            });
        });
    }

    // Botón eliminar
    document.body.addEventListener('click', function(e) {
        let btn = e.target.closest('.btn-eliminar-usuario');
        if (btn) {
            let id = btn.getAttribute('data-id');
            Swal.fire({
                title: '¿Eliminar usuario?',
                text: "Esta acción no se puede deshacer.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#fc544b',
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar'
            }).then((res) => {
                if (res.isConfirmed) {
                    const fd = new FormData();
                    fd.append('_token', '{{ csrf_token() }}');
                    fd.append('accion', 'eliminar');
                    fd.append('id', id);

                    fetch('{{ route("usuarios.api") }}', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(d => {
                        if (d.exito) location.reload();
                        else Swal.fire('Error', d.error, 'error');
                    });
                }
            });
        }
    });
});

function modalUsuario() {
    let rolesOptions = '';
    const roles = JSON.parse(document.getElementById('roles-json').textContent);
    roles.forEach(r => {
        rolesOptions += `<option value="${r.id}">${r.nombre}</option>`;
    });

    Swal.fire({
        title: 'Nuevo Usuario',
        html: `
            <div style="text-align: left; font-size: 13px; padding: 10px 0;">
                <div style="margin-bottom: 15px;">
                    <label style="font-weight:bold; display:block; margin-bottom: 5px;">Nombre Completo:</label>
                    <input id="u-nombre" class="swal2-input" placeholder="Ej. Juan Pérez" style="width: 100%; margin: 0; height: 38px; box-sizing: border-box;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="font-weight:bold; display:block; margin-bottom: 5px;">Usuario (Login):</label>
                    <input id="u-username" class="swal2-input" placeholder="Ej. jperez" style="width: 100%; margin: 0; height: 38px; box-sizing: border-box;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="font-weight:bold; display:block; margin-bottom: 5px;">Contraseña:</label>
                    <input id="u-pass" type="password" class="swal2-input" placeholder="******" style="width: 100%; margin: 0; height: 38px; box-sizing: border-box;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="font-weight:bold; display:block; margin-bottom: 5px;">Rol de Sistema:</label>
                    <select id="u-rol" class="swal2-input" style="width: 100%; margin: 0; height: 38px; box-sizing: border-box;">${rolesOptions}</select>
                </div>
            </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonColor: '#47c363',
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        preConfirm: () => {
            return {
                nombre: document.getElementById('u-nombre').value.trim(),
                username: document.getElementById('u-username').value.trim(),
                password: document.getElementById('u-pass').value,
                rol_id: document.getElementById('u-rol').value
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            const val = result.value;
            if (!val.nombre || !val.username || !val.password) {
                Swal.showValidationMessage('Todos los campos son obligatorios');
                return;
            }

            const fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            fd.append('accion', 'crear');
            fd.append('nombre', val.nombre);
            fd.append('username', val.username);
            fd.append('password', val.password);
            fd.append('rol_id', val.rol_id);

            fetch('{{ route("usuarios.api") }}', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                if (d.exito) {
                    Swal.fire('¡Éxito!', d.mensaje, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', d.error, 'error');
                }
            });
        }
    });
}
</script>
@endsection