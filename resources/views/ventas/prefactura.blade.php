<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Prefactura — Cuenta {{ $cuenta->nombre }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Courier New', Courier, monospace; }
        body { width: 100%; max-width: 300px; margin: 0 auto; padding: 10px; font-size: 12px; color: #000; }
        .centro { text-align: center; }
        .derecha { text-align: right; }
        .linea { border-top: 1px dashed #000; margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 3px 0; vertical-align: top; }
        h2 { font-size: 16px; margin-bottom: 3px; }
        p { font-size: 11px; margin-bottom: 2px; }
        .resaltado { font-weight: bold; font-size: 13px; }
        .sugerencia { font-size: 10px; color: #555; }
        .btn-imprimir { display: block; width: 100%; padding: 10px; margin-top: 12px; background: #1a1a2e; color: #fff; border: 0; font-size: 13px; cursor: pointer; }
        @media print { .btn-imprimir { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="centro">
        <h2>RESTAURANTE EL LOBITO</h2>
        <p><strong>PREFACTURA - SIMULACIÓN TEMPORAL</strong></p>
        <p style="font-size:10px;">NO ES FACTURA FISCAL - VALORES ESTIMADOS - SIN EFECTOS EN BASE DE DATOS</p>
    </div>

    <table>
        <tr><td><strong>Fecha:</strong></td><td class="derecha">{{ $emision->format('d/m/Y H:i') }}</td></tr>
        <tr><td><strong>Mesa:</strong></td><td class="derecha">{{ $mesa->numero ?? 'Mesa' }}</td></tr>
        <tr><td><strong>Cuenta:</strong></td><td class="derecha">{{ $cuenta->nombre }} (#{{ $cuenta->id }})</td></tr>
        <tr><td><strong>Estado:</strong></td><td class="derecha">{{ $cuenta->estado }}</td></tr>
        <tr>
            <td><strong>Mesero encargado:</strong></td>
            <td class="derecha">
                @if ($puedeEditarMesero ?? false)
                    <select id="pf-mesero" style="padding:3px; border:1px solid #ddd; border-radius:3px;">
                        <option value="">Pendiente de asignación</option>
                        @foreach ($meserosSelect ?? [] as $m)
                            <option value="{{ $m->id }}" @selected(isset($cuenta->mesero_responsable_id) && (int) $cuenta->mesero_responsable_id === (int) $m->id)>
                                {{ $m->nombre }}
                            </option>
                        @endforeach
                    </select>
                    <button id="pf-mesero-guardar" type="button" style="margin-left:5px; padding:2px 8px;">Guardar</button>
                @else
                    {{ $meseroEncargado ?? 'Pendiente de asignación' }}
                @endif
            </td>
        </tr>
        <tr><td><strong>Abierta por:</strong></td><td class="derecha">{{ $abrioPor ?? 'No registrado' }}</td></tr>
    </table>

    <div class="linea"></div>

    <p><strong>Detalle del consumo (esta cuenta):</strong></p>
    @if (count($lineas) > 0)
        <table style="margin-top: 6px;">
            <tr>
                <td><strong>Cant.</strong></td>
                <td><strong>Producto</strong></td>
            </tr>
            @foreach ($lineas as $linea)
                <tr>
                    <td>{{ (int) $linea['cantidad'] }}x</td>
                    <td>
                        {{ $linea['producto'] }}
                        @if (array_key_exists('requiere_cocina', $linea) && $linea['requiere_cocina'] !== null)
                            <div class="sugerencia">Cocina: {{ $linea['requiere_cocina'] ? 'Sí' : 'No' }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @else
        <p style="margin-top: 6px;">Sin consumo asignado a esta cuenta.</p>
    @endif

    <div class="linea"></div>

    {{-- Desglose completo: solo los conceptos que realmente se aplican y
         tienen importe mayor a cero. No se muestran estados internos
         ACTIVO/INACTIVO ni filas con C$0.00. --}}
    <table>
        <tr>
            <td>Consumo:</td>
            <td class="derecha">C$ {{ number_format($consumo, 2) }}</td>
        </tr>
        @if ($ivaHabilitado && ($fiscal['iva'] ?? 0) > 0)
        <tr>
            <td>IVA (15 %):</td>
            <td class="derecha">+ C$ {{ number_format($fiscal['iva'], 2) }}</td>
        </tr>
        @endif
        @if ($descuento > 0)
        <tr>
            <td>Descuento:</td>
            <td class="derecha">− C$ {{ number_format($descuento, 2) }}</td>
        </tr>
        @endif
        @if ($descuento > 0 || $propina > 0)
        <tr>
            <td>Base (consumo − descuento):</td>
            <td class="derecha">C$ {{ number_format($base, 2) }}</td>
        </tr>
        @endif
        @if ($propina > 0)
        <tr>
            <td>
                Propina:
                @if ($porcentajePropina !== null)
                    <span class="sugerencia">({{ number_format($porcentajePropina, 2) }}% de la base)</span>
                @endif
            </td>
            <td class="derecha">+ C$ {{ number_format($propina, 2) }}</td>
        </tr>
        @endif
        @if ($propina <= 0 && $descuento > 0 && $propinaSugerida > 0)
            <tr>
                <td class="sugerencia" colspan="2">Propina sugerida (10% de la base): C$ {{ number_format($propinaSugerida, 2) }} — no incluida.</td>
            </tr>
        @endif
        @if (($fiscal['total_retenciones'] ?? 0) > 0)
        <tr>
            <td>Total factura:</td>
            <td class="derecha">C$ {{ number_format($fiscal['total_factura'], 2) }}</td>
        </tr>
        @if ($retencionDgiHabilitada && ($fiscal['retencion_dgi'] ?? 0) > 0)
        <tr>
            <td>Retención DGI (2 %):</td>
            <td class="derecha">- C$ {{ number_format($fiscal['retencion_dgi'], 2) }}</td>
        </tr>
        @endif
        @if ($retencionAlcaldiaHabilitada && ($fiscal['retencion_alcaldia'] ?? 0) > 0)
        <tr>
            <td>Retención Alcaldía (1 %):</td>
            <td class="derecha">- C$ {{ number_format($fiscal['retencion_alcaldia'], 2) }}</td>
        </tr>
        @endif
        <tr>
            <td>Total retenciones:</td>
            <td class="derecha">- C$ {{ number_format($fiscal['total_retenciones'], 2) }}</td>
        </tr>
        @endif
        <tr>
            <td class="resaltado">TOTAL POR COBRAR:</td>
            <td class="derecha resaltado">C$ {{ number_format($total, 2) }}</td>
        </tr>
    </table>

    <div class="linea"></div>
    <div class="centro" style="margin-top: 8px;">
        <p>Este documento NO constituye factura fiscal.</p>
        <p>Los montos pueden variar hasta confirmar el cobro.</p>
    </div>

    <button type="button" class="btn-imprimir" onclick="window.print()">Imprimir</button>
    <script>
        (function () {
            const btn = document.getElementById('pf-mesero-guardar');
            const select = document.getElementById('pf-mesero');
            if (!btn || !select) return;
            btn.addEventListener('click', async function () {
                const meseroId = Number(select.value) || 0;
                const formData = new FormData();
                formData.append('_token', '{{ csrf_token() }}');
                formData.append('mesero_id', meseroId > 0 ? String(meseroId) : '');
                const respuesta = await fetch('{{ route('cuentas.mesero.asignar', ['cuentaId' => $cuenta->id]) }}', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: formData,
                });
                const cuerpo = await respuesta.json().catch(() => null);
                if (respuesta.ok && cuerpo && cuerpo.exito) {
                    window.location.reload();
                } else {
                    alert((cuerpo && cuerpo.error) ? cuerpo.error : 'No se pudo guardar el mesero.');
                }
            });
        })();
    </script>
</body>
</html>
