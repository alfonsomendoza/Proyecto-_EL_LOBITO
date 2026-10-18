<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cierre de caja (arqueo) de un turno.
 *
 * Usa la tabla legacy `caja_cierre`, que NO tiene created_at/updated_at:
 * la fecha de negocio vive en `fecha` y la de sistema en `creado_en`
 * (DEFAULT CURRENT_TIMESTAMP en MySQL). Por eso desactivamos los
 * timestamps automáticos de Eloquent.
 *
 * Estados:
 *  - CUADRA   : el efectivo contado alcanza (o supera) el esperado
 *               (diferencia >= 0).
 *  - DESCUADRE: falta efectivo en la gaveta (diferencia < 0).
 *  - ANULADO  : cierre anulado lógicamente. Al anular el último cierre
 *               vigente, la apertura vuelve a quedar ABIERTA.
 */
class CajaCierre extends Model
{
    protected $table = 'caja_cierre';

    public $timestamps = false;

    protected $fillable = [
        'apertura_id',
        'fecha',
        'cajero',
        'turno',
        'caja_id',
        'monto_apertura',
        'ventas_efectivo',
        'otros_ingresos',
        'egresos',
        'retiros',
        'total_esperado',
        'tasa_cambio',
        'monto_extranjero',
        'total_contado',
        'diferencia',
        'estado',
        'conteo_json',
        'observaciones',
        'usuario_id',
    ];

    protected $casts = [
        'apertura_id' => 'integer',
        'caja_id' => 'integer',
        'monto_apertura' => 'float',
        'ventas_efectivo' => 'float',
        'otros_ingresos' => 'float',
        'egresos' => 'float',
        'retiros' => 'float',
        'total_esperado' => 'float',
        'tasa_cambio' => 'float',
        'monto_extranjero' => 'float',
        'total_contado' => 'float',
        'diferencia' => 'float',
        'conteo_json' => 'array',
        'fecha' => 'datetime',
        'usuario_id' => 'integer',
    ];

    /**
     * Turno (apertura) al que corresponde este cierre.
     */
    public function apertura(): BelongsTo
    {
        return $this->belongsTo(CajaApertura::class, 'apertura_id');
    }

    /**
     * Usuario que registró el cierre (puede ser null en datos heredados).
     */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
