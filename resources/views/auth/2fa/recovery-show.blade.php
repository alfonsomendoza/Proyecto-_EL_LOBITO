<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Códigos de Recuperación - Restaurante "El Lobito"</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #f4f6f9; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; }
        .card { background: #ffffff; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); overflow: hidden; }
        .card-header { background-color: #6777ef; color: #ffffff; padding: 20px; }
        .card-body { padding: 30px; }
        .recovery-codes { background: #f4f6f9; padding: 15px; margin: 15px 0; border-radius: 4px; display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .recovery-codes code { padding: 5px; font-size: 13px; }
        .btn { background-color: #6777ef; color: #fff; padding: 10px 20px; text-decoration: none; border-radius: 4px; display: inline-block; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-key"></i> Códigos de Recuperación</h2>
            </div>
            <div class="card-body">
                <p style="margin-bottom: 15px; font-size: 13px;">Estos son tus códigos de recuperación restantes.</p>
                
                <div class="recovery-codes">
                    @forelse ($recoveryCodes as $code)
                        <code>{{ $code }}</code>
                    @empty
                        <p style="grid-column: 1 / -1;">No quedan códigos de recuperación.</p>
                    @endforelse
                </div>
                
                <a href="{{ route('inicio') }}" class="btn">
                    <i class="fas fa-arrow-left"></i> Volver
                </a>
            </div>
        </div>
    </div>
    @include('partials.revalidar-pagina')
</body>
</html>
