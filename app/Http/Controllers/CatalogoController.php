<?php

namespace App\Http\Controllers;

use App\Exceptions\ImportacionProductosException;
use App\Models\Categoria;
use App\Models\Producto;
use App\Rules\NumeroEstricto;
use App\Services\ProductoImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CatalogoController extends Controller
{
    // Método para mostrar la vista con categorías y productos
    public function index()
    {
        // Autorización en servidor: el catálogo muestra precios. COCINERO no
        // debe consultarlo. ADMIN, GERENTE, CAJERO y MESERO conservan el acceso.
        $rol = strtoupper((string) (Auth::user()?->rol?->nombre ?? ''));

        if (! in_array($rol, ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO'], true)) {
            abort(403, 'No tienes permiso para ver el catálogo.');
        }

        // Todas las categorías (las categorías sí pueden quedar inactivas).
        $categorias = Categoria::all();

        // Catálogo normal: SOLO productos activos. Un producto eliminado
        // (activo=0) no debe seguir apareciendo en la lista principal.
        $productos = Producto::with('categoria')
            ->where('activo', 1)
            ->orderBy('nombre')
            ->get();

        // Listado aparte de productos eliminados (activo=0), para consultarlos
        // y poder reactivarlos. No se mezclan con los activos.
        $productosEliminados = Producto::with('categoria')
            ->where('activo', 0)
            ->orderBy('nombre')
            ->get();

        return view('catalogo.index', compact('categorias', 'productos', 'productosEliminados'));
    }

    /* ------------------------------------------------------------------
     | IMPORTACIÓN DE PRODUCTOS DESDE EXCEL O CSV
     | ------------------------------------------------------------------
     | El flujo es en dos pasos, SIEMPRE con autorización de servidor:
     |  1. previsualizarImportacion(): lee y valida el archivo; NO escribe.
     |  2. confirmarImportacion(): vuelve a validar y guarda en transacción.
     |
     | La vista previa queda asociada al usuario autenticado mediante un token
     | aleatorio guardado en la sesión. El navegador no puede enviar filas ni
     | un usuario distintos: solo el token de su propia sesión.
     | ------------------------------------------------------------------ */

    /**
     * Descarga la plantilla oficial .xlsx con las hojas Productos y Categorías.
     */
    public function plantillaImportacion(ProductoImportService $importador)
    {
        if (! $this->autorizado()) {
            abort(403, 'No tienes permiso para importar productos.');
        }

        try {
            $contenido = $importador->plantillaXlsx();
        } catch (ImportacionProductosException $e) {
            abort(500, $e->getMessage());
        }

        return response($contenido, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="plantilla_productos.xlsx"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * Lee el Excel o CSV, valida cada fila y devuelve la vista previa. No guarda nada.
     */
    public function previsualizarImportacion(Request $request, ProductoImportService $importador)
    {
        if (! $this->autorizado()) {
            return $this->noAutorizado();
        }

        $request->validate([
            'archivo' => ['required', 'file', 'max:2048'],
        ]);

        $archivo = $request->file('archivo');
        $extension = strtolower((string) $archivo?->getClientOriginalExtension());

        // Se valida también en servidor, no solo con el atributo accept del
        // navegador. El servicio comprueba además la estructura real del .xlsx.
        if (! in_array($extension, ['xlsx', 'csv', 'txt'], true)) {
            return response()->json([
                'exito' => false,
                'error' => 'El archivo debe tener extensión .xlsx, .csv o .txt.',
            ], 422);
        }

        try {
            $analisis = $importador->analizar($archivo);
        } catch (ImportacionProductosException $e) {
            return response()->json(['exito' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Error al analizar archivo de productos: '.$e->getMessage());

            return response()->json([
                'exito' => false,
                'error' => 'No se pudo analizar el archivo.',
            ], 500);
        }

        // Las filas crudas se guardan en la sesión del servidor para que la
        // confirmación no dependa de datos enviados por el navegador.
        $token = Str::random(48);
        $request->session()->put('importaciones_productos.'.$token, [
            'usuario_id' => (int) Auth::id(),
            'creado_en' => now()->timestamp,
            'filas' => $analisis['filas_crudas'],
        ]);

        unset($analisis['filas_crudas']);

        return response()->json([
            'exito' => true,
            'token' => $token,
        ] + $analisis);
    }

    /**
     * Confirma una vista previa y crea los productos válidos.
     *
     * El token es de un solo uso. Si la validación o la transacción fallan no
     * se guarda nada; las filas inválidas o duplicadas se omiten y se
     * informan, pero el resultado NUNCA se presenta como totalmente exitoso
     * si hubo omisiones.
     */
    public function confirmarImportacion(Request $request, ProductoImportService $importador)
    {
        if (! $this->autorizado()) {
            return $this->noAutorizado();
        }

        $request->validate([
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{40,64}$/'],
        ]);

        $token = (string) $request->input('token');
        $clave = 'importaciones_productos.'.$token;

        // Evita dos confirmaciones simultáneas del mismo token. El candado se
        // libera siempre; el token se elimina antes de escribir.
        $lock = Cache::lock('importacion-productos:'.$token, 15);

        if (! $lock->get()) {
            return response()->json([
                'exito' => false,
                'error' => 'Esta importación ya se está procesando. Espera un momento.',
            ], 409);
        }

        try {
            $datos = $request->session()->get($clave);

            if (! is_array($datos)) {
                return response()->json([
                    'exito' => false,
                    'error' => 'La vista previa expiró o ya fue utilizada. Analiza el archivo de nuevo.',
                ], 422);
            }

            if ((int) ($datos['usuario_id'] ?? 0) !== (int) Auth::id()) {
                $request->session()->forget($clave);

                return response()->json([
                    'exito' => false,
                    'error' => 'Esta vista previa pertenece a otro usuario.',
                ], 403);
            }

            if (now()->timestamp - (int) ($datos['creado_en'] ?? 0) > 1800) {
                $request->session()->forget($clave);

                return response()->json([
                    'exito' => false,
                    'error' => 'La vista previa expiró (30 minutos). Analiza el archivo de nuevo.',
                ], 422);
            }

            $filas = $datos['filas'] ?? [];

            // Un solo uso: aunque el guardado falle, evita reintentos ciegos
            // con una vista previa vieja. El usuario puede volver a analizar.
            $request->session()->forget($clave);

            if (! is_array($filas) || $filas === []) {
                return response()->json([
                    'exito' => false,
                    'error' => 'La vista previa no contiene filas para importar.',
                ], 422);
            }

            $resultado = $importador->confirmar($filas);

            return response()->json(['exito' => true] + $resultado);
        } catch (ImportacionProductosException $e) {
            return response()->json(['exito' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Error al confirmar importación de productos: '.$e->getMessage());

            return response()->json([
                'exito' => false,
                'error' => 'No se pudo guardar la importación. No se creó ningún producto.',
            ], 500);
        } finally {
            $lock->release();
        }
    }

    // Método para manejar Crear, Editar y Eliminar del catálogo
    public function storeOrUpdate(Request $request)
    {
        // Seguridad en backend: solo ADMIN y GERENTE pueden crear, editar o
        // desactivar productos y categorías. CAJERO y MESERO solo consultan.
        // El guard se ejecuta ANTES de validar o escribir cualquier registro.
        if (! $this->autorizado()) {
            return $this->noAutorizado();
        }

        try {
            // --- GESTIÓN DE CATEGORÍAS ---
            if ($request->accion === 'crear_categoria' || $request->accion === 'editar_categoria') {
                // Validación de backend: el nombre es obligatorio.
                $request->validate([
                    'nombre' => 'required|string|max:100',
                ]);

                Categoria::updateOrCreate(
                    ['id' => $request->id],
                    ['nombre' => $request->nombre, 'activa' => 1]
                );

                return response()->json(['exito' => true]);
            }

            // --- ACTIVAR / DESACTIVAR CATEGORÍAS ---
            // Desactivar NO elimina la categoría ni sus productos: solo cambia
            // la columna real `categorias.activa`. Los productos conservan su
            // categoria_id y siguen existiendo (activos) en el catálogo.
            if ($request->accion === 'activar_categoria' || $request->accion === 'desactivar_categoria') {
                $categoria = Categoria::find((int) $request->id);

                if (! $categoria) {
                    return response()->json(['exito' => false, 'error' => 'La categoría no existe.'], 404);
                }

                $activar = $request->accion === 'activar_categoria';

                // Auditoría de la ÚLTIMA operación. El usuario se obtiene de
                // la sesión autenticada (nunca de un id enviado por el
                // navegador). Al cambiar de estado NO se limpian los campos
                // de la operación anterior: se conserva el último registro de
                // desactivación y el último de reactivación.
                $datos = ['activa' => $activar ? 1 : 0];

                if ($activar) {
                    $datos['reactivado_por'] = (int) Auth::id();
                    $datos['reactivado_en'] = now();
                } else {
                    $datos['desactivado_por'] = (int) Auth::id();
                    $datos['desactivado_en'] = now();
                }

                $categoria->update($datos);

                return response()->json([
                    'exito' => true,
                    'mensaje' => $activar ? 'Categoría activada.' : 'Categoría desactivada.',
                ]);
            }

            // --- GESTIÓN DE PRODUCTOS ---
            if ($request->accion === 'crear_producto' || $request->accion === 'editar_producto') {
                // Validación de backend de los campos existentes del producto.
                $request->validate([
                    'categoria_id' => 'required|exists:categorias,id',
                    'nombre' => 'required|string|max:100',
                    'precio' => ['required', 'numeric', new NumeroEstricto(2), 'min:0', 'max:9999999999.99'],
                ], [
                    'precio.max' => 'El precio supera el máximo permitido (C$ 9,999,999,999.99).',
                ]);

                // Datos comunes. NO se incluye 'activo' aquí: editar nunca debe
                // reactivar un producto eliminado de forma silenciosa.
                $datos = [
                    'categoria_id' => $request->categoria_id,
                    'nombre' => $request->nombre,
                    'precio' => $request->precio,
                    // 1 si la casilla está marcada, 0 si está desmarcada.
                    'requiere_cocina' => $request->boolean('requiere_cocina') ? 1 : 0,
                ];

                if ($request->accion === 'editar_producto' && (int) $request->id > 0) {
                    // Se actualiza SOLO el producto indicado (mismo ID, sin duplicar).
                    $producto = Producto::find((int) $request->id);
                    if (! $producto) {
                        return response()->json(['exito' => false, 'error' => 'El producto no existe.'], 404);
                    }
                    // update() no toca la columna 'activo': un producto eliminado
                    // permanece eliminado hasta que se use "reactivar".
                    $producto->update($datos);
                } else {
                    // Producto nuevo: siempre nace activo.
                    Producto::create($datos + ['activo' => 1]);
                }

                return response()->json(['exito' => true]);
            }

            // --- ELIMINACIÓN LÓGICA DE PRODUCTOS ---
            if ($request->accion === 'eliminar_producto') {
                $producto = Producto::find((int) $request->id);

                // No se reporta éxito si el producto no existe o la fila no cambió.
                if (! $producto) {
                    return response()->json(['exito' => false, 'error' => 'El producto no existe.'], 404);
                }

                // Eliminación lógica: conserva ID, nombre, categoría e historial.
                // No borra la fila ni toca el stock.
                $producto->update(['activo' => 0]);

                return response()->json(['exito' => true, 'mensaje' => 'Producto eliminado.']);
            }

            // --- REACTIVACIÓN DE PRODUCTOS ---
            if ($request->accion === 'reactivar_producto') {
                $producto = Producto::find((int) $request->id);

                if (! $producto) {
                    return response()->json(['exito' => false, 'error' => 'El producto no existe.'], 404);
                }

                // Reactiva el MISMO registro: no se crea otro ni se toca el stock.
                $producto->update(['activo' => 1]);

                return response()->json(['exito' => true, 'mensaje' => 'Producto reactivado.']);
            }

            return response()->json(['exito' => false, 'error' => 'Acción no reconocida.'], 422);

        } catch (ValidationException $e) {
            // Errores de validación: se devuelven legibles para el front-end.
            return response()->json(['exito' => false, 'error' => $e->validator->errors()->first()], 422);
        } catch (\Exception $e) {
            Log::error('Error en CatalogoController: '.$e->getMessage());

            return response()->json(['exito' => false, 'error' => 'Error en la base de datos al guardar.'], 500);
        }
    }

    /**
     * Solo ADMIN y GERENTE modifican el catálogo. El resto de roles
     * autenticados (CAJERO, MESERO) conservan la consulta de solo lectura.
     */
    private function autorizado(): bool
    {
        $usuario = Auth::user();

        if (! $usuario) {
            return false;
        }

        $rol = $usuario->rol->nombre ?? '';

        return in_array($rol, ['ADMIN', 'GERENTE'], true);
    }

    private function noAutorizado()
    {
        return response()->json([
            'exito' => false,
            'error' => 'No tienes permiso para modificar el catálogo.',
        ], 403);
    }
}
