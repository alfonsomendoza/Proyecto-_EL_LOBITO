<?php

namespace App\Console\Commands;

use App\Models\Codigo2FAAdministrado;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class Inicializar2FAAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = '2fa:inicializar-admin {username? : Username del administrador}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inicializa el código 2FA para el primer administrador cuando no hay otros administradores capaces de generarlo';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (! Schema::hasTable('codigos_2fa_administrados')) {
            $this->error('La tabla codigos_2fa_administrados no existe. Debe ejecutar las migraciones primero.');
            return Command::FAILURE;
        }

        $username = $this->argument('username') ?? $this->ask('Ingrese el username del administrador');

        $usuario = User::where('username', $username)
            ->whereHas('rol', function ($q) {
                $q->where('nombre', 'ADMIN');
            })
            ->first();

        if (! $usuario) {
            $this->error('Usuario no encontrado o no es un administrador.');
            return Command::FAILURE;
        }

        if (! $usuario->activo) {
            $this->error('El usuario administrador está inactivo.');
            return Command::FAILURE;
        }

        // Verificar si existe OTRO ADMIN activo capaz de generar códigos.
        // GERENTE ya no puede emitir códigos (solo ADMIN), así que no cuenta:
        // de lo contrario el comando se negaría a funcionar justo cuando hay
        // un único ADMIN bloqueado y un GERENTE sin permiso de emisión.
        $otrosAdmins = User::whereHas('rol', function ($q) {
            $q->where('nombre', 'ADMIN');
        })
        ->where('activo', 1)
        ->where('id', '!=', $usuario->id)
        ->count();

        if ($otrosAdmins > 0) {
            $this->error('Ya existe otro administrador activo capaz de generar códigos 2FA.');
            $this->error('Este comando solo debe usarse cuando NO hay otro administrador capaz de generar códigos.');
            return Command::FAILURE;
        }

        // Comprobar que no exista otro código pendiente válido para ese usuario
        $codigoPendiente = Codigo2FAAdministrado::where('usuario_id', $usuario->id)
            ->where('usado', false)
            ->where(function($q) {
                $q->whereNull('expira_en')
                  ->orWhere('expira_en', '>', now());
            })
            ->exists();

        if ($codigoPendiente) {
            $this->error('Ya existe un código 2FA pendiente válido para este usuario.');
            $this->error('Use el comando nuevamente después de que expire o sea revocado.');
            return Command::FAILURE;
        }

        // Invalidar códigos anteriores
        Codigo2FAAdministrado::where('usuario_id', $usuario->id)
            ->where('usado', false)
            ->update(['usado' => true, 'usado_en' => now()]);

        // Generar código criptográficamente seguro de 6 dígitos
        $codigo = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Codigo2FAAdministrado::create([
            'usuario_id' => $usuario->id,
            'codigo_hash' => Hash::make($codigo),
            'generado_por' => $usuario->id, // Auto-generado por inicialización
            'generado_en' => now(),
            'expira_en' => now()->addMinutes(5),
            'usado' => false,
            'intentos' => 0,
            'ip_entrega' => '127.0.0.1', // Origen local
        ]);

        // Registrar en auditoría
        try {
            $auditService = app(AuditService::class);
            $auditService->log('2fa_inicializacion_admin', $usuario->id, 'Inicialización de emergencia para primer administrador', [
                'metodo' => 'comando_artisan',
                'origen' => 'cli'
            ]);
        } catch (\Throwable $e) {
            // Continuar aunque falle la auditoría
        }

        $this->info('=== CÓDIGO 2FA GENERADO ===');
        $this->info('Usuario: ' . $usuario->username . ' (' . $usuario->nombre . ')');
        $this->info('Código: ' . $codigo);
        $this->info('Expira en: 5 minutos');
        $this->warn('IMPORTANTE: Este código solo se muestra UNA VEZ.');
        $this->warn('No lo guarde en archivos ni logs.');
        $this->info('Use este código en la pantalla de verificación 2FA al iniciar sesión.');
        
        return Command::SUCCESS;
    }
}
