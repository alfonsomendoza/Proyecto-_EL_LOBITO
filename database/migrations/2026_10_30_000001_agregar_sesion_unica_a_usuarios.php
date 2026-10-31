<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sesión única por usuario.
 *
 * `usuarios.session_id` guarda el identificador de la ÚNICA sesión autorizada
 * y `usuarios.ultima_actividad` la última petición válida de esa sesión. Con
 * ambos datos, un segundo inicio de sesión dentro de los 15 minutos posteriores
 * a la última actividad se rechaza (la sesión original sigue intacta).
 *
 * La migración es ADITIVA: solo agrega dos columnas nullable a la tabla
 * existente `usuarios`. No renombra ni elimina columnas, no toca datos y no
 * modifica el algoritmo de contraseñas. Es compatible con MySQL 8.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Se comprueba columna por columna para que la migración sea segura
        // incluso si una ejecución anterior quedó a medias.
        Schema::table('usuarios', function (Blueprint $table) {
            if (! Schema::hasColumn('usuarios', 'session_id')) {
                // El id de sesión de Laravel mide 40 caracteres; 100 deja
                // margen sin cambiar el tipo de la clave primaria.
                $table->string('session_id', 100)->nullable();
            }

            if (! Schema::hasColumn('usuarios', 'ultima_actividad')) {
                $table->timestamp('ultima_actividad')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            if (Schema::hasColumn('usuarios', 'session_id')) {
                $table->dropColumn('session_id');
            }

            if (Schema::hasColumn('usuarios', 'ultima_actividad')) {
                $table->dropColumn('ultima_actividad');
            }
        });
    }
};
