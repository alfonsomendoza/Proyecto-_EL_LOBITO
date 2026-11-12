<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('auditoria_seguridad')) {
            Schema::create('auditoria_seguridad', function (Blueprint $table) {
                $table->bigInteger('id')->autoIncrement();
                $table->integer('usuario_id')->nullable();
                $table->foreign('usuario_id')->references('id')->on('usuarios')->onDelete('set null');
                $table->string('evento', 100);
                $table->string('ip', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->text('motivo')->nullable();
                $table->json('detalles')->nullable();
                $table->timestamp('creado_en')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria_seguridad');
    }
};
