<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('caja_bloqueo')) {
            Schema::create('caja_bloqueo', function (Blueprint $table) {
                $table->integer('id')->primary();
            });
        }

        $existe = DB::table('caja_bloqueo')->where('id', 1)->exists();
        if (! $existe) {
            DB::table('caja_bloqueo')->insert(['id' => 1]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('caja_bloqueo');
    }
};
