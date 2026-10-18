@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 1100px; margin: 0 auto;">
    <div class="monitor-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <span><i class="fas fa-clock-rotate-left"></i> Inventario — Historial de movimientos</span>
        <a href="{{ route('inventario.index') }}"
           style="background-color:#34495e; color:#fff; padding:6px 12px; border-radius:4px; font-weight:bold; font-size:12px; text-decoration:none;">
            <i class="fas fa-boxes-stacked"></i> Volver al inventario
        </a>
    </div>

    <div style="padding: 25px;">

        @if ($pendiente)
            <div style="background:#fff7e6; border:1px solid #f5d9a8; border-radius:6px; padding:14px 16px; font-size:13px; color:#7a5a1a; line-height:1.5;">
                <i class="fas fa-exclamation-triangle"></i>
                El módulo de inventario todavía no está disponible: faltan aplicar las
                migraciones de la base de datos.
            </div>
        @else

        {{-- Filtros del historial --}}
        <div style="border:1px solid #e3e6f0; border-radius:8px; padding:14px 16px; margin-bottom:18px;">
            <form method="GET" action="{{ route('inventario.historial') }}" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label style="font-size:0.85rem; font-weight:bold; color:#555;">Producto:</label>
                    <select name="producto_id" style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px; min-width:220px;">
                        <option value="">Todos los productos</option>
                        @foreach ($productosFiltro as $p)
                            <option value="{{ $p->id }}" {{ (int) $productoId === (int) $p->id ? 'selected' : '' }}>
                                {{ $p->nombre }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div style="display:flex; flex-direction:column; gap:5px;">
                    <label style="font-size:0.85rem; font-weight:bold; color:#555;">Tipo de movimiento:</label>
                    <select name="tipo" style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px; min-width:180px;">
                        <option value="">Todos los tipos</option>
                        @foreach ($tipos as $t)
                            <option value="{{ $t }}" {{ $tipo === $t ? 'selected' : '' }}>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit"
                        style="background-color:#17a2b8; color:#fff; border:none; padding:9px 18px; border-radius:5px; font-weight:bold; cursor:pointer;">
                    <i class="fas fa-filter"></i> Filtrar
                </button>
                <a href="{{ route('inventario.historial') }}"
                   style="color:#6c757d; font-size:13px; text-decoration:none; padding:9px 4px;">
                    Limpiar
                </a>
            </form>
        </div>

        {{-- Tabla del historial (máximo 300 movimientos más recientes) --}}
        <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden;">
            <div style="padding:12px 16px; background:#2c3e50; color:#fff; font-weight:600; font-size:14px;">
                <i class="fas fa-list-alt"></i> Movimientos ({{ $movimientos->count() }})
            </div>

            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#f4f6f9; color:#495057; text-align:left;">
                        <th style="padding:10px 12px;">Fecha / hora</th>
                        <th style="padding:10px 12px;">Producto</th>
                        <th style="padding:10px 12px;">Tipo</th>
                        <th style="padding:10px 12px; text-align:right;">Cantidad</th>
                        <th style="padding:10px 12px;">Usuario</th>
                        <th style="padding:10px 12px;">Observación</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($movimientos as $m)
                        @php
                            $cantidad = (float) $m->cantidad;
                            $fecha = $m->creado_en ? \Illuminate\Support\Carbon::parse($m->creado_en) : null;
                        @endphp
                        <tr style="border-bottom:1px solid #f1f1f1;">
                            <td style="padding:10px 12px; white-space:nowrap;">
                                {{ $fecha ? $fecha->format('d/m/Y H:i') : '—' }}
                            </td>
                            <td style="padding:10px 12px;"><strong>{{ $m->producto ?? ('#' . $m->id) }}</strong></td>
                            <td style="padding:10px 12px;">
                                <span style="background:#eef2ff; color:#3b4cca; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">
                                    {{ $m->tipo }}
                                </span>
                            </td>
                            <td style="padding:10px 12px; text-align:right; font-weight:bold; color:{{ $cantidad < 0 ? '#c0392b' : '#155724' }};">
                                {{ $cantidad > 0 ? '+' : '' }}{{ number_format($cantidad, 3) }}
                            </td>
                            <td style="padding:10px 12px;">{{ $m->usuario ?? 'Sistema' }}</td>
                            <td style="padding:10px 12px;">{{ $m->observacion ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="text-align:center; padding:20px; color:#888;">
                                No hay movimientos de inventario que coincidan con el filtro.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @endif
    </div>
</div>
@endsection
