<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de 2FA - Restaurante "El Lobito"</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #f4f6f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; padding: 20px; }
        .login-wrapper { width: 100%; max-width: 500px; }
        .login-card { background: #ffffff; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); overflow: hidden; }
        .login-header { background-color: #6777ef; color: #ffffff; text-align: center; padding: 30px 20px; }
        .login-header h2 { font-size: 24px; font-weight: 700; letter-spacing: 2px; margin-bottom: 5px; }
        .login-header p { font-size: 13px; opacity: 0.8; }
        .login-body { padding: 30px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 600; color: #34395e; }
        .input-group { position: relative; }
        .input-group input { width: 100%; padding: 12px 15px; border: 1px solid #e4e6fc; border-radius: 4px; font-size: 14px; }
        .btn-login { width: 100%; background-color: #6777ef; color: #fff; border: none; padding: 12px; border-radius: 4px; font-size: 15px; font-weight: bold; cursor: pointer; }
        .qr-code { text-align: center; margin: 20px 0; display: flex; justify-content: center; }
        .secret { background: #f4f6f9; padding: 10px; word-break: break-all; text-align: center; font-family: monospace; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-header">
                <h2>Configuración 2FA Obligatoria</h2>
                <p>Escanea el código QR con tu aplicación autenticadora</p>
            </div>
            <div class="login-body">
                <p style="margin-bottom: 15px; font-size: 13px;">Usa Google Authenticator, Microsoft Authenticator o cualquier app compatible con TOTP.</p>
                
                <div class="qr-code">
                    @if(isset($qrDataUri) && $qrDataUri)
                        <img src="{{ $qrDataUri }}" alt="Código QR" width="200" height="200">
                    @else
                        <div style="background-color:#f8d7da; border:1px solid #f5c6cb; color:#721c24; padding:12px 14px; border-radius:6px; font-size:13px; text-align:left;">
                            <strong>No se pudo generar el código QR.</strong><br>
                            Usa la clave manual que aparece abajo para registrar la cuenta en tu aplicación autenticadora.
                        </div>
                    @endif
                </div>
                
                <p style="font-size: 12px; text-align: center; margin-bottom: 10px;">O ingresa manualmente este código:</p>
                <div class="secret">{{ $secret }}</div>
                
                @if ($errors->any())
                    <div style="background-color: #fc544b; color: white; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 13px;">
                        {{ $errors->first() }}
                    </div>
                @endif
                
                <form method="POST" action="{{ route('2fa.confirm') }}">
                    @csrf
                    <div class="form-group">
                        <label>Código de verificación</label>
                        <div class="input-group">
                            <input type="text" name="code" placeholder="Ingresa el código de 6 dígitos" required maxlength="6" pattern="[0-9]{6}">
                        </div>
                    </div>
                    <button type="submit" class="btn-login">
                        Confirmar y Activar <i class="fas fa-check" style="margin-left: 5px;"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
    @include('partials.revalidar-pagina')
</body>
</html>
