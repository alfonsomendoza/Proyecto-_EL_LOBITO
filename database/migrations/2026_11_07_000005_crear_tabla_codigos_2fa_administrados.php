<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('codigos_2fa_administrados')) {
            Schema::create('codigos_2fa_administrados', function (Blueprint $table) {
                $table->bigInteger('id')->autoIncrement();
                $table->integer('usuario_id');
                $table->foreign('usuario_id')->references('id')->on('usuarios')->onDelete('cascade');
                $table->string('codigo_hash', 255);
                $table->integer('generado_por');
                $table->foreign('generado_por')->references('id')->on('usuarios')->onDelete('cascade');
                $table->timestamp('generado_en')->useCurrent();
                $table->timestamp('expira_en')->nullable();
                $table->timestamp('usado_en')->nullable();
                $table->boolean('usado')->default(false);
                $table->integer('intentos')->default(0);
                $table->string('ip_entrega')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('codigos_2fa_administrados');
    }
};
