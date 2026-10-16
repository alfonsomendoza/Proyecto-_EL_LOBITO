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
        {{-- Filtros: la búsqueda y los filtros se resuelven en el servidor y se
             aplican a las dos secciones (activos y eliminados). --}}
        <form method="GET" action="{{ route('usuarios.index') }}" style="margin-bottom: 20px; background: #f8f9fa; padding: 12px 15px; border-radius: 6px; border: 1px solid #e3e6f0; display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
            <div style="display: flex; align-items: center; gap: 8px; flex: 1 1 240px;">
                <i class="fas fa-search" style="color: #aeb4be; font-size: 15px;"></i>
                <input type="text" name="q" value="{{ $filtros['q'] }}" placeholder="Buscar por nombre o usuario..." style="width: 100%; border: 1px solid #e3e6f0; background: #fff; border-radius: 4px; padding: 8px 10px; font-size: 13px; color: #34395e; outline: none;">
            </div>

            <select name="rol" style="border: 1px solid #e3e6f0; background: #fff; border-radius: 4px; padding: 8px 10px; font-size: 13px; color: #34395e; outline: none;">
                <option value="">Todos los roles</option>
                @foreach($roles as $r)
                    <option value="{{ $r->nombre }}" @selected($filtros['rol'] === strtoupper($r->nombre))>{{ $r->nombre }}</option>
                @endforeach
            </select>

            <select name="estado" style="border: 1px solid #e3e6f0; background: #fff; border-radius: 4px; padding: 8px 10px; font-size: 13px; color: #34395e; outline: none;">
                <option value="todos" @selected($filtros['estado'] === 'todos')>Activos y eliminados</option>
                <option value="activos" @selected($filtros['estado'] === 'activos')>Solo activos</option>
                <option value="eliminados" @selected($filtros['estado'] === 'eliminados')>Solo eliminados</option>
            </select>

            <button type="submit" style="background-color: #3f9ce8; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px;">
                <i class="fas fa-filter"></i> Filtrar
            </button>
            <a href="{{ route('usuarios.index') }}" style="background: #fff; color: #6c757d; border: 1px solid #e3e6f0; padding: 8px 16px; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 13px; text-decoration: none;">
                <i class="fas fa-times"></i> Limpiar
            </a>
        </form>

        {{-- ===================== SECCIÓN A: USUARIOS ACTIVOS ===================== --}}
        @if ($mostrarActivos)
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <h3 style="margin: 0; font-size: 15px; color: #34395e;">
                <i class="fas fa-user-check" style="color: #1e7e46;"></i> Usuarios activos
            </h3>
            <span style="font-size: 12px; color: #6c757d;">
                {{ $activos->count() }} {{ $activos->count() === 1 ? 'usuario' : 'usuarios' }}
            </span>
        </div>

        <div style="border-radius: 8px; border: 1px solid #e3e6f0; overflow: hidden;">
            <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                <thead style="background-color: #f4f6f9; border-bottom: 1px solid #ddd;">
                    <tr>
                        <th style="padding: 12px 15px;">Nombre Completo</th>
                        <th style="padding: 12px 15px;">Usuario (Login)</th>
                        <th style="padding: 12px 15px;">Rol de Sistema</th>
                        <th style="padding: 12px 15px;">Estado</th>
                        <th style="padding: 12px 15px; text-align: center;">Acciones</th>
                    </tr>
                </thead>
                <tbody id="tabla-usuarios">
                    @forelse($activos as $u)
                    <tr style="border-bottom: 1px solid #f0f0f0;">
                        <td style="padding: 12px 15px; font-weight: 600;">{{ $u->nombre }}</td>
                        <td style="padding: 12px 15px;">{{ $u->username }}</td>
                        <td style="padding: 12px 15px;">
                            <span style="background: #e3eaef; padding: 3px 8px; border-radius: 12px; font-size: 11px; color: #4b5d67; font-weight: bold;">
                                {{ $u->rol }}
                            </span>
                        </td>
                        <td style="padding: 12px 15px;">
                            <span style="background: #e8f8ef; padding: 3px 8px; border-radius: 12px; font-size: 11px; color: #1e7e46; font-weight: bold;">
                                <i class="fas fa-check-circle"></i> ACTIVO
                            </span>
                        </td>
                        <td style="padding: 12px 15px; text-align: center; white-space: nowrap;">
                            @if ($u->id != auth()->id())
                                @php
                                    $rolAuth = strtoupper(auth()->user()->rol->nombre ?? '');
                                    $rolTarget = strtoupper($u->rol ?? '');
                                    $puedeCambiarPass = false;
                                    if ($rolAuth === 'ADMIN') {
                                        $puedeCambiarPass = true;
                                    } elseif ($rolAuth === 'GERENTE') {
                                        if (in_array($rolTarget, ['CAJERO', 'MESERO'], true)) {
                                            $puedeCambiarPass = true;
                                        }
                                    }
                                @endphp
                                @if ($puedeCambiarPass)
                                    <button class="btn-reset-pass" data-id="{{ $u->id }}" data-nombre="{{ $u->nombre }}" style="background: transparent; color: #3f9ce8; border: 1px solid #3f9ce8; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px; margin-right: 4px;">
                                        <i class="fas fa-key"></i> Cambiar contraseña
                                    </button>
                                @endif
                                <button class="btn-eliminar-usuario" data-id="{{ $u->id }}" style="background: transparent; color: #fc544b; border: 1px solid #fc544b; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                    <i class="fas fa-trash-alt"></i> Eliminar
                                </button>
                            @else
                                <span style="font-size: 11px; color: #aaa; font-weight: bold;">Tu Sesión</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" style="padding: 20px 15px; text-align: center; color: #9aa0a6; font-size: 13px;">
                            No se encontraron usuarios activos.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @endif

        {{-- ===================== SECCIÓN B: USUARIOS ELIMINADOS ===================== --}}
        @if ($mostrarEliminados)
        <div style="margin-top: 30px; border-top: 1px solid #e3e6f0; padding-top: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <h3 style="margin: 0; font-size: 15px; color: #34395e;">
                    <i class="fas fa-user-slash" style="color: #c0392b;"></i> Usuarios eliminados
                </h3>
                <span style="font-size: 12px; color: #6c757d;">
                    {{ $eliminados->count() }} {{ $eliminados->count() === 1 ? 'usuario' : 'usuarios' }}
                </span>
            </div>

            <div style="border-radius: 8px; border: 1px solid #e3e6f0; overflow: hidden;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px; text-align: left;">
                    <thead style="background-color: #fdf3f2; border-bottom: 1px solid #ddd;">
                        <tr>
                            <th style="padding: 12px 15px;">Nombre Completo</th>
                            <th style="padding: 12px 15px;">Usuario (Login)</th>
                            <th style="padding: 12px 15px;">Rol de Sistema</th>
                            <th style="padding: 12px 15px;">Estado</th>
                            <th style="padding: 12px 15px; text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="tabla-usuarios-eliminados">
                        @forelse($eliminados as $u)
                        <tr style="border-bottom: 1px solid #f0f0f0;">
                            <td style="padding: 12px 15px; font-weight: 600; text-decoration: line-through; color: #a0a4ab;">{{ $u->nombre }}</td>
                            <td style="padding: 12px 15px; text-decoration: line-through; color: #a0a4ab;">{{ $u->username }}</td>
                            <td style="padding: 12px 15px;">
                                <span style="background: #e3eaef; padding: 3px 8px; border-radius: 12px; font-size: 11px; color: #4b5d67; font-weight: bold;">
                                    {{ $u->rol }}
                                </span>
                            </td>
                            <td style="padding: 12px 15px;">
                                <span style="background: #fdecea; padding: 3px 8px; border-radius: 12px; font-size: 11px; color: #c0392b; font-weight: bold;">
                                    <i class="fas fa-ban"></i> ELIMINADO
                                </span>
                            </td>
                            <td style="padding: 12px 15px; text-align: center; white-space: nowrap;">
                                <button class="btn-reactivar-usuario" data-id="{{ $u->id }}" data-nombre="{{ $u->nombre }}" style="background: transparent; color: #1e7e46; border: 1px solid #1e7e46; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 12px;">
                                    <i class="fas fa-undo"></i> Restablecer usuario
                                </button>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" style="padding: 20px 15px; text-align: center; color: #9aa0a6; font-size: 13px;">
                                No hay usuarios eliminados.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>
