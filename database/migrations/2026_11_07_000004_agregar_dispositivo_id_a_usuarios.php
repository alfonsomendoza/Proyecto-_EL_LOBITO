<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            if (! Schema::hasColumn('usuarios', 'dispositivo_id')) {
                $table->integer('dispositivo_id')->nullable()->after('session_id');
                $table->foreign('dispositivo_id')->references('id')->on('dispositivos')->onDelete('set null');
            }
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            if (Schema::hasColumn('usuarios', 'dispositivo_id')) {
                $table->dropForeign(['dispositivo_id']);
                $table->dropColumn('dispositivo_id');
            }
        });
    }
};
