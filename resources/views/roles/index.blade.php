@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 1000px; margin: 0 auto;">
    <div class="monitor-header">
        <span><i class="fas fa-user-shield"></i> Catálogo de Roles del Sistema</span>
    </div>

    <div style="padding: 30px;">

        {{-- ===================== ROLES OFICIALES ===================== --}}
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <h3 style="margin: 0; font-size: 15px; color: #34395e;">
                <i class="fas fa-list-ol" style="color: #3f9ce8;"></i> Roles oficiales
            </h3>
            <span style="font-size: 12px; color: #6c757d;">
                {{ count($rolesOficiales) }} roles en el catálogo oficial
            </span>
        </div>

        <div style="border-radius: 8px; border: 1px solid #e3e6f0; overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                <thead style="background-color: #f4f6f9; border-bottom: 1px solid #ddd;">
                    <tr>
                        <th style="padding: 12px 15px; width: 90px;">ID esperado</th>
                        <th style="padding: 12px 15px;">Nombre</th>
                        <th style="padding: 12px 15px;">En catálogo</th>
                        <th style="padding: 12px 15px; text-align: center;">Usuarios</th>
                        <th style="padding: 12px 15px;">Activación</th>
                        <th style="padding: 12px 15px; text-align: center;">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rolesOficiales as $r)
                    <tr style="border-bottom: 1px solid #f0f0f0;">
                        <td style="padding: 12px 15px; font-weight: 600;">{{ $r['id_esperado'] }}</td>
                        <td style="padding: 12px 15px; font-weight: 600;">{{ $r['nombre'] }}</td>
                        <td style="padding: 12px 15px;">
                            @if ($r['existe'])
                                <span style="background: #e8f8ef; padding: 3px 8px; border-radius: 12px; font-size: 11px; color: #1e7e46; font-weight: bold;">
                                    <i class="fas fa-check-circle"></i> REGISTRADO
                                </span>
                            @else
                                <span style="background: #fdecea; padding: 3px 8px; border-radius: 12px; font-size: 11px; color: #c0392b; font-weight: bold;">
                                    <i class="fas fa-exclamation-circle"></i> FALTANTE
                                </span>
                            @endif
                        </td>
                        <td style="padding: 12px 15px; text-align: center; font-weight: 600; color: #34395e;">
                            @if ($r['existe'])
                                {{ $r['usuarios'] }}
                            @else
                                <span style="color: #aaa;">—</span>
                            @endif
                        </td>
                        <td style="padding: 12px 15px;">
                            @if (! $r['existe'])
                                <span style="font-size: 11px; color: #aaa; font-weight: bold;">—</span>
                            @elseif ($r['activo'])
                                <span style="background: #e8f8ef; padding: 3px 8px; border-radius: 12px; font-size: 11px; color: #1e7e46; font-weight: bold;">
                                    <i class="fas fa-toggle-on"></i> ACTIVO
                                </span>
                            @else
                                <span style="background: #fdecea; padding: 3px 8px; border-radius: 12px; font-size: 11px; color: #c0392b; font-weight: bold;">
                                    <i class="fas fa-toggle-off"></i> INACTIVO
                                </span>
                            @endif
                        </td>
                        <td style="padding: 12px 15px; text-align: center; white-space: nowrap;">
                            @if (! $r['existe'])
                                @if ($r['nombre'] === 'ADMIN')
                                    {{-- ADMIN forma parte del catálogo, pero nunca se crea desde aquí. --}}
                                    <span style="font-size: 11px; color: #aaa; font-weight: bold;">—</span>
                                @else
                                    <button class="btn-crear-rol" data-nombre="{{ $r['nombre'] }}" style="background: transparent; color: #3f9ce8; border: 1px solid #3f9ce8; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                        <i class="fas fa-plus"></i> Crear
                                    </button>
                                @endif
                            @elseif ($r['nombre'] === 'ADMIN')
                                {{-- ADMIN siempre operativo: no puede desactivarse. --}}
                                <span style="font-size: 11px; color: #1e7e46; font-weight: bold;" title="El sistema necesita siempre un administrador operativo.">
                                    <i class="fas fa-lock"></i> Protegido
                                </span>
                            @elseif (! $tieneEstado)
                                <span style="font-size: 11px; color: #8a6d3b; font-weight: bold;">
                                    Requiere migración
                                </span>
                            @elseif ($r['activo'])
                                <button class="btn-estado-rol" data-id="{{ $r['id'] }}" data-nombre="{{ $r['nombre'] }}"
                                        data-usuarios="{{ $r['usuarios'] }}" data-activo="1"
                                        style="background: transparent; color: #c0392b; border: 1px solid #c0392b; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                    <i class="fas fa-toggle-off"></i> Desactivar
                                </button>
                            @else
                                <button class="btn-estado-rol" data-id="{{ $r['id'] }}" data-nombre="{{ $r['nombre'] }}" data-usuarios="{{ $r['usuarios'] }}" data-activo="0" style="background: transparent; color: #1e7e46; border: 1px solid #1e7e46; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                    <i class="fas fa-toggle-on"></i> Reactivar
                                </button>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- ===================== RESUMEN ===================== --}}
        @if ($catalogoCompleto)
            <div style="margin-top: 20px; background: #e8f8ef; border: 1px solid #b7e4c7; border-radius: 6px; padding: 15px; color: #1e7e46; font-size: 13px;">
                <i class="fas fa-check-circle"></i>
                El catálogo de roles está completo. No hay roles oficiales faltantes y la creación de nuevos roles está deshabilitada.
            </div>
        @else
            <div style="margin-top: 20px; background: #fff8e1; border: 1px solid #ffe08a; border-radius: 6px; padding: 15px; color: #8a6d3b; font-size: 13px;">
                <i class="fas fa-exclamation-triangle"></i>
                Faltan {{ count($faltantes) }} {{ count($faltantes) === 1 ? 'rol oficial' : 'roles oficiales' }}. Use el botón «Crear» para registrar solo los roles del catálogo oficial.
            </div>
        @endif

        {{-- ===================== NOTA SOBRE DESACTIVACIÓN ===================== --}}
        <div style="margin-top: 15px; background: #eef4fb; border: 1px solid #cfe0f3; border-radius: 6px; padding: 15px; color: #34527a; font-size: 12.5px;">
            <i class="fas fa-info-circle"></i>
            <strong>Desactivar un rol</strong> no borra el rol ni reasigna usuarios: conserva su ID y las asignaciones
            existentes. Mientras esté inactivo, los usuarios con ese rol no podrán iniciar sesión ni seguir operando.
            Puede revertirse con «Reactivar». El rol ADMIN no puede desactivarse.
            <br>
            <span style="color: #6c757d;">
                Se guarda quién y cuándo realizó la última desactivación y la última reactivación. No es un historial
                completo de todas las operaciones.
            </span>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Escucha delegada: funciona con las filas generadas por Blade.
    document.body.addEventListener('click', function(e) {
        let btn = e.target.closest('.btn-crear-rol');
        if (!btn) return;

        const nombre = btn.getAttribute('data-nombre');

        Swal.fire({
            title: '¿Registrar rol?',
            // `text:` se interpreta como texto plano, no como HTML.
            text: 'Se registrará el rol oficial "' + nombre + '".',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3f9ce8',
            confirmButtonText: 'Sí, registrar',
            cancelButtonText: 'Cancelar'
        }).then((res) => {
            if (!res.isConfirmed) return;

            const fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            fd.append('accion', 'crear');
            fd.append('nombre', nombre);

            fetch('{{ route("roles.api") }}', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.exito) {
                        Swal.fire('¡Listo!', d.mensaje || 'Rol registrado.', 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Error', d.error || 'No se pudo registrar el rol.', 'error');
                    }
                })
                .catch(() => Swal.fire('Error', 'No se pudo conectar con el servidor.', 'error'));
        });
    });

    // Escucha delegada para desactivar / reactivar un rol.
    document.body.addEventListener('click', function(e) {
        let btn = e.target.closest('.btn-estado-rol');
        if (!btn) return;

        const id = btn.getAttribute('data-id');
        const nombre = btn.getAttribute('data-nombre');
        const usuarios = parseInt(btn.getAttribute('data-usuarios') || '0', 10);
        const desactivar = btn.getAttribute('data-activo') === '1';

        // Advertencia clara: se indica cuántos usuarios están asociados y qué
        // les ocurre. No se borra nada ni se reasigna a nadie.
        let advertencia = desactivar
            ? 'El rol no se borra y no se reasigna a ningún usuario: conserva su ID y las asignaciones existentes.'
            : 'El rol volverá a estar disponible y sus usuarios recuperarán el acceso. No se modifica ningún usuario.';

        if (desactivar) {
            advertencia += ' Los usuarios con este rol (' + usuarios + ') no podrán iniciar sesión ni operar mientras siga inactivo.';
        }

        Swal.fire({
            title: '¿' + (desactivar ? 'Desactivar' : 'Reactivar') + ' el rol ' + nombre + '?',
            text: advertencia,
            icon: desactivar ? 'warning' : 'question',
            showCancelButton: true,
            confirmButtonColor: desactivar ? '#fc544b' : '#1e7e46',
            confirmButtonText: desactivar ? 'Sí, desactivar' : 'Sí, reactivar',
            cancelButtonText: 'Cancelar'
        }).then((res) => {
            if (!res.isConfirmed) return;

            const fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            fd.append('accion', desactivar ? 'desactivar' : 'reactivar');
            fd.append('id', id);

            fetch('{{ route("roles.api") }}', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.exito) {
                        let ok = d.mensaje || 'Operación realizada.';
                        if (d.operador) {
                            ok += ' Acción ejecutada por ' + d.operador + '.';
                        }
                        Swal.fire('¡Listo!', ok, 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Error', d.error || 'No se pudo completar la operación.', 'error');
                    }
                })
                .catch(() => Swal.fire('Error', 'No se pudo conectar con el servidor.', 'error'));
        });
    });
});
</script>
@endsection