</div>

<script id="roles-json" type="application/json">
    {!! json_encode($roles) !!}
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Escuchas delegadas en body: funcionan con las filas que genera Blade
    // aunque se filtren o recarguen, y evitan duplicar listeners.

    // Botón eliminar (borrado lógico)
    document.body.addEventListener('click', function(e) {
        let btn = e.target.closest('.btn-eliminar-usuario');
        if (!btn) return;

        let id = btn.getAttribute('data-id');
        Swal.fire({
            title: '¿Eliminar usuario?',
            text: "El usuario quedará desactivado (borrado lógico) y podrá restablecerse.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#fc544b',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar'
        }).then((res) => {
            if (res.isConfirmed) {
                enviarAccion({ accion: 'eliminar', id: id }, 'Usuario desactivado correctamente.');
            }
        });
    });

    // Botón restablecer (reactivar el mismo registro)
    document.body.addEventListener('click', function(e) {
        let btn = e.target.closest('.btn-reactivar-usuario');
        if (!btn) return;

        let id = btn.getAttribute('data-id');
        // `text:` de SweetAlert2 se interpreta como TEXTO PLANO, no como HTML,
        // por lo que un nombre con caracteres HTML es seguro aquí.
        let nombre = btn.getAttribute('data-nombre') || '';

        Swal.fire({
            title: '¿Restablecer usuario?',
            text: 'El usuario "' + nombre + '" volverá a estar activo y podrá iniciar sesión.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#1e7e46',
            confirmButtonText: 'Sí, restablecer',
            cancelButtonText: 'Cancelar'
        }).then((res) => {
            if (res.isConfirmed) {
                enviarAccion({ accion: 'reactivar', id: id }, 'Usuario restablecido correctamente.');
            }
        });
    });

    // Botón cambiar contraseña
    document.body.addEventListener('click', function(e) {
        let btn = e.target.closest('.btn-reset-pass');
        if (!btn) return;

        modalResetPass(btn.getAttribute('data-id'), btn.getAttribute('data-nombre'));
    });
});

