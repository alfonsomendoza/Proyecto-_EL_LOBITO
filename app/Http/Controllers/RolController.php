<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| CATÁLOGO OFICIAL DE ROLES
|--------------------------------------------------------------------------
|
| El sistema usa exclusivamente estos cinco roles oficiales, en este orden
| de ID:
|
|   1 => ADMIN
|   2 => GERENTE
|   3 => CAJERO
|   4 => MESERO
|   5 => COCINERO
|
| Este controlador SOLO permite:
| - Consultar (ADMIN) qué roles oficiales faltan, ordenados por ID ascendente.
| - Crear (ADMIN) un rol oficial que falte.
|
| No existe ninguna acción de eliminar o editar roles: el catálogo no puede
| alterarse por la interfaz ni por peticiones manipuladas. Los roles ya
| existentes no se tocan.
|
| Seguridad: el rol del usuario autenticado se resuelve SIEMPRE en servidor
| desde la relación real `Auth::user()->rol->nombre`. Nunca desde el request
| (`rol`, `rol_id`, `ADMIN`, etc.).
|
*/
class RolController extends Controller
{
    /*
     * Catálogo oficial indexado por nombre, con el ID esperado.
     * El orden del arreglo es el orden oficial (ID ascendente).
     */
    public const ROLES_OFICIALES = [
        'ADMIN' => 1,
        'GERENTE' => 2,
        'CAJERO' => 3,
        'MESERO' => 4,
        'COCINERO' => 5,
    ];

    /*
    |--------------------------------------------------------------------------
    | LISTADO: ROLES FALTANTES
    |--------------------------------------------------------------------------
    */

    public function index()
    {
        // Autorización en servidor: solo ADMIN administra el catálogo de roles.
        if ($this->rolDelAutenticado() !== 'ADMIN') {
            return redirect()
                ->route('inicio')
                ->with('error', 'Acceso denegado.');
        }

        // Nombres existentes normalizados (mayúsculas y sin espacios) para
        // comparar contra el catálogo oficial sin falsos negativos.
        $existentes = $this->nombresExistentesNormalizados();

        // Construimos la lista COMPLETA de roles oficiales con su estado y,
        // aparte, la lista de los que faltan (siempre ordenados por ID).
        $rolesOficiales = [];
        $faltantes = [];

        foreach (self::ROLES_OFICIALES as $nombre => $idEsperado) {
            $existe = in_array($nombre, $existentes, true);

            $rolesOficiales[] = [
                'id' => $idEsperado,
                'nombre' => $nombre,
                'existe' => $existe,
            ];

            if (! $existe) {
                $faltantes[] = [
                    'id' => $idEsperado,
                    'nombre' => $nombre,
                ];
            }
        }

        $catalogoCompleto = empty($faltantes);

        return view('roles.index', compact('rolesOficiales', 'faltantes', 'catalogoCompleto'));
    }

    /*
    |--------------------------------------------------------------------------
    | API: CREAR ROL OFICIAL FALTANTE
    |--------------------------------------------------------------------------
    */

    public function api(Request $request)
    {
        // Autorización en servidor (nunca desde el request).
        if ($this->rolDelAutenticado() !== 'ADMIN') {
            return response()->json([
                'exito' => false,
                'error' => 'No autorizado',
            ], 403);
        }

        $accion = $request->input('accion');

        // Solo existe la acción de crear. Cualquier otra (eliminar, editar,
        // etc.) se rechaza: el catálogo queda protegido en el backend.
        if ($accion !== 'crear') {
            return response()->json([
                'exito' => false,
                'error' => 'Acción inválida.',
            ]);
        }

        // El nombre se normaliza para comparar sin distinguir mayúsculas ni
        // espacios sobrantes.
        $nombre = strtoupper(trim((string) $request->input('nombre')));

        // 1) El nombre debe pertenecer al catálogo oficial.
        if (! array_key_exists($nombre, self::ROLES_OFICIALES)) {
            return response()->json([
                'exito' => false,
                'error' => 'El nombre no pertenece al catálogo oficial de roles.',
            ], 422);
        }

        // 1b) El rol ADMIN forma parte del catálogo, pero NUNCA se crea desde
        //     aquí: ya existe (permite autenticarse) y no debe duplicarse ni
        //     recrearse, ni siquiera con una petición HTTP manipulada.
        if ($nombre === 'ADMIN') {
            return response()->json([
                'exito' => false,
                'error' => 'El rol ADMIN no se crea desde este catálogo.',
            ], 422);
        }

        // 2) No debe existir ya un rol equivalente (sin distinguir mayúsculas
        //    ni espacios).
        if (in_array($nombre, $this->nombresExistentesNormalizados(), true)) {
            return response()->json([
                'exito' => false,
                'error' => 'El rol ya existe.',
            ], 422);
        }

        // 3) Conflicto con el ID esperado: si otro rol ya ocupa ese ID, no
        //    creamos nada para no alterar registros existentes.
        $idEsperado = self::ROLES_OFICIALES[$nombre];

        if (DB::table('roles')->where('id', $idEsperado)->exists()) {
            return response()->json([
                'exito' => false,
                'error' => 'El ID esperado ya está ocupado por otro rol.',
            ], 422);
        }

        try {

            // Insertamos SOLO el nombre. No forzamos el ID ni alteramos el
            // esquema: la tabla conserva su estructura original.
            DB::table('roles')->insert([
                'nombre' => $nombre,
            ]);

            return response()->json([
                'exito' => true,
                'mensaje' => 'Rol '.$nombre.' registrado con éxito.',
            ]);

        } catch (\Exception $e) {

            return response()->json([
                'exito' => false,
                'error' => 'No se pudo registrar el rol.',
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | AUXILIARES
    |--------------------------------------------------------------------------
    */

    /**
     * Rol real del usuario autenticado, resuelto en servidor.
     */
    private function rolDelAutenticado(): string
    {
        $usuario = Auth::user();

        if (! $usuario) {
            return '';
        }

        return strtoupper((string) ($usuario->rol->nombre ?? ''));
    }

    /*
     * Nombres de los roles guardados en la base, normalizados a mayúsculas
     * y sin espacios, para comparaciones seguras.
     */
    private function nombresExistentesNormalizados(): array
    {
        return DB::table('roles')
            ->pluck('nombre')
            ->map(fn ($nombre) => strtoupper(trim((string) $nombre)))
            ->all();
    }
}
