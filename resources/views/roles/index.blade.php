@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 900px; margin: 0 auto;">
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
                        <th style="padding: 12px 15px;">Estado</th>
                        <th style="padding: 12px 15px; text-align: center;">Acción</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rolesOficiales as $r)
                    <tr style="border-bottom: 1px solid #f0f0f0;">
                        <td style="padding: 12px 15px; font-weight: 600;">{{ $r['id'] }}</td>
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
                        <td style="padding: 12px 15px; text-align: center; white-space: nowrap;">
                            @if ($r['existe'])
                                <span style="font-size: 11px; color: #aaa; font-weight: bold;">—</span>
                            @elseif ($r['nombre'] === 'ADMIN')
                                {{-- ADMIN es parte del catálogo, pero nunca se crea desde aquí. --}}
                                <span style="font-size: 11px; color: #aaa; font-weight: bold;">—</span>
                            @else
                                <button class="btn-crear-rol" data-nombre="{{ $r['nombre'] }}" style="background: transparent; color: #3f9ce8; border: 1px solid #3f9ce8; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                    <i class="fas fa-plus"></i> Crear
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
});
</script>
@endsection
