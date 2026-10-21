@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h4>Historial de Caja</h4>
                </div>
                <div class="card-body">
                    <h5>Aperturas</h5>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Fecha</th>
                                    <th>Cajero</th>
                                    <th>Caja</th>
                                    <th>Turno</th>
                                    <th>Monto Inicial</th>
                                    <th>Estado</th>
                                    <th>Usuario ID</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($aperturas as $ap)
                                <tr>
                                    <td>{{ $ap->id }}</td>
                                    <td>{{ $ap->fecha }}</td>
                                    <td>{{ $ap->cajero }}</td>
                                    <td>{{ $ap->caja_id }}</td>
                                    <td>{{ $ap->turno }}</td>
                                    <td>C${{ number_format($ap->monto_inicial ?? 0, 2) }}</td>
                                    <td>{{ $ap->estado }}</td>
                                    <td>{{ $ap->usuario_id }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="8" class="text-center">Sin aperturas</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <h5 class="mt-4">Cierres</h5>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Apertura ID</th>
                                    <th>Fecha</th>
                                    <th>Usuario ID</th>
                                    <th>Estado</th>
                                    <th>Diferencia</th>
                                    <th>Observaciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($cierres as $c)
                                <tr>
                                    <td>{{ $c->id }}</td>
                                    <td>{{ $c->apertura_id }}</td>
                                    <td>{{ $c->fecha_cierre ?? $c->fecha ?? '' }}</td>
                                    <td>{{ $c->usuario_id ?? $c->cajero ?? '' }}</td>
                                    <td>{{ $c->estado }}</td>
                                    <td>C${{ number_format($c->diferencia ?? 0, 2) }}</td>
                                    <td>{{ Str::limit($c->observaciones ?? '', 50) }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="7" class="text-center">Sin cierres</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <h5 class="mt-4">Anulaciones</h5>
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Tipo</th>
                                    <th>Registro Original</th>
                                    <th>Apertura ID</th>
                                    <th>Cierre ID</th>
                                    <th>Fecha Anulación</th>
                                    <th>Usuario</th>
                                    <th>Rol</th>
                                    <th>Motivo</th>
                                    <th>Estado Anterior/Resultante</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($anulaciones as $a)
                                <tr>
                                    <td>{{ $a->id }}</td>
                                    <td>{{ $a->tipo }}</td>
                                    <td>{{ $a->registro_original_id }}</td>
                                    <td>{{ $a->apertura_id }}</td>
                                    <td>{{ $a->cierre_id }}</td>
                                    <td>{{ $a->fecha_anulacion }}</td>
                                    <td>{{ $a->usuario_id }}</td>
                                    <td>{{ $a->rol }}</td>
                                    <td>{{ Str::limit($a->motivo, 40) }}</td>
                                    <td>{{ $a->estado_anterior ?? '-' }} → {{ $a->estado_resultante ?? '-' }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="10" class="text-center">Sin anulaciones</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>
@endsection
