<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ==========================================================================
 * COBRO INTEGRAL: PAGOS MIXTOS Y AJUSTES OPCIONALES DE LA CUENTA
 * ==========================================================================
 *
 * 1) `pagos_cuenta`: una fila por CADA LÍNEA DE PAGO de un cobro (una cuenta
 *    puede pagarse con efectivo + débito + dólares, etc.). Antes solo
 *    existían `cuentas.metodo_pago` (un único método) y se perdía el resto.
 *
 *    - `metodo_pago`: EFECTIVO_CORDOBA / EFECTIVO_USD / TARJETA_DEBITO /
 *      TARJETA_CREDITO (también admite los valores históricos EFECTIVO,
 *      TARJETA, TRANSFERENCIA de las peticiones antiguas).
 *    - `banco`: SOLO para tarjeta (Banpro / Ficohsa / BAC / Lafise). El banco
 *      es un dato de la línea, nunca un método independiente.
 *    - `moneda` + `monto`: importe y moneda ORIGINALES del pago (NIO o USD).
 *    - `tasa_cambio`: solo en USD; el servidor calcula `monto_cordobas` con
 *      la tasa vigente (nunca un valor fijo inventado por el código).
 *    - `clave_operacion`: idempotencia — un reintento no duplica la línea.
 *    - SIN FK a propósito (igual que 2026_10_18_000001): la integridad la
 *      valida la transacción del cobro y así el despliegue en producción
 *      no depende del tipo exacto de las PK históricas.
 *
 * 2) Ajustes opcionales en `cuentas` (por defecto 0 = sin ajuste, no se
 *    aplican automáticamente):
 *
 *    - `descuento` + `descuento_motivo` + `descuento_usuario_id`: descuento
 *      manual introducido al cobrar (solo ADMIN/GERENTE; el rol CONTADOR NO
 *      existe en `roles` y no se crea en silencio — decisión documentada).
 *    - `propina` opcional (la propina sugerida del 10 % la calcula la
 *      interfaz; la base es consumo − descuento y la fórmula vive en
 *      VentaController::pagarCuenta, una sola vez por cobro).
 *
 * `cuentas.total` SIGNEIFICANDO EL CONSUMO (no cambia de significado): el
 * total a cobrar es consumo − descuento + propina y se recalcula en el
 * cobro. Así las divisiones y los traslados siguen trabajando con consumo.
 *
 * Guardas hasTable/hasColumn: la migración es inocua si ya se aplicó.
 * down() SOLO elimina lo que esta migración crea (columnas con valor por
 * defecto 0 y tabla nueva); no toca datos históricos.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // 1. Líneas de pago de cada cobro (pagos mixtos y parciales).
        // ---------------------------------------------------------------
        if (!Schema::hasTable('pagos_cuenta')) {
            Schema::create('pagos_cuenta', function (Blueprint $table) {
                $table->bigIncrements('id');

                // Idempotencia por línea (uuid del navegador).
                $table->string('clave_operacion', 64)->nullable()->unique();

                $table->unsignedBigInteger('cuenta_id');
                $table->integer('venta_id');

                $table->string('metodo_pago', 30);
                $table->string('banco', 30)->nullable();

                $table->string('moneda', 10)->default('NIO');
                $table->decimal('monto', 12, 2);
                $table->decimal('tasa_cambio', 10, 4)->nullable();
                $table->decimal('monto_cordobas', 12, 2);

                $table->integer('usuario_id')->nullable();
                $table->timestamp('creado_en')->nullable();

                $table->index('cuenta_id');
                $table->index('venta_id');
            });
        }

        // ---------------------------------------------------------------
        // 2. Ajustes opcionales de cobro en la cuenta (descuento/propina).
        // ---------------------------------------------------------------
        if (Schema::hasTable('cuentas') && !Schema::hasColumn('cuentas', 'descuento')) {
            Schema::table('cuentas', function (Blueprint $table) {
                $table->decimal('descuento', 12, 2)->default(0);
                $table->string('descuento_motivo', 255)->nullable();
                $table->integer('descuento_usuario_id')->nullable();
                $table->decimal('propina', 12, 2)->default(0);
            });
        }
    }

    /**
     * Solo lo creado aquí: la tabla de líneas de pago y las cuatro columnas
     * de ajuste (todas con valor por defecto 0 o NULL, sin datos históricos
     * que perder: antes de esta migración no existían).
     */
    public function down(): void
    {
        if (Schema::hasTable('cuentas') && Schema::hasColumn('cuentas', 'descuento')) {
            Schema::table('cuentas', function (Blueprint $table) {
                $table->dropColumn([
                    'descuento',
                    'descuento_motivo',
                    'descuento_usuario_id',
                    'propina',
                ]);
            });
        }

        Schema::dropIfExists('pagos_cuenta');
    }
};
