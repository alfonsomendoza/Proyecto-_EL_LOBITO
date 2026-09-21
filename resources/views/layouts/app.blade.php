<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>El Lobito - Dashboard</title>
    <link rel="stylesheet" href="{{ asset('style.css') }}">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    @php
        $rol = auth()->user()->rol->nombre ?? 'MESERO';
    @endphp

    <aside class="sidebar">
        <div class="sidebar-brand">
            <h2>EL LOBITO</h2>
        </div>

        <ul class="sidebar-menu">
            <li>
                <a href="{{ route('inicio') }}" class="{{ request()->routeIs('inicio') ? 'active' : '' }}">
                    <i class="fas fa-home"></i> Inicio
                </a>
            </li>

            <li>
                <a href="#">
                    <i class="fas fa-file-invoice"></i> Historial Facturas
                </a>
            </li>

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

            <li>
                <a href="#">
                    <i class="fas fa-shopping-cart"></i> Ventas
                </a>
            </li>

            <li>
                <a href="{{ route('clientes.index') }}" class="{{ request()->routeIs('clientes.index') ? 'active' : '' }}">
                    <i class="fas fa-address-book"></i> Clientes
                </a>
            </li>

            @if (in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO']))
                <li>
                    <a href="#">
                        <i class="fas fa-file-invoice-dollar"></i> Gastos
                    </a>
                </li>
                <li>
                    <a href="#">
                        <i class="fas fa-cash-register"></i> Cierre
                    </a>
                </li>
                <li>
                    <a href="{{ route('catalogo.index') }}" class="{{ request()->routeIs('catalogo.index') ? 'active' : '' }}">
                        <i class="fas fa-box-open"></i> Menú (Productos)
                    </a>
                </li>
            @endif

            @if (in_array($rol, ['ADMIN', 'GERENTE']))
                <li class="menu-header">ADMINISTRACIÓN</li>
                <li>
                    <a href="#">
                        <i class="fas fa-box"></i> Operaciones
                    </a>
                </li>
                <li>
                    <a href="{{ route('usuarios.index') }}" class="{{ request()->routeIs('usuarios.index') ? 'active' : '' }}">
                        <i class="fas fa-users"></i> Usuarios
                    </a>
                </li>
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

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="{{ asset('script.js') }}"></script>
</body>
</html>