<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida números escritos en formato convencional.
 *
 * Regla deliberadamente estricta: NO acepta notación científica (`1e3`,
 * `0e123`), letras, signos de más/menos ni separadores repetidos. Solo admite
 * dígitos y, cuando el campo lo permite, un punto decimal con la precisión
 * indicada.
 *
 * Es independiente de `numeric` de Laravel, que sí acepta `1e3`.
 */
class NumeroEstricto implements ValidationRule
{
    public function __construct(
        private int $decimales = 2,
        private bool $aceptaPorcentaje = false,
    ) {}

    /**
     * Quita UN símbolo `%` final para poder validar el número. No elimina
     * letras ni otros caracteres: si la cadena no es un porcentaje completo,
     * se devuelve tal cual y la validación la rechazará.
     */
    public static function normalizarPorcentaje(mixed $valor): mixed
    {
        if (! is_string($valor)) {
            return $valor;
        }

        $texto = trim($valor);

        // Solo se quita UN `%` final y solo si no hay ningún otro símbolo.
        // Así "10%%" y "10abc%" no se convierten en números válidos.
        if ($texto === '' || ! str_ends_with($texto, '%') || substr_count($texto, '%') !== 1) {
            return $texto;
        }

        return trim(substr($texto, 0, -1));
    }

    /**
     * Comprobación reutilizable fuera de la validación de Laravel (por ejemplo,
     * al normalizar ajustes de cobro que no pasan por `$request->validate`).
     */
    public static function esValido(
        mixed $valor,
        int $decimales = 2,
        ?float $minimo = null,
        ?float $maximo = null,
        bool $aceptaPorcentaje = false,
    ): bool {
        $texto = self::textoNumerico($valor, $aceptaPorcentaje);

        if ($texto === null) {
            return false;
        }

        if (! preg_match(self::patron($decimales), $texto)) {
            return false;
        }

        $numero = (float) $texto;

        if (! is_finite($numero)) {
            return false;
        }

        if ($minimo !== null && $numero < $minimo) {
            return false;
        }

        if ($maximo !== null && $numero > $maximo) {
            return false;
        }

        return true;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $texto = self::textoNumerico($value, $this->aceptaPorcentaje);

        if ($texto === null || ! preg_match(self::patron($this->decimales), $texto)) {
            $limite = $this->decimales > 0
                ? 'con hasta '.$this->decimales.' decimal(es)'
                : 'sin decimales';

            $porcentaje = $this->aceptaPorcentaje ? ' (se admite el símbolo % al final)' : '';

            $fail('El campo :attribute debe ser un número escrito en formato normal, '.$limite.', sin letras, signos ni notación científica'.$porcentaje.'.');

            return;
        }

        if (! is_finite((float) $texto)) {
            $fail('El campo :attribute no contiene un número válido.');
        }
    }

    private static function textoNumerico(mixed $valor, bool $aceptaPorcentaje): ?string
    {
        if (is_string($valor)) {
            $texto = trim($valor);

            if ($aceptaPorcentaje && str_ends_with($texto, '%')) {
                $texto = trim(substr($texto, 0, -1));
            }

            return $texto === '' ? null : $texto;
        }

        if (is_int($valor) || is_float($valor)) {
            if (! is_finite((float) $valor)) {
                return null;
            }

            return (string) $valor;
        }

        return null;
    }

    private static function patron(int $decimales): string
    {
        return $decimales > 0
            ? '/^\d+(?:\.\d{1,'.$decimales.'})?$/'
            : '/^\d+$/';
    }
}
