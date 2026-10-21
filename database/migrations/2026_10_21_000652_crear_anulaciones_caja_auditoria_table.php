<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('anulaciones_caja_auditoria')) {
            Schema::create('anulaciones_caja_auditoria', function (Blueprint $table) {
                $table->id();
                $table->string('tipo', 20)->index(); // apertura|cierre
                $table->unsignedBigInteger('registro_original_id')->index();
                $table->unsignedBigInteger('apertura_id')->nullable()->index();
                $table->unsignedBigInteger('cierre_id')->nullable()->index();
                $table->dateTime('fecha_anulacion')->index();
                $table->unsignedBigInteger('usuario_id')->nullable()->index();
                $table->string('rol', 20)->nullable();
                $table->text('motivo');
                $table->string('estado_anterior', 20)->nullable();
                $table->string('estado_resultante', 20)->nullable();
                $table->json('referencia_json')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('anulaciones_caja_auditoria');
    }
};
