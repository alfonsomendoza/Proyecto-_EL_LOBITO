<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

class Recuperar2FA extends Command
{
    protected $signature = '2fa:recuperar {--user= : ID del usuario a restablecer} {--all : Restablecer todos los usuarios con secreto irrecuperable} {--force : Ejecutar sin confirmacion interactiva}';

    protected $description = 'Restablece de forma segura el 2FA de usuarios cuyo secreto es irrecuperable (APP_KEY anterior perdida).';

    public function handle(AuditService $audit): int
    {
        if (! $this->option('user') && ! $this->option('all')) {
            $this->error('Debe indicar --user=ID o --all.');
            return 1;
        }

        $query = User::query()->where('two_factor_enabled', 1);
        if ($this->option('user')) {
            $query->where('id', (int) $this->option('user'));
        }

        $candidates = $query->get()->filter(function (User $u) {
            if (empty($u->two_factor_secret)) {
                return false;
            }
            try {
                Crypt::decryptString($u->two_factor_secret);
                return false; // descifrable -> no tocar
            } catch (\Throwable $e) {
                return true; // irrecuperable -> restablecer
            }
        });

        if ($candidates->isEmpty()) {
            $this->info('No hay usuarios con secreto irrecuperable.');
            return 0;
        }

        $this->info('Usuarios a restablecer: '.$candidates->count());
        foreach ($candidates as $u) {
            $this->line('  - #'.$u->id.' ('.$u->username.')');
        }

        if (! $this->option('force')) {
            if (! $this->confirm('¿Confirma el restablecimiento de 2FA para estos usuarios?')) {
                $this->info('Operacion cancelada.');
                return 0;
            }
        }

        $restablecidos = 0;
        foreach ($candidates as $u) {
            DB::transaction(function () use ($u, $audit) {
                $u->two_factor_secret = null;
                $u->two_factor_recovery_codes = null;
                $u->two_factor_confirmed_at = null;
                $u->two_factor_enabled = false;
                $u->save();

                $audit->log('2fa_restablecido', $u->id, 'Restablecido por recuperacion forense de APP_KEY', ['motivo' => 'secreto_irrecuperable'], null);
            });
            $restablecidos++;
            $this->info('Restablecido: #'.$u->id);
        }

        $this->info("Total restablecidos: {$restablecidos}");
        return 0;
    }
}