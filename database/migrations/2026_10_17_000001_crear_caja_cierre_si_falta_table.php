<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Versiona la tabla legacy `caja_cierre` (arqueo/cierre de caja) que usa el
 * módulo de Cierre de Caja. Esa tabla YA existe en la base real
 * (`sistemadecaja`) desde el respaldo PHP original, pero no estaba cubierta
 * por ninguna migración; esta migración la documenta y la crea solo si falta.
 *
 * SEGURIDAD / DECISIONES:
 *  - `up()` está guardado con Schema::hasTable('caja_cierre'): en la base real
 *    (y en cualquier entorno donde la tabla ya exista) NO hace nada.
 *  - Las claves foráneas a `caja_apertura` y `usuarios` se agregan solo si esas
 *    tablas existen, porque NO tienen migración propia (son legacy). Así, en un
 *    entorno limpio sin ellas, la migración no falla.
 *  - `down()` NO borra la tabla a propósito: es una tabla de negocio con datos
 *    históricos de arqueos. Eliminarla en un `migrate:rollback` provocaría
 *    pérdida de datos y está prohibido por las reglas del proyecto.
 *
 * El módulo lee/escribe esta tabla; el esquema debe coincidir con el DDL real:
 *   id, apertura_id, fecha, cajero, turno, caja_id, monto_apertura,
 *   ventas_efectivo, otros_ingresos, egresos, retiros, total_esperado,
 *   tasa_cambio, monto_extranjero, total_contado, diferencia, estado,
 *   conteo_json, observaciones, creado_en, usuario_id
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('caja_cierre')) {
            // La tabla ya existe (caso de la base real): no se toca.
            return;
        }

        Schema::create('caja_cierre', function (Blueprint $table) {
            // Entero firmado con autoincremento, igual que el DDL real (`int`).
            $table->integer('id')->autoIncrement();
            $table->integer('apertura_id');
            $table->dateTime('fecha');
            $table->string('cajero', 100);
            $table->string('turno', 50)->nullable();
            $table->integer('caja_id');
            $table->decimal('monto_apertura', 12, 2);
            $table->decimal('ventas_efectivo', 12, 2)->default(0);
            $table->decimal('otros_ingresos', 12, 2)->default(0);
            $table->decimal('egresos', 12, 2)->default(0);
            $table->decimal('retiros', 12, 2)->default(0);
            $table->decimal('total_esperado', 12, 2);
            $table->decimal('tasa_cambio', 10, 4)->default(1);
            $table->decimal('monto_extranjero', 12, 2)->default(0);
            $table->decimal('total_contado', 12, 2);
            $table->decimal('diferencia', 12, 2);
            $table->string('estado', 20);
            $table->json('conteo_json')->nullable();
            $table->text('observaciones')->nullable();
            $table->timestamp('creado_en')->nullable()->useCurrent();
            $table->integer('usuario_id')->nullable();

            $table->index('fecha', 'idx_cierre_fecha');
            $table->index('caja_id', 'idx_cierre_caja');
            $table->index('estado', 'idx_cierre_estado');
        });

        // Las FK dependen de tablas legacy sin migración propia: se agregan
        // únicamente si existen (si no, en un entorno limpio la tabla se queda
        // sin FK y el módulo sigue funcionando).
        if (Schema::hasTable('caja_apertura')) {
            Schema::table('caja_cierre', function (Blueprint $table) {
                $table->foreign('apertura_id', 'fk_cierre_apertura')
                    ->references('id')
                    ->on('caja_apertura')
                    ->onDelete('restrict');
            });
        }

        if (Schema::hasTable('usuarios')) {
            Schema::table('caja_cierre', function (Blueprint $table) {
                $table->foreign('usuario_id', 'fk_cierre_usuario')
                    ->references('id')
                    ->on('usuarios');
            });
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío: NO se elimina `caja_cierre` porque contiene
        // datos históricos de arqueos. Ver la nota de seguridad del encabezado.
    }
};
