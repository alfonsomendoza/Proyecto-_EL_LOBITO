<?php

namespace App\Services;

use App\Models\CajaApertura;
use App\Models\CajaGasto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de negocio del módulo de Gastos.
 *
 * Reglas:
 *  - Todo gasto se vincula a la apertura de caja REALMENTE activa
 *    (estado ABIERTA) y al usuario autenticado. Nunca a valores enviados
 *    por el navegador.
 *  - El registro de varias filas es transaccional: si una falla, no queda
 *    ningún registro parcial.
 *  - La anulación es lógica (estado = ANULADO). Nunca se borra la fila.
 */
class GastoService
{
    /**
     * Devuelve la apertura de caja activa (ABIERTA), o null si no hay ninguna.
     * Se consulta en el servidor; nunca se confía en un id del navegador.
     */
    public function aperturaActiva(): ?CajaApertura
    {
        return CajaApertura::query()
            ->where('estado', 'ABIERTA')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Gastos del turno indicado, incluyendo los anulados para poder mostrarlos
     * marcados en el panel. Orden cronológico ascendente.
     */
    public function gastosDeApertura(int $aperturaId): Collection
    {
        return CajaGasto::query()
            ->with('usuario')
            ->where('apertura_id', $aperturaId)
            ->orderBy('id')
            ->get();
    }

    /**
     * Gastos ACTIVOS del turno. Excluye los anulados en la propia consulta.
     * Es el listado normal del panel: nunca muestra filas ANULADO.
     */
    public function gastosActivosDeApertura(int $aperturaId): Collection
    {
        return CajaGasto::query()
            ->with('usuario')
            ->where('apertura_id', $aperturaId)
            ->where('estado', 'ACTIVO')
            ->orderBy('id')
            ->get();
    }

    /**
     * Suma de los egresos ACTIVOS de la colección. Los anulados se excluyen.
     */
    public function totalActivos(Collection $gastos): float
    {
        return (float) $gastos
            ->where('estado', 'ACTIVO')
            ->sum('monto');
    }

    /**
     * Registra varias filas de gasto en una sola transacción.
     *
     * @param  array<int, string> $conceptos
     * @param  array<int, mixed>  $montos
     * @return array{exito: bool, mensaje?: string, error?: string}
     */
    public function registrarGastos(int $aperturaId, int $usuarioId, array $conceptos, array $montos): array
    {
        if (count($conceptos) === 0 || count($conceptos) !== count($montos)) {
            return [
                'exito' => false,
                'error' => 'Los datos de los gastos están incompletos o no coinciden.',
            ];
        }

        try {
            DB::transaction(function () use ($aperturaId, $usuarioId, $conceptos, $montos) {
                foreach (array_values($conceptos) as $i => $concepto) {
                    $descripcion = trim((string) $concepto);
                    $monto = (float) ($montos[$i] ?? 0);

                    if ($descripcion === '' || $monto <= 0) {
                        // Cualquier fila inválida aborta la transacción completa.
                        throw new \InvalidArgumentException('Concepto vacío o monto inválido.');
                    }

                    CajaGasto::create([
                        'apertura_id' => $aperturaId,
                        'usuario_id' => $usuarioId,
                        'concepto' => $descripcion,
                        'monto' => $monto,
                        'estado' => 'ACTIVO',
                        'fecha' => now(),
                    ]);
                }
            });
        } catch (\Throwable $e) {
            return [
                'exito' => false,
                'error' => 'No fue posible registrar los gastos. No se guardó ningún registro.',
            ];
        }

        return [
            'exito' => true,
            'mensaje' => 'Gastos registrados correctamente.',
        ];
    }

    /**
     * Anulación lógica de un gasto. No elimina físicamente la fila.
     *
     * @return array{exito: bool, codigo: int, mensaje?: string, error?: string}
     */
    public function anular(int $gastoId): array
    {
        $gasto = CajaGasto::find($gastoId);

        if (!$gasto) {
            return ['exito' => false, 'codigo' => 404, 'error' => 'El gasto no existe.'];
        }

        if ($gasto->estado === 'ANULADO') {
            return ['exito' => false, 'codigo' => 409, 'error' => 'El gasto ya estaba anulado.'];
        }

        $gasto->estado = 'ANULADO';
        $gasto->save();

        return ['exito' => true, 'codigo' => 200, 'mensaje' => 'Gasto anulado correctamente.'];
    }
}
