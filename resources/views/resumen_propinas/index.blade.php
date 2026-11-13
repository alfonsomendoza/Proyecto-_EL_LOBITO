@extends('layouts.app')

@section('content')
@php
    $opciones = $resumen['opciones'] ?? ['meseros' => [], 'usuarios' => [], 'mesas' => [], 'turnos' => [], 'cobradores' => []];
    $hayChips = ! empty($chips);
    // "Desde"/"Hasta" delimitan el período: no cuentan como filtros activos
    // para el mensaje de estado vacío.
    $hayFiltros = ! empty(array_filter(
        $chips,
        fn ($chip) => ! in_array($chip['clave'] ?? '', ['desde', 'hasta'], true)
    ));
    $total = (float) ($resumen['total'] ?? 0);
    $atribuido = (float) ($resumen['atribuido'] ?? 0);
    $pendiente = (float) ($resumen['pendiente'] ?? 0);
    $sinFactura = (int) ($resumen['cuentas_sin_factura'] ?? 0);

    // Formato monetario único del módulo: dos decimales y separador de miles.
    $money = fn ($valor) => 'C$ '.number_format((float) $valor, 2, '.', ',');

    // Participación SIEMPRE sobre el total real ya calculado (nunca estimada).
    $participacion = fn ($valor) => $total > 0 ? round(((float) $valor) * 100 / $total, 1) : 0.0;

    $consultaActual = request()->query();
    $urlCsv = route('reportes.propinas.csv', $consultaActual);
    $urlImprimir = route('reportes.propinas.imprimir', $consultaActual);
    $urlLimpiar = route('reportes.propinas');

    $kpiBase = 'border:1px solid #e3e6f0; border-radius:10px; background:#fff; padding:16px 18px; min-width:170px; flex:1 1 170px;';
@endphp

