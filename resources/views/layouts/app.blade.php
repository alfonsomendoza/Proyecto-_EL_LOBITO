<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>El Lobito - Dashboard</title>
    <link rel="stylesheet" href="{{ asset('style.css') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="{{ asset('script.js') }}?v={{ filemtime(public_path('script.js')) }}"></script>
</head>
<body>
    @php
        $rol = auth()->user()->rol->nombre ?? 'MESERO';

        // Interruptores de módulos (solo ADMIN los cambia). Se consultan en el
        // backend para decidir si se muestra el enlace; la autorización real
        // siempre se valida en el controlador correspondiente.
        $configuracion = app(\App\Services\ConfiguracionService::class);
        $cocinaDigitalHabilitada = $configuracion->cocinaDigitalHabilitada();
        $inventarioHabilitado = $configuracion->inventarioHabilitado();
    @endphp

    <aside class="sidebar">
        <div class="sidebar-brand">
            <img src="{{ asset('images/RestauranteELLOBITO.jpg') }}" alt="Logo Restaurante El Lobito"
                 style="height: 36px; width: auto; border-radius: 6px; margin-right: 12px; flex-shrink: 0;">
            <h2>EL LOBITO</h2>
        </div>

        <ul class="sidebar-menu">
            <li>
                <a href="{{ route('inicio') }}" class="{{ request()->routeIs('inicio') ? 'active' : '' }}">
                    <i class="fas fa-home"></i> Inicio
                </a>
            </li>

            {{-- Cocina digital (opcional): solo si el módulo está habilitado. --}}
            @if ($cocinaDigitalHabilitada && in_array($rol, ['ADMIN', 'GERENTE', 'COCINERO']))
                <li>
                    <a href="{{ route('cocina.index') }}" class="{{ request()->routeIs('cocina.*') ? 'active' : '' }}">
                        <i class="fas fa-fire"></i> Cocina
                    </a>
                </li>
            @endif

            {{-- Historial de facturas: solo ADMIN y GERENTE pueden listarlo
                 (validado también en FacturaController). --}}
            @if (in_array($rol, ['ADMIN', 'GERENTE']))
                <li>
                    <a href="{{ route('facturas.index') }}" class="{{ request()->routeIs('facturas.*') ? 'active' : '' }}">
                        <i class="fas fa-file-invoice"></i> Historial Facturas
                    </a>
                </li>
            @endif

            @if (in_array($rol, ['ADMIN', 'GERENTE', 'MESERO', 'CAJERO']))
                <li>
                    <a href="{{ route('mesas.index') }}" class="{{ request()->routeIs('mesas.index') ? 'active' : '' }}">
                        <i class="fas fa-desktop"></i> Monitor
                    </a>
                </li>
            @endif

            @if (in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO']))
                <li>
                    <a href="{{ route('caja.apertura') }}" class="{{ request()->routeIs('caja.apertura') ? 'active' : '' }}">
                        <i class="fas fa-store"></i> Abrir Caja
                    </a>
                </li>
            @endif

            {{-- Ventas: módulo de cuentas pendientes de cobro e historial.
                 Solo ADMIN, GERENTE y CAJERO. El MESERO trabaja desde el
                 Monitor y no accede al cobro; la autorización real se valida
                 en VentaController::panelVentas (403 para el resto). --}}
            @if (in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO']))
                <li>
                    <a href="{{ route('ventas.index') }}" class="{{ request()->routeIs('ventas.*') ? 'active' : '' }}">
                        <i class="fas fa-shopping-cart"></i> Ventas
                    </a>
                </li>
            @endif

            @if (in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO']))
                <li>
                    <a href="{{ route('clientes.index') }}" class="{{ request()->routeIs('clientes.index') ? 'active' : '' }}">
                        <i class="fas fa-address-book"></i> Clientes
                    </a>
                </li>
            @endif

            @if (in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO']))
                <li>
                    <a href="{{ route('gastos.index') }}" class="{{ request()->routeIs('gastos.index') ? 'active' : '' }}">
                        <i class="fas fa-file-invoice-dollar"></i> Gastos
                    </a>
                </li>
                <li>
                    <a href="{{ route('caja.cierre') }}" class="{{ request()->routeIs('caja.cierre*') ? 'active' : '' }}">
                        <i class="fas fa-cash-register"></i> Cierre
                    </a>
                </li>
                <li>
                    <a href="{{ route('catalogo.index') }}" class="{{ request()->routeIs('catalogo.index') ? 'active' : '' }}">
                        <i class="fas fa-box-open"></i> Menú (Productos)
                    </a>
                </li>
                {{-- Inventario: ADMIN, GERENTE y CAJERO consultan; solo
                     ADMIN/GERENTE administran (validado en el backend). Se
                     oculta el enlace si el módulo está deshabilitado. --}}
                @if ($inventarioHabilitado)
                <li>
                    <a href="{{ route('inventario.index') }}" class="{{ request()->routeIs('inventario.*') ? 'active' : '' }}">
                        <i class="fas fa-boxes-stacked"></i> Inventario
                    </a>
                </li>
                @endif
            @endif

            @if (in_array($rol, ['ADMIN', 'GERENTE']))
                <li class="menu-header">ADMINISTRACIÓN</li>
                <li>
                    <a href="{{ route('reportes.resumen') }}" class="{{ request()->routeIs('reportes.resumen*') ? 'active' : '' }}">
                        <i class="fas fa-box"></i> Operaciones
                    </a>
                </li>
                <li>
                    <a href="{{ route('reportes.propinas') }}" class="{{ request()->routeIs('reportes.propinas*') ? 'active' : '' }}">
                        <i class="fas fa-hand-holding-dollar"></i> Propinas
                    </a>
                </li>
                @if (in_array($rol, ['ADMIN', 'GERENTE']))
                <li>
                    <a href="{{ route('usuarios.index') }}" class="{{ request()->routeIs('usuarios.index') ? 'active' : '' }}">
                        <i class="fas fa-users"></i> Usuarios
                    </a>
                </li>
                @endif
                <li>
                    <a href="{{ route('backups.index') }}" class="{{ request()->routeIs('backups.index') ? 'active' : '' }}">
                        <i class="fas fa-database"></i> Copias de Seguridad
                    </a>
                </li>
                @if ($rol === 'ADMIN')
                <li>
                    <a href="{{ route('roles.index') }}" class="{{ request()->routeIs('roles.index') ? 'active' : '' }}">
                        <i class="fas fa-user-shield"></i> Roles
                    </a>
                </li>
                <li>
                    <a href="{{ route('configuracion.index') }}" class="{{ request()->routeIs('configuracion.*') ? 'active' : '' }}">
                        <i class="fas fa-sliders"></i> Configuración
                    </a>
                </li>
                @endif
            @endif
        </ul>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div class="topbar-left">
                <i class="fas fa-bars"></i>
            </div>
            <div class="topbar-right">
                <div class="user-dropdown">
                    <div class="user-profile">
                        <div class="user-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <span>{{ auth()->user()->nombre ?? 'Usuario' }}</span>
                        <i class="fas fa-chevron-down" style="font-size: 10px; margin-left: 5px; color: var(--text-muted);"></i>
                    </div>
                    <div class="dropdown-menu">
                        <form action="{{ route('logout') }}" method="POST">
                            @csrf
                            <button type="submit" style="background: none; border: none; padding: 0; cursor: pointer;">
                                Cerrar sesión
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <div class="content-area">
            @yield('content')
        </div>
    </main>



</body>
</html>