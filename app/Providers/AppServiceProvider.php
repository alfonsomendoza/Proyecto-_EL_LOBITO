<?php

namespace App\Providers;

use App\Services\Backup\CompositeDumper;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\MysqldumpDumper;
use App\Services\Backup\PdoDumper;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // El módulo de respaldo depende de un "dumper". Se inyecta un dumper
        // compuesto que usa mysqldump si está disponible y, si no, genera el
        // volcado con el mismo PHP (PdoDumper), sin depender de herramientas
        // externas. Las pruebas sustituyen este binding por simulados para
        // no tocar la base real.
        $this->app->bind(DatabaseDumper::class, function (): DatabaseDumper {
            return new CompositeDumper(
                new MysqldumpDumper(),
                new PdoDumper()
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Forzar protocolo HTTPS cuando las peticiones vengan a través de Ngrok / Reverse Proxy
        if (request()->header('x-forwarded-proto') === 'https' || request()->secure()) {
            URL::forceScheme('https');
        }
    }
}