// Escapa texto que se va a insertar en cadenas tratadas como HTML.
function escaparHtml(valor) {
    return String(valor)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// Envía una acción a la API de usuarios y recarga al terminar.
function enviarAccion(datos, mensajeExito) {
    const fd = new FormData();
    fd.append('_token', '{{ csrf_token() }}');
    Object.keys(datos).forEach(k => fd.append(k, datos[k]));

    fetch('{{ route("usuarios.api") }}', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(d => {
            if (d.exito) {
                Swal.fire('¡Listo!', d.mensaje || mensajeExito, 'success').then(() => location.reload());
            } else {
                Swal.fire('Error', d.error || 'No se pudo completar la operación.', 'error');
            }
        })
        .catch(() => Swal.fire('Error', 'No se pudo conectar con el servidor.', 'error'));
}

// Genera una contraseña aleatoria segura usando la Web Crypto API.
function generarPassword(longitud = 12) {
    const mayusculas = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const minusculas = 'abcdefghijkmnopqrstuvwxyz';
    const numeros = '23456789';
    const simbolos = '!@#$%&*';
    const todos = mayusculas + minusculas + numeros + simbolos;

    const bytes = new Uint32Array(longitud);
    const cryptoObj = window.crypto || window.msCrypto;
    cryptoObj.getRandomValues(bytes);

    // Garantizamos al menos un carácter de cada grupo.
    let pwd = mayusculas[bytes[0] % mayusculas.length]
        + minusculas[bytes[1] % minusculas.length]
        + numeros[bytes[2] % numeros.length]
        + simbolos[bytes[3] % simbolos.length];

    for (let i = 4; i < longitud; i++) {
        pwd += todos[bytes[i] % todos.length];
    }

    // Mezclamos para que los obligatorios no queden siempre al inicio.
    const arr = pwd.split('');
    for (let i = arr.length - 1; i > 0; i--) {
        const j = bytes[i] % (i + 1);
        [arr[i], arr[j]] = [arr[j], arr[i]];
    }
    return arr.join('');
}

// Copia texto al portapapeles con respaldo para navegadores antiguos.
function copiarTexto(texto, boton) {
    const avisar = () => {
        if (boton) {
            const original = boton.innerHTML;
            boton.innerHTML = '<i class="fas fa-check"></i> Copiado';
            setTimeout(() => { boton.innerHTML = original; }, 1200);
        }
    };

    const respaldo = () => {
        const ta = document.createElement('textarea');
        ta.value = texto;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try { document.execCommand('copy'); } catch (e) { /* sin soporte */ }
        document.body.removeChild(ta);
        avisar();
    };

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(texto).then(avisar).catch(respaldo);
    } else {
        respaldo();
    }
}

function modalUsuario() {
    let rolesOptions = '';
    const roles = JSON.parse(document.getElementById('roles-json').textContent);
    roles.forEach(r => {
        // El id es numérico y el nombre se escapa antes de inyectarlo como HTML.
        rolesOptions += `<option value="${Number(r.id)}">${escaparHtml(r.nombre)}</option>`;
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
                <div style="margin-bottom: 5px;">
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
            if (val.password.length < 6) {
                Swal.showValidationMessage('La contraseña debe tener al menos 6 caracteres');
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
            })
            .catch(() => Swal.fire('Error', 'No se pudo conectar con el servidor.', 'error'));
        }
    });
}

