<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Códigos 2FA - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #f4f6f9; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        .card { background: #ffffff; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .card-header { background-color: #6777ef; color: #ffffff; padding: 20px; display: flex; justify-content: space-between; align-items: center; }
        .card-body { padding: 30px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e4e6fc; }
        th { background-color: #f8f9fa; font-weight: 600; }
        .btn { background-color: #28a745; color: #fff; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .alert-success { background-color: #d4edda; color: #155724; }
        .alert-danger { background-color: #f8d7da; color: #721c24; }
        .codigo-generado { background: #fff3cd; padding: 15px; text-align: center; font-size: 24px; font-weight: bold; letter-spacing: 5px; font-family: monospace; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-shield-alt"></i> Generar Códigos 2FA</h2>
                <a href="{{ route('admin.2fa.codigos.historial') }}" style="color: white;">Ver Historial</a>
            </div>
            <div class="card-body">
                @if(session('codigo_generado'))
                <div class="alert alert-success">
                    <strong>Código generado para {{ session('usuario_codigo') }}:</strong><br>
                    <div class="codigo-generado">{{ session('codigo_generado') }}</div>
                    <small>Este código expira en 5 minutos y solo puede usarse una vez. Nunca se mostrará nuevamente.</small>
                </div>
                @endif
                
                @if($errors->any())
                <div class="alert alert-danger">
                    {{ $errors->first() }}
                </div>
                @endif
                
                @php
                    $esAdmin = strtoupper((string) (auth()->user()->rol->nombre ?? '')) === 'ADMIN';
                @endphp

                <table>
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Nombre</th>
                            <th>Rol</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($usuarios as $usuario)
                        <tr>
                            <td>{{ $usuario->username }}</td>
                            <td>{{ $usuario->nombre }}</td>
                            <td>{{ $usuario->rol->nombre ?? '-' }}</td>
                            <td>
                                @if(! $esAdmin)
                                <span style="color: #999;">Solo ADMIN puede generar códigos</span>
                                @elseif($usuario->id != auth()->id())
                                <form method="POST" action="{{ route('admin.2fa.codigos.generar', $usuario->id) }}" style="display:inline;">
                                    @csrf
                                    <button type="submit" class="btn" onclick="return confirm('¿Generar código 2FA para este usuario?')">
                                        <i class="fas fa-key"></i> Generar Código
                                    </button>
                                </form>
                                @else
                                <span style="color: #999;">No puedes generarte código a ti mismo</span>
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
