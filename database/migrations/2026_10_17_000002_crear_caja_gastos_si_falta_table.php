<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Versiona la tabla legacy `caja_gastos` (salidas de dinero del turno) que usa
 * el módulo de Gastos. Esa tabla YA existe en la base real (`sistemadecaja`)
 * desde el respaldo PHP original, pero no estaba cubierta por ninguna
 * migración; esta migración la documenta y la crea solo si falta.
 *
 * SEGURIDAD / DECISIONES:
 *  - `up()` está guardado con Schema::hasTable('caja_gastos'): en la base real
 *    (y en cualquier entorno donde la tabla ya exista) NO hace nada. Por eso
 *    esta migración es 100% aditiva y segura de aplicar sobre producción.
 *  - El DDL replica el esquema REAL verificado con SHOW CREATE TABLE en MySQL
 *    8.0.43 (InnoDB, utf8mb4): id autoincremental `int`, apertura_id NOT NULL,
 *    concepto varchar(255), monto decimal(12,2), fecha datetime con
 *    CURRENT_TIMESTAMP, estado varchar(20) default 'ACTIVO', usuario_id
 *    nullable. Sin created_at/updated_at (el modelo desactiva timestamps).
 *  - Las claves foráneas a `caja_apertura` y `usuarios` se agregan solo si esas
 *    tablas existen, porque NO tienen migración propia (son legacy). Así, en un
 *    entorno limpio sin ellas, la migración no falla y el módulo sigue
 *    funcionando.
 *  - `down()` NO borra la tabla a propósito: es una tabla de negocio con datos
 *    históricos de gastos. Eliminarla en un `migrate:rollback` provocaría
 *    pérdida de datos y está prohibido por las reglas del proyecto.
 *
 * El módulo lee/escribe esta tabla; las columnas que usa el código son:
 *   id, apertura_id, concepto, monto, fecha, estado, usuario_id
 * (ver App\Models\CajaGasto y App\Services\GastoService).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('caja_gastos')) {
            // La tabla ya existe (caso de la base real): no se toca.
            return;
        }

        Schema::create('caja_gastos', function (Blueprint $table) {
            // Entero firmado con autoincremento, igual que el DDL real (`int`).
            $table->integer('id')->autoIncrement();
            $table->integer('apertura_id');
            $table->string('concepto', 255);
            $table->decimal('monto', 12, 2);
            $table->dateTime('fecha')->nullable()->useCurrent();
            $table->string('estado', 20)->default('ACTIVO');
            $table->integer('usuario_id')->nullable();

            $table->index('apertura_id', 'idx_gastos_apertura');
            $table->index('usuario_id', 'fk_gastos_usuario');
        });

        // Las FK dependen de tablas legacy sin migración propia: se agregan
        // únicamente si existen (si no, en un entorno limpio la tabla se queda
        // sin FK y el módulo sigue funcionando).
        if (Schema::hasTable('caja_apertura')) {
            Schema::table('caja_gastos', function (Blueprint $table) {
                $table->foreign('apertura_id', 'fk_gastos_apertura')
                    ->references('id')
                    ->on('caja_apertura')
                    ->onDelete('restrict');
            });
        }

        if (Schema::hasTable('usuarios')) {
            Schema::table('caja_gastos', function (Blueprint $table) {
                $table->foreign('usuario_id', 'fk_gastos_usuario')
                    ->references('id')
                    ->on('usuarios');
            });
        }
    }

    public function down(): void
    {
        // Intencionalmente vacío: NO se elimina `caja_gastos` porque contiene
        // datos históricos de gastos. Ver la nota de seguridad del encabezado.
    }
};
