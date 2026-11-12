@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 1200px; margin: 0 auto;">
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <span><i class="fas fa-shield-alt"></i> Administración de seguridad 2FA</span>
        @php
            $modoActual = $modo ?? 'desactivado';
        @endphp
        @if($modoActual === 'desactivado')
            <span style="background:#6c757d; color:#fff; padding:6px 12px; border-radius:4px; font-weight:bold; font-size:12px;">DESACTIVADO</span>
        @else
            <span style="background:#28a745; color:#fff; padding:6px 12px; border-radius:4px; font-weight:bold; font-size:12px;">OBLIGATORIO</span>
        @endif
    </div>

    <div style="padding: 25px;">
        <p style="color:#6c757d; margin-bottom:20px;">Gestión de la política global de autenticación de dos factores.</p>

        @if(session('success'))
            <div style="background:#d4edda; border:1px solid #c3e6cb; color:#155724; padding:12px 16px; border-radius:6px; margin-bottom:16px;">
                <i class="fas fa-check-circle"></i> {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div style="background:#f8d7da; border:1px solid #f5c6cb; color:#721c24; padding:12px 16px; border-radius:6px; margin-bottom:16px;">
                <i class="fas fa-exclamation-circle"></i> {{ session('error') }}
            </div>
        @endif

        @php
            $rolActor = strtoupper((string) (auth()->user()->rol->nombre ?? ''));
            $esAdmin = $rolActor === 'ADMIN';
            // ADMIN y GERENTE pueden administrar el 2FA individual de usuarios;
            // el control global (modo) sigue siendo EXCLUSIVO de ADMIN.
            $esGestor2fa = in_array($rolActor, ['ADMIN', 'GERENTE'], true);
            $modoActual = $modo ?? 'desactivado';
            $totalUsuarios = $usuarios->count();
            $usuariosConfigurados = $usuarios->filter(function($u) { return !empty($u->two_factor_enabled) && !empty($u->two_factor_confirmed_at); })->count();
            $usuariosPendientes = $totalUsuarios - $usuariosConfigurados;
        @endphp

        <div style="border:1px solid #e3e6f0; border-radius:8px; margin-bottom:20px; overflow:hidden;">
            <div style="background:#f8f9fc; padding:12px 18px; border-bottom:1px solid #e3e6f0; font-weight:600; color:#2c3e50;">
                <i class="fas fa-cog"></i> Configuración global
            </div>
            <div style="padding:18px;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:16px;">
                    <div style="flex:1; min-width:260px;">
                        <p style="margin-bottom:10px;"><strong>Estado actual:</strong>
                            @if($modoActual === 'desactivado')
                                <span style="background:#6c757d; color:#fff; padding:4px 10px; border-radius:4px; font-size:12px;">DESACTIVADO</span>
                            @else
                                <span style="background:#28a745; color:#fff; padding:4px 10px; border-radius:4px; font-size:12px;">OBLIGATORIO para todos los roles</span>
                            @endif
                        </p>
                        @if($modoActual === 'obligatorio')
                            <div style="background:#fff3cd; border:1px solid #ffc107; color:#856404; padding:12px 14px; border-radius:6px;">
                                <i class="fas fa-exclamation-triangle"></i> <strong>Atención:</strong> Al tenerlo activado, todos los roles deberán configurar y verificar 2FA antes de acceder al sistema.
                            </div>
                        @else
                            <div style="background:#e7f3ff; border:1px solid #b8daff; color:#004085; padding:12px 14px; border-radius:6px;">
                                <i class="fas fa-info-circle"></i> <strong>Info:</strong> Desactivar 2FA no elimina secretos, códigos, dispositivos ni auditorías existentes.
                            </div>
                        @endif
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        @if($esAdmin)
                            <form method="POST" action="{{ route('admin.2fa.configurar') }}">
                                @csrf
                                <input type="hidden" name="modo" value="obligatorio">
                                <button type="submit" {{ $modoActual === 'obligatorio' ? 'disabled' : '' }} style="background:{{ $modoActual === 'obligatorio' ? '#adb5bd' : '#28a745' }}; color:#fff; border:none; padding:10px 18px; border-radius:5px; font-weight:bold; cursor:{{ $modoActual === 'obligatorio' ? 'not-allowed' : 'pointer' }};" onclick="return confirm('¿Activar 2FA OBLIGATORIO para todos los usuarios? Todos deberán completar la configuración y verificación 2FA.')">
                                    <i class="fas fa-lock"></i> Activar 2FA obligatorio
                                </button>
                            </form>
                            <form method="POST" action="{{ route('admin.2fa.configurar') }}">
                                @csrf
                                <input type="hidden" name="modo" value="desactivado">
                                <button type="submit" {{ $modoActual === 'desactivado' ? 'disabled' : '' }} style="background:{{ $modoActual === 'desactivado' ? '#adb5bd' : '#6c757d' }}; color:#fff; border:none; padding:10px 18px; border-radius:5px; font-weight:bold; cursor:{{ $modoActual === 'desactivado' ? 'not-allowed' : 'pointer' }};" onclick="return confirm('¿Desactivar 2FA globalmente? Los usuarios podrán iniciar sesión solo con usuario y contraseña. No se eliminarán secretos, códigos, dispositivos ni auditorías.')">
                                    <i class="fas fa-unlock"></i> Desactivar 2FA
                                </button>
                            </form>
                        @else
                            <span style="color:#6c757d; font-size:13px;">Solo el administrador puede modificar la configuración global.</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap:16px; margin-bottom:20px;">
            <div style="border:1px solid #e3e6f0; border-radius:8px; padding:16px; display:flex; align-items:center; gap:14px; background:#fff;">
                <div style="background:rgba(103,119,239,0.1); border-radius:50%; width:48px; height:48px; display:flex; align-items:center; justify-content:center;">
                    <i class="fas fa-users" style="color:#6777ef; font-size:18px;"></i>
                </div>
                <div>
                    <div style="color:#6c757d; font-size:12px; text-transform:uppercase;">Total de usuarios</div>
                    <div style="font-size:24px; font-weight:bold; color:#2c3e50;">{{ $totalUsuarios }}</div>
                </div>
            </div>
            <div style="border:1px solid #e3e6f0; border-radius:8px; padding:16px; display:flex; align-items:center; gap:14px; background:#fff;">
                <div style="background:rgba(40,167,69,0.1); border-radius:50%; width:48px; height:48px; display:flex; align-items:center; justify-content:center;">
                    <i class="fas fa-check-circle" style="color:#28a745; font-size:18px;"></i>
                </div>
                <div>
                    <div style="color:#6c757d; font-size:12px; text-transform:uppercase;">Configurados</div>
                    <div style="font-size:24px; font-weight:bold; color:#2c3e50;">{{ $usuariosConfigurados }}</div>
                </div>
            </div>
            <div style="border:1px solid #e3e6f0; border-radius:8px; padding:16px; display:flex; align-items:center; gap:14px; background:#fff;">
                <div style="background:rgba(255,193,7,0.15); border-radius:50%; width:48px; height:48px; display:flex; align-items:center; justify-content:center;">
                    <i class="fas fa-exclamation-circle" style="color:#d39e00; font-size:18px;"></i>
                </div>
                <div>
                    <div style="color:#6c757d; font-size:12px; text-transform:uppercase;">Pendientes</div>
                    <div style="font-size:24px; font-weight:bold; color:#2c3e50;">{{ $usuariosPendientes }}</div>
                </div>
            </div>
        </div>

        <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden;">
            <div style="background:#2c3e50; color:#fff; padding:12px 18px; font-weight:600; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <span><i class="fas fa-users-cog"></i> Estado por usuario</span>
                <span style="font-size:12px; opacity:0.9;">ADMIN y GERENTE pueden administrar el 2FA individual • Solo ADMIN puede restablecer</span>
            </div>
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; min-width:700px;">
                    <thead>
                        <tr style="background:#f8f9fc;">
                            <th style="text-align:left; padding:12px 18px; font-size:13px; color:#6c757d; border-bottom:1px solid #e3e6f0;">Usuario</th>
                            <th style="text-align:left; padding:12px 18px; font-size:13px; color:#6c757d; border-bottom:1px solid #e3e6f0;">Rol</th>
                            <th style="text-align:left; padding:12px 18px; font-size:13px; color:#6c757d; border-bottom:1px solid #e3e6f0;">Estado 2FA</th>
                            <th style="text-align:left; padding:12px 18px; font-size:13px; color:#6c757d; border-bottom:1px solid #e3e6f0;">Configurado el</th>
                            <th style="text-align:right; padding:12px 18px; font-size:13px; color:#6c757d; border-bottom:1px solid #e3e6f0;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($usuarios as $usuario)
                        <tr style="border-bottom:1px solid #f1f3f5;">
                            <td style="padding:12px 18px;">
                                <div style="font-weight:600; color:#2c3e50;">{{ $usuario->nombre }}</div>
                                <div style="color:#6c757d; font-size:12px;">{{ $usuario->username }}</div>
                            </td>
                            <td style="padding:12px 18px;">
                                @php $rolNombre = strtoupper($usuario->rol->nombre ?? '-'); @endphp
                                @if($rolNombre === 'ADMIN')
                                    <span style="background:#dc3545; color:#fff; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:bold;">ADMIN</span>
                                @elseif($rolNombre === 'GERENTE')
                                    <span style="background:#ffc107; color:#212529; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:bold;">GERENTE</span>
                                @elseif($rolNombre === 'CAJERO')
                                    <span style="background:#17a2b8; color:#fff; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:bold;">CAJERO</span>
                                @elseif($rolNombre === 'MESERO')
                                    <span style="background:#007bff; color:#fff; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:bold;">MESERO</span>
                                @elseif($rolNombre === 'COCINERO')
                                    <span style="background:#6c757d; color:#fff; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:bold;">COCINERO</span>
                                @else
                                    <span style="background:#e9ecef; color:#495057; padding:4px 8px; border-radius:4px; font-size:11px; font-weight:bold;">{{ $rolNombre }}</span>
                                @endif
                            </td>
                            <td style="padding:12px 18px;">
                                @if($usuario->two_factor_enabled && $usuario->two_factor_confirmed_at)
                                    <span style="background:#28a745; color:#fff; padding:4px 10px; border-radius:4px; font-size:11px; font-weight:bold;"><i class="fas fa-check"></i> CONFIRMADO</span>
                                @elseif($usuario->two_factor_enabled)
                                    <span style="background:#17a2b8; color:#fff; padding:4px 10px; border-radius:4px; font-size:11px; font-weight:bold;"><i class="fas fa-clock"></i> PENDIENTE</span>
                                @else
                                    <span style="background:#6c757d; color:#fff; padding:4px 10px; border-radius:4px; font-size:11px; font-weight:bold;"><i class="fas fa-unlock"></i> DESHABILITADO</span>
                                @endif
                            </td>
                            <td style="padding:12px 18px; color:#6c757d; font-size:13px;">
                                @if($usuario->two_factor_confirmed_at)
                                    {{ \Carbon\Carbon::parse($usuario->two_factor_confirmed_at)->format('d/m/Y H:i') }}
                                @else
                                    —
                                @endif
                            </td>
                            <td style="padding:12px 18px; text-align:right;">
                                @if($esGestor2fa)
                                    @if($usuario->two_factor_enabled && $usuario->two_factor_confirmed_at)
                                        {{-- El usuario tiene 2FA activo: ofrecer desactivarlo. --}}
                                        <form method="POST" action="{{ route('admin.2fa.desactivar', $usuario->id) }}" style="display:inline;">
                                            @csrf
                                            <button type="submit" style="background:#fff; color:#d39e00; border:1px solid #ffc107; padding:6px 12px; border-radius:4px; font-size:12px; font-weight:bold; cursor:pointer;">
                                                <i class="fas fa-shield-halved"></i> Desactivar
                                            </button>
                                        </form>
                                    @else
                                        {{-- Sin 2FA activo (o pendiente): ofrecer activarlo, lo que fuerza un nuevo enrolle seguro. --}}
                                        <form method="POST" action="{{ route('admin.2fa.activar', $usuario->id) }}" style="display:inline;">
                                            @csrf
                                            <button type="submit" style="background:#fff; color:#28a745; border:1px solid #28a745; padding:6px 12px; border-radius:4px; font-size:12px; font-weight:bold; cursor:pointer;">
                                                <i class="fas fa-lock-open"></i> Activar
                                            </button>
                                        </form>
                                    @endif
                                    @if($esAdmin)
                                        @if($usuario->two_factor_enabled && $usuario->two_factor_confirmed_at)
                                            <form method="POST" action="{{ route('admin.2fa.reset', $usuario->id) }}" style="display:inline;">
                                                @csrf
                                                <button type="submit" style="background:#fff; color:#d39e00; border:1px solid #ffc107; padding:6px 12px; border-radius:4px; font-size:12px; font-weight:bold; cursor:pointer;" onclick="return confirm('¿Restablecer 2FA para {{ $usuario->username }}?')">
                                                    <i class="fas fa-redo"></i> Restablecer
                                                </button>
                                            </form>
                                        @else
                                            <span style="color:#adb5bd; font-size:12px;">Sin acciones</span>
                                        @endif
                                    @endif
                                @else
                                    <span style="color:#adb5bd; font-size:12px;">Solo ADMIN y GERENTE pueden administrar el 2FA.</span>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" style="text-align:center; color:#6c757d; padding:24px;">No hay usuarios para mostrar.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
