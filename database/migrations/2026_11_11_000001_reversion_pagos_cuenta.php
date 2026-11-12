<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reversión LÓGICA de pagos y vínculo pago ↔ factura.
 *
 * CONTEXTO
 * --------
 * La anulación de una factura no borra dinero ni pagos: marca cada línea de
 * `pagos_cuenta` como REVERSADO y conserva la fila original (método, banco,
 * moneda, monto, tasa, usuario y clave de operación). Así el historial sigue
 * siendo auditable y una factura reemitida tras el recobro puede mostrar solo
 * sus propias líneas.
 *
 * COLUMNAS (todas aditivas y nullable para no romper datos históricos)
 * -------------------------------------------------------------------
 *  - factura_id         : factura a la que pertenece la línea. Se rellena al
 *                         cobrar, después de emitir la factura.
 *  - estado             : ACTIVO (cobro vigente) o REVERSADO (anulado).
 *  - reversado_en/por   : cuándo y quién ejecutó la reversión lógica.
 *  - motivo_reversion   : observación de la anulación.
 *  - anulacion_id       : fila de `anulaciones_caja_auditoria` que respalda
 *                         la reversión.
 *  - devolucion_estado  : seguimiento del REEMBOLSO FÍSICO (PENDIENTE /
 *                         REALIZADA). La reversión lógica NO inventa un
 *                         movimiento de caja: el dinero se devuelve aparte.
 *  - devolucion_en/por  : cuándo y quién registró el reembolso físico.
 *
 * No se modifica ninguna fila existente: los pagos históricos quedan con
 * estado NULL (se interpretan como ACTIVO) y factura_id NULL (se resuelven
 * por cuenta, compatibilidad hacia atrás).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pagos_cuenta')) {
            return;
        }

        $columnas = [
            'factura_id' => fn (Blueprint $t) => $t->unsignedBigInteger('factura_id')->nullable(),
            'estado' => fn (Blueprint $t) => $t->string('estado', 20)->nullable(),
            'reversado_en' => fn (Blueprint $t) => $t->timestamp('reversado_en')->nullable(),
            'reversado_por' => fn (Blueprint $t) => $t->unsignedBigInteger('reversado_por')->nullable(),
            'motivo_reversion' => fn (Blueprint $t) => $t->string('motivo_reversion', 255)->nullable(),
            'anulacion_id' => fn (Blueprint $t) => $t->unsignedBigInteger('anulacion_id')->nullable(),
            'devolucion_estado' => fn (Blueprint $t) => $t->string('devolucion_estado', 20)->nullable(),
            'devolucion_en' => fn (Blueprint $t) => $t->timestamp('devolucion_en')->nullable(),
            'devolucion_por' => fn (Blueprint $t) => $t->unsignedBigInteger('devolucion_por')->nullable(),
        ];

        foreach ($columnas as $nombre => $definicion) {
            if (Schema::hasColumn('pagos_cuenta', $nombre)) {
                continue;
            }

            Schema::table('pagos_cuenta', function (Blueprint $t) use ($definicion) {
                $definicion($t);
            });
        }

        // Índices de consulta: reversión por factura y por anulación.
        if (Schema::hasColumn('pagos_cuenta', 'factura_id')) {
            Schema::table('pagos_cuenta', function (Blueprint $t) {
                $t->index('factura_id', 'pagos_cuenta_factura_id_index');
            });
        }

        if (Schema::hasColumn('pagos_cuenta', 'anulacion_id')) {
            Schema::table('pagos_cuenta', function (Blueprint $t) {
                $t->index('anulacion_id', 'pagos_cuenta_anulacion_id_index');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('pagos_cuenta')) {
            return;
        }

        foreach ([
            'factura_id',
            'estado',
            'reversado_en',
            'reversado_por',
            'motivo_reversion',
            'anulacion_id',
            'devolucion_estado',
            'devolucion_en',
            'devolucion_por',
        ] as $nombre) {
            if (Schema::hasColumn('pagos_cuenta', $nombre)) {
                Schema::table('pagos_cuenta', function (Blueprint $t) use ($nombre) {
                    $t->dropColumn($nombre);
                });
            }
        }
    }
};
