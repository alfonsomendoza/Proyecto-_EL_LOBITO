<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dispositivos')) {
            Schema::create('dispositivos', function (Blueprint $table) {
                $table->integer('id')->autoIncrement();
                $table->string('identificador', 255)->unique();
                $table->string('nombre', 100)->nullable();
                $table->string('token_dispositivo', 255)->nullable();
                $table->integer('usuario_id')->nullable();
                $table->foreign('usuario_id')->references('id')->on('usuarios')->onDelete('set null');
                $table->string('ip_registro', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('registrado_en')->nullable();
                $table->timestamp('ultimo_uso')->nullable();
                $table->boolean('autorizado')->default(false);
                $table->boolean('activo')->default(true);
                $table->timestamp('desautorizado_en')->nullable();
                $table->integer('desautorizado_por')->nullable();
                $table->foreign('desautorizado_por')->references('id')->on('usuarios')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dispositivos');
    }
};
