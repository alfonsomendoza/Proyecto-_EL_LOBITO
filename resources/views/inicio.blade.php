@extends('layouts.app')

@section('content')
@php
    // Mismo criterio de rol que el menú lateral (layouts/app.blade.php).
    $rol = auth()->user()->rol->nombre ?? 'MESERO';

    // Interruptores de módulos (solo ADMIN los cambia).
    $configuracion = app(\App\Services\ConfiguracionService::class);
    $cocinaDigitalHabilitada = $configuracion->cocinaDigitalHabilitada();
    $inventarioHabilitado = $configuracion->inventarioHabilitado();
@endphp

<div class="monitor-card" style="max-width: 900px; margin: 0 auto; margin-top: 20px;">
    <div class="monitor-header">
        <span><i class="fas fa-home"></i> Inicio - Restaurante El Lobito</span>
    </div>
    <div style="padding: 40px 30px; text-align: center;">
        <img src="{{ asset('images/RestauranteELLOBITO.jpg') }}" alt="Logo Restaurante El Lobito"
             style="max-width: 170px; width: 100%; height: auto; border-radius: 8px; margin-bottom: 15px;">
        <div style="font-size: 50px; color: var(--color-primary); margin-bottom: 15px;">
            <i class="fas fa-utensils"></i>
        </div>
        <h2 style="color: var(--color-dark); margin-bottom: 10px;">¡Bienvenido, {{ auth()->user()->nombre }}!</h2>
        <p style="color: var(--text-muted); font-size: 15px; margin-bottom: 30px;">Selecciona un módulo para comenzar a operar en tu turno.</p>

        {{-- Accesos directos a todos los módulos del sistema --}}
        <div style="display: flex; justify-content: center; gap: 15px; flex-wrap: wrap;">

            @if (in_array($rol, ['ADMIN', 'GERENTE', 'MESERO', 'CAJERO']))
                <a href="{{ route('mesas.index') }}" style="text-decoration: none; background: var(--color-primary); color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-desktop"></i> Monitor</a>
            @endif

            {{-- Cocina digital (opcional): solo si está habilitada y el rol accede. --}}
            @if ($cocinaDigitalHabilitada && in_array($rol, ['ADMIN', 'GERENTE', 'COCINERO']))
                <a href="{{ route('cocina.index') }}" style="text-decoration: none; background: #e74c3c; color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-fire"></i> Cocina</a>
            @endif

            @if (in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO']))
                <a href="{{ route('caja.apertura') }}" style="text-decoration: none; background: var(--color-success); color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-store"></i> Abrir Caja</a>
            @endif

            @if (in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO']))
                <a href="{{ route('clientes.index') }}" style="text-decoration: none; background: #17a2b8; color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-address-book"></i> Clientes</a>
            @endif

            {{-- Ventas: cuentas pendientes de cobro e historial. Solo ADMIN,
                 GERENTE y CAJERO (igual que el menú lateral); el MESERO no
                 recibe acceso al cobro. Autorización real en el controlador. --}}
            @if (in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO']))
                <a href="{{ route('ventas.index') }}" style="text-decoration: none; background: var(--color-primary); color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-shopping-cart"></i> Ventas</a>
            @endif

            @if (in_array($rol, ['ADMIN', 'GERENTE']))
                <a href="{{ route('facturas.index') }}" style="text-decoration: none; background: #34495e; color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-file-invoice"></i> Historial Facturas</a>
            @endif

            @if (in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO']))
                <a href="{{ route('gastos.index') }}" style="text-decoration: none; background: #f39c12; color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-file-invoice-dollar"></i> Gastos</a>

                <a href="{{ route('caja.cierre') }}" style="text-decoration: none; background: #e67e22; color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-cash-register"></i> Cierre</a>

                <a href="{{ route('catalogo.index') }}" style="text-decoration: none; background: #6f42c1; color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-box-open"></i> Menú (Productos)</a>

                @if ($inventarioHabilitado)
                    <a href="{{ route('inventario.index') }}" style="text-decoration: none; background: #16a085; color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-boxes-stacked"></i> Inventario</a>
                @endif
            @endif

            @if (in_array($rol, ['ADMIN', 'GERENTE']))
                <a href="{{ route('usuarios.index') }}" style="text-decoration: none; background: #34395e; color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-users"></i> Usuarios</a>

                <a href="{{ route('backups.index') }}" style="text-decoration: none; background: #495057; color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-database"></i> Copias de Seguridad</a>

                <a href="{{ route('reportes.resumen') }}" style="text-decoration: none; background: #2c3e50; color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-box"></i> Operaciones</a>

                <a href="{{ route('reportes.propinas') }}" style="text-decoration: none; background: #16a085; color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-hand-holding-dollar"></i> Propinas</a>
            @endif

            {{-- Catálogo de roles: solo ADMIN. La autorización real se valida
                 en el servidor (RolController), no en esta vista. --}}
            @if ($rol === 'ADMIN')
                <a href="{{ route('roles.index') }}" style="text-decoration: none; background: #6c5ce7; color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-user-shield"></i> Roles</a>

                <a href="{{ route('configuracion.index') }}" style="text-decoration: none; background: #2d3436; color: white; padding: 12px 20px; border-radius: 5px; font-weight: 600;"><i class="fas fa-sliders"></i> Configuración</a>
            @endif

        </div>
    </div>
</div>
@endsection
