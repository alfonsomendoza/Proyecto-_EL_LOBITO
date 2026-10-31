<?php

namespace App\Http\Controllers;

use App\Rules\NumeroEstricto;
use App\Services\InventarioService;
use App\Services\ConfiguracionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Módulo de Inventario (interfaz).
 *
 * Permisos (validados SIEMPRE en el backend, no solo en Blade/JS):
 *  - ADMIN y GERENTE: administrar (activar/desactivar control, carga inicial,
 *                     stock mínimo/unidad y ajustes) y consultar el historial.
 *  - CAJERO         : SOLO consulta (panel e historial). No puede modificar: 403.
 *  - MESERO         : sin acceso al módulo (403 en toda ruta).
 *
 * El inventario es INFORMATIVO: ninguna acción de este módulo bloquea la venta
 * ni altera las reglas del POS. Las migraciones de la Etapa 1 pueden seguir
 * pendientes; el módulo lo detecta y muestra un aviso en lugar de fallar (no
 * ejecuta migraciones por su cuenta).
 */
class InventarioController extends Controller
{
    public function __construct(
        private InventarioService $service,
        private ConfiguracionService $configuracion
    ) {}

    /* ------------------------------------------------------------------
     | Panel principal: productos con su existencia y estado
     | ------------------------------------------------------------------ */

