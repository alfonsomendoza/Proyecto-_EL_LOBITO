@extends('layouts.app')

@section('content')

<style>
    .factura-ticket {
        max-width: 700px;
        margin: 0 auto;
        border: 1px solid #eceff3;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 4px 10px rgba(0,0,0,0.05);
        font-size: 13px;
    }
    .factura-header {
        background: #34395e;
        color: #fff;
        padding: 16px 20px;
    }
    .factura-header h2 {
        margin: 0;
        font-size: 18px;
    }
    .factura-body { padding: 18px 20px; }
    .factura-meta {
        display: flex;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 14px;
    }
    .factura-meta div { font-size: 12px; color: #5a5f7d; }
    .factura-meta strong { color: #34395e; }
    .factura-cliente {
        background: #f8f9fa;
        border-left: 3px solid #6777ef;
        padding: 10px 14px;
        border-radius: 4px;
        margin-bottom: 16px;
        font-size: 13px;
    }
    .factura-cliente .label {
        font-size: 11px;
        text-transform: uppercase;
        color: #98a6ad;
        font-weight: 700;
        letter-spacing: .4px;
    }
    .factura-cliente .value {
        font-weight: 600;
        color: #34395e;
        margin-top: 2px;
    }
    .factura-cliente .value small {
        color: #98a6ad;
        font-weight: 400;
        margin-left: 6px;
    }
    .factura-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .factura-table th {
        text-align: left;
        padding: 8px 10px;
        background: #f4f6f9;
        border-bottom: 1px solid #e3e6f0;
        color: #5a5f7d;
        font-weight: 700;
    }
    .factura-table td { padding: 8px 10px; border-bottom: 1px solid #f0f0f0; }
    .factura-footer {
        border-top: 1px solid #eceff3;
        padding: 12px 20px;
        display: flex;
        justify-content: space-between;
        background: #f8f9fa;
    }
    .factura-importe { font-size: 20px; font-weight: 800; color: #34395e; }
    .factura-propina { font-size: 12px; color: #98a6ad; }
    .factura-pendiente { color: #dc3545; font-weight: 600; }
    .factura-anulada {
        background: #fdecea;
        color: #dc3545;
        padding: 10px 14px;
        border-radius: 4px;
        font-weight: 600;
        margin-bottom: 12px;
    }
    .factura-snapshot-hint {
        font-size: 11px;
        color: #98a6ad;
        margin-top: 6px;
        font-style: italic;
    }
</style>

<div class="factura-ticket">
    <div class="factura-header">
        <h2><i class="fas fa-file-invoice"></i> Factura #{{ $factura->id }}</h2>
        <p style="margin:4px 0 0; font-size:12px; opacity:.9;">
            {{ $factura->fecha->format('d/m/Y H:i') }}
        </p>
    </div>

    <div class="factura-body">
        @if($factura->estado === 'ANULADA')
            <div class="factura-anulada">
                <i class="fas fa-ban"></i> Factura Anulada
            </div>
        @endif

        <div class="factura-meta">
            <div>
                <strong>Fecha:</strong> {{ $factura->fecha->format('d/m/Y H:i') }}<br>
                <strong>Mesa:</strong> {{ $factura->mesa_numero ?? 'N/A' }}<br>
                <strong>Venta:</strong> #{{ $factura->venta_id ?? 'N/A' }}
            </div>
            <div>
                <strong>Estado:</strong> {{ $factura->estado }}<br>
                <strong>Método:</strong> {{ $factura->metodo_pago ?? 'N/A' }}<br>
                <strong>Banco:</strong> {{ $factura->banco ?? 'N/A' }}
            </div>
        </div>

        @if(isset($factura->cliente_snapshot) && $factura->cliente_snapshot)
            <div class="factura-cliente">
                <div class="label"><i class="fas fa-user"></i> Cliente (snapshot al emitir)</div>
                <div class="value">
                    {{ $factura->cliente_snapshot->nombre ?? '-' }}
                    @if($factura->cliente_snapshot->identificacion)
                        <small>· {{ $factura->cliente_snapshot->identificacion }}</small>
                    @endif
                    @if($factura->cliente_snapshot->telefono)
                        <small>· {{ $factura->cliente_snapshot->telefono }}</small>
                    @endif
                </div>
                <div class="factura-snapshot-hint">
                    <i class="fas fa-info-circle"></i>
                    Este dato es una copia fija tomada al emitir la factura; editar el cliente después no modifica esta factura.
                </div>
            </div>
        @else
            <div class="factura-cliente">
                <div class="label"><i class="fas fa-user"></i> Cliente</div>
                <div class="value">
                    {{ $factura->cliente_nombre ?? 'No registrado' }}
                    @if($factura->cliente_identificacion)
                        <small>· {{ $factura->cliente_identificacion }}</small>
                    @endif
                </div>
                <div class="factura-snapshot-hint">
                    <i class="fas fa-exclamation-circle"></i>
                    Factura histórica sin snapshot de cliente.
                </div>
            </div>
        @endif

        <table class="factura-table">
            <thead>
                <tr>
                    <th>Producto</th>
                    <th style="text-align:right;">Cant.</th>
                    <th style="text-align:right;">P. Unit.</th>
                    <th style="text-align:right;">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                @foreach($factura->detalles as $detalle)
                <tr>
                    <td>{{ $detalle->nombre }}</td>
                    <td style="text-align:right;">{{ $detalle->cantidad }}</td>
                    <td style="text-align:right;">C$ {{ number_format($detalle->precio_unitario, 2) }}</td>
                    <td style="text-align:right;">C$ {{ number_format($detalle->subtotal, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        @if((float) ($factura->descuento ?? 0) > 0 || (float) ($factura->propina ?? 0) > 0)
            <div style="margin: 10px 0; font-size:12px;">
                @if((float) ($factura->descuento ?? 0) > 0)
                    <div>Descuento: C$ {{ number_format((float) $factura->descuento, 2) }}</div>
                @endif
                @if((float) ($factura->propina ?? 0) > 0)
                    <div>Propina: C$ {{ number_format((float) $factura->propina, 2) }}</div>
                @endif
            </div>
        @endif

        <div class="factura-footer">
            <div>
                <div style="font-size:12px; color:#5a5f7d;">Total consumos</div>
                <div class="factura-importe">C$ {{ number_format((float) $factura->total, 2) }}</div>
            </div>
            <div>
                <div style="font-size:12px; color:#5a5f7d;">Cantidad recibida</div>
                <div style="font-weight:600; color:#34395e;">C$ {{ number_format((float) ($factura->monto_recibido ?? 0), 2) }}</div>
            </div>
            <div>
                <div style="font-size:12px; color:#5a5f7d;">Cambio</div>
                <div style="font-weight:600; color:#34395e;">C$ {{ number_format((float) ($factura->cambio ?? 0), 2) }}</div>
            </div>
        </div>
    </div>
</div>

@endsection
