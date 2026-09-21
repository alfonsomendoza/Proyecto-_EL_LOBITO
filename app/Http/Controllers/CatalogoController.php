<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\Producto;
use Illuminate\Http\Request;
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
        try {
            // --- GESTIÓN DE CATEGORÍAS ---
            if ($request->accion === 'crear_categoria' || $request->accion === 'editar_categoria') {
                Categoria::updateOrCreate(
                    ['id' => $request->id], 
                    ['nombre' => $request->nombre, 'activo' => 1]
                );
                return response()->json(['exito' => true]);
            }

            // --- GESTIÓN DE PRODUCTOS ---
            if ($request->accion === 'crear_producto' || $request->accion === 'editar_producto') {
                Producto::updateOrCreate(
                    ['id' => $request->id],
                    [
                        'categoria_id' => $request->categoria_id,
                        'nombre' => $request->nombre,
                        'precio' => $request->precio,
                        'activo' => 1
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

        } catch (\Exception $e) {
            Log::error('Error en CatalogoController: ' . $e->getMessage());
            return response()->json(['exito' => false, 'error' => 'Error en la base de datos al guardar.']);
        }
    }
}