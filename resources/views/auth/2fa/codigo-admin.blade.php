<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación 2FA - Restaurante "El Lobito"</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #f4f6f9; display: flex; justify-content: center; align-items: center; height: 100vh; }
        .login-wrapper { width: 100%; max-width: 400px; padding: 20px; }
        .login-card { background: #ffffff; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); overflow: hidden; }
        .login-header { background-color: #6777ef; color: #ffffff; text-align: center; padding: 30px 20px; }
        .login-header h2 { font-size: 24px; font-weight: 700; letter-spacing: 2px; margin-bottom: 5px; }
        .login-header p { font-size: 13px; opacity: 0.8; }
        .login-body { padding: 30px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-size: 13px; font-weight: 600; color: #34395e; }
        .input-group { position: relative; }
        .input-group input { width: 100%; padding: 12px 15px; border: 1px solid #e4e6fc; border-radius: 4px; font-size: 14px; text-align: center; letter-spacing: 5px; font-size: 18px; }
        .btn-login { width: 100%; background-color: #6777ef; color: #fff; border: none; padding: 12px; border-radius: 4px; font-size: 15px; font-weight: bold; cursor: pointer; }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-header">
                <h2>Segundo Factor de Autenticación</h2>
                <p>Ingresa el código generado por ADMIN o GERENTE</p>
            </div>
            <div class="login-body">
                @if ($errors->any())
                    <div style="background-color: #fc544b; color: white; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 13px;">
                        {{ $errors->first() }}
                    </div>
                @endif
                
                <form method="POST" action="{{ route('2fa.verificar') }}">
                    @csrf
                    <div class="form-group">
                        <label>Código de 6 dígitos</label>
                        <div class="input-group">
                            <input type="text" name="codigo" placeholder="000000" required maxlength="6" pattern="[0-9]{6}" autocomplete="off" autofocus>
                        </div>
                    </div>
                    <button type="submit" class="btn-login">
                        Verificar <i class="fas fa-check" style="margin-left: 5px;"></i>
                    </button>
                </form>
                <p style="text-align: center; margin-top: 15px; font-size: 12px; color: #666;">
                    Solicita el código a un ADMIN o GERENTE
                </p>
            </div>
        </div>
    </div>
    @include('partials.revalidar-pagina')
</body>
</html>
