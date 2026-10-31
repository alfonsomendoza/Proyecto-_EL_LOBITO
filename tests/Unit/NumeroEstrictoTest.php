<?php

namespace Tests\Unit;

use App\Rules\NumeroEstricto;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Regla compartida de validación numérica.
 *
 * No toca base de datos: comprueba el formato aceptado/rechazado y que la
 * regla se integre con el validador de Laravel.
 */
class NumeroEstrictoTest extends TestCase
{
    public function test_acepta_formatos_numericos_validos(): void
    {
        foreach (['0', '10', '10.5', '10.50', '999999.99'] as $valor) {
            $this->assertTrue(NumeroEstricto::esValido($valor, 2), $valor);
        }

        $this->assertTrue(NumeroEstricto::esValido(10, 2));
        $this->assertTrue(NumeroEstricto::esValido(10.5, 2));
    }

    public function test_rechaza_letras_notacion_cientifica_y_caracteres_extra(): void
    {
        $invalidos = [
            'e',
            'E',
            '0e',
            '0e13212312132121',
            '1e3',
            '10abc',
            '--10',
            '-10',
            '10%%',
            '10.999',
            '1,5',
            '',
        ];

        foreach ($invalidos as $valor) {
            $this->assertFalse(NumeroEstricto::esValido($valor, 2), 'Debió rechazar: '.$valor);
        }
    }

    public function test_precision_cero_solo_admite_enteros(): void
    {
        $this->assertTrue(NumeroEstricto::esValido('10', 0));
        $this->assertFalse(NumeroEstricto::esValido('10.0', 0));
        $this->assertFalse(NumeroEstricto::esValido('10.5', 0));
    }

    public function test_porcentajes_y_su_normalizacion(): void
    {
        $this->assertSame('10', NumeroEstricto::normalizarPorcentaje('10%'));
        $this->assertSame('10%%', NumeroEstricto::normalizarPorcentaje('10%%'));

        $this->assertTrue(NumeroEstricto::esValido('10%', 2, 0, 100, true));
        $this->assertFalse(NumeroEstricto::esValido('10%%', 2, 0, 100, true));
        $this->assertFalse(NumeroEstricto::esValido('101', 2, 0, 100, true));
    }

    public function test_validador_laravel_rechaza_notacion_cientifica(): void
    {
        $validador = Validator::make(
            ['monto' => '1e3'],
            ['monto' => [new NumeroEstricto(2)]]
        );

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('monto', $validador->errors()->toArray());
    }

    public function test_validador_laravel_acepta_decimales_y_vacio(): void
    {
        $valido = Validator::make(
            ['monto' => '10.50'],
            ['monto' => [new NumeroEstricto(2)]]
        );
        $this->assertFalse($valido->fails());

        $vacio = Validator::make(
            ['monto' => ''],
            ['monto' => [new NumeroEstricto(2)]]
        );
        $this->assertFalse($vacio->fails());
    }
}
