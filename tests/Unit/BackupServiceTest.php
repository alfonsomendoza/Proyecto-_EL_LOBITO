<?php

namespace Tests\Unit;

use App\Services\BackupService;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas unitarias de las validaciones puras del módulo de respaldo.
 * No requieren base de datos ni proceso externo.
 */
class BackupServiceTest extends TestCase
{
    public function test_generar_nombre_sigue_el_formato_controlado(): void
    {
        $fecha = new DateTimeImmutable('2026-10-12 16:30:00');
        $nombre = BackupService::generarNombre('sistemadecaja', $fecha);

        $this->assertSame('backup_sistemadecaja_20261012_163000.sql', $nombre);
        $this->assertTrue(BackupService::nombreValido($nombre));
    }

    public function test_generar_nombre_saneala_caracteres_peligrosos_de_la_base(): void
    {
        $fecha = new DateTimeImmutable('2026-10-12 16:30:00');

        // Puntos, espacios y caracteres raros se eliminan para mantener el patrón.
        $nombre = BackupService::generarNombre('sistemadecaja.test extra', $fecha);

        $this->assertTrue(BackupService::nombreValido($nombre));
        $this->assertStringNotContainsString('.test', $nombre);
        $this->assertStringNotContainsString(' ', $nombre);
        $this->assertMatchesRegularExpression('/^backup_sistemadecajatestextra_\d{8}_\d{6}\.sql$/', $nombre);
    }

    public function test_nombre_valido_acepta_solo_el_patron_del_modulo(): void
    {
        $this->assertTrue(BackupService::nombreValido('backup_sistemadecaja_20261012_163000.sql'));
        $this->assertTrue(BackupService::nombreValido('backup_sistemadecaja_20261012_163000_1.sql'));
    }

    public function test_nombre_valido_rechaza_traversal_y_extensiones_ajenas(): void
    {
        $invalidos = [
            '',
            '../backup_sistemadecaja_20261012_163000.sql',
            '..\\backup_sistemadecaja_20261012_163000.sql',
            '../../.env',
            'C:\\windows\\system32\\backup.sql',
            'backup.sql',
            'backup_sistemadecaja_20261012_163000.sql.exe',
            'backup_sistemadecaja_20261012_163000.sql/escaped.sql',
            'sin_prefijo_20261012_163000.sql',
            'backup_20261012_163000.sql',
        ];

        foreach ($invalidos as $nombre) {
            $this->assertFalse(
                BackupService::nombreValido($nombre),
                'Debió rechazarse: ' . $nombre
            );
        }
    }

    public function test_parece_volcado_mysql_detecta_estructura_real(): void
    {
        $temporal = tempnam(sys_get_temp_dir(), 'elobito_unit_');
        file_put_contents($temporal, "-- MySQL dump 10.13 Distrib 8.0.43, for Win64\nCREATE TABLE `ventas` (`id` bigint);\n");

        $this->assertTrue(BackupService::pareceVolcadoMysql($temporal));

        @unlink($temporal);
    }

    public function test_parece_volcado_mysql_rechaza_archivos_sin_estructura(): void
    {
        $temporal = tempnam(sys_get_temp_dir(), 'elobito_unit_');

        // Contenido arbitrario que no es un volcado MySQL.
        file_put_contents($temporal, "contenido arbitrario sin estructura sql\n" . str_repeat('x', 5000));
        $this->assertFalse(BackupService::pareceVolcadoMysql($temporal));

        // Archivo vacío.
        file_put_contents($temporal, '');
        $this->assertFalse(BackupService::pareceVolcadoMysql($temporal));

        @unlink($temporal);
    }
}