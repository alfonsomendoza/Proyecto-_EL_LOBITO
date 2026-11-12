<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial Códigos 2FA</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #f4f6f9; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: #ffffff; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .card-header { background-color: #6777ef; color: #ffffff; padding: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card-body { padding: 30px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #e4e6fc; }
        th { background-color: #f8f9fa; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; }
        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .btn { background-color: #dc3545; color: #fff; padding: 5px 10px; border: none; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-history"></i> Historial de Códigos 2FA</h2>
                <a href="{{ route('admin.2fa.codigos.index') }}" style="color: white;">Volver</a>
            </div>
            <div class="card-body">
                @php
                    $esAdmin = strtoupper((string) (auth()->user()->rol->nombre ?? '')) === 'ADMIN';
                @endphp

                <table>
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Generado Por</th>
                            <th>Generado</th>
                            <th>Expira</th>
                            <th>Estado</th>
                            <th>Intentos</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($codigos as $codigo)
                        <tr>
                            <td>{{ $codigo->usuario->username ?? '-' }}</td>
                            <td>{{ $codigo->generador->username ?? '-' }}</td>
                            <td>{{ $codigo->generado_en }}</td>
                            <td>{{ $codigo->expira_en }}</td>
                            <td>
                                @if($codigo->usado)
                                    <span class="badge badge-success">Usado {{ $codigo->usado_en }}</span>
                                @elseif($codigo->expira_en && $codigo->expira_en < now())
                                    <span class="badge badge-danger">Vencido</span>
                                @else
                                    <span class="badge badge-warning">Pendiente</span>
                                @endif
                            </td>
                            <td>{{ $codigo->intentos }}</td>
                            <td>
                                @if($esAdmin && !$codigo->usado && $codigo->expira_en > now())
                                <form method="POST" action="{{ route('admin.2fa.codigos.revocar', $codigo->id) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn">Revocar</button>
                                </form>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