    public function index(Request $request)
    {
        if (! $this->puedeConsultar()) {
            abort(403, 'No tienes permiso para acceder al módulo de inventario.');
        }

        // Interruptor de módulo (solo ADMIN lo cambia): si está deshabilitado,
        // se bloquea el acceso funcional conservando todos los datos.
        if (! $this->configuracion->inventarioHabilitado()) {
            abort(403, 'El módulo de inventario está deshabilitado.');
        }

        // Si el esquema aún no está migrado, se avisa en la vista sin consultar
        // tablas inexistentes (no se ejecutan migraciones desde el controlador).
        if (! $this->service->esquemaDisponible()) {
            return view('inventario.index', [
                'productos' => collect(),
                'totalProductos' => 0,
                'categorias' => collect(),
                'categoriaId' => null,
                'haySinCategoria' => false,
                'q' => '',
                'estado' => 'todos',
                'puedeAdministrar' => $this->puedeAdministrar(),
                'pendiente' => true,
            ]);
        }

        $q = trim((string) $request->query('q', ''));
        $estado = $this->filtroEstado($request);

        // Categorías reales de la base (para el desplegable del filtro). Se
        // muestran todas, igual que en el módulo de catálogo.
        $categorias = DB::table('categorias')->orderBy('nombre')->get(['id', 'nombre']);
        $categoriaId = $this->filtroCategoria($request, $categorias);
        $haySinCategoria = DB::table('productos')->whereNull('categoria_id')->where('activo', 1)->exists();

        $filas = DB::table('productos as p')
            ->leftJoin('categorias as c', 'c.id', '=', 'p.categoria_id')
            ->leftJoin('inventario_producto as i', 'i.producto_id', '=', 'p.id')
            ->select(
                'p.id',
                'p.nombre',
                'p.activo',
                'p.controla_stock',
                'c.nombre as categoria',
                'i.stock_actual',
                'i.stock_minimo',
                'i.unidad'
            )
            // Disponibilidad operativa: un producto eliminado (activo=0) no se
            // ofrece aquí para nuevas operaciones. El historial de movimientos
            // (historial()) u otras consultas históricas no se ven afectados.
            ->where('p.activo', 1)
            ->when($q !== '', function ($query) use ($q) {
                $query->where('p.nombre', 'like', '%'.$q.'%');
            })
            // Filtro por categoría. $categoriaId: null = todas, 0 = "sin
            // categoría" (categoria_id NULL), >0 = esa categoría concreta.
            ->when($categoriaId !== null, function ($query) use ($categoriaId) {
                $categoriaId === 0
                    ? $query->whereNull('p.categoria_id')
                    : $query->where('p.categoria_id', $categoriaId);
            })
            ->orderBy('p.nombre')
            ->get();

        // El estado "bajo mínimo" y los filtros se resuelven en PHP para no
        // depender de la portabilidad de NULL/comparaciones entre MySQL y SQLite.
        $productos = $filas
            ->map(function ($fila) {
                $controla = (bool) $fila->controla_stock;
                $stock = $fila->stock_actual !== null ? (float) $fila->stock_actual : null;
                $minimo = $fila->stock_minimo !== null ? (float) $fila->stock_minimo : 0.0;

                return (object) [
                    'id' => (int) $fila->id,
                    'nombre' => $fila->nombre,
                    'categoria' => $fila->categoria,
                    'controla_stock' => $controla,
                    'stock_actual' => $stock,
                    'stock_minimo' => $minimo,
                    'unidad' => $fila->unidad ?? 'unidad',
                    // Un producto solo puede estar "bajo mínimo" si controla stock.
                    // stock < minimo (estricto): con stock_minimo = 0 y stock_actual = 0
                    // no se marca falso positivo; con existencias negativas sí se alerta.
                    'bajo' => $controla && $stock !== null && $stock < $minimo,
                ];
            })
            ->filter(fn ($p) => $this->pasaFiltroEstado($p, $estado))
            ->values();

        // Paginación: el listado puede superar 200 productos, así que se pagina
        // en servidor. Los filtros (q, estado, categoria_id) viajan en la URL y
        // se conservan en la paginación gracias a appends().
        $porPagina = 25;
        $pagina = max(1, (int) $request->query('page', 1));

        $total = $productos->count();
        $productosPaginados = new \Illuminate\Pagination\LengthAwarePaginator(
            $productos->forPage($pagina, $porPagina)->values(),
            $total,
            $porPagina,
            $pagina,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        return view('inventario.index', [
            'productos' => $productosPaginados,
            'totalProductos' => $total,
            'categorias' => $categorias,
            'categoriaId' => $categoriaId,
            'haySinCategoria' => $haySinCategoria,
            'q' => $q,
            'estado' => $estado,
            'puedeAdministrar' => $this->puedeAdministrar(),
            'pendiente' => false,
        ]);
    }

    /* ------------------------------------------------------------------
     | Historial de movimientos (consulta)
     | ------------------------------------------------------------------ */

    public function historial(Request $request)
    {
        if (! $this->puedeConsultar()) {
            abort(403, 'No tienes permiso para acceder al módulo de inventario.');
        }

        if (! $this->configuracion->inventarioHabilitado()) {
            abort(403, 'El módulo de inventario está deshabilitado.');
        }

        if (! $this->service->esquemaDisponible()) {
            return view('inventario.historial', [
                'movimientos' => collect(),
                'productosFiltro' => collect(),
                'tipos' => $this->tiposValidos(),
                'productoId' => null,
                'tipo' => null,
                'pendiente' => true,
            ]);
        }

        $productoId = $request->query('producto_id');
        $productoId = is_numeric($productoId) ? (int) $productoId : null;

        $tipo = (string) $request->query('tipo', '');
        $tipo = in_array($tipo, $this->tiposValidos(), true) ? $tipo : null;

        // Se limita el listado para no cargar historiales enormes de golpe.
        $movimientos = DB::table('movimientos_inventario as m')
            ->leftJoin('productos as p', 'p.id', '=', 'm.producto_id')
            ->leftJoin('usuarios as u', 'u.id', '=', 'm.usuario_id')
            ->select(
                'm.id',
                'm.tipo',
                'm.cantidad',
                'm.observacion',
                'm.creado_en',
                'p.nombre as producto',
                'u.nombre as usuario'
            )
            ->when($productoId, fn ($query) => $query->where('m.producto_id', $productoId))
            ->when($tipo, fn ($query) => $query->where('m.tipo', $tipo))
            ->orderByDesc('m.id')
            ->limit(300)
            ->get();

        $productosFiltro = DB::table('productos')
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        return view('inventario.historial', [
            'movimientos' => $movimientos,
            'productosFiltro' => $productosFiltro,
            'tipos' => $this->tiposValidos(),
            'productoId' => $productoId,
            'tipo' => $tipo,
            'pendiente' => false,
        ]);
    }

    /* ------------------------------------------------------------------
     | Activar / desactivar el control de stock (solo ADMIN y GERENTE)
     | ------------------------------------------------------------------ */

    public function cambiarControl(Request $request, int $producto)
    {
        if (! $this->puedeAdministrar()) {
            return $this->noAutorizado('No tienes permiso para modificar el inventario.');
        }

        if (! $this->configuracion->inventarioHabilitado()) {
            return $this->noHabilitado();
        }

        if (! $this->service->esquemaDisponible()) {
            return $this->noDisponible();
        }

        $datos = $request->validate([
            'activo' => ['required', 'boolean'],
        ]);

        if (! $this->productoExiste($producto)) {
            return $this->noEncontrado();
        }

        // Activar/desactivar NO borra existencias ni historial: solo cambia la
        // bandera del producto. Reactivar conserva todo lo registrado.
        DB::table('productos')
            ->where('id', $producto)
            ->update(['controla_stock' => $request->boolean('activo') ? 1 : 0]);

        return response()->json([
            'exito' => true,
            'controla_stock' => $request->boolean('activo'),
        ]);
    }

    /* ------------------------------------------------------------------
     | Carga inicial de existencias (solo ADMIN y GERENTE)
     | ------------------------------------------------------------------ */

    public function cargaInicial(Request $request, int $producto)
    {
        if (! $this->puedeAdministrar()) {
            return $this->noAutorizado('No tienes permiso para modificar el inventario.');
        }

        if (! $this->configuracion->inventarioHabilitado()) {
            return $this->noHabilitado();
        }

        if (! $this->service->esquemaDisponible()) {
            return $this->noDisponible();
        }

        $datos = $request->validate([
            'cantidad' => ['required', 'numeric', new NumeroEstricto(3), 'gt:0', 'max:999999999.999'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ], [
            'cantidad.gt' => 'La cantidad debe ser mayor que cero.',
            'cantidad.max' => 'La cantidad supera el máximo permitido (999,999,999.999).',
        ]);

        if (! $this->productoExiste($producto)) {
            return $this->noEncontrado();
        }

        if (! $this->productoControlaStock($producto)) {
            return $this->noControlaStock();
        }

        try {
            DB::transaction(function () use ($producto, $datos) {
                $this->service->registrarCargaInicial(
                    $producto,
                    $datos['cantidad'],
                    (int) Auth::id(),
                    $datos['observacion'] ?? null
                );
            });
        } catch (\DomainException $e) {
            // Segunda carga inicial: el servicio ya la detectó (sin UNIQUE de DB).
            return response()->json(['exito' => false, 'error' => $e->getMessage()], 409);
        }

        return response()->json(['exito' => true]);
    }

    /* ------------------------------------------------------------------
     | Configurar stock mínimo y unidad (solo ADMIN y GERENTE)
     | ------------------------------------------------------------------ */

    public function configurarMinimo(Request $request, int $producto)
    {
        if (! $this->puedeAdministrar()) {
            return $this->noAutorizado('No tienes permiso para modificar el inventario.');
        }

        if (! $this->configuracion->inventarioHabilitado()) {
            return $this->noHabilitado();
        }

        if (! $this->service->esquemaDisponible()) {
            return $this->noDisponible();
        }

        $datos = $request->validate([
            'stock_minimo' => ['required', 'numeric', new NumeroEstricto(3), 'min:0', 'max:999999999.999'],
            'unidad' => ['nullable', 'string', 'max:20'],
        ], [
            'stock_minimo.min' => 'El stock mínimo no puede ser negativo.',
            'stock_minimo.max' => 'El stock mínimo supera el máximo permitido (999,999,999.999).',
        ]);

        if (! $this->productoExiste($producto)) {
            return $this->noEncontrado();
        }

        if (! $this->productoControlaStock($producto)) {
            return $this->noControlaStock();
        }

        DB::transaction(function () use ($producto, $datos) {
            // Garantiza la fila sin tocar el stock ya registrado.
            $this->service->asegurarFila($producto);

            $cambios = ['stock_minimo' => (float) $datos['stock_minimo']];
            if (isset($datos['unidad']) && $datos['unidad'] !== '') {
                $cambios['unidad'] = $datos['unidad'];
            }
            $cambios['updated_at'] = now();

            // No se actualiza stock_actual: el mínimo es solo un umbral de aviso.
            DB::table('inventario_producto')
                ->where('producto_id', $producto)
                ->update($cambios);
        });

        return response()->json(['exito' => true]);
    }

    /* ------------------------------------------------------------------
     | Ajuste manual de existencias (solo ADMIN y GERENTE)
     | ------------------------------------------------------------------ */

    public function ajuste(Request $request, int $producto)
    {
        if (! $this->puedeAdministrar()) {
            return $this->noAutorizado('No tienes permiso para modificar el inventario.');
        }

        if (! $this->configuracion->inventarioHabilitado()) {
            return $this->noHabilitado();
        }

        if (! $this->service->esquemaDisponible()) {
            return $this->noDisponible();
        }

        $datos = $request->validate([
            'tipo' => ['required', 'in:AJUSTE_ENTRADA,AJUSTE_SALIDA'],
            'cantidad' => ['required', 'numeric', new NumeroEstricto(3), 'gt:0', 'max:999999999.999'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ], [
            'cantidad.gt' => 'La cantidad debe ser mayor que cero.',
            'cantidad.max' => 'La cantidad supera el máximo permitido (999,999,999.999).',
        ]);

        if (! $this->productoExiste($producto)) {
            return $this->noEncontrado();
        }

        if (! $this->productoControlaStock($producto)) {
            return $this->noControlaStock();
        }

        DB::transaction(function () use ($producto, $datos) {
            $this->service->registrarAjuste(
                $producto,
                $datos['tipo'],
                $datos['cantidad'],
                (int) Auth::id(),
                $datos['observacion'] ?? null
            );
        });

        return response()->json(['exito' => true]);
    }

    /* ------------------------------------------------------------------
     | Filtros y utilidades
     | ------------------------------------------------------------------ */

    private function filtroEstado(Request $request): string
    {
        $estado = (string) $request->query('estado', 'todos');

        return in_array($estado, ['todos', 'controlados', 'sin_control', 'bajo'], true)
            ? $estado
            : 'todos';
    }

    /**
     * Filtro de categoría recibido por URL.
     *
     *  - null => todas las categorías.
     *  - 0    => "Sin categoría" (producto con categoria_id NULL).
     *  - >0   => esa categoría concreta (solo si existe en la base).
     *
     * Un valor desconocido o inexistente se ignora (se muestran todas) para
     * no filtrar por una categoría que no existe.
     */
    private function filtroCategoria(Request $request, $categorias): ?int
    {
        $valor = $request->query('categoria_id', '');

        if ($valor === '' || $valor === null) {
            return null;
        }

        if (! is_numeric($valor)) {
            return null;
        }

        $valor = (int) $valor;

        if ($valor === 0) {
            return 0;
        }

        // Solo se acepta una categoría que realmente exista.
        $existe = $categorias->contains(fn ($c) => (int) $c->id === $valor);

        return $existe ? $valor : null;
    }

    private function pasaFiltroEstado(object $producto, string $estado): bool
    {
        return match ($estado) {
            'controlados' => $producto->controla_stock,
            'sin_control' => ! $producto->controla_stock,
            'bajo' => $producto->bajo,
            default => true,
        };
    }

    /**
     * @return array<int,string>
     */
    private function tiposValidos(): array
    {
        return [
            InventarioService::TIPO_VENTA,
            InventarioService::TIPO_ANULACION,
            InventarioService::TIPO_CANCELACION,
            InventarioService::TIPO_CARGA_INICIAL,
            InventarioService::TIPO_AJUSTE_ENTRADA,
            InventarioService::TIPO_AJUSTE_SALIDA,
        ];
    }

    private function productoExiste(int $producto): bool
    {
        return DB::table('productos')->where('id', $producto)->exists();
    }

    private function productoControlaStock(int $producto): bool
    {
        return (bool) DB::table('productos')->where('id', $producto)->value('controla_stock');
    }

    private function noControlaStock()
    {
        return response()->json([
            'exito' => false,
            'error' => 'El producto no tiene activado el control de inventario. Active el control antes de registrar cargas o ajustes.',
        ], 422);
    }

    /* ------------------------------------------------------------------
     | Autorización
     | ------------------------------------------------------------------ */

    /**
     * Consulta (panel e historial): ADMIN, GERENTE y CAJERO.
     */
    private function puedeConsultar(): bool
    {
        return in_array($this->rolActual(), ['ADMIN', 'GERENTE', 'CAJERO'], true);
    }

    /**
     * Administrar (control, carga inicial, mínimo y ajustes): ADMIN y GERENTE.
     */
    private function puedeAdministrar(): bool
    {
        return in_array($this->rolActual(), ['ADMIN', 'GERENTE'], true);
    }

    private function rolActual(): string
    {
        $usuario = Auth::user();

        if (! $usuario) {
            return '';
        }

        return strtoupper((string) ($usuario->rol->nombre ?? ''));
    }

    private function noAutorizado(string $mensaje)
    {
        return response()->json(['exito' => false, 'error' => $mensaje], 403);
    }

    private function noDisponible()
    {
        return response()->json([
            'exito' => false,
            'error' => 'El módulo de inventario no está disponible: faltan aplicar las migraciones.',
        ], 503);
    }

    /**
     * El módulo de inventario está deshabilitado por configuración (solo ADMIN
     * puede volver a habilitarlo).
     */
    private function noHabilitado()
    {
        return response()->json([
            'exito' => false,
            'error' => 'El módulo de inventario está deshabilitado.',
        ], 403);
    }

    private function noEncontrado()
    {
        return response()->json([
            'exito' => false,
            'error' => 'El producto indicado no existe.',
        ], 404);
    }
}
