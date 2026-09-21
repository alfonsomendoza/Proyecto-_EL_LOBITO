<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuenta_detalles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cuenta_id');
            $table->unsignedBigInteger('venta_detalle_id');
            // Permite repartir, por ejemplo, 3 bebidas entre dos cuentas.
            $table->unsignedInteger('cantidad');
            $table->decimal('subtotal', 10, 2);
            $table->timestamps();

            $table->unique(['cuenta_id', 'venta_detalle_id']);
            $table->index('venta_detalle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuenta_detalles');
    }
};
