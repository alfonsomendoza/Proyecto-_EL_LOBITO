<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ==========================================================================
 * ACTIVACIÓN / DESACTIVACIÓN DEL CATÁLOGO DE ROLES
 * ==========================================================================
 *
 * Añade a `roles`:
 *
 *  - `activo`          : 1 = activo (valor por defecto), 0 = desactivado.
 *  - `desactivado_por` : id del usuario que desactivó el rol (última vez).
 *  - `desactivado_en`  : fecha/hora de esa desactivación.
 *  - `reactivado_por`  : id del usuario que reactivó el rol (última vez).
 *  - `reactivado_en`   : fecha/hora de esa reactivación.
 *
 * Es una migración ADITIVA y REVERSIBLE:
 *  - Los cinco roles oficiales (ADMIN, GERENTE, CAJERO, MESERO, COCINERO) ya
 *    existentes quedan ACTIVOS al aplicar; el impacto es NULO.
 *  - No se recrea la tabla ni se tocan ids, nombres, `usuarios.rol_id`,
 *    ventas, cuentas, facturas, cierres ni ningún registro histórico.
 *  - `down()` solo elimina las columnas si existen.
 *
 * ALCANCE DE LA DESACTIVACIÓN (baja LÓGICA):
 *  - El rol NO se borra y las asignaciones `usuarios.rol_id` NO se modifican.
 *  - Los usuarios del rol desactivado no pueden iniciar sesión y sus
 *    peticiones se rechazan (middleware VerificarRolActivo) hasta que un
 *    ADMIN reactive el rol. Es reversible.
 *  - El rol ADMIN nunca puede desactivarse: el sistema conserva siempre un
 *    administrador operativo.
 *
 * ALCANCE DE LA AUDITORÍA (importante, no exagerar):
 *  - Estas cuatro columnas guardan QUIÉN y CUÁNDO realizó la ÚLTIMA
 *    desactivación y la ÚLTIMA reactivación de cada rol.
 *  - NO son un histórico completo: si un rol se desactiva y se reactiva
 *    varias veces, solo se conserva la última operación de cada tipo.
 *  - Para una auditoría completa (todas las operaciones, en orden) haría
 *    falta una tabla de historial aparte; queda PROPUESTA, no implementada.
 *
 * DESPLIEGUE: el código funciona también SIN esta migración. El middleware
 * y las acciones detectan la ausencia de las columnas y no bloquean ni
 * fallan (ver app/Http/Middleware/VerificarRolActivo.php). Orden recomendado:
 * respaldo -> migrate -> código.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guardas de instalación limpia y de bases importadas desde respaldo.
        if (! Schema::hasTable('roles') || Schema::hasColumn('roles', 'activo')) {
            return;
        }

        Schema::table('roles', function (Blueprint $table) {
            // 1 = activo (valor por defecto), 0 = desactivado.
            // Los cinco roles oficiales existentes quedan ACTIVOS.
            $table->boolean('activo')->default(1)->index();

            // Auditoría de la última operación. Nullable y sin clave foránea
            // para no alterar las relaciones existentes ni impedir la baja
            // lógica de usuarios (que nunca se borran, solo se desactivan).
            $table->integer('desactivado_por')->nullable();
            $table->timestamp('desactivado_en')->nullable();
            $table->integer('reactivado_por')->nullable();
            $table->timestamp('reactivado_en')->nullable();
        });
    }

    /**
     * Deshacer SOLO lo creado por esta migración. No toca datos de negocio:
     * los roles y sus asignaciones en `usuarios.rol_id` quedan intactos.
     */
    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        Schema::table('roles', function (Blueprint $table) {
            foreach (['activo', 'desactivado_por', 'desactivado_en', 'reactivado_por', 'reactivado_en'] as $columna) {
                if (Schema::hasColumn('roles', $columna)) {
                    $table->dropColumn($columna);
                }
            }
        });
    }
};
