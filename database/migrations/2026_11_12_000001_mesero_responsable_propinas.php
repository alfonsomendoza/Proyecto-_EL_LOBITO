<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Asignación de mesero responsable por mesa / venta / cuenta e histórico de
 * cambios.
 *
 * Resumen de diseño e integridad: ver docs/ y la PR previa. Reglas clave:
 *
 *  - Columnas de responsable NULLABLE: sin asignación no se atribuye a nadie.
 *  - Atribución histórica NO depende del estado del usuario.
 *  - No se inventa mesero; propinas sin atribución inequívoca quedan pendientes.
 *  - `down()` nunca borra historial; rollback bloqueado si existe.
 *
 * INTEGRIDAD REFERENCIAL (6 FK, todas RESTRICT)
 * ---------------------------------------------
 *   1. mesa_asignaciones.venta_id      -> ventas.id    RESTRICT
 *   2. mesa_asignaciones.mesa_id       -> mesas.id     RESTRICT
 *   3. mesa_asignaciones.mesero_id     -> usuarios.id  RESTRICT
 *   4. mesa_asignaciones.asignado_por  -> usuarios.id  RESTRICT
 *   5. ventas.mesero_responsable_id   -> usuarios.id  RESTRICT
 *   6. cuentas.mesero_responsable_id  -> usuarios.id  RESTRICT
 *
 * DETECCIÓN DE FK YA EXISTENTES
 * -----------------------------
 * Una FK se considera VÁLIDA si existe una FK que referencia
 * `columna -> tabla_ref.col_ref` con `ON DELETE` RESTRICT (o NO ACTION,
 * equivalente). Se valida exigiendo la correspondencia exacta: misma tabla
 * (`$tabla`), misma columna, misma tabla referenciada, misma columna
 * referenciada y regla de eliminación compatible. Con ese criterio:
 *
 *  - FK ausente                         -> se crea.
 *  - FK existente correcta (otro nombre) -> se reconoce y NO se duplica.
 *  - FK existente incompatible           -> FALLA la migración (no se toca nada).
 *  - Error al crear la FK                -> FALLA la migración (nada se omite).
 *
 * ROLLBACK PARCIAL E INTEGRIDAD DE DDL
 * ------------------------------------
 * MySQL NO garantiza rollback completo para DDL. Si esta migración falla tras
 * crear columnas/índices/FKs, pueden quedar presentes en la base aunque la
 * migración se marque como fallida. Para reparar un fallo parcial sin borrar
 * datos:
 *
 *   1. No alterar nada; inspeccionar tablas y columnas con:
 *      `SHOW COLUMNS FROM ventas`, `SHOW COLUMNS FROM cuentas`,
 *      `SHOW TABLES LIKE 'mesa_asignaciones'`, `SHOW CREATE TABLE ...`.
 *   2. Identificar los objetos creados: columnas `mesero_responsable_id` en
 *      ventas y cuentas; índices `ventas_mesero_responsable_id_index` /
 *      `cuentas_mesero_responsable_id_index`; tabla `mesa_asignaciones` y sus
 *      4 FKs.
 *   3. Decidir: completar el esquema manualmente aplicando lo que la migración
 *      no pudo ejecutar, o revertir conservando historial (ver `down()`).
 *   4. NUNCA eliminar `mesa_asignaciones` ni sus columnas de responsable para
 *      «arreglar» el fallo: contienen el historial de asignaciones.
 *
 * Si se detecta un objeto parcial, la migración falla siempre (nunca continúa
 * como generada correctamente). Reparar requiere un procedimiento controlado y
 * respaldo de historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------
        // PREVALIDACIÓN: antes de crear nada se comprueba que se puede
        // ejecutar la migración. Si algo no es compatible se aborta aquí,
        // con excepción clara y sin crear ningún objeto.
        // ------------------------------------------------------------
        $this->prevalidar();

        // ------------------------------------------------------------
        // Crear columnas (idempotente).
        // ------------------------------------------------------------
        if (! Schema::hasColumn('ventas', 'mesero_responsable_id')) {
            Schema::table('ventas', function (Blueprint $table) {
                $table->integer('mesero_responsable_id')->nullable()->after('usuario_id');
                $table->index('mesero_responsable_id');
            });
        }

        if (! Schema::hasColumn('cuentas', 'mesero_responsable_id')) {
            Schema::table('cuentas', function (Blueprint $table) {
                $table->integer('mesero_responsable_id')->nullable()->after('venta_id');
                $table->index('mesero_responsable_id');
            });
        }

        // ------------------------------------------------------------
        // Crear tabla (idempotente). Las cuatro FK de la tabla se exigen
        // también si la tabla ya existía de una ejecución anterior.
        // ------------------------------------------------------------
        if (! Schema::hasTable('mesa_asignaciones')) {
            Schema::create('mesa_asignaciones', function (Blueprint $table) {
                $table->id();
                $table->integer('venta_id');
                $table->integer('mesa_id');
                $table->integer('mesero_id')->nullable();
                $table->integer('asignado_por')->nullable();
                $table->string('motivo', 255)->nullable();
                $table->timestamp('creado_en')->useCurrent();
            });
        }

        // Índice de consulta.
        if (! $this->indiceExiste('mesa_asignaciones', 'venta_id')) {
            Schema::table('mesa_asignaciones', function (Blueprint $table) {
                $table->index('venta_id');
                $table->index('mesa_id');
            });
        }

        // ------------------------------------------------------------
        // FK obligatorias: si alguna no existe, se crea y, si falla, se
        // devuelve RuntimeException para que la migración falle completa.
        // ------------------------------------------------------------
        $this->exigirFk('mesa_asignaciones', 'venta_id', 'ventas', 'id');
        $this->exigirFk('mesa_asignaciones', 'mesa_id', 'mesas', 'id');
        $this->exigirFk('mesa_asignaciones', 'mesero_id', 'usuarios', 'id');
        $this->exigirFk('mesa_asignaciones', 'asignado_por', 'usuarios', 'id');
        $this->exigirFk('ventas', 'mesero_responsable_id', 'usuarios', 'id');
        $this->exigirFk('cuentas', 'mesero_responsable_id', 'usuarios', 'id');
    }

    public function down(): void
    {
        // PROTECCIÓN DE ROLLBACK: nunca borrar historial automáticamente.
        if (Schema::hasTable('mesa_asignaciones')
            && DB::table('mesa_asignaciones')->exists()) {
            throw new \RuntimeException(
                'Rollback cancelado: existe historial de asignaciones de meseros en `mesa_asignaciones`. '
                .'Respalde el historial (p. ej. un dump de la tabla) antes de revertir esta migración. '
                .'No se eliminó ninguna columna ni tabla.'
            );
        }

        // Rollback desde un estado sin historial: rollback normal.
        if (Schema::hasTable('ventas') && Schema::hasColumn('ventas', 'mesero_responsable_id')) {
            try {
                Schema::table('ventas', function (Blueprint $table) {
                    try {
                        $table->dropIndex(['mesero_responsable_id']);
                    } catch (\Throwable $e) {
                        // Índice inexistente: no impide el rollback.
                    }
                });
            } catch (\Throwable $e) {
                // Ignorar fallos de dropIndex (compatibilidad SQLite).
            }

            if (DB::connection()->getDriverName() === 'sqlite') {
                $this->rebuildTablaSinColumnaSqlite('ventas', 'mesero_responsable_id');
            } else {
                Schema::table('ventas', function (Blueprint $table) {
                    $fk = $this->buscarFk('ventas', 'mesero_responsable_id');
                    if ($fk !== null) {
                        try {
                            $table->dropForeign($fk->nombre);
                        } catch (\Throwable $e) {
                            // Si el motor no permite eliminar la FK por nombre, se continúa.
                        }
                    } else {
                        try {
                            $table->dropForeign(['mesero_responsable_id']);
                        } catch (\Throwable $e) {
                            // Si no existe la FK, se omite.
                        }
                    }

                    $table->dropColumn('mesero_responsable_id');
                });
            }
        }

        if (Schema::hasTable('cuentas') && Schema::hasColumn('cuentas', 'mesero_responsable_id')) {
            try {
                Schema::table('cuentas', function (Blueprint $table) {
                    try {
                        $table->dropIndex(['mesero_responsable_id']);
                    } catch (\Throwable $e) {
                        // Índice inexistente.
                    }
                });
            } catch (\Throwable $e) {
                // Ignorar fallos de dropIndex.
            }

            if (DB::connection()->getDriverName() === 'sqlite') {
                $this->rebuildTablaSinColumnaSqlite('cuentas', 'mesero_responsable_id');
            } else {
                Schema::table('cuentas', function (Blueprint $table) {
                    $fk = $this->buscarFk('cuentas', 'mesero_responsable_id');
                    if ($fk !== null) {
                        try {
                            $table->dropForeign($fk->nombre);
                        } catch (\Throwable $e) {
                            // Si el motor no permite eliminar la FK por nombre, se continúa.
                        }
                    } else {
                        try {
                            $table->dropForeign(['mesero_responsable_id']);
                        } catch (\Throwable $e) {
                            // Si no existe la FK, se omite.
                        }
                    }

                    $table->dropColumn('mesero_responsable_id');
                });
            }
        }

        Schema::dropIfExists('mesa_asignaciones');
    }

    /**
     * Prevalidación estructural: tablas y columnas requeridas. Se ejecuta
     * ANTES de cualquier DDL para no crear objetos de forma parcial.
     */
    private function prevalidar(): void
    {
        foreach (['ventas', 'cuentas', 'mesas', 'usuarios'] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                throw new \RuntimeException("Prevalidación fallida: falta la tabla '{$tabla}'. La migración no creó ningún objeto.");
            }
        }

        if (! Schema::hasColumn('usuarios', 'id')) {
            throw new \RuntimeException("Prevalidación fallida: 'usuarios.id' no existe. La migración no creó ningún objeto.");
        }

        // Tipos de las referencias compatibles con enteros en destino.
        $tipoUsuario = $this->tipoColumna('usuarios', 'id');

        if ($tipoUsuario !== null && ! str_contains(strtolower((string) $tipoUsuario), 'int')) {
            throw new \RuntimeException("Prevalidación fallida: 'usuarios.id' ({$tipoUsuario}) no es entero. La migración no creó ningún objeto.");
        }
    }

    /**
     * Exige una FK. Lanza RuntimeException si detecta conflicto o si la
     * creación falla. NUNCA se ignora un error de FK creando parcialmente.
     */
    private function exigirFk(string $tabla, string $columna, string $tablaRef, string $columnaRef): void
    {
        if (! Schema::hasTable($tabla) || ! Schema::hasColumn($tabla, $columna)) {
            throw new \RuntimeException("FK requerida {$tabla}.{$columna} -> {$tablaRef}.{$columnaRef}: falta la tabla o la columna. La migración falló antes de crearla.");
        }

        if (! Schema::hasTable($tablaRef) || ! Schema::hasColumn($tablaRef, $columnaRef)) {
            throw new \RuntimeException("FK requerida {$tabla}.{$columna} -> {$tablaRef}.{$columnaRef}: falta la tabla o la columna referenciada. La migración falló antes de crearla.");
        }

        // 1) Detectar FK existente con esta relación.
        $existente = $this->buscarFk($tabla, $columna, $tablaRef, $columnaRef);

        if ($existente !== null) {
            $onDelete = strtoupper((string) ($existente->onDelete ?? ''));

            if (! in_array($onDelete, ['RESTRICT', 'NO ACTION'], true)) {
                throw new \RuntimeException("FK existente {$tabla}.{$columna} -> {$tablaRef}.{$columnaRef} con regla onDelete={$onDelete} es incompatible (se requiere RESTRICT). La migración se detiene; no se altera el esquema existente.");
            }

            return;
        }

        // 1b) Existe una FK para la misma columna pero contra otra tabla:
        // conflicto incompatible, se aborta.
        $otroConflicto = $this->buscarFk($tabla, $columna, null, null);
        if ($otroConflicto !== null) {
            $desc = "FK existente incompatible {$tabla}.{$columna} -> {$otroConflicto->tablaRef}.{$otroConflicto->columnaRef} (onDelete={$otroConflicto->onDelete}), no coincide con la requerida {$tablaRef}.{$columnaRef} (RESTRICT).";
            throw new \RuntimeException($desc.' La migración se detiene; no se altera el esquema existente.');
        }

        // 2) Verificar huérfanos ANTES de crear la FK.
        $huerfanos = (int) DB::table($tabla)
            ->whereNotNull($columna)
            ->whereNotExists(function ($q) use ($tablaRef, $columnaRef, $columna, $tabla) {
                $q->select(DB::raw(1))
                    ->from($tablaRef)
                    ->whereColumn("{$tablaRef}.{$columnaRef}", "{$tabla}.{$columna}");
            })
            ->count();

        if ($huerfanos > 0) {
            throw new \RuntimeException("FK requerida {$tabla}.{$columna} -> {$tablaRef}.{$columnaRef}: existen {$huerfanos} registros sin referencia válida. La migración falla ANTES de crear la FK; los datos no se modifican.");
        }

        // 3) Crear la FK. Si falla, se PROPAGA y la migración falla.
        try {
            Schema::table($tabla, function (Blueprint $table) use ($columna, $tablaRef, $columnaRef, $tabla) {
                $table->foreign($columna, $tabla.'_'.$columna.'_foreign')
                    ->references($columnaRef)
                    ->on($tablaRef)
                    ->onDelete('restrict');
            });
        } catch (\Throwable $e) {
            throw new \RuntimeException("No se pudo crear la FK requerida {$tabla}.{$columna} -> {$tablaRef}.{$columnaRef} (RESTRICT): ".$e->getMessage().' La migración falló; no se omitió el error.', 0, $e);
        }
    }

    /**
     * Busca una FK para una columna. Si se pasan $tablaRef y $columnaRef,
     * solo devuelve la FK que coincide con esa relación; si son null,
     * devuelve la primera FK que exista para la columna (incompatibilidad).
     *
     * @return object{nombre: string, tablaRef: string, columnaRef: string, onDelete: string}|null
     */
    private function buscarFk(string $tabla, string $columna, ?string $tablaRef = null, ?string $columnaRef = null): ?object
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $filas = DB::select('PRAGMA foreign_key_list(`'.$tabla.'`)');

            foreach ($filas as $fila) {
                if (($fila->from ?? null) !== $columna) {
                    continue;
                }

                if ($tablaRef !== null && strtolower($fila->table ?? '') !== strtolower($tablaRef)) {
                    continue;
                }

                if ($columnaRef !== null && strtolower((string) ($fila->to ?? '')) !== strtolower((string) $columnaRef)) {
                    continue;
                }

                $onDelete = strtoupper((string) ($fila->on_delete ?? ''));

                if ($onDelete === 'SET NULL') {
                    return (object) ['nombre' => 'fk_'.$fila->id, 'tablaRef' => $fila->table, 'columnaRef' => $fila->to, 'onDelete' => 'CASCADE/SET NULL'];
                }

                if (in_array($onDelete, ['RESTRICT', 'NO ACTION'], true)) {
                    return (object) ['nombre' => 'fk_'.$fila->id, 'tablaRef' => $fila->table, 'columnaRef' => $fila->to, 'onDelete' => $onDelete];
                }

                return (object) ['nombre' => 'fk_'.$fila->id, 'tablaRef' => $fila->table, 'columnaRef' => $fila->to, 'onDelete' => $onDelete];
            }

            return null;
        }

        // MySQL / MariaDB.
        $sql = "SELECT kcu.CONSTRAINT_NAME AS constraint_name,
                       kcu.COLUMN_NAME AS columna,
                       kcu.REFERENCED_TABLE_NAME AS tabla_ref,
                       kcu.REFERENCED_COLUMN_NAME AS columna_ref,
                       rc.DELETE_RULE AS on_delete
                  FROM information_schema.KEY_COLUMN_USAGE kcu
                  JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
                    ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                   AND rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
                 WHERE kcu.TABLE_SCHEMA = DATABASE()
                   AND kcu.TABLE_NAME = ?
                   AND kcu.COLUMN_NAME = ?";

        if ($tablaRef !== null && $columnaRef !== null) {
            $sql .= " AND kcu.REFERENCED_TABLE_NAME = ? AND kcu.REFERENCED_COLUMN_NAME = ?";
        }

        $params = [$tabla, $columna];

        if ($tablaRef !== null && $columnaRef !== null) {
            $params[] = $tablaRef;
            $params[] = $columnaRef;
        }

        $filas = DB::select($sql, $params);

        foreach ($filas as $fila) {
            $onDelete = strtoupper((string) ($fila->on_delete ?? ''));

            if (in_array($onDelete, ['RESTRICT', 'NO ACTION'], true)) {
                return (object) ['nombre' => $fila->constraint_name, 'tablaRef' => $fila->tabla_ref, 'columnaRef' => $fila->columna_ref, 'onDelete' => $onDelete];
            }

            return (object) ['nombre' => $fila->constraint_name, 'tablaRef' => $fila->tabla_ref, 'columnaRef' => $fila->columna_ref, 'onDelete' => $onDelete];
        }

        return null;
    }

    /**
     * ¿Existe un índice NOMBRADO sobre esa columna? Se detecta por position/definition en el propio motor.
     */
    private function indiceExiste(string $tabla, string $columna): bool
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $indices = DB::select('PRAGMA index_list(`'.$tabla.'`)');
            foreach ($indices as $indice) {
                $cols = DB::select('PRAGMA index_info(`'.$indice->name.'`)');

                foreach ($cols as $col) {
                    if (strtolower($col->name ?? '') === strtolower($columna)) {
                        return true;
                    }
                }
            }

            return false;
        }

        $count = (int) DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', $tabla)
            ->where('COLUMN_NAME', $columna)
            ->count();

        return $count > 0;
    }

    /**
     * Reconstruye una tabla SQLite eliminando una columna. Necesario porque
     * SQLite no permite `DROP COLUMN` cuando la tabla declara una FK que lo
     * referencie. Se preservan datos, clave primaria e índices del motor.
     */
    private function rebuildTablaSinColumnaSqlite(string $tabla, string $columnaOmitida): void
    {
        $columnas = DB::select('PRAGMA table_info("'.$tabla.'")');
        $definiciones = [];
        $columnasCopia = [];
        $pk = [];

        foreach ($columnas as $col) {
            if ($col->name === $columnaOmitida) {
                continue;
            }

            $definicion = '"'.$col->name.'" '.$col->type;

            if ((int) $col->notnull === 1) {
                $definicion .= ' NOT NULL';
            }

            if ($col->dflt_value !== null) {
                $definicion .= ' DEFAULT '.$col->dflt_value;
            }

            $definiciones[] = $definicion;
            $columnasCopia[] = '"'.$col->name.'"';

            if ((int) $col->pk > 0) {
                $tablaPkKey = ((int) $col->pk).'|'.$col->name;
                $pk[(int) $col->pk - 1] = $tablaPkKey;
            }
        }

        if ($pk !== []) {
            sort($pk);
            $nombresPk = array_map(fn ($v) => explode('|', $v)[1], $pk);
            $definiciones[] = 'PRIMARY KEY ("'.implode('","', $nombresPk).'")';
        }

        DB::statement('CREATE TABLE "_temp_'.$tabla.'" ('.implode(', ', $definiciones).')');
        DB::statement('INSERT INTO "_temp_'.$tabla.'" SELECT '.implode(',', $columnasCopia).' FROM "'.$tabla.'"');
        DB::statement('DROP TABLE "'.$tabla.'"');
        DB::statement('ALTER TABLE "_temp_'.$tabla.'" RENAME TO "'.$tabla.'"');
    }

    /**
     * Tipo de una columna (informativo, para prevalidación MySQL).
     */
    private function tipoColumna(string $tabla, string $columna): ?string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $filas = DB::select("PRAGMA table_info('".$tabla."')");

            foreach ($filas as $fila) {
                if ($fila->name === $columna) {
                    return (string) $fila->type;
                }
            }

            return null;
        }

        $row = DB::selectOne("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?", [$tabla, $columna]);

        return $row ? (string) $row->COLUMN_TYPE : null;
    }
};
