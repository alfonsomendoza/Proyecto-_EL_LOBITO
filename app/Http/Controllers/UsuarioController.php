<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;

class UsuarioController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | LISTADO DE USUARIOS
    |--------------------------------------------------------------------------
    */

    public function index()
    {
        // Obtenemos el rol del usuario actual.
        $rol = Auth::user()->rol->nombre ?? '';

        // Solamente ADMIN puede acceder.
        if ($rol !== 'ADMIN') {
            return redirect()
                ->route('inicio')
                ->with('error', 'Acceso denegado.');
        }

        // Obtenemos usuarios activos junto con su rol.
        $usuarios = DB::table('usuarios')
            ->join('roles', 'usuarios.rol_id', '=', 'roles.id')
            ->select(
                'usuarios.id',
                'usuarios.nombre',
                'usuarios.username',
                'roles.nombre as rol'
            )
            ->where('usuarios.activo', 1)
            ->orderBy('usuarios.id', 'DESC')
            ->get();

        // Obtenemos los roles disponibles.
        $roles = DB::table('roles')->get();

        // Enviamos los datos a la vista.
        return view('usuarios.index', compact('usuarios', 'roles'));
    }


    /*
    |--------------------------------------------------------------------------
    | API DE USUARIOS
    |--------------------------------------------------------------------------
    */

    public function api(Request $request)
    {
        // Obtenemos el rol del usuario actual.
        $rolActual = Auth::user()->rol->nombre ?? '';

        // Solamente ADMIN puede ejecutar estas acciones.
        if ($rolActual !== 'ADMIN') {
            return response()->json([
                'exito' => false,
                'error' => 'No autorizado'
            ], 403);
        }

        // Obtenemos la acción solicitada.
        $accion = $request->input('accion');


        /*
        |--------------------------------------------------------------------------
        | CREAR USUARIO
        |--------------------------------------------------------------------------
        */

        if ($accion === 'crear') {

            $nombre = trim($request->input('nombre'));
            $username = trim($request->input('username'));
            $password = $request->input('password');
            $rol_id = (int) $request->input('rol_id');

            // Validamos campos.
            if (
                !$nombre ||
                !$username ||
                !$password ||
                $rol_id <= 0
            ) {
                return response()->json([
                    'exito' => false,
                    'error' => 'Todos los campos son obligatorios.'
                ]);
            }

            try {

                // Insertamos el nuevo usuario.
                DB::table('usuarios')->insert([
                    'nombre' => $nombre,
                    'username' => $username,
                    'password_hash' => Hash::make($password),
                    'rol_id' => $rol_id,
                    'activo' => 1
                ]);

                return response()->json([
                    'exito' => true,
                    'mensaje' => 'Usuario registrado con éxito.'
                ]);

            } catch (\Exception $e) {

                return response()->json([
                    'exito' => false,
                    'error' => 'El usuario (login) ya está en uso.'
                ]);
            }
        }


        /*
        |--------------------------------------------------------------------------
        | DESACTIVAR USUARIO
        |--------------------------------------------------------------------------
        */

        if ($accion === 'eliminar') {

            $id = (int) $request->input('id');

            // No permitimos eliminar al usuario que tiene la sesión actual.
            if ($id === Auth::id()) {
                return response()->json([
                    'exito' => false,
                    'error' => 'No puedes eliminar tu propia sesión activa.'
                ]);
            }

            try {

                // Desactivamos en lugar de borrar físicamente.
                $afectados = DB::table('usuarios')
                    ->where('id', $id)
                    ->update([
                        'activo' => 0
                    ]);

                if ($afectados > 0) {
                    return response()->json([
                        'exito' => true,
                        'mensaje' => 'Usuario desactivado correctamente.'
                    ]);
                }

                return response()->json([
                    'exito' => false,
                    'error' => 'No se pudo encontrar o desactivar el usuario.'
                ]);

            } catch (\Exception $e) {

                return response()->json([
                    'exito' => false,
                    'error' => 'Error de base de datos: ' . $e->getMessage()
                ]);
            }
        }


        /*
        |--------------------------------------------------------------------------
        | ACCIÓN DESCONOCIDA
        |--------------------------------------------------------------------------
        */

        return response()->json([
            'exito' => false,
            'error' => 'Acción inválida.'
        ]);
    }
}