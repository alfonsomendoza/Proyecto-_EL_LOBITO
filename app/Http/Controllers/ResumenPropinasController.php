<?php

namespace App\Http\Controllers;

use App\Services\ResumenPropinasService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo "Administración" → Resumen de Propinas.
 *
 * Permite consultar, por rango de fechas, cuánto se cobró en propinas y
 * contrastarlo contra los vouchers de las facturas. Es de SOLO LECTURA: NUNCA
 * modifica facturas, cuentas ni propinas históricas (registrar el pago de
 * propinas a los meseros NO está definido por el negocio y no se implementa
 * aquí).
 *
 * Importante: la propina se cobra en el mismo cobro de la factura y el
 * esquema NO registra su pago posterior al personal, así que el módulo no
 * presenta un segundo total de «propinas pagadas». Quién abrió la mesa
 * (`ventas.usuario_id`), quién atendió (`comandas.usuario_id`) y quién cobró
 * (`cuentas.usuario_cobro`) son datos distintos y se muestran por separado:
 * ver App\Services\ResumenPropinasService.
 *
 * FILTROS (combinables): `desde`, `hasta`, `mesero` (usuarios con rol MESERO
 * que enviaron comandas, o `pendiente`), `abrio` (usuario que abrió la mesa),
 * `mesa`, `turno` (caja: `cuentas.apertura_id`) y `cobro` (usuario que cobró:
 * `cuentas.usuario_cobro`). Solo se aceptan valores válidos; lo que no lo sea
 * se ignora y NO se convierte en un filtro silencioso.
 *
 * UN SOLO CÁLCULO: la pantalla, el CSV y la vista imprimible usan
 * `calcular()`, de modo que las tres salidas muestran exactamente los mismos
 * importes y los mismos filtros. La paginación afecta únicamente al detalle:
 * los indicadores y los totales se calculan sobre el conjunto completo.
 *
 * Permisos (validados SIEMPRE en backend, no solo en Blade/JS):
 *  - ADMIN y GERENTE: consultar, exportar, imprimir y atribuir propinas.
 *  - CAJERO: solo la pantalla mínima «Pendientes de atribución»
 *    (`reportes.propinas.pendientes`) y el POST de atribución
 *    (`cuentas.propina.atribuir`); NO ve totales ni reporte financiero.
 *  - MESERO y COCINERO: sin acceso (403).
 */
class ResumenPropinasController extends Controller
{
    /** Filas de detalle por página (solo presentación: no altera totales). */
    private const POR_PAGINA = 50;

    public function __construct(private ResumenPropinasService $service) {}

    /* ------------------------------------------------------------------
     | Pantalla
     | ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        $this->exigirAcceso();

        $calculado = $this->calcular($request);
        $resumen = $calculado['resumen'];

        return view('resumen_propinas.index', array_merge([
            'desde' => $calculado['rango']['desde'],
            'hasta' => $calculado['rango']['hasta'],
            'filtros' => $calculado['filtros'],
            'resumen' => $resumen,
            'paginaDetalle' => $this->paginarDetalle($resumen, $request),
            'chips' => $this->chips($request, $calculado['rango'], $calculado['filtros'], $resumen['opciones'] ?? []),
        ], $this->construirPanelPendientes($calculado['rango']['desde'], $calculado['rango']['hasta'], $calculado['filtros'])));
    }

    /* ------------------------------------------------------------------
     | Pendientes de atribución (vista mínima)
     | ------------------------------------------------------------------ */

    /**
     * Apartado «Pendientes de atribución» en versión autónoma, pensado
     * también para CAJERO (que NO ve el reporte financiero completo).
     * Muestra las propinas cobradas sin mesero inequívoco y permite atribuir
     * cada una al MESERO real, con motivo obligatorio y auditoría.
     */
    public function pendientes(Request $request)
    {
        $this->exigirAccesoPanelPendientes();

        return view('resumen_propinas.pendientes', $this->construirPanelPendientes());
    }

    /* ------------------------------------------------------------------
     | Exportación CSV (UTF-8 con BOM, sin dependencias)
     | ------------------------------------------------------------------ */

