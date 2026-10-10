@extends('layouts.app')

@section('content')

{{--
    Panel de subcuentas de una mesa.

    Muestra únicamente las cuentas que pertenecen a la VENTA ACTIVA de la mesa
    seleccionada. No crea ventas ni cuentas: solo lista y permite abrir cada
    cuenta para trabajar con ella.

    Variables recibidas desde VentaController@panelCuentas:
    - $mesa      : datos de la mesa (tabla mesas)
    - $venta     : venta activa de la mesa (tabla ventas)
    - $cuentas   : cuentas de esa venta (se excluyen las CANCELADA)
    - $puedeCrear: true para ADMIN, GERENTE, CAJERO y MESERO (Fase 2)
    - $puedeDividir: true para ADMIN, GERENTE y CAJERO (permisos actuales)
--}}

<style>
    .grid-cuentas { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 20px; padding: 20px; }
    .cuenta-card { background: #fff; border: 1px solid #f0f0f0; border-radius: 10px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.06); overflow: hidden; display: flex; flex-direction: column; transition: transform 0.2s, box-shadow 0.2s; }
    .cuenta-card:hover { transform: translateY(-4px); box-shadow: 0 8px 14px rgba(0, 0, 0, 0.12); }
    .cuenta-body { padding: 20px; flex: 1; }
    .cuenta-nombre { font-size: 17px; font-weight: 700; color: #191d21; margin: 0 0 6px; word-break: break-word; }
    .cuenta-total-label { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #98a6ad; font-weight: 700; }
    .cuenta-total { font-size: 26px; font-weight: 800; color: #34395e; margin: 4px 0 0; }
    .badge-estado { display: inline-block; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; color: #fff; }
    .badge-ABIERTA { background: #47c363; }
    .badge-COBRANDO { background: #ffa426; }
    .badge-PAGADA { background: #6c757d; }
    .cuenta-footer { border-top: 1px solid #f4f6f9; padding: 12px 20px; display: flex; justify-content: flex-end; }
    .btn-abrir { background: #6777ef; color: #fff; border: none; padding: 7px 16px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-abrir:hover { opacity: .88; }
    .btn-volver { background: #6c757d; color: #fff; border: none; padding: 7px 16px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-volver:hover { opacity: .88; }
    .btn-dividir { background: transparent; color: #6777ef; border: 1px solid #6777ef; padding: 6px 14px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-dividir:hover { background: #6777ef; color: #fff; }
    .contenido-vacio { padding: 50px 20px; text-align: center; color: #98a6ad; }
</style>

<div id="panel-cuentas" class="monitor-card" style="max-width: 1200px; margin: 0 auto;">

    {{-- Encabezado del panel --}}
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; background: #fff; padding: 18px 20px;">
        <div style="display: flex; align-items: center; gap: 15px;">
            <span style="font-size: 18px; font-weight: bold; color: #34395e;">
                <i class="fas fa-layer-group"></i> Cuentas de Mesa {{ $mesa->numero }}
            </span>
            <span style="font-size: 12px; color: #98a6ad; font-weight: bold;">
                Venta #{{ $venta->id }} · {{ $venta->estado }}
            </span>
        </div>

        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
            @if($puedeDividir)
                {{-- Lleva al POS general, donde ya existe el modal de dividir cuentas. --}}
                <a href="{{ route('pos.index', $mesa->id) }}" class="btn-dividir"
                   title="Administrar la venta completa de la mesa">
                    <i class="fas fa-people-arrows"></i> Dividir cuentas
                </a>
            @endif
            <a href="{{ route('mesas.index') }}" class="btn-volver">
                <i class="fas fa-arrow-left"></i> Volver a Mesas
            </a>
        </div>
    </div>

    @if($cuentas->isEmpty())
        <div class="contenido-vacio">
            <i class="fas fa-inbox" style="font-size: 40px; display: block; margin-bottom: 10px;"></i>
            Esta mesa no tiene cuentas registradas.
        </div>
    @else
        <div class="grid-cuentas">
            @foreach($cuentas as $cuenta)
                @php
                    // Clase de color del badge según el estado real de la cuenta.
                    $clasesEstado = [
                        'ABIERTA' => 'badge-ABIERTA',
                        'COBRANDO' => 'badge-COBRANDO',
                        'PAGADA' => 'badge-PAGADA',
                    ];
                    $claseBadge = $clasesEstado[$cuenta->estado] ?? 'badge-PAGADA';
                @endphp

                <div class="cuenta-card">
                    <div class="cuenta-body">
                        <p class="cuenta-nombre">{{ $cuenta->nombre }}</p>
                        <span class="badge-estado {{ $claseBadge }}">{{ $cuenta->estado }}</span>
                        <div style="margin-top: 14px;">
                            <span class="cuenta-total-label">Total</span>
                            <p class="cuenta-total">C${{ number_format((float) $cuenta->total, 2) }}</p>
                        </div>
                    </div>
                    <div class="cuenta-footer">
                        {{--
                            Abre el POS para esta cuenta. En la Fase 1 el POS aún
                            trabaja a nivel de mesa; el parámetro ?cuenta= queda
                            listo para la Fase 3 (POS filtrado por subcuenta).
                        --}}
                        <a class="btn-abrir"
                           href="{{ route('pos.index', $mesa->id) }}?cuenta={{ $cuenta->id }}"
                           title="Abrir esta cuenta">
                            <i class="fas fa-folder-open"></i> Abrir cuenta
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

@endsection
