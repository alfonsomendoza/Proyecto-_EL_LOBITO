<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Códigos de Recuperación - Restaurante "El Lobito"</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #f4f6f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .login-wrapper { width: 100%; max-width: 500px; }
        .login-card { background: #ffffff; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); overflow: hidden; }
        .login-header { background-color: #28a745; color: #ffffff; text-align: center; padding: 30px 20px; }
        .login-header h2 { font-size: 24px; font-weight: 700; margin-bottom: 5px; }
        .login-body { padding: 30px; }
        .recovery-codes { background: #f4f6f9; padding: 15px; margin: 15px 0; border-radius: 4px; }
        .recovery-codes code { display: block; padding: 5px 0; font-size: 13px; }
        .btn-login { width: 100%; background-color: #6777ef; color: #fff; border: none; padding: 12px; border-radius: 4px; font-size: 15px; font-weight: bold; cursor: pointer; text-decoration: none; display: block; text-align: center; }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-header">
                <h2>¡2FA Activado!</h2>
                <p>Guarda estos códigos de recuperación</p>
            </div>
            <div class="login-body">
                <p style="margin-bottom: 15px; font-size: 13px; color: #dc3545;">
                    <strong>Importante:</strong> Guarda estos códigos en un lugar seguro. Cada código solo puede usarse una vez.
                </p>
                
                <div class="recovery-codes">
                    @foreach ($recoveryCodes as $code)
                        <code>{{ $code }}</code>
                    @endforeach
                </div>
                
                <a href="{{ route('inicio') }}" class="btn-login">
                    Continuar al Sistema <i class="fas fa-arrow-right" style="margin-left: 5px;"></i>
                </a>
            </div>
        </div>
    </div>
    @include('partials.revalidar-pagina')
</body>
</html>
