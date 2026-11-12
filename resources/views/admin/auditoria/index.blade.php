@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h2>Auditoría de Seguridad</h2>
            <table class="table table-bordered table-sm">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Usuario</th>
                        <th>Evento</th>
                        <th>IP</th>
                        <th>Motivo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($eventos as $evento)
                    <tr>
                        <td>{{ $evento->creado_en }}</td>
                        <td>{{ $evento->usuario ? $evento->usuario->username : '-' }}</td>
                        <td>{{ $evento->evento }}</td>
                        <td>{{ $evento->ip }}</td>
                        <td>{{ $evento->motivo }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
