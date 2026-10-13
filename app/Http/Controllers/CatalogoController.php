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
        // Traemos todas las categorías (sin filtrar por 'activo')
        $categorias = Categoria::all();
        
        // Traemos los productos con su categoría (asumiendo que productos tampoco tiene 'activo')
        // Si productos SÍ tiene la columna 'activo', vuelve a poner ->where('activo', 1) antes de ->get()
        $productos = Producto::with('categoria')->get();
        
        return view('catalogo.index', compact('categorias', 'productos'));
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

                // updateOrCreate filtrado por 'id' garantiza que SOLO se actualiza
                // el producto seleccionado (o se crea uno nuevo cuando no llega id).
                Producto::updateOrCreate(
                    ['id' => $request->id],
                    [
                        'categoria_id'    => $request->categoria_id,
                        'nombre'          => $request->nombre,
                        'precio'          => $request->precio,
                        // 1 si la casilla está marcada, 0 si está desmarcada.
                        'requiere_cocina' => $request->boolean('requiere_cocina') ? 1 : 0,
                        'activo'          => 1
                    ]
                );
                return response()->json(['exito' => true]);
            }

            // --- ELIMINACIÓN DE PRODUCTOS ---
            if ($request->accion === 'eliminar_producto') {
                Producto::where('id', $request->id)->update(['activo' => 0]);
                return response()->json(['exito' => true]);
            }

            return response()->json(['exito' => false, 'error' => 'Acción no reconocida.']);

        } catch (\Illuminate\Validation\ValidationException $e) {
            // Errores de validación: se devuelven legibles para el front-end.
            return response()->json(['exito' => false, 'error' => $e->validator->errors()->first()]);
        } catch (\Exception $e) {
            Log::error('Error en CatalogoController: ' . $e->getMessage());
            return response()->json(['exito' => false, 'error' => 'Error en la base de datos al guardar.']);
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