    public function exportarCsv(Request $request)
    {
        $this->exigirAcceso();

        $calculado = $this->calcular($request);
        $resumen = $calculado['resumen'];
        $chips = $this->chips($request, $calculado['rango'], $calculado['filtros'], $resumen['opciones'] ?? []);

        $nombre = 'propinas_'.$calculado['rango']['desde'].'_'.$calculado['rango']['hasta'].'.csv';

        return response()->streamDownload(function () use ($resumen, $chips, $calculado) {
            $salida = fopen('php://output', 'w');

            // BOM UTF-8: Excel abre los acentos correctamente.
            fwrite($salida, "\xEF\xBB\xBF");

            $fila = function (array $valores) use ($salida) {
                // Fila en blanco: separador visual entre secciones.
                if ($valores === []) {
                    fwrite($salida, "\n");

                    return;
                }

                fputcsv($salida, $valores, ';');
            };

            $fila(['Resumen de Propinas', 'Caja y Restaurante El Lobito']);
            $fila(['Período', $calculado['rango']['desde'], $calculado['rango']['hasta']]);
            $fila(['Filtros', $chips === [] ? 'Ninguno' : implode(' · ', array_column($chips, 'texto'))]);
            $fila(['Generado', now()->format('d/m/Y H:i:s')]);
            $fila([]);

            if (! ($resumen['exito'] ?? false)) {
                // Sin cálculo no se exportan cifras: se informa el motivo.
                $fila(['No disponible', $resumen['error'] ?? 'Sin información para el período.']);
                fclose($salida);

                return;
            }

            $total = (float) $resumen['total'];

            $fila(['INDICADORES']);
            $fila(['Propinas cobradas al cliente', $this->numero($total)]);
            $fila(['Atribuido a meseros', $this->numero($resumen['atribuido'])]);
            $fila(['Pendiente de atribución', $this->numero($resumen['pendiente'])]);
            $fila(['Cuentas cobradas con propina', $resumen['cantidad']]);
            $fila(['Meseros participantes', $resumen['meseros_participantes']]);
            $fila(['Cuentas pagadas sin factura vigente (no suman)', $resumen['cuentas_sin_factura']]);
            $fila([]);

            $fila(['DETALLE POR MESERO']);
            $fila(['Fecha', 'Mesero', 'Estado', 'Mesas atendidas', 'Cuentas cobradas', 'Consumo', 'Propinas cobradas', 'Participación %']);

            foreach ($resumen['resumen_meseros'] as $linea) {
                $participacion = $total > 0 ? round((float) $linea['propinas'] * 100 / $total, 2) : 0.0;

                $fila([
                    $linea['fecha'],
                    $linea['mesero'],
                    $linea['pendiente'] ? 'Pendiente de atribución' : 'Atribuida',
                    $linea['mesas_atendidas'],
                    $linea['cuentas_cobradas'],
                    $this->numero($linea['consumo']),
                    $this->numero($linea['propinas']),
                    $this->numero($participacion),
                ]);
            }
            $fila([]);

            $fila(['DETALLE DE CUENTAS']);
            $fila([
                'Fecha de emisión',
                'Factura',
                'Mesa',
                'Cuenta',
                'Mesero que atendió',
                'Abrió la mesa',
                'Cobró',
                'Turno/caja',
                'Consumo',
                'Propina',
                'Atribución',
                'Mesero atribuido',
                'Motivo de pendiente',
            ]);

            foreach ($resumen['detalle'] as $detalle) {
                $fila([
                    $detalle['fecha'] !== null ? (string) $detalle['fecha'] : '',
                    $detalle['numero_factura'] ?? '',
                    $detalle['mesa_nombre'] ?? '',
                    $detalle['cuenta_nombre'].' #'.$detalle['cuenta_id'],
                    $detalle['mesero_atendio'] ?? 'No disponible',
                    $detalle['usuario_abre_mesa'] ?? 'No disponible',
                    $detalle['usuario_cobro'] ?? 'No disponible',
                    $detalle['turno'] ?? '',
                    $this->numero($detalle['monto_consumo'] ?? 0),
                    $this->numero($detalle['propina']),
                    $detalle['atribucion'],
                    $detalle['mesero_atribuido'] ?? '',
                    $detalle['atribucion'] === 'PENDIENTE'
                        ? ($detalle['motivo_pendiente'] === 'varios_meseros'
                            ? 'Varios meseros en la misma venta'
                            : 'Sin comanda de un usuario MESERO')
                        : '',
                ]);
            }

            $fila([]);
            $fila(['TOTAL COBRADO', $this->numero($total)]);
            $fila(['Atribuido + pendiente', $this->numero($resumen['atribuido'] + $resumen['pendiente'])]);

            fclose($salida);
        }, $nombre, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /* ------------------------------------------------------------------
     | Vista imprimible (mismo cálculo, sin paginar el detalle)
     | ------------------------------------------------------------------ */

    public function imprimir(Request $request)
    {
        $this->exigirAcceso();

        $calculado = $this->calcular($request);

        return view('resumen_propinas.imprimir', [
            'desde' => $calculado['rango']['desde'],
            'hasta' => $calculado['rango']['hasta'],
            'filtros' => $calculado['filtros'],
            'resumen' => $calculado['resumen'],
            'chips' => $this->chips($request, $calculado['rango'], $calculado['filtros'], $calculado['resumen']['opciones'] ?? []),
        ]);
    }

    /* ------------------------------------------------------------------
     | Cálculo y presentación compartidos
     | ------------------------------------------------------------------ */

    /**
     * Resuelve rango + filtros y ejecuta UNA sola vez el servicio. Todo lo que
     * se muestra (pantalla, CSV, impresión) sale de este arreglo.
     *
     * @return array{rango: array<string, mixed>, filtros: array<string, mixed>, resumen: array<string, mixed>}
     */
    private function calcular(Request $request): array
    {
        $rango = $this->rangoSolicitado($request);
        $filtros = $this->filtrosSolicitados($request);

        // Si alguna fecha no es válida NO se genera un resumen: se muestra el
        // aviso y los totales en cero (estado controlado).
        $resumen = $rango['valido']
            ? $this->service->resumenDelRango($rango['desde'], $rango['hasta'], $filtros)
            : $this->service->respuestaVacia($rango['desde'], $rango['hasta'], $rango['error'], $filtros);

        return [
            'rango' => $rango,
            'filtros' => $resumen['filtros'],
            'resumen' => $resumen,
        ];
    }

    /**
     * Pagina SOLO el detalle. Los indicadores y totales ya vienen calculados
     * sobre el conjunto completo.
     */
    private function paginarDetalle(array $resumen, Request $request): LengthAwarePaginator
    {
        $detalle = collect($resumen['detalle'] ?? []);
        $total = $detalle->count();
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = min(max(1, $this->enteroPositivo($request->query('pagina')) ?? 1), $paginas);

        return new LengthAwarePaginator(
            $detalle->forPage($pagina, self::POR_PAGINA)->values(),
            $total,
            self::POR_PAGINA,
            $pagina,
            ['path' => route('reportes.propinas'), 'query' => $request->query()]
        );
    }

    /**
     * Filtros activos, con un enlace para quitar cada uno.
     *
     * Las fechas solo se muestran si el usuario las envió: sin parámetros se
     * consulta el día de hoy y no tiene sentido "marcarlo" como filtro.
     *
     * @param  array<string, mixed>  $rango
     * @param  array<string, mixed>  $filtros
     * @param  array<string, mixed>  $opciones
     * @return array<int, array{clave: string, texto: string, quitar: string}>
     */
    private function chips(Request $request, array $rango, array $filtros, array $opciones): array
    {
        $chips = [];
        $consulta = $request->query();

        $agregar = function (string $clave, string $texto) use (&$chips, $consulta): void {
            $restantes = $consulta;
            unset($restantes[$clave]);

            $chips[] = [
                'clave' => $clave,
                'texto' => $texto,
                'quitar' => route('reportes.propinas', $restantes),
            ];
        };

        if (trim((string) $request->query('desde', '')) !== '') {
            $agregar('desde', 'Desde: '.$rango['desde']);
        }

        if (trim((string) $request->query('hasta', '')) !== '') {
            $agregar('hasta', 'Hasta: '.$rango['hasta']);
        }

        if ($filtros['mesero'] === ResumenPropinasService::SIN_ATRIBUIR) {
            $agregar('mesero', 'Mesero: pendiente de atribución');
        } elseif ($filtros['mesero'] !== null) {
            $agregar('mesero', 'Mesero: '.($opciones['meseros'][$filtros['mesero']] ?? 'Usuario no identificado'));
        }

        if ($filtros['abrio'] !== null) {
            $agregar('abrio', 'Abrió: '.($opciones['usuarios'][$filtros['abrio']] ?? 'Usuario no identificado'));
        }

        if ($filtros['mesa'] !== null) {
            $agregar('mesa', 'Mesa: '.($opciones['mesas'][$filtros['mesa']] ?? ('#'.$filtros['mesa'])));
        }

        if ($filtros['apertura'] !== null) {
            $agregar('turno', 'Turno: '.($opciones['turnos'][$filtros['apertura']] ?? ('Turno #'.$filtros['apertura'])));
        }

        if ($filtros['cobro'] !== null) {
            $agregar('cobro', 'Cobró: '.($opciones['cobradores'][$filtros['cobro']] ?? 'Usuario no identificado'));
        }

        return $chips;
    }

    /** Importe con dos decimales, sin separador de miles (para CSV). */
    private function numero($valor): string
    {
        return number_format((float) $valor, 2, '.', '');
    }

    /* ------------------------------------------------------------------
     | Utilidades internas
     | ------------------------------------------------------------------ */

    /**
     * Rango consultado (Desde/Hasta, Y-m-d).
     *
     * Por defecto (sin parámetros) se consulta el DÍA ACTUAL, que es el mismo
     * comportamiento que tenía el módulo de un solo día. Si el usuario envía
     * fechas, se usan tal cual; si alguna es inválida, se devuelve
     * `valido = false` con un mensaje claro y NO se calcula el resumen.
     *
     * @return array{valido: bool, desde: string, hasta: string, error: ?string}
     */
    private function rangoSolicitado(Request $request): array
    {
        $hoy = now()->format('Y-m-d');
        $desde = trim((string) $request->query('desde', ''));
        $hasta = trim((string) $request->query('hasta', ''));

        // Sin filtros: día actual (comportamiento previo).
        if ($desde === '' && $hasta === '') {
            return ['valido' => true, 'desde' => $hoy, 'hasta' => $hoy, 'error' => null];
        }

        // Si solo llega una de las dos, se completa con la otra (o con hoy si
        // tampoco llega) para no romper la consulta.
        if ($desde === '') {
            $desde = $this->esFechaValida($hasta) ? $hasta : $hoy;
        }
        if ($hasta === '') {
            $hasta = $this->esFechaValida($desde) ? $desde : $hoy;
        }

        if (! $this->esFechaValida($desde) || ! $this->esFechaValida($hasta)) {
            return [
                'valido' => false,
                'desde' => $desde,
                'hasta' => $hasta,
                'error' => 'Las fechas indicadas no son válidas.',
            ];
        }

        if ($desde > $hasta) {
            return [
                'valido' => false,
                'desde' => $desde,
                'hasta' => $hasta,
                'error' => 'La fecha "Desde" no puede ser posterior a la fecha "Hasta".',
            ];
        }

        return ['valido' => true, 'desde' => $desde, 'hasta' => $hasta, 'error' => null];
    }

    /**
     * Filtros opcionales de atribución, turno y mesa. Un valor no numérico se
     * descarta (null) en lugar de aplicarse a ciegas.
     *
     * @return array{mesero: int|string|null, abrio: int|null, mesa: int|null, apertura: int|null, cobro: int|null}
     */
    private function filtrosSolicitados(Request $request): array
    {
        $mesero = $request->query('mesero');
        $mesero = ($mesero === ResumenPropinasService::SIN_ATRIBUIR)
            ? ResumenPropinasService::SIN_ATRIBUIR
            : $this->enteroPositivo($mesero);

        return [
            'mesero' => $mesero,
            'abrio' => $this->enteroPositivo($request->query('abrio')),
            'mesa' => $this->enteroPositivo($request->query('mesa')),
            'apertura' => $this->enteroPositivo($request->query('turno')),
            'cobro' => $this->enteroPositivo($request->query('cobro')),
        ];
    }

    private function enteroPositivo(mixed $valor): ?int
    {
        if (! is_scalar($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return (ctype_digit($valor) && (int) $valor > 0) ? (int) $valor : null;
    }

    private function esFechaValida(string $fecha): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return false;
        }

        [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha));

        return checkdate($mes, $dia, $anio);
    }

    /**
     * Autorización de backend compartida por pantalla, CSV e impresión.
     * Nunca se apoya en que el botón esté oculto en la vista.
     */
    private function exigirAcceso(): void
    {
        if (! $this->autorizado()) {
            abort(403, 'No tienes permiso para ver el resumen de propinas.');
        }
    }

    private function autorizado(): bool
    {
        $rol = strtoupper((string) (Auth::user()?->rol?->nombre ?? ''));

        return in_array($rol, ['ADMIN', 'GERENTE'], true);
    }

    /* ------------------------------------------------------------------
     | Pendientes de atribución (datos compartidos por pantalla e reporte)
     | ------------------------------------------------------------------ */

    /**
     * Autorización del panel de pendientes: ADMIN, GERENTE y CAJERO.
     * CAJERO solo accede aquí (sin totales ni reporte financiero).
     */
    private function exigirAccesoPanelPendientes(): void
    {
        $rol = strtoupper((string) (Auth::user()?->rol?->nombre ?? ''));

        if (! in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO'], true)) {
            abort(403, 'No tienes permiso para atribuir propinas.');
        }
    }

    /**
     * Construye los datos del apartado «Pendientes de atribución».
     *
     * Fuentes:
     *  - Pendientes = propinas de cuentas PAGADAS con factura EMITIDA que el
     *    motor de atribución considera PENDIENTE (ni responsable asignado ni
     *    UN ÚNICO mesero en comandas). Dentro del reporte se usa EL MISMO
     *    período y los mismos filtros de la página (un solo cálculo). En la
     *    pantalla autónoma (`reportes.propinas.pendientes`) se listan
     *    TODOS los pendientes, sin importar fecha ni filtros.
     *  - La caja de cada propina es `cuentas.apertura_id`; se marca
     *    `cierre_previo` cuando la apertura YA tiene cierre registrado o no
     *    está ABIERTA. Eso decide si basta CAJERO (antes del cierre) o si se
     *    exige ADMIN/GERENTE (corrección post-cierre).
     *  - Los meseros seleccionables son usuarios activos con rol MESERO.
     *
     * @param  array{mesero?: int|string|null, abrio?: int|null, mesa?: int|null, apertura?: int|null, cobro?: int|null}  $filtros
     */
    private function construirPanelPendientes(?string $desde = null, ?string $hasta = null, array $filtros = []): array
    {
        $this->exigirAccesoPanelPendientes();

        if ($desde !== null && $hasta !== null) {
            $resumen = $this->service->resumenDelRango($desde, $hasta, $filtros);
            $todas = collect($resumen['detalle'] ?? [])
                ->where('atribucion', 'PENDIENTE');
        } else {
            $resumen = $this->service->resumenDelRango('2000-01-01', '2100-12-31', [
                'mesero' => ResumenPropinasService::SIN_ATRIBUIR,
            ]);
            $todas = collect($resumen['detalle'] ?? []);
        }

        $pendientes = $todas
            ->sortByDesc('fecha')
            ->values()
            ->all();

        // Estado de cierre por apertura (solo informativo para la UI; el
        // backend vuelve a validarlo al atribuir).
        $aperturaIds = collect($pendientes)
            ->pluck('apertura_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $cierres = [];
        if (Schema::hasTable('caja_cierre') && $aperturaIds !== []) {
            $cierres = DB::table('caja_cierre')
                ->whereIn('apertura_id', $aperturaIds)
                ->pluck('apertura_id')
                ->all();
        }

        $estadosApertura = [];
        if (Schema::hasTable('caja_apertura') && $aperturaIds !== []) {
            $estadosApertura = DB::table('caja_apertura')
                ->whereIn('id', $aperturaIds)
                ->pluck('estado', 'id')
                ->all();
        }

        $pendientes = array_map(function (array $linea) use ($cierres, $estadosApertura) {
            $aperturaId = $linea['apertura_id'] ?? null;
            $linea['cierre_previo'] = $aperturaId !== null && (
                in_array((int) $aperturaId, array_map('intval', $cierres), true)
                || strtoupper((string) ($estadosApertura[$aperturaId] ?? '')) !== 'ABIERTA'
            );

            return $linea;
        }, $pendientes);

        $meseros = collect();
        if (Schema::hasTable('usuarios') && Schema::hasTable('roles')) {
            $query = DB::table('usuarios')
                ->join('roles', 'roles.id', '=', 'usuarios.rol_id')
                ->where('roles.nombre', 'MESERO');

            if (Schema::hasColumn('usuarios', 'activo')) {
                $query->where('usuarios.activo', 1);
            }

            $meseros = $query
                ->orderBy('usuarios.nombre')
                ->get(['usuarios.id', 'usuarios.nombre']);
        }

        $rolPanel = strtoupper((string) (Auth::user()?->rol?->nombre ?? ''));

        return [
            'pendientes' => $pendientes,
            'meserosAtribucion' => $meseros,
            'rolPanel' => $rolPanel,
            'puedeCorregir' => in_array($rolPanel, ['ADMIN', 'GERENTE'], true),
        ];
    }
}
