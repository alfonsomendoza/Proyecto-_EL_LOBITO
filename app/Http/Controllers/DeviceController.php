<?php

namespace App\Http\Controllers;

use App\Models\Dispositivo;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    /**
     * Prefijo de los tokens nuevos. Permite distinguir un token guardado como
     * hash de uno heredado en claro sin tocar la columna existente.
     */
    private const PREFIJO_HASH = 'sha256:';

    protected AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Alta de dispositivos.
     *
     * Solo un ADMIN autenticado puede darla de alta. El token se genera y se
     * devuelve UNA sola vez, en el momento de la creación. Si el identificador
     * ya existe no se devuelve ni se rota ninguna credencial: así un registro
     * duplicado no permite suplantar a un dispositivo legítimo.
     */
    public function registrar(Request $request)
    {
        $usuario = Auth::user();

        if (! $usuario) {
            abort(401, 'Debes iniciar sesión para registrar dispositivos.');
        }

        $rol = strtoupper((string) ($usuario->rol->nombre ?? ''));

        if ($rol !== 'ADMIN') {
            abort(403, 'No tienes permiso para registrar dispositivos.');
        }

        $request->validate([
            'identificador' => 'required|string|max:255',
            'nombre' => 'nullable|string|max:100',
        ]);

        $identificador = (string) $request->identificador;
        [$token, $tokenHash] = $this->generarToken();

        $dispositivo = Dispositivo::firstOrCreate(
            ['identificador' => $identificador],
            [
                'nombre' => $request->nombre,
                'token_dispositivo' => $tokenHash,
                'ip_registro' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'registrado_en' => now(),
                'autorizado' => false,
                'activo' => true,
            ]
        );

        if (! $dispositivo->wasRecentlyCreated) {
            $this->auditService->log('dispositivo_registro_duplicado', null, 'Intento de registrar un dispositivo ya existente', ['identificador' => substr($identificador, 0, 10)], $request);

            return response()->json([
                'exito' => false,
                'mensaje' => 'El dispositivo ya está registrado. Solicita al administrador la renovación de sus credenciales.',
            ], 409);
        }

        // Auditoría sin el token: solo un prefijo del identificador.
        $this->auditService->log('dispositivo_registrado', null, 'Dispositivo registrado pendiente de autorización', ['identificador' => substr($identificador, 0, 10)], $request);

        return response()->json([
            'exito' => true,
            'token' => $token,
            'autorizado' => $dispositivo->autorizado,
        ]);
    }

    public function verificar(Request $request)
    {
        $request->validate([
            'identificador' => 'required|string|max:255',
            'token' => 'required|string|max:255',
        ]);

        $dispositivo = Dispositivo::where('identificador', $request->identificador)->first();

        if (! $dispositivo || ! $this->tokenCoincide((string) $request->token, $dispositivo->token_dispositivo)) {
            return response()->json(['exito' => false, 'mensaje' => 'Dispositivo no válido'], 401);
        }

        if (! $dispositivo->activo || ! $dispositivo->autorizado) {
            return response()->json(['exito' => false, 'mensaje' => 'Dispositivo no autorizado'], 403);
        }

        // Check if device is in use by another user
        if ($dispositivo->usuario_id && Auth::check() && Auth::id() != $dispositivo->usuario_id) {
            return response()->json(['exito' => false, 'mensaje' => 'Dispositivo en uso por otro usuario'], 403);
        }

        // Migración transparente: si el dispositivo aún conserva un token en
        // claro (registro anterior a este cambio), se guarda como hash tras una
        // autenticación correcta. El token del cliente no cambia.
        if (! str_starts_with((string) $dispositivo->token_dispositivo, self::PREFIJO_HASH)) {
            $dispositivo->token_dispositivo = self::PREFIJO_HASH.hash('sha256', (string) $request->token);
            $dispositivo->save();
        }

        return response()->json(['exito' => true, 'autorizado' => true]);
    }

    /**
     * Devuelve [token en claro, token para guardar].
     *
     * Solo se guarda el hash SHA-256 con prefijo. El token en claro se entrega
     * una vez al ADMIN y no se puede recuperar después desde la base de datos.
     */
    private function generarToken(): array
    {
        $token = Str::random(64);

        return [$token, self::PREFIJO_HASH.hash('sha256', $token)];
    }

    /**
     * Compara el token recibido con el almacenado.
     *
     * Los tokens nuevos usan `sha256:<hex>`. Los dispositivos ya registrados
     * pueden conservar su token en claro; se comparan igual con hash_equals
     * para no romperlos ni exponer el valor.
     */
    private function tokenCoincide(string $recibido, ?string $guardado): bool
    {
        if (! is_string($guardado) || $guardado === '') {
            return false;
        }

        if (str_starts_with($guardado, self::PREFIJO_HASH)) {
            $hashEsperado = substr($guardado, strlen(self::PREFIJO_HASH));

            return hash_equals($hashEsperado, hash('sha256', $recibido));
        }

        return hash_equals($guardado, $recibido);
    }
}
