@extends('layouts.app')

@section('content')
<div class="monitor-card" style="max-width: 1180px; margin: 0 auto;">
    <div class="monitor-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <span><i class="fas fa-hand-holding-dollar"></i> Atribuir propinas — Pendientes</span>
        <span style="font-size:12px; color:#6c757d;">No ves totales ni el reporte financiero</span>
    </div>

    <div style="padding: 22px 25px;">
        <p style="font-size:13px; color:#555; margin:0 0 16px; line-height:1.6;">
            Propinas cobradas que aún no tienen mesero inequívoco. Selecciona el mesero que
            realmente atendió la cuenta e indica el motivo. La operación queda auditada y
            nunca altera factura, consumos, pagos ni el importe de la propina.
        </p>

        @include('resumen_propinas._pendientes', [
            'pendientes' => $pendientes,
            'meserosAtribucion' => $meserosAtribucion,
            'rolPanel' => $rolPanel,
            'puedeCorregir' => $puedeCorregir,
        ])
    </div>
</div>
@endsection
