<?php

namespace App\Http\Controllers;

use App\Services\ConfiguracionService;
use App\Services\FiscalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ConfiguracionFiscalController extends Controller
{
    public function __construct(
        private ConfiguracionService $configuracion,
        private FiscalService $fiscal
    ) {}

    public function index()
    {
        $rol = strtoupper((string) (Auth::user()?->rol?->nombre ?? ''));

        if (!in_array($rol, ['ADMIN', 'GERENTE'], true)) {
            abort(403, 'Solo ADMIN o GERENTE pueden ver la configuración fiscal y comercial.');
        }

        $estados = $this->configuracion;

        return view('configuracion.fiscal', [
            'tablaDisponible' => $estados->tablaDisponible(),
            'iva' => $estados->ivaHabilitado(),
            'retencionDgi' => $estados->retencionDgiHabilitada(),
            'retencionAlcaldia' => $estados->retencionAlcaldiaHabilitada(),
            'descuento' => $estados->descuentoFiscalHabilitado(),
            'propina' => $estados->propinaFiscalHabilitada(),
            'tasas' => $this->fiscal->tasas(),
        ]);
    }

    public function actualizar(Request $request)
    {
        $rol = strtoupper((string) (Auth::user()?->rol?->nombre ?? ''));

        if (!in_array($rol, ['ADMIN', 'GERENTE'], true)) {
            return response()->json([
                'exito' => false,
                'error' => 'No tienes permiso para modificar la configuración fiscal.',
            ], 403);
        }

        if (! $this->configuracion->tablaDisponible()) {
            return response()->json([
                'exito' => false,
                'error' => 'Falta aplicar la migración de configuración.',
            ], 503);
        }

        $datos = $request->validate([
            'concepto' => ['required', 'string', 'in:iva,retencion_dgi,retencion_alcaldia,descuento,propina'],
            'habilitado' => ['required', 'boolean'],
        ]);

        $mapa = [
            'iva' => ConfiguracionService::CLAVE_IVA,
            'retencion_dgi' => ConfiguracionService::CLAVE_RETENCION_DGI,
            'retencion_alcaldia' => ConfiguracionService::CLAVE_RETENCION_ALCALDIA,
            'descuento' => ConfiguracionService::CLAVE_DESCUENTO_FISCAL,
            'propina' => ConfiguracionService::CLAVE_PROPINA_FISCAL,
        ];

        $clave = $mapa[$datos['concepto']];
        $habilitado = $request->boolean('habilitado');

        if ($habilitado) {
            $this->configuracion->habilitar($clave);
        } else {
            $this->configuracion->deshabilitar($clave);
        }

        return response()->json([
            'exito' => true,
            'concepto' => $datos['concepto'],
            'habilitado' => $habilitado,
        ]);
    }
}
