@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 1000px; margin: 0 auto;">
    <div class="monitor-header">
        <span><i class="fas fa-file-invoice"></i> Historial de Facturas</span>
    </div>

    <div style="padding: 25px;">

        {{-- Filtros del historial. Son de solo lectura. --}}
        <form method="GET" action="{{ route('facturas.index') }}"
              style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; border:1px solid #e3e6f0; border-radius:8px; padding:14px 16px; margin-bottom:22px; background:#fbfbfd;">
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="numero" style="font-size:0.85rem; font-weight:bold; color:#555;">N° Factura:</label>
                <input type="text" id="numero" name="numero" value="{{ $filtros['numero'] }}" placeholder="FAC-0001"
                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="cliente" style="font-size:0.85rem; font-weight:bold; color:#555;">Cliente:</label>
                <input type="text" id="cliente" name="cliente" value="{{ $filtros['cliente'] }}" placeholder="Nombre o identificación"
                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="mesa" style="font-size:0.85rem; font-weight:bold; color:#555;">Mesa:</label>
                <input type="text" id="mesa" name="mesa" value="{{ $filtros['mesa'] }}" placeholder="Mesa 1"
                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="estado" style="font-size:0.85rem; font-weight:bold; color:#555;">Estado:</label>
                <select id="estado" name="estado" style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
                    <option value="">Todos</option>
                    <option value="EMITIDA" {{ $filtros['estado'] === 'EMITIDA' ? 'selected' : '' }}>Emitidas</option>
                    <option value="ANULADA" {{ $filtros['estado'] === 'ANULADA' ? 'selected' : '' }}>Anuladas</option>
                </select>
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="desde" style="font-size:0.85rem; font-weight:bold; color:#555;">Desde:</label>
                <input type="date" id="desde" name="desde" value="{{ $filtros['desde'] }}"
                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="hasta" style="font-size:0.85rem; font-weight:bold; color:#555;">Hasta:</label>
                <input type="date" id="hasta" name="hasta" value="{{ $filtros['hasta'] }}"
                       style="padding:8px 12px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <button type="submit"
                    style="background-color:#6777ef; color:#fff; border:none; padding:9px 18px; border-radius:5px; font-weight:bold; cursor:pointer;">
                <i class="fas fa-search"></i> Buscar
            </button>
            <a href="{{ route('facturas.index') }}"
               style="background-color:#adb5bd; color:#fff; padding:9px 18px; border-radius:5px; font-weight:bold; text-decoration:none;">
                Limpiar
            </a>
        </form>

        <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden;">
            <table style="width:100%; border-collapse:collapse; font-size:13px;">
                <thead>
                    <tr style="background:#f4f6f9; color:#495057; text-align:left;">
                        <th style="padding:10px 12px;">N° Factura</th>
                        <th style="padding:10px 12px;">Fecha</th>
                        <th style="padding:10px 12px;">Mesa</th>
                        <th style="padding:10px 12px;">Cuenta</th>
                        <th style="padding:10px 12px;">Cliente</th>
                        <th style="padding:10px 12px;">Cajero</th>
                        <th style="padding:10px 12px;">Método</th>
                        <th style="padding:10px 12px; text-align:right;">Total</th>
                        <th style="padding:10px 12px; text-align:center;">Estado</th>
                        <th style="padding:10px 12px; text-align:center;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($facturas as $factura)
                        <tr style="border-bottom:1px solid #f1f1f1;">
                            <td style="padding:10px 12px;"><strong>{{ $factura->numero_factura }}</strong></td>
                            <td style="padding:10px 12px;">{{ $factura->fecha_emision ? \Illuminate\Support\Carbon::parse($factura->fecha_emision)->format('d/m/Y H:i') : '--' }}</td>
                            <td style="padding:10px 12px;">{{ $factura->mesa }}</td>
                            <td style="padding:10px 12px;">
                                @if (!empty($factura->cuenta_id))
                                    {{ $factura->cuenta_nombre ?? 'Cuenta' }} <small style="color:#6c757d;">(#{{ $factura->cuenta_id }})</small>
                                @else
                                    <span style="color:#aaa;">--</span>
                                @endif
                            </td>
                            <td style="padding:10px 12px;">{{ $factura->cliente ?? '--' }}</td>
                            <td style="padding:10px 12px;">{{ $factura->cajero ?? '--' }}</td>
                            <td style="padding:10px 12px;">
                                {{ $factura->metodo_pago ?? '--' }}
                                @if (!empty($factura->banco))<small style="color:#6c757d;"> · {{ $factura->banco }}</small>@endif
                            </td>
                            <td style="padding:10px 12px; text-align:right; font-weight:bold;">C$ {{ number_format((float) $factura->total, 2) }}</td>
                            <td style="padding:10px 12px; text-align:center;">
                                @if ($factura->estado === 'ANULADA')
                                    <span style="background:#f8d7da; color:#721c24; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">ANULADA</span>
                                @else
                                    <span style="background:#d4edda; color:#155724; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold;">EMITIDA</span>
                                @endif
                            </td>
                            <td style="padding:10px 12px; text-align:center; white-space:nowrap;">
                                <a href="{{ route('facturas.ticket', ['id' => $factura->id]) }}" target="_blank"
                                   style="display:inline-block; background:transparent; color:#5a6268; border:1px solid #5a6268; padding:4px 10px; border-radius:4px; font-size:12px; text-decoration:none;"
                                   title="Imprimir esta factura">
                                    <i class="fas fa-print"></i> Imprimir
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" style="text-align:center; padding:24px; color:#888;">
                                No hay facturas que coincidan con los filtros.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
