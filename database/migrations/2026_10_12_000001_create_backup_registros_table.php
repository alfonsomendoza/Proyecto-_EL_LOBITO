<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de historial/auditoría del módulo de copias de seguridad.
 *
 * Registra quién inició cada operación (crear/eliminar), su estado y su
 * resultado, sin guardar credenciales ni contenido del volcado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_registros', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedBigInteger('usuario_id')->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->string('tipo', 20)->default('CREACION');
            $table->string('estado', 20)->default('EN_PROCESO');
            $table->string('nombre_archivo', 150)->nullable()->index();
            $table->char('hash_sha256', 64)->nullable();
            $table->unsignedBigInteger('tamano_bytes')->nullable();
            $table->unsignedBigInteger('duracion_ms')->nullable();
            $table->string('mensaje', 500)->nullable();
            $table->timestamp('iniciado_en')->nullable();
            $table->timestamp('finalizado_en')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_registros');
    }
};