<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CatalogoController extends Controller
{
    // Método para mostrar la vista con categorías y productos
    public function index()
    {
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

    // Método para manejar Crear, Editar y Eliminar del catálogo
    public function storeOrUpdate(Request $request)
    {
        // Seguridad en backend: solo ADMIN y GERENTE pueden crear, editar o
        // desactivar productos y categorías. CAJERO y MESERO solo consultan.
        // El guard se ejecuta ANTES de validar o escribir cualquier registro.
        if (!$this->autorizado()) {
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

            // --- GESTIÓN DE PRODUCTOS ---
            if ($request->accion === 'crear_producto' || $request->accion === 'editar_producto') {
                // Validación de backend de los campos existentes del producto.
                $request->validate([
                    'categoria_id' => 'required|exists:categorias,id',
                    'nombre'       => 'required|string|max:100',
                    'precio'       => 'required|numeric|min:0',
                ]);

                // Datos comunes. NO se incluye 'activo' aquí: editar nunca debe
                // reactivar un producto eliminado de forma silenciosa.
                $datos = [
                    'categoria_id'    => $request->categoria_id,
                    'nombre'          => $request->nombre,
                    'precio'          => $request->precio,
                    // 1 si la casilla está marcada, 0 si está desmarcada.
                    'requiere_cocina' => $request->boolean('requiere_cocina') ? 1 : 0,
                ];

                if ($request->accion === 'editar_producto' && (int) $request->id > 0) {
                    // Se actualiza SOLO el producto indicado (mismo ID, sin duplicar).
                    $producto = Producto::find((int) $request->id);
                    if (!$producto) {
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
                if (!$producto) {
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

                if (!$producto) {
                    return response()->json(['exito' => false, 'error' => 'El producto no existe.'], 404);
                }

                // Reactiva el MISMO registro: no se crea otro ni se toca el stock.
                $producto->update(['activo' => 1]);

                return response()->json(['exito' => true, 'mensaje' => 'Producto reactivado.']);
            }

            return response()->json(['exito' => false, 'error' => 'Acción no reconocida.'], 422);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Errores de validación: se devuelven legibles para el front-end.
            return response()->json(['exito' => false, 'error' => $e->validator->errors()->first()], 422);
        } catch (\Exception $e) {
            Log::error('Error en CatalogoController: ' . $e->getMessage());
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

        if (!$usuario) {
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