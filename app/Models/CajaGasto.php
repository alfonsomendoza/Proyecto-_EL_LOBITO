<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gastos de caja (salidas de dinero del turno).
 *
 * Usa la tabla legacy `caja_gastos`, que NO tiene created_at/updated_at:
 * por eso desactivamos los timestamps de Eloquent y definimos la fecha
 * de negocio manualmente en la columna `fecha`.
 *
 * Estados:
 *  - ACTIVO  : egreso vigente, afecta totales y ticket.
 *  - ANULADO : egreso anulado lógicamente; NUNCA se borra la fila.
 */
class CajaGasto extends Model
{
    protected $table = 'caja_gastos';

    public $timestamps = false;

    protected $fillable = [
        'apertura_id',
        'concepto',
        'monto',
        'fecha',
        'estado',
        'usuario_id',
    ];

    protected $casts = [
        'apertura_id' => 'integer',
        'usuario_id' => 'integer',
        'monto' => 'decimal:2',
        'fecha' => 'datetime',
    ];

    /**
     * Apertura de caja a la que pertenece el gasto.
     */
    public function apertura(): BelongsTo
    {
        return $this->belongsTo(CajaApertura::class, 'apertura_id');
    }

    /**
     * Usuario que registró el gasto (puede ser null en datos heredados).
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
