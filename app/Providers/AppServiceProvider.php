<?php

namespace App\Providers;

use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\MysqldumpDumper;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // El módulo de respaldo depende de un "dumper"; se inyecta la
        // implementación real con mysqldump. Las pruebas la sustituyen
        // por una simulada para no tocar la base real.
        $this->app->bind(DatabaseDumper::class, MysqldumpDumper::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
