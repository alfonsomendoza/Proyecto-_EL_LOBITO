<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega columnas de normalización a `clientes` y (fuera de esta tarea)
 * prepara la base para índice único de identificación normalizada.
 *
 * Reglas de la migración:
 * - Solo se ejecuta si la tabla clientes existe (compatibilidad con bases
 *   existentes que no tienen la tabla porque se importaron sin migraciones).
 * - Agrega `identificacion_normalizada` (string nullable) y
 *   `telefono_normalizado` (string nullable).
 * - Normaliza datos existentes SINO existen esas columnas.
 * - Detecta duplicados reales de identificación normalizada y DETIENE la
 *   migración con error claro si los hay (para que no se cree índice único
 *   sobre duplicados).
 * - Vacíos se guardan como NULL.
 * - Cliente General (ID 1) queda intacto.
 * - NO backfilla `cuentas.cliente_id`, NO borra clientes y NO modifica
 *   ventas.
 *
 * La creación del índice único se hace en una migración SEPARADA para
 * que, en instalaciones limpias, primero se normalicen datos y detecten
 * duplicados antes de imponer la restricción.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('clientes')) {
            return;
        }

        Schema::table('clientes', function (Blueprint $table) {
            if (!Schema::hasColumn('clientes', 'identificacion_normalizada')) {
                $table->string('identificacion_normalizada', 50)->nullable()->after('identificacion');
            }

            if (!Schema::hasColumn('clientes', 'telefono_normalizado')) {
                $table->string('telefono_normalizado', 30)->nullable()->after('telefono');
            }
        });

        // Normalizar datos existentes solo si las columnas acabamos de
        // crear (si ya existían, asumimos que ya están normalizadas o
        // el negocio gestiona manualmente).
        if (!Schema::hasColumn('clientes', 'identificacion_normalizada')
            || !Schema::hasColumn('clientes', 'telefono_normalizado')) {
            return;
        }

        $clientes = DB::table('clientes')->get();
        $duplicados = [];

        // Solo ejecutar normalización y detección de duplicados si las
        // columnas existen; si no existen es porque la tabla no tiene las
        // columnas (base importada sin migraciones) y no debemos intentar
        // normalizar ni comparar duplicados aquí. La migración aún puede
        // ejecutarse para crear las columnas en instalaciones limpias.

        // Solo ejecutar normalización y detección de duplicados si las
        // columnas existen; si no existen es porque la tabla no tiene las
        // columnas (base importada sin migraciones) y no debemos intentar
        // normalizar ni comparar duplicados aquí. La migración aún puede
        // ejecutarse para crear las columnas en instalaciones limpias.

        foreach ($clientes as $cliente) {
            // Identificación normalizada.
            $ident = $cliente->identificacion;
            $identNormalizada = self::normalizarIdentificacion($ident);

            if ($identNormalizada !== null && $cliente->identificacion_normalizada !== $identNormalizada) {
                DB::table('clientes')
                    ->where('id', $cliente->id)
                    ->update(['identificacion_normalizada' => $identNormalizada]);
            }

            // Teléfono normalizado.
            $telefono = $cliente->telefono;
            $telefonoNormalizado = self::normalizarTelefono($telefono);

            if ($telefonoNormalizado !== null && $cliente->telefono_normalizado !== $telefonoNormalizado) {
                DB::table('clientes')
                    ->where('id', $cliente->id)
                    ->update(['telefono_normalizado' => $telefonoNormalizado]);
            }

            // Detectar duplicados de identificación (ignora NULL y Cliente General).
            if ($identNormalizada !== null && (int) $cliente->id !== 1) {
                $duplicados[$identNormalizada][] = $cliente->id;
            }
        }

        // Detener si hay duplicados reales de identificación.
        foreach ($duplicados as $ident => $ids) {
            if (count($ids) > 1) {
                throw new RuntimeException(
                    "No se puede crear el índice único de identificación: existen clientes duplicados con identificación normalizada '{$ident}' (IDs: ".implode(',', $ids)."). ".
                    "Resuelva los duplicados manualmente antes de ejecutar esta migración en producción."
                );
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('clientes')) {
            return;
        }

        Schema::table('clientes', function (Blueprint $table) {
            if (Schema::hasColumn('clientes', 'identificacion_normalizada')) {
                $table->dropColumn('identificacion_normalizada');
            }

            if (Schema::hasColumn('clientes', 'telefono_normalizado')) {
                $table->dropColumn('telefono_normalizado');
            }
        });
    }

    private static function normalizarIdentificacion(?string $valor): ?string
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        $limpio = trim((string) $valor);
        $limpio = str_replace(['$', ',', ' '], '', $limpio);
        $limpio = str_replace('-', '', $limpio);
        $limpio = strtoupper($limpio);

        return $limpio === '' ? null : $limpio;
    }

    private static function normalizarTelefono(?string $valor): ?string
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        $limpio = trim((string) $valor);
        $limpio = str_replace([' ', '-'], '', $limpio);
        $limpio = str_replace(['(', ')'], '', $limpio);

        if (str_starts_with($limpio, '+507')) {
            $limpio = substr($limpio, 4);
        } elseif (str_starts_with($limpio, '507')) {
            $limpio = substr($limpio, 3);
        }

        $limpio = ltrim($limpio, '0');

        return $limpio === '' ? null : $limpio;
    }
};
