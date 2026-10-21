<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ==========================================================================
 * AUDITORÍA DEL ESTADO DE LAS CATEGORÍAS
 * ==========================================================================
 *
 * Añade a `categorias` cuatro columnas NULLABLE para registrar la ÚLTIMA
 * desactivación y la ÚLTIMA reactivación:
 *
 *  - `desactivado_por` : id del usuario que desactivó la categoría.
 *  - `desactivado_en`  : fecha/hora de esa desactivación.
 *  - `reactivado_por`  : id del usuario que reactivó la categoría.
 *  - `reactivado_en`   : fecha/hora de esa reactivación.
 *
 * Es una migración ADITIVA y REVERSIBLE:
 *  - No modifica `activa`, ni nombres, ni productos, ni ventas, ni consumos.
 *  - Las categorías existentes conservan todos sus datos y quedan con las
 *    cuatro columnas en NULL (no tienen historial todavía).
 *  - No se agregan claves foráneas: el proyecto ya usa ids lógicos para sus
 *    auditorías (por ejemplo `roles.desactivado_por`) y una restricción podría
 *    impedir conservar el historial si un usuario se elimina lógicamente.
 *
 * La aplicación obtiene el usuario de la sesión autenticada; nunca acepta un
 * id de usuario enviado por el navegador.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('categorias')) {
            return;
        }

        $columnas = [
            'desactivado_por' => function (Blueprint $table): void {
                $table->integer('desactivado_por')->nullable();
            },
            'desactivado_en' => function (Blueprint $table): void {
                $table->timestamp('desactivado_en')->nullable();
            },
            'reactivado_por' => function (Blueprint $table): void {
                $table->integer('reactivado_por')->nullable();
            },
            'reactivado_en' => function (Blueprint $table): void {
                $table->timestamp('reactivado_en')->nullable();
            },
        ];

        foreach ($columnas as $nombre => $definir) {
            if (Schema::hasColumn('categorias', $nombre)) {
                continue;
            }

            Schema::table('categorias', function (Blueprint $table) use ($definir): void {
                $definir($table);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('categorias')) {
            return;
        }

        foreach ([
            'reactivado_en',
            'reactivado_por',
            'desactivado_en',
            'desactivado_por',
        ] as $nombre) {
            if (! Schema::hasColumn('categorias', $nombre)) {
                continue;
            }

            Schema::table('categorias', function (Blueprint $table) use ($nombre): void {
                $table->dropColumn($nombre);
            });
        }
    }
};
