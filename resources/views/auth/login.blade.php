<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Restaurante "El Lobito"</title>
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
        .input-group i { position: absolute; top: 50%; left: 15px; transform: translateY(-50%); color: #aeb4be; }
        .input-group input { width: 100%; padding: 12px 15px 12px 40px; border: 1px solid #e4e6fc; border-radius: 4px; font-size: 14px; transition: all 0.3s; background-color: #fdfdff; }
        .input-group input:focus { outline: none; border-color: #6777ef; box-shadow: 0 0 0 3px rgba(103,119,239,0.1); }
        .btn-login { width: 100%; background-color: #6777ef; color: #fff; border: none; padding: 12px; border-radius: 4px; font-size: 15px; font-weight: bold; cursor: pointer; transition: 0.3s; margin-top: 10px; }
        .btn-login:hover { background-color: #3f52e3; }
        .footer-text { text-align: center; margin-top: 20px; font-size: 12px; color: #98a6ad; }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-header">
                <h2>Restaurante "El Lobito"</h2>
                <p>Sistema de Gestión POS</p>
            </div>
            <div class="login-body">
                @if ($errors->any())
                    <div style="background-color: #fc544b; color: white; padding: 10px; border-radius: 4px; margin-bottom: 15px; font-size: 13px;">
                        {{ $errors->first() }}
                    </div>
                @endif

                {{-- Todo se maneja por JavaScript, NO hay etiqueta <form> para que el navegador no detecte nada --}}
                <div class="form-group">
                    <label>Usuario</label>
                    <div class="input-group">
                        <i class="fas fa-user"></i>
                        <input type="text" id="pos_usuario" placeholder="Ingresa tu usuario" autofocus autocomplete="off">
                    </div>
                </div>
                <div class="form-group">
                    <label>Contraseña</label>
                    <div class="input-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="pos_password" placeholder="Ingresa tu contraseña" autocomplete="new-password">
                    </div>
                </div>
                
                <button type="button" class="btn-login" id="btnAcceder">
                    Iniciar Sesión <i class="fas fa-sign-in-alt" style="margin-left: 5px;"></i>
                </button>
            </div>
        </div>
    </div>

    <script>
        function ejecutarLoginMilitar() {
            const userField = document.getElementById('pos_usuario');
            const passField = document.getElementById('pos_password');

            if(userField.value.trim() === '' || passField.value.trim() === '') return;

            // 1. Construimos el formulario real en la memoria oculta
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '{{ route("login.post") }}';

            const csrf = document.createElement('input');
            csrf.type = 'hidden';
            csrf.name = '_token';
            csrf.value = '{{ csrf_token() }}';

            const u = document.createElement('input');
            u.type = 'hidden';
            u.name = 'usuario';
            u.value = userField.value;

            const p = document.createElement('input');
            p.type = 'hidden';
            p.name = 'password';
            p.value = passField.value;

            form.appendChild(csrf);
            form.appendChild(u);
            form.appendChild(p);
            document.body.appendChild(form);

            // 2. EL TRUCO DEFINITIVO: Destruimos el campo de contraseña visible de la pantalla
            passField.remove();

            // 3. Enviamos los datos. Firefox buscará contraseñas en la pantalla y encontrará cero.
            form.submit();
        }

        // Asignar eventos de clic y teclado (Enter)
        document.getElementById('btnAcceder').addEventListener('click', ejecutarLoginMilitar);
        
        document.getElementById('pos_usuario').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') document.getElementById('pos_password').focus();
        });
        
        document.getElementById('pos_password').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') ejecutarLoginMilitar();
        });
    </script>
</body>
</html>