<div class="monitor-card" style="max-width: 1180px; margin: 0 auto;">
    <div class="monitor-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
        <span><i class="fas fa-hand-holding-dollar"></i> Administración — Resumen de Propinas</span>
        <span style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="{{ $urlCsv }}"
               style="background:#1e7e5c; color:#fff; padding:8px 14px; border-radius:5px; font-size:12px; font-weight:bold; text-decoration:none;">
                <i class="fas fa-file-csv"></i> Exportar CSV
            </a>
            <a href="{{ $urlImprimir }}" target="_blank" rel="noopener"
               style="background:#e9ecef; color:#333; padding:8px 14px; border-radius:5px; font-size:12px; font-weight:bold; text-decoration:none;">
                <i class="fas fa-print"></i> Imprimir
            </a>
        </span>
    </div>

    <div style="padding: 22px 25px;">

        {{-- Filtros combinables: fechas, mesero, quién abrió, mesa, turno y quién cobró. --}}
        <form method="GET" action="{{ route('reportes.propinas') }}"
              style="display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; border:1px solid #e3e6f0; border-radius:8px; padding:14px 16px; margin-bottom:18px; background:#fbfbfd;">
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="desde" style="font-size:0.8rem; font-weight:bold; color:#555;">Desde:</label>
                <input type="date" id="desde" name="desde" value="{{ $desde }}"
                       style="padding:8px 10px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="hasta" style="font-size:0.8rem; font-weight:bold; color:#555;">Hasta:</label>
                <input type="date" id="hasta" name="hasta" value="{{ $hasta }}"
                       style="padding:8px 10px; border:1px solid #ced4da; border-radius:5px;">
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="mesero" style="font-size:0.8rem; font-weight:bold; color:#555;">Mesero que atendió:</label>
                <select id="mesero" name="mesero" style="padding:8px 10px; border:1px solid #ced4da; border-radius:5px; min-width:190px;">
                    <option value="">Todos</option>
                    @foreach ($opciones['meseros'] as $idMesero => $nombreMesero)
                        <option value="{{ $idMesero }}" @selected((string) $filtros['mesero'] === (string) $idMesero)>{{ $nombreMesero }}</option>
                    @endforeach
                    <option value="pendiente" @selected($filtros['mesero'] === 'pendiente')>Pendiente de atribución</option>
                </select>
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="abrio" style="font-size:0.8rem; font-weight:bold; color:#555;">Abrió la mesa:</label>
                <select id="abrio" name="abrio" style="padding:8px 10px; border:1px solid #ced4da; border-radius:5px; min-width:170px;">
                    <option value="">Todos</option>
                    @foreach ($opciones['usuarios'] as $idUsuario => $nombreUsuario)
                        <option value="{{ $idUsuario }}" @selected((string) $filtros['abrio'] === (string) $idUsuario)>{{ $nombreUsuario }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="mesa" style="font-size:0.8rem; font-weight:bold; color:#555;">Mesa:</label>
                <select id="mesa" name="mesa" style="padding:8px 10px; border:1px solid #ced4da; border-radius:5px; min-width:130px;">
                    <option value="">Todas</option>
                    @foreach ($opciones['mesas'] as $idMesa => $nombreMesa)
                        <option value="{{ $idMesa }}" @selected((string) $filtros['mesa'] === (string) $idMesa)>{{ $nombreMesa }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="turno" style="font-size:0.8rem; font-weight:bold; color:#555;">Turno / caja:</label>
                <select id="turno" name="turno" style="padding:8px 10px; border:1px solid #ced4da; border-radius:5px; min-width:200px;">
                    <option value="">Todos</option>
                    @foreach ($opciones['turnos'] as $idTurno => $nombreTurno)
                        <option value="{{ $idTurno }}" @selected((string) $filtros['apertura'] === (string) $idTurno)>{{ $nombreTurno }}</option>
                    @endforeach
                </select>
            </div>
            <div style="display:flex; flex-direction:column; gap:5px;">
                <label for="cobro" style="font-size:0.8rem; font-weight:bold; color:#555;">Cobró:</label>
                <select id="cobro" name="cobro" style="padding:8px 10px; border:1px solid #ced4da; border-radius:5px; min-width:170px;">
                    <option value="">Todos</option>
                    @foreach ($opciones['cobradores'] as $idCobrador => $nombreCobrador)
                        <option value="{{ $idCobrador }}" @selected((string) $filtros['cobro'] === (string) $idCobrador)>{{ $nombreCobrador }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit"
                    style="background-color:#6777ef; color:#fff; border:none; padding:9px 18px; border-radius:5px; font-weight:bold; cursor:pointer;">
                <i class="fas fa-search"></i> Filtrar
            </button>
            <a href="{{ $urlLimpiar }}"
               style="background-color:#e9ecef; color:#333; padding:9px 18px; border-radius:5px; font-weight:bold; text-decoration:none;">
                <i class="fas fa-eraser"></i> Limpiar filtros
            </a>
        </form>

        {{-- Chips: qué filtros están activos y cuántos resultados hay. --}}
        @if ($hayChips)
            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:18px;">
                <span style="font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#8a94a6; font-weight:bold;">Filtros activos</span>
                @foreach ($chips as $chip)
                    <span style="display:inline-flex; align-items:center; gap:6px; background:#eef1ff; color:#3f52e3; border:1px solid #d6dcff; border-radius:999px; padding:4px 10px; font-size:12px;">
                        {{ $chip['texto'] }}
                        <a href="{{ $chip['quitar'] }}" title="Quitar este filtro"
                           style="color:#3f52e3; text-decoration:none; font-weight:bold;">&times;</a>
                    </span>
                @endforeach
                <a href="{{ $urlLimpiar }}" style="font-size:12px; color:#8a94a6;">Quitar todos</a>
            </div>
        @endif

        @if (! ($resumen['exito'] ?? false))
            <div style="background:#fff7e6; border:1px solid #f5d9a8; border-radius:6px; padding:14px 16px; font-size:13px; color:#7a5a1a; line-height:1.5;">
                <i class="fas fa-exclamation-triangle"></i>
                {{ $resumen['error'] ?? 'No hay información para esta fecha.' }}
            </div>
        @else

            {{-- Indicadores principales. El total se muestra UNA sola vez. --}}
            <div style="display:flex; gap:12px; flex-wrap:wrap; margin-bottom:14px;">
                <div style="{{ $kpiBase }} border-left:4px solid #16a085;">
                    <div style="font-size:10px; letter-spacing:.6px; text-transform:uppercase; color:#8a94a6; font-weight:bold;">TOTAL PROPINAS</div>
                    <div style="font-size:11px; color:#555; margin-top:2px;">Propinas cobradas al cliente</div>
                    <div style="font-size:22px; font-weight:bold; color:#16a085; margin-top:6px;">{{ $money($total) }}</div>
                    <div style="font-size:11px; color:#8a94a6;">{{ $resumen['cantidad'] }} cuenta(s) del período</div>
                </div>
                <div style="{{ $kpiBase }} border-left:4px solid #1e7e5c;">
                    <div style="font-size:11px; color:#555;">Atribuido a meseros</div>
                    <div style="font-size:19px; font-weight:bold; color:#1e7e5c; margin-top:8px;">{{ $money($atribuido) }}</div>
                    <div style="font-size:11px; color:#8a94a6;">{{ $resumen['cantidad_atribuidas'] }} cuenta(s) con mesero único</div>
                </div>
                <div style="{{ $kpiBase }} border-left:4px solid #b8860b;">
                    <div style="font-size:11px; color:#555;">Pendiente de atribución</div>
                    <div style="font-size:19px; font-weight:bold; color:#b8860b; margin-top:8px;">{{ $money($pendiente) }}</div>
                    <div style="font-size:11px; color:#8a94a6;">{{ $resumen['cantidad_pendientes'] }} cuenta(s) sin mesero único</div>
                </div>
                <div style="{{ $kpiBase }} border-left:4px solid #2980b9;">
                    <div style="font-size:11px; color:#555;">Cuentas cobradas con propina</div>
                    <div style="font-size:19px; font-weight:bold; color:#2980b9; margin-top:8px;">{{ $resumen['cantidad'] }}</div>
                    <div style="font-size:11px; color:#8a94a6;">Facturas EMITIDAS del período</div>
                </div>
                <div style="{{ $kpiBase }} border-left:4px solid #6c5ce7;">
                    <div style="font-size:11px; color:#555;">Meseros participantes</div>
                    <div style="font-size:19px; font-weight:bold; color:#6c5ce7; margin-top:8px;">{{ $resumen['meseros_participantes'] }}</div>
                    <div style="font-size:11px; color:#8a94a6;">{{ $resumen['meseros_con_propina'] }} con propina atribuida</div>
                </div>
            </div>

            {{-- Conciliación: una sola línea, sin repetir el total. --}}
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; font-size:12px; color:#495057; background:#e8f8f5; border:1px solid #c9ece2; border-radius:8px; padding:10px 14px; margin-bottom:14px;">
                <i class="fas fa-scale-balanced" style="color:#16a085;"></i>
                <span><strong>Conciliación:</strong> {{ $money($atribuido) }} atribuido + {{ $money($pendiente) }} pendiente = <strong>{{ $money($atribuido + $pendiente) }}</strong></span>
                <span style="color:#1e7e5c;"><i class="fas fa-circle-check"></i> coincide con las propinas cobradas</span>
            </div>

            {{-- Consistencia: cobros sin respaldo documental (NO suman al total). --}}
            <div style="font-size:12px; border-radius:8px; padding:10px 14px; margin-bottom:22px;
                        border:1px solid {{ $sinFactura > 0 ? '#f5d9a8' : '#e3e6f0' }};
                        background:{{ $sinFactura > 0 ? '#fff7e6' : '#fbfbfd' }};
                        color:{{ $sinFactura > 0 ? '#7a5a1a' : '#6c757d' }};">
                <i class="fas {{ $sinFactura > 0 ? 'fa-triangle-exclamation' : 'fa-circle-info' }}"></i>
                Cuentas pagadas sin factura vigente en el período: <strong>{{ $sinFactura }}</strong>.
                @if ($sinFactura > 0)
                    No se suman al total; revísalas en Facturas antes de cuadrar la propina.
                @else
                    Todos los cobros con propina tienen factura vigente.
                @endif
            </div>

            {{-- Resumen diario individual por mesero. --}}
            <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden; margin-bottom:22px;">
                <div style="padding:12px 16px; background:#2980b9; color:#fff; font-weight:600; font-size:14px;">
                    <i class="fas fa-user-tag"></i> Resumen diario individual por mesero
                </div>

                @if ($resumen['resumen_meseros']->isEmpty())
                    <div style="padding:24px 16px; text-align:center; color:#6c757d; font-size:13px;">
                        <i class="fas fa-inbox" style="font-size:24px; display:block; margin-bottom:8px; color:#c9ced6;"></i>
                        No hay propinas que resumir para los filtros seleccionados.
                    </div>
                @else
                    <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:13px; min-width:840px;">
                        <thead>
                            <tr style="background:#f4f6f9; color:#495057; text-align:left;">
                                <th style="padding:10px 12px;">Fecha</th>
                                <th style="padding:10px 12px;">Mesero</th>
                                <th style="padding:10px 12px;">Estado</th>
                                <th style="padding:10px 12px; text-align:right;">Mesas</th>
                                <th style="padding:10px 12px; text-align:right;">Cuentas</th>
                                <th style="padding:10px 12px; text-align:right;">Consumo</th>
                                <th style="padding:10px 12px; text-align:right;">Propinas</th>
                                <th style="padding:10px 12px; text-align:right;">Participación</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($resumen['resumen_meseros'] as $fila)
                                <tr style="border-bottom:1px solid #f1f1f1; {{ $fila['pendiente'] ? 'background:#fffdf5;' : '' }}">
                                    <td style="padding:10px 12px;">{{ \Illuminate\Support\Carbon::parse($fila['fecha'])->format('d/m/Y') }}</td>
                                    <td style="padding:10px 12px;">
                                        @if ($fila['pendiente'])
                                            <a href="{{ route('reportes.propinas', array_merge($consultaActual, ['mesero' => 'pendiente'])) }}"
                                               style="color:#b8860b; font-weight:bold; text-decoration:none;">{{ $fila['mesero'] }}</a>
                                        @else
                                            <a href="{{ route('reportes.propinas', array_merge($consultaActual, ['mesero' => $fila['mesero_id']])) }}"
                                               style="color:#2980b9; font-weight:bold; text-decoration:none;"
                                               title="Ver solo las cuentas de este mesero">{{ $fila['mesero'] }}</a>
                                        @endif
                                    </td>
                                    <td style="padding:10px 12px;">
                                        @if ($fila['pendiente'])
                                            <span style="background:#fff3cd; color:#8a6d1a; border-radius:999px; padding:2px 9px; font-size:11px;">Pendiente</span>
                                        @else
                                            <span style="background:#e8f8f5; color:#1e7e5c; border-radius:999px; padding:2px 9px; font-size:11px;">Atribuida</span>
                                        @endif
                                    </td>
                                    <td style="padding:10px 12px; text-align:right;">{{ $fila['mesas_atendidas'] }}</td>
                                    <td style="padding:10px 12px; text-align:right;">{{ $fila['cuentas_cobradas'] }}</td>
                                    <td style="padding:10px 12px; text-align:right;">{{ $money($fila['consumo']) }}</td>
                                    <td style="padding:10px 12px; text-align:right; font-weight:bold;">{{ $money($fila['propinas']) }}</td>
                                    <td style="padding:10px 12px; text-align:right;">{{ number_format($participacion($fila['propinas']), 1) }} %</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                    <div style="padding:8px 14px; font-size:11px; color:#8a94a6; border-top:1px solid #f1f1f1;">
                        La participación se calcula sobre las propinas cobradas del período. La suma de este
                        desglose coincide con el total general.
                    </div>
                @endif
            </div>

            {{-- Pendientes de atribución: atribuir una propina cobrada al mesero real. --}}
            @include('resumen_propinas._pendientes', [
                'pendientes' => $pendientes ?? [],
                'meserosAtribucion' => $meserosAtribucion ?? collect(),
                'rolPanel' => $rolPanel ?? (strtoupper((string) (auth()->user()?->rol?->nombre ?? ''))),
                'puedeCorregir' => $puedeCorregir ?? in_array(strtoupper((string) (auth()->user()?->rol?->nombre ?? '')), ['ADMIN', 'GERENTE'], true),
            ])

            {{-- Detalle por cuenta/factura (paginado: no afecta los totales). --}}
            <div style="border:1px solid #e3e6f0; border-radius:8px; overflow:hidden;">
                <div style="padding:12px 16px; background:#34495e; color:#fff; font-weight:600; font-size:14px; display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                    <span><i class="fas fa-list-alt"></i> Detalle por cuenta/factura</span>
                    <span style="font-size:12px; font-weight:400;">
                        @if ($paginaDetalle->total() > 0)
                            {{ $paginaDetalle->firstItem() }}–{{ $paginaDetalle->lastItem() }} de {{ $paginaDetalle->total() }}
                        @endif
                    </span>
                </div>

                @if ($paginaDetalle->total() === 0)
                    <div style="padding:30px 16px; text-align:center; color:#6c757d; font-size:13px;">
                        <i class="fas fa-inbox" style="font-size:28px; display:block; margin-bottom:10px; color:#c9ced6;"></i>
                        @if ($hayFiltros)
                            Ninguna cuenta del período {{ $desde }} → {{ $hasta }} coincide con los filtros
                            seleccionados. Quita alguno de los filtros activos para ampliar la búsqueda.
                        @else
                            No se registraron propinas cobradas en el período {{ $desde }} → {{ $hasta }}.
                        @endif
                    </div>
                @else
                    <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:13px; min-width:1280px;">
                        <thead>
                            <tr style="background:#f4f6f9; color:#495057; text-align:left;">
                                <th style="padding:10px 12px;">Fecha emisión</th>
                                <th style="padding:10px 12px;">Factura</th>
                                <th style="padding:10px 12px;">Mesa</th>
                                <th style="padding:10px 12px;">Cuenta</th>
                                <th style="padding:10px 12px;">Mesero</th>
                                <th style="padding:10px 12px;">Abrió</th>
                                <th style="padding:10px 12px;">Cobró</th>
                                <th style="padding:10px 12px;">Turno</th>
                                <th style="padding:10px 12px; text-align:right;">Consumo</th>
                                <th style="padding:10px 12px; text-align:right;">Propina</th>
                                <th style="padding:10px 12px;">Atribución</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($paginaDetalle as $linea)
                                <tr style="border-bottom:1px solid #f1f1f1;">
                                    <td style="padding:10px 12px; white-space:nowrap;">
                                        {{ $linea['fecha'] ? \Illuminate\Support\Carbon::parse($linea['fecha'])->format('d/m/Y H:i') : '—' }}
                                    </td>
                                    <td style="padding:10px 12px;">{{ $linea['numero_factura'] ?? '—' }}</td>
                                    <td style="padding:10px 12px;">{{ $linea['mesa_nombre'] ?: '—' }}</td>
                                    <td style="padding:10px 12px;">
                                        {{ $linea['cuenta_nombre'] }}
                                        <span style="color:#8a94a6; font-size:11px;">#{{ $linea['cuenta_id'] }}</span>
                                    </td>
                                    <td style="padding:10px 12px;">
                                        @if ($linea['atribucion'] === 'ATRIBUIDA')
                                            {{ $linea['mesero_atribuido'] ?? 'Usuario no identificado' }}
                                            @if (! empty($linea['mesero_atendio']) && $linea['mesero_atendio'] !== ($linea['mesero_atribuido'] ?? null))
                                                <div style="font-size:10px; color:#8a94a6;">atendió (comandas): {{ $linea['mesero_atendio'] }}</div>
                                            @endif
                                        @else
                                            {{ $linea['mesero_atendio'] ?? 'Sin mesero inequívoco' }}
                                        @endif
                                    </td>
                                    <td style="padding:10px 12px;">{{ $linea['usuario_abre_mesa'] ?? 'No disponible' }}</td>
                                    <td style="padding:10px 12px;">{{ $linea['usuario_cobro'] ?? 'No disponible' }}</td>
                                    <td style="padding:10px 12px;">{{ $linea['turno'] ?? '—' }}</td>
                                    <td style="padding:10px 12px; text-align:right;">{{ $money($linea['monto_consumo'] ?? 0) }}</td>
                                    <td style="padding:10px 12px; text-align:right; font-weight:bold;">{{ $money($linea['propina']) }}</td>
                                    <td style="padding:10px 12px;">
                                        @if ($linea['atribucion'] === 'ATRIBUIDA')
                                            <span style="color:#1e7e5c;"><i class="fas fa-check-circle"></i> {{ $linea['mesero_atribuido'] }}</span>
                                        @else
                                            <span style="color:#b8860b;"
                                                  title="{{ $linea['motivo_pendiente'] === 'varios_meseros' ? 'Varios meseros en la misma venta' : 'Sin comanda de un usuario MESERO' }}">
                                                <i class="fas fa-clock"></i> Pendiente
                                                <span style="font-size:11px; color:#8a94a6;">
                                                    ({{ $linea['motivo_pendiente'] === 'varios_meseros' ? 'varios meseros' : 'sin mesero' }})
                                                </span>
                                            </span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>

                    @if ($paginaDetalle->lastPage() > 1)
                        <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 14px; border-top:1px solid #f1f1f1; font-size:12px;">
                            <span style="color:#6c757d;">Página {{ $paginaDetalle->currentPage() }} de {{ $paginaDetalle->lastPage() }}</span>
                            <span style="display:flex; gap:8px;">
                                @if ($paginaDetalle->onFirstPage())
                                    <span style="padding:6px 12px; border:1px solid #e3e6f0; border-radius:5px; color:#adb5bd;">Anterior</span>
                                @else
                                    <a href="{{ $paginaDetalle->previousPageUrl() }}"
                                       style="padding:6px 12px; border:1px solid #ced4da; border-radius:5px; color:#495057; text-decoration:none;">Anterior</a>
                                @endif
                                @if ($paginaDetalle->hasMorePages())
                                    <a href="{{ $paginaDetalle->nextPageUrl() }}"
                                       style="padding:6px 12px; border:1px solid #ced4da; border-radius:5px; color:#495057; text-decoration:none;">Siguiente</a>
                                @else
                                    <span style="padding:6px 12px; border:1px solid #e3e6f0; border-radius:5px; color:#adb5bd;">Siguiente</span>
                                @endif
                            </span>
                        </div>
                    @endif
                @endif
            </div>

            {{-- Notas metodológicas (colapsables para no competir con las cifras). --}}
            <details style="margin-top:18px; font-size:12px; color:#6c757d;">
                <summary style="cursor:pointer; font-weight:bold; color:#495057;">
                    <i class="fas fa-circle-info"></i> Notas metodológicas
                </summary>
                <div style="padding:10px 4px; line-height:1.6;">
                    <p style="margin:0 0 8px;">
                        Solo se incluyen cuentas <strong>PAGADAS</strong> con propina mayor a cero cuya factura esté
                        <strong>EMITIDA</strong>; se excluyen ABIERTA/CANCELADA y las facturas ANULADAS. De cada cuenta se
                        toma únicamente la última factura EMITIDA, así un recobro no duplica el importe.
                    </p>
                    <p style="margin:0 0 8px;">
                        El período se aplica a la <strong>fecha de emisión de la factura</strong>
                        (<code>facturas.fecha_emision</code>), que se registra al cobrar y no cambia después. No se usa
                        <code>cuentas.updated_at</code> porque editar una cuenta antigua no debe moverla de día.
                    </p>
                    <p style="margin:0 0 8px;">
                        La propina se lee una sola vez por cuenta desde <code>cuentas.propina</code>. El «consumo» sale de
                        <code>cuenta_detalles</code> (la misma fuente del cobro), por eso no aparece en cero si hay
                        consumo registrado.
                    </p>
                    <p style="margin:0 0 8px;">
                        <strong>Solo los usuarios con rol MESERO reciben propinas.</strong> Abrir la mesa o cobrar la
                        factura no atribuye la propina. Si la venta tiene varios meseros, la propina queda
                        <strong>pendiente de atribución</strong>: no se reparte ni se cuenta más de una vez. Por eso
                        <code>atribuido + pendiente</code> es siempre igual al total.
                    </p>
                    <p style="margin:0;">
                        La propina se cobra al cliente en el mismo cobro de la factura. El <strong>pago de las propinas al
                        personal no se registra en el sistema</strong>, así que este módulo no muestra ese dato como si
                        existiera. El módulo es de solo lectura: no modifica facturas, cuentas ni propinas.
                    </p>
                </div>
            </details>

        @endif
    </div>
</div>
@endsection
