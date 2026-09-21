<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La venta permite validar por claves foráneas compuestas que una
        // cuenta y un detalle pertenecen exactamente a la misma venta.
        Schema::table('cuenta_detalles', function (Blueprint $table) {
            $table->integer('venta_id')->nullable()->after('id');
        });

        $inconsistencias = DB::table('cuenta_detalles as cd')
            ->leftJoin('cuentas as c', 'c.id', '=', 'cd.cuenta_id')
            ->leftJoin('venta_detalles as vd', 'vd.id', '=', 'cd.venta_detalle_id')
            ->whereNull('c.id')
            ->orWhereNull('vd.id')
            ->orWhereColumn('c.venta_id', '<>', 'vd.venta_id')
            ->exists();

        if ($inconsistencias) {
            throw new RuntimeException(
                'No se puede fortalecer cuenta_detalles: existen asignaciones sin cuenta, sin detalle o de ventas distintas.'
            );
        }

        // Las asignaciones ya existentes toman su venta y subtotal del detalle
        // original. Así evitamos recalcular dinero con float en PHP.
        DB::statement(
            'UPDATE cuenta_detalles cd '
            . 'INNER JOIN venta_detalles vd ON vd.id = cd.venta_detalle_id '
            . 'SET cd.venta_id = vd.venta_id, '
            . 'cd.subtotal = ROUND(cd.cantidad * vd.precio_unitario, 2)'
        );

        // Compatibilidad: los datos antiguos aún viven en venta_detalles.cuenta_id.
        // Solo se copian detalles que todavía no tienen ninguna asignación nueva.
        DB::statement(
            'INSERT INTO cuenta_detalles '
            . '(cuenta_id, venta_id, venta_detalle_id, cantidad, subtotal, created_at, updated_at) '
            . 'SELECT vd.cuenta_id, vd.venta_id, vd.id, vd.cantidad, vd.subtotal, NOW(), NOW() '
            . 'FROM venta_detalles vd '
            . 'INNER JOIN cuentas c ON c.id = vd.cuenta_id AND c.venta_id = vd.venta_id '
            . 'LEFT JOIN cuenta_detalles cd ON cd.venta_detalle_id = vd.id '
            . 'WHERE vd.cuenta_id IS NOT NULL AND cd.id IS NULL'
        );

        Schema::table('cuenta_detalles', function (Blueprint $table) {
            $table->integer('venta_id')->nullable(false)->change();
            $table->index('venta_id', 'cuenta_detalles_venta_id_index');
        });

        // Los índices compuestos son necesarios para las FK que protegen
        // la pertenencia de ambos extremos a la misma venta.
        Schema::table('cuentas', function (Blueprint $table) {
            $table->unique(['id', 'venta_id'], 'cuentas_id_venta_id_unique');
        });

        Schema::table('venta_detalles', function (Blueprint $table) {
            $table->unique(['id', 'venta_id'], 'venta_detalles_id_venta_id_unique');
        });

        Schema::table('cuenta_detalles', function (Blueprint $table) {
            $table->foreign(['cuenta_id', 'venta_id'], 'cuenta_detalles_cuenta_venta_fk')
                ->references(['id', 'venta_id'])
                ->on('cuentas')
                ->restrictOnDelete()
                ->restrictOnUpdate();

            $table->foreign(['venta_detalle_id', 'venta_id'], 'cuenta_detalles_detalle_venta_fk')
                ->references(['id', 'venta_id'])
                ->on('venta_detalles')
                ->restrictOnDelete()
                ->restrictOnUpdate();
        });

        DB::statement(
            'ALTER TABLE cuenta_detalles '
            . 'ADD CONSTRAINT cuenta_detalles_cantidad_positiva CHECK (cantidad > 0), '
            . 'ADD CONSTRAINT cuenta_detalles_subtotal_no_negativo CHECK (subtotal >= 0)'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE cuenta_detalles '
            . 'DROP CHECK cuenta_detalles_cantidad_positiva, '
            . 'DROP CHECK cuenta_detalles_subtotal_no_negativo'
        );

        Schema::table('cuenta_detalles', function (Blueprint $table) {
            $table->dropForeign('cuenta_detalles_cuenta_venta_fk');
            $table->dropForeign('cuenta_detalles_detalle_venta_fk');
        });

        Schema::table('venta_detalles', function (Blueprint $table) {
            $table->dropUnique('venta_detalles_id_venta_id_unique');
        });

        Schema::table('cuentas', function (Blueprint $table) {
            $table->dropUnique('cuentas_id_venta_id_unique');
        });

        Schema::table('cuenta_detalles', function (Blueprint $table) {
            $table->dropIndex('cuenta_detalles_venta_id_index');
            $table->dropColumn('venta_id');
        });
    }
};
