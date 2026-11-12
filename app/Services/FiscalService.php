<?php

namespace App\Services;

/**
 * Servicio centralizado de cálculo fiscal.
 *
 * Las retenciones solo se aplican si la operación está sujeta a ellas.
 * La clasificación de clientes/operaciones sujetos a retención aún no existe,
 * por lo que por defecto no se aplican retenciones.
 */
class FiscalService
{
    public const TASA_IVA = 0.15;
    public const TASA_RETENCION_DGI = 0.02;
    public const TASA_RETENCION_ALCALDIA = 0.01;

    public function __construct(private ConfiguracionService $configuracion) {}

    public function configuracion(): array
    {
        return [
            'iva' => $this->configuracion->ivaHabilitado(),
            'retencion_dgi' => $this->configuracion->retencionDgiHabilitada(),
            'retencion_alcaldia' => $this->configuracion->retencionAlcaldiaHabilitada(),
            'descuento' => $this->configuracion->descuentoFiscalHabilitado(),
            'propina' => $this->configuracion->propinaFiscalHabilitada(),
        ];
    }

    public function tasas(): array
    {
        return [
            'iva' => self::TASA_IVA,
            'retencion_dgi' => self::TASA_RETENCION_DGI,
            'retencion_alcaldia' => self::TASA_RETENCION_ALCALDIA,
        ];
    }

    /**
     * Calcula el desglose fiscal.
     *
     * @param float $consumo Importe del consumo.
     * @param float $descuento Importe del descuento.
     * @param float $propina Importe de la propina.
     * @param bool $sujetoRetencion Si la operación está sujeta a retenciones.
     */
    public function calcular(float $consumo, float $descuento, float $propina, bool $sujetoRetencion = false, ?bool $ivaHabilitado = null, ?bool $retDgiHabilitado = null, ?bool $retAlcHabilitado = null): array
    {
        $config = $this->configuracion();

        if ($ivaHabilitado === null) { $ivaHabilitado = $config['iva']; }
        if ($retDgiHabilitado === null) { $retDgiHabilitado = $config['retencion_dgi']; }
        if ($retAlcHabilitado === null) { $retAlcHabilitado = $config['retencion_alcaldia']; }

        $descuentoAplicado = $config['descuento'] ? round($descuento, 2) : 0.0;
        $base = round($consumo - $descuentoAplicado, 2);

        $iva = $ivaHabilitado ? round($consumo * self::TASA_IVA, 2) : 0.0;

        $propinaAplicada = $config['propina'] ? round($propina, 2) : 0.0;

        $totalFactura = round($base + $iva + $propinaAplicada, 2);

        $retencionDgi = ($retDgiHabilitado && $sujetoRetencion)
            ? round($consumo * self::TASA_RETENCION_DGI, 2)
            : 0.0;

        $retencionAlcaldia = ($retAlcHabilitado && $sujetoRetencion)
            ? round($consumo * self::TASA_RETENCION_ALCALDIA, 2)
            : 0.0;

        $totalRetenciones = round($retencionDgi + $retencionAlcaldia, 2);

        $neto = round($totalFactura - $totalRetenciones, 2);

        return [
            'consumo' => round($consumo, 2),
            'descuento' => $descuentoAplicado,
            'base' => $base,
            'iva' => $iva,
            'propina' => $propinaAplicada,
            'retencion_dgi' => $retencionDgi,
            'retencion_alcaldia' => $retencionAlcaldia,
            'total_retenciones' => $totalRetenciones,
            'total_factura' => $totalFactura,
            'neto' => $neto,
        ];
    }
}
