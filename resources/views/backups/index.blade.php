@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 1100px; margin: 0 auto;">
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <span><i class="fas fa-database"></i> Copias de Seguridad</span>
        <button id="btn-crear-backup" style="background-color: var(--color-success); color: white; border: none; padding: 6px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px;">
            <i class="fas fa-save"></i> Crear Copia
        </button>
    </div>

    <div class="backups-contenido" style="padding: 30px;">

        {{-- Estado de la herramienta y configuración --}}
        <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px;">
            <div style="flex: 1; min-width: 220px; background: #f8f9fa; border: 1px solid #e3e6f0; border-radius: 6px; padding: 12px 15px; font-size: 13px;">
                <strong>Base de datos:</strong> {{ $baseDatos }}
            </div>
            <div style="flex: 1; min-width: 220px; background: #f8f9fa; border: 1px solid #e3e6f0; border-radius: 6px; padding: 12px 15px; font-size: 13px;">
                <strong>Generación de respaldos:</strong>
                @if ($herramientaDisponible)
                    <span style="color: var(--color-success); font-weight: bold;">Disponible</span>
                    <div style="font-size: 12px; color: #888; margin-top: 4px;">
                        Se usa <code>mysqldump</code> cuando está instalado y, si no, el generador PHP/PDO integrado.
                    </div>
                @else
                    <span style="color: #fc544b; font-weight: bold;">No disponible</span>
                    <div style="font-size: 12px; color: #888; margin-top: 4px;">
                        Comprueba la conexión de base de datos configurada. <code>mysqldump</code> es opcional: el generador PHP/PDO integrado no requiere herramientas externas.
                    </div>
                @endif
            </div>
            <div style="flex: 1; min-width: 220px; background: #f8f9fa; border: 1px solid #e3e6f0; border-radius: 6px; padding: 12px 15px; font-size: 13px;">
                <strong>Almacenamiento:</strong> privado (<code>storage/app/private/backups</code>)
            </div>
        </div>

        {{-- Nota de integridad / recuperación --}}
        <div style="background: #fff7e6; border: 1px solid #f5d9a8; border-radius: 6px; padding: 12px 15px; font-size: 12.5px; color: #7a5a1a; margin-bottom: 20px; line-height: 1.5;">
            <i class="fas fa-info-circle"></i> <strong>Importante:</strong> un hash SHA-256 demuestra que el archivo coincide con el contenido registrado, pero no garantiza por sí solo que sea restaurable.
            La restauración se documenta y se prueba únicamente en un entorno aislado (<code>docs/BACKUP.md</code>). No existe botón de restauración en producción.
        </div>

        {{-- Mensajes de la operación --}}
        <div id="mensaje-backup" style="display: none; margin-bottom: 20px; padding: 12px 15px; border-radius: 6px; font-size: 13px;"></div>

        {{-- Historial --}}
        <div style="border-radius: 8px; border: 1px solid #e3e6f0; overflow: hidden;">
            <div style="padding: 12px 15px; background: #f4f6f9; border-bottom: 1px solid #ddd; font-weight: bold; font-size: 13px;">
                <i class="fas fa-history"></i> Historial de respaldos
            </div>

            @if ($historial->isEmpty())
                <div style="padding: 30px; text-align: center; color: #888; font-size: 13px;">
                    Todavía no hay respaldos registrados. Crea la primera copia con el botón superior.
                </div>
            @else
                <div class="backups-tabla-scroll">
                <table style="width: 100%; border-collapse: collapse; font-size: 12.5px; text-align: left;">
                    <thead style="background-color: #f4f6f9; border-bottom: 1px solid #ddd;">
                        <tr>
                            <th style="padding: 10px 12px;">#</th>
                            <th style="padding: 10px 12px;">Fecha / Usuario</th>
                            <th style="padding: 10px 12px;">Archivo</th>
                            <th style="padding: 10px 12px;">Tamaño</th>
                            <th style="padding: 10px 12px;">Estado</th>
                            <th style="padding: 10px 12px;">Resultado</th>
                            <th style="padding: 10px 12px; text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($historial as $h)
                        <tr style="border-bottom: 1px solid #f0f0f0; vertical-align: middle;">
                            <td style="padding: 10px 12px;">{{ $h->id }}</td>
                            <td style="padding: 10px 12px; white-space: nowrap;">
                                {{ $h->iniciado_en ? \Illuminate\Support\Carbon::parse($h->iniciado_en)->format('d/m/Y H:i:s') : '—' }}
                                <div style="font-size: 11px; color: #888;">Usuario ID: {{ $h->usuario_id ?? '—' }}</div>
                            </td>
                            <td style="padding: 10px 12px; word-break: break-all;">
                                <code style="font-size: 11px;">{{ $h->nombre_archivo ?? '—' }}</code>
                                @if ($h->hash_sha256)
                                    <div style="font-size: 10px; color: #888;" title="SHA-256">sha256: {{ substr($h->hash_sha256, 0, 16) }}…</div>
                                @endif
                            </td>
                            <td style="padding: 10px 12px; white-space: nowrap;">
                                {{ $h->tamano_bytes ? number_format($h->tamano_bytes) . ' B' : '—' }}
                                @if ($h->duracion_ms !== null)
                                    <div style="font-size: 11px; color: #888;">({{ number_format($h->duracion_ms / 1000, 2) }} s)</div>
                                @endif
                            </td>
                            <td style="padding: 10px 12px;">
                                @if ($h->estado === 'COMPLETADO')
                                    <span style="background: #d4edda; color: #155724; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">COMPLETADO</span>
                                @elseif ($h->estado === 'EN_PROCESO')
                                    <span style="background: #fff3cd; color: #856404; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">EN_PROCESO</span>
                                @elseif ($h->estado === 'FALLIDO')
                                    <span style="background: #f8d7da; color: #721c24; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">FALLIDO</span>
                                @else
                                    <span style="background: #e2e3e5; color: #383d41; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">{{ $h->estado }}</span>
                                @endif
                            </td>
                            <td style="padding: 10px 12px; max-width: 260px; word-break: break-word;">{{ $h->mensaje ?? '—' }}</td>
                            <td style="padding: 10px 12px; text-align: center; white-space: nowrap;">
                                @if ($h->estado === 'COMPLETADO')
                                    <a href="{{ route('backups.descargar', $h->id) }}"
                                       style="background: transparent; color: #34395e; border: 1px solid #34395e; padding: 4px 10px; border-radius: 4px; font-size: 12px; text-decoration: none;">
                                        <i class="fas fa-download"></i> Descargar
                                    </a>
                                    <button class="btn-eliminar-backup" data-id="{{ $h->id }}"
                                            style="background: transparent; color: #fc544b; border: 1px solid #fc544b; padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; margin-left: 4px;">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
                                @else
                                    <span style="font-size: 11px; color: #aaa;">—</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @endif
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('btn-crear-backup');
    const divMensaje = document.getElementById('mensaje-backup');

    function mostrarMensaje(ok, texto) {
        divMensaje.style.display = 'block';
        divMensaje.style.background = ok ? '#d4edda' : '#f8d7da';
        divMensaje.style.border = '1px solid ' + (ok ? '#c3e6cb' : '#f5c6cb');
        divMensaje.style.color = ok ? '#155724' : '#721c24';
        divMensaje.innerHTML = '<i class="fas fa-' + (ok ? 'check-circle' : 'times-circle') + '"></i> ' + texto;
    }

    if (btn) {
        btn.addEventListener('click', function () {
            Swal.fire({
                title: '¿Crear copia de seguridad?',
                text: 'Se generará un volcado de la base de datos. La operación se registrará.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#47c363',
                confirmButtonText: 'Sí, crear',
                cancelButtonText: 'Cancelar'
            }).then((res) => {
                if (!res.isConfirmed) return;

                btn.disabled = true;
                divMensaje.style.display = 'none';

                const fd = new FormData();
                fd.append('_token', '{{ csrf_token() }}');

                fetch('{{ route("backups.crear") }}', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(d => {
                        mostrarMensaje(d.exito, d.exito ? d.mensaje : d.error);
                        if (d.exito) setTimeout(() => location.reload(), 1200);
                        else btn.disabled = false;
                    })
                    .catch(() => {
                        mostrarMensaje(false, 'No fue posible contactar el servidor.');
                        btn.disabled = false;
                    });
            });
        });
    }

    // Eliminación manual con confirmación
    document.body.addEventListener('click', function (e) {
        const el = e.target.closest('.btn-eliminar-backup');
        if (!el) return;

        const id = el.getAttribute('data-id');

        Swal.fire({
            title: '¿Eliminar esta copia?',
            text: 'Solo ADMIN o GERENTE pueden eliminar. La copia más reciente está protegida y no se puede eliminar.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#fc544b',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar'
        }).then((res) => {
            if (!res.isConfirmed) return;

            // El token CSRF se envía en la cabecera X-CSRF-TOKEN: para una
            // petición DELETE con multipart/form-data, PHP no rellena $_POST,
            // por lo que un token colocado solo en el cuerpo nunca lo recibe
            // Laravel y el middleware responde 419. La cabecera sí se lee.
            fetch('{{ route("backups.eliminar", "ID") }}'.replace('ID', id), {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(r => r.json())
                .then(d => {
                    mostrarMensaje(d.exito, d.exito ? d.mensaje : d.error);
                    if (d.exito) setTimeout(() => location.reload(), 1200);
                })
                .catch(() => {
                    mostrarMensaje(false, 'No fue posible eliminar la copia. Comprueba tu sesión e inténtalo de nuevo.');
                });
        });
    });
});
</script>
@endsection