/*
 * Modal Cambiar contraseña.
 * - Opción A: generar automáticamente (mostrar/ocultar, copiar, regenerar).
 * - Opción B: escribir manualmente (mostrar/ocultar, copiar, confirmación).
 * La contraseña viaja como "password"/"password_confirmation" y el servidor la
 * hashea; nunca se muestra la contraseña actual.
 *
 * Seguridad: el nombre del usuario NO se interpola en el HTML del modal. Se
 * coloca con textContent en didOpen(), de modo que caracteres HTML se muestran
 * como texto y no se ejecutan.
 */
function modalResetPass(usuarioId, nombreUsuario) {
    Swal.fire({
        title: 'Cambiar contraseña',
        html: `
            <div style="text-align: left; font-size: 13px; padding: 6px 0;">
                <p style="margin: 0 0 12px; color: #6c757d; font-size: 12px;">
                    Usuario: <strong id="reset-usuario-nombre"></strong>
                </p>
                <div style="display: flex; gap: 6px; margin-bottom: 15px;">
                    <button type="button" id="tab-auto" class="pw-tab" style="flex:1; padding: 8px; border: 1px solid #3f9ce8; background:#3f9ce8; color:#fff; border-radius:4px; cursor:pointer; font-size:12px; font-weight:bold;">
                        <i class="fas fa-magic"></i> Automática
                    </button>
                    <button type="button" id="tab-manual" class="pw-tab" style="flex:1; padding: 8px; border: 1px solid #e3e6f0; background:#fff; color:#4b5d67; border-radius:4px; cursor:pointer; font-size:12px; font-weight:bold;">
                        <i class="fas fa-keyboard"></i> Manual
                    </button>
                </div>

                <div id="panel-auto">
                    <label style="font-weight:bold; display:block; margin-bottom: 5px;">Contraseña generada:</label>
                    <input id="reset-pass-auto" type="text" readonly class="swal2-input" style="width:100%; margin:0; height:38px; box-sizing:border-box; font-family: monospace;">
                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;">
                        <button type="button" id="btn-toggle-auto" style="border:1px solid #e3e6f0; background:#fff; border-radius:4px; padding:6px 10px; cursor:pointer; font-size:12px;"><i class="fas fa-eye-slash"></i> Ocultar</button>
                        <button type="button" id="btn-copy-auto" style="border:1px solid #e3e6f0; background:#fff; border-radius:4px; padding:6px 10px; cursor:pointer; font-size:12px;"><i class="fas fa-copy"></i> Copiar</button>
                        <button type="button" id="btn-regen-auto" style="border:1px solid #e3e6f0; background:#fff; border-radius:4px; padding:6px 10px; cursor:pointer; font-size:12px;"><i class="fas fa-sync-alt"></i> Regenerar</button>
                    </div>
                    <p style="font-size:11px; color:#888; margin-top:8px;">
                        Entrégala al usuario ahora. Por seguridad no se guarda en texto plano ni puede consultarse después.
                    </p>
                </div>

                <div id="panel-manual" style="display:none;">
                    <label style="font-weight:bold; display:block; margin-bottom: 5px;">Nueva contraseña:</label>
                    <input id="reset-pass" type="password" class="swal2-input" placeholder="Mínimo 6 caracteres" style="width:100%; margin:0 0 12px; height:38px; box-sizing:border-box;">
                    <label style="font-weight:bold; display:block; margin-bottom: 5px;">Confirmar contraseña:</label>
                    <input id="reset-confirm" type="password" class="swal2-input" placeholder="Repite la contraseña" style="width:100%; margin:0; height:38px; box-sizing:border-box;">
                    <div style="display:flex; gap:6px; margin-top:8px;">
                        <button type="button" id="btn-toggle-manual" style="border:1px solid #e3e6f0; background:#fff; border-radius:4px; padding:6px 10px; cursor:pointer; font-size:12px;"><i class="fas fa-eye"></i> Mostrar</button>
                        <button type="button" id="btn-copy-manual" style="border:1px solid #e3e6f0; background:#fff; border-radius:4px; padding:6px 10px; cursor:pointer; font-size:12px;"><i class="fas fa-copy"></i> Copiar</button>
                    </div>
                </div>
            </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonColor: '#3f9ce8',
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        didOpen: () => {
            // Nombre del usuario como TEXTO (seguro ante caracteres HTML).
            document.getElementById('reset-usuario-nombre').textContent = nombreUsuario || '';

            const tabAuto = document.getElementById('tab-auto');
            const tabManual = document.getElementById('tab-manual');
            const panelAuto = document.getElementById('panel-auto');
            const panelManual = document.getElementById('panel-manual');
            const autoInput = document.getElementById('reset-pass-auto');
            const manualInput = document.getElementById('reset-pass');
            const confirmInput = document.getElementById('reset-confirm');

            // Generamos una contraseña inicial.
            autoInput.value = generarPassword();

            const activarAuto = () => {
                panelAuto.style.display = '';
                panelManual.style.display = 'none';
                tabAuto.style.background = '#3f9ce8'; tabAuto.style.color = '#fff'; tabAuto.style.borderColor = '#3f9ce8';
                tabManual.style.background = '#fff'; tabManual.style.color = '#4b5d67'; tabManual.style.borderColor = '#e3e6f0';
            };
            const activarManual = () => {
                panelAuto.style.display = 'none';
                panelManual.style.display = '';
                tabManual.style.background = '#3f9ce8'; tabManual.style.color = '#fff'; tabManual.style.borderColor = '#3f9ce8';
                tabAuto.style.background = '#fff'; tabAuto.style.color = '#4b5d67'; tabAuto.style.borderColor = '#e3e6f0';
            };

            tabAuto.addEventListener('click', activarAuto);
            tabManual.addEventListener('click', activarManual);

            document.getElementById('btn-regen-auto').addEventListener('click', () => {
                autoInput.value = generarPassword();
            });

            let autoVisible = true;
            document.getElementById('btn-toggle-auto').addEventListener('click', function() {
                autoVisible = !autoVisible;
                autoInput.type = autoVisible ? 'text' : 'password';
                this.innerHTML = autoVisible
                    ? '<i class="fas fa-eye-slash"></i> Ocultar'
                    : '<i class="fas fa-eye"></i> Mostrar';
            });

            document.getElementById('btn-copy-auto').addEventListener('click', function() {
                copiarTexto(autoInput.value, this);
            });

            let manualVisible = false;
            document.getElementById('btn-toggle-manual').addEventListener('click', function() {
                manualVisible = !manualVisible;
                const tipo = manualVisible ? 'text' : 'password';
                manualInput.type = tipo;
                confirmInput.type = tipo;
                this.innerHTML = manualVisible
                    ? '<i class="fas fa-eye-slash"></i> Ocultar'
                    : '<i class="fas fa-eye"></i> Mostrar';
            });

            document.getElementById('btn-copy-manual').addEventListener('click', function() {
                copiarTexto(manualInput.value, this);
            });
        },
        preConfirm: () => {
            const esAuto = document.getElementById('panel-auto').style.display !== 'none';
            let pass, conf;
            if (esAuto) {
                pass = document.getElementById('reset-pass-auto').value;
                conf = pass;
            } else {
                pass = document.getElementById('reset-pass').value;
                conf = document.getElementById('reset-confirm').value;
            }

            if (!pass || pass.length < 6) {
                Swal.showValidationMessage('La contraseña debe tener al menos 6 caracteres');
                return false;
            }
            if (pass !== conf) {
                Swal.showValidationMessage('Las contraseñas no coinciden');
                return false;
            }
            return { password: pass, password_confirmation: conf };
        }
    }).then((result) => {
        if (!result.isConfirmed) return;

        const val = result.value;

        // Confirmación explícita antes de aplicar el cambio.
        Swal.fire({
            title: '¿Confirmar cambio?',
            text: 'La contraseña se actualizará para el usuario seleccionado.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3f9ce8',
            confirmButtonText: 'Sí, cambiar',
            cancelButtonText: 'Cancelar'
        }).then((confirmacion) => {
            if (!confirmacion.isConfirmed) return;

            const fd = new FormData();
            fd.append('_token', '{{ csrf_token() }}');
            fd.append('accion', 'reset_password');
            fd.append('usuario_id', usuarioId);
            fd.append('password', val.password);
            fd.append('password_confirmation', val.password_confirmation);

            fetch('{{ route("usuarios.api") }}', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.exito) {
                        Swal.fire('¡Éxito!', d.mensaje || 'Contraseña actualizada correctamente', 'success').then(() => location.reload());
                    } else {
                        Swal.fire('Error', d.error || 'Error al actualizar contraseña', 'error');
                    }
                })
                .catch(() => Swal.fire('Error', 'No se pudo conectar con el servidor.', 'error'));
        });
    });
}
</script>
@endsection
