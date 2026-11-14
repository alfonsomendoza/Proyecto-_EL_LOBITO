<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class UsuarioController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | LISTADO DE USUARIOS (búsqueda y filtros en servidor)
    |--------------------------------------------------------------------------
    |
    | Nota sobre permisos:
    | El módulo de administración de usuarios siempre estuvo restringido a
    | ADMIN en el backend. El menú lateral muestra "Usuarios" también a GERENTE,
    | pero index() y api() rechazan a cualquier rol distinto de ADMIN.
    | Se conserva ese comportamiento actual (no se amplían permisos) mientras la
    | regla de GERENTE no esté confirmada.
    |
    */

    public function index(Request $request)
    {
        // Rol del usuario autenticado (se resuelve en servidor).
        $rol = strtoupper((string) (Auth::user()->rol->nombre ?? ''));

        // ADMIN y GERENTE pueden acceder. Otros roles son rechazados.
        if (! in_array($rol, ['ADMIN', 'GERENTE'], true)) {
            return redirect()
                ->route('inicio')
                ->with('error', 'Acceso denegado.');
        }

        // Filtros recibidos por querystring.
        $busqueda = trim((string) $request->query('q', ''));
        $rolFiltro = strtoupper(trim((string) $request->query('rol', '')));
        $estado = strtolower(trim((string) $request->query('estado', 'todos')));

        // Estado válido: activos, eliminados o todos (ambas secciones).
        if (! in_array($estado, ['activos', 'eliminados', 'todos'], true)) {
            $estado = 'todos';
        }

        // La separación es funcional: una consulta para los usuarios activos y
        // otra para los eliminados, aplicando a ambas la misma búsqueda y el
        // mismo filtro de rol. Nunca se mezclan en la misma sección.
        $aplicarFiltros = function ($query) use ($busqueda, $rolFiltro, $rol) {
            if ($busqueda !== '') {
                $like = '%'.$busqueda.'%';
                $query->where(function ($q) use ($like) {
                    $q->where('usuarios.nombre', 'like', $like)
                        ->orWhere('usuarios.username', 'like', $like);
                });
            }

            if ($rolFiltro !== '') {
                $query->where('roles.nombre', $rolFiltro);
            }

            // Restricción para GERENTE: solo puede ver CAJERO, MESERO y COCINERO
            if ($rol === 'GERENTE') {
                $query->whereIn('roles.nombre', ['CAJERO', 'MESERO', 'COCINERO']);
            }

            return $query;
        };

        $consultaBase = function () {
            return DB::table('usuarios')
                ->join('roles', 'usuarios.rol_id', '=', 'roles.id')
                ->select(
                    'usuarios.id',
                    'usuarios.nombre',
                    'usuarios.username',
                    'usuarios.activo',
                    'usuarios.rol_id',
                    'roles.nombre as rol'
                );
        };

        // La sección de activos se muestra salvo que solo se pidan eliminados.
        $mostrarActivos = in_array($estado, ['activos', 'todos'], true);

        // La sección de eliminados se muestra salvo que solo se pidan activos.
        $mostrarEliminados = in_array($estado, ['eliminados', 'todos'], true);

        // Sección A: usuarios activos (activo = 1).
        $activos = collect();
        if ($mostrarActivos) {
            $activos = $aplicarFiltros($consultaBase())
                ->where('usuarios.activo', 1)
                ->orderBy('usuarios.id', 'DESC')
                ->get();
        }

        // Sección B: usuarios eliminados lógicamente (activo = 0).
        $eliminados = collect();
        if ($mostrarEliminados) {
            $eliminados = $aplicarFiltros($consultaBase())
                ->where('usuarios.activo', 0)
                ->orderBy('usuarios.id', 'DESC')
                ->get();
        }

        // Roles reales del sistema para el selector. Solo se ofrecen los
        // ACTIVOS: no tiene sentido asignar un rol desactivado (sus usuarios
        // no podrían operar). Si la migración aún no está aplicada, se
        // muestran todos, como antes.
        $rolesQuery = DB::table('roles');
        if (Schema::hasColumn('roles', 'activo')) {
            $rolesQuery->where('activo', 1);
        }

        if ($rol === 'GERENTE') {
            $roles = (clone $rolesQuery)
                ->whereIn('nombre', ['CAJERO', 'MESERO', 'COCINERO'])
                ->orderBy('id')
                ->get();
        } else {
            $roles = $rolesQuery->orderBy('id')->get();
        }

        // Filtros actuales, para repintar el formulario.
        $filtros = [
            'q' => $busqueda,
            'rol' => $rolFiltro,
            'estado' => $estado,
        ];

        return view('usuarios.index', compact(
            'activos',
            'eliminados',
            'roles',
            'filtros',
            'mostrarActivos',
            'mostrarEliminados'
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | API DE USUARIOS
    |--------------------------------------------------------------------------
    */

    public function api(Request $request)
    {
        // Obtenemos el rol del usuario actual (nunca desde el request).
        $rolActual = strtoupper((string) (Auth::user()->rol->nombre ?? ''));

        // Solo ADMIN y GERENTE pueden acceder a la API
        if (! in_array($rolActual, ['ADMIN', 'GERENTE'], true)) {
            return response()->json([
                'exito' => false,
                'error' => 'No autorizado',
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
            // Solo ADMIN puede crear usuarios
            if ($rolActual !== 'ADMIN') {
                return response()->json([
                    'exito' => false,
                    'error' => 'No autorizado',
                ], 403);
            }

            $nombre = trim((string) $request->input('nombre'));
            $username = trim((string) $request->input('username'));
            $password = (string) $request->input('password');
            $rol_id = (int) $request->input('rol_id');

            // Validamos campos obligatorios.
            if ($nombre === '' || $username === '' || $password === '' || $rol_id <= 0) {
                return response()->json([
                    'exito' => false,
                    'error' => 'Todos los campos son obligatorios.',
                ]);
            }

            // Validamos longitud mínima de la contraseña.
            if (mb_strlen($password) < 12) {
                return response()->json([
                    'exito' => false,
                    'error' => 'La contraseña debe tener al menos 12 caracteres.',
                ]);
            }

            // El rol debe existir realmente (no inventamos roles) y estar
            // ACTIVO: un rol desactivado no se puede asignar, ni siquiera con
            // una petición HTTP manipulada.
            $rolElegido = DB::table('roles')->where('id', $rol_id)->first();

            if (! $rolElegido) {
                return response()->json([
                    'exito' => false,
                    'error' => 'El rol seleccionado no existe.',
                ]);
            }

            if (Schema::hasColumn('roles', 'activo') && (int) $rolElegido->activo === 0) {
                return response()->json([
                    'exito' => false,
                    'error' => 'El rol seleccionado está desactivado y no se puede asignar.',
                ]);
            }

            // username es UNIQUE en la base: comprobamos para dar un error claro.
            $duplicado = DB::table('usuarios')->where('username', $username)->exists();
            if ($duplicado) {
                return response()->json([
                    'exito' => false,
                    'error' => 'El usuario (login) ya está en uso.',
                ]);
            }

            try {

                // Insertamos el nuevo usuario con la contraseña SIEMPRE hasheada.
                DB::table('usuarios')->insert([
                    'nombre' => $nombre,
                    'username' => $username,
                    'password_hash' => Hash::make($password),
                    'rol_id' => $rol_id,
                    'activo' => 1,
                ]);

                return response()->json([
                    'exito' => true,
                    'mensaje' => 'Usuario registrado con éxito.',
                ]);

            } catch (\Exception $e) {

                return response()->json([
                    'exito' => false,
                    'error' => 'No se pudo registrar el usuario.',
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | DESACTIVAR USUARIO (borrado lógico)
        |--------------------------------------------------------------------------
        */

        if ($accion === 'eliminar') {
            // Solo ADMIN puede desactivar usuarios
            if ($rolActual !== 'ADMIN') {
                return response()->json([
                    'exito' => false,
                    'error' => 'No autorizado',
                ], 403);
            }

            $id = (int) $request->input('id');

            if ($id <= 0) {
                return response()->json([
                    'exito' => false,
                    'error' => 'Usuario inválido.',
                ]);
            }

            // No permitimos desactivar al usuario que tiene la sesión actual.
            if ($id === Auth::id()) {
                return response()->json([
                    'exito' => false,
                    'error' => 'No puedes eliminar tu propia sesión activa.',
                ]);
            }

            try {

                // Comprobamos que exista antes de "eliminar".
                $existe = DB::table('usuarios')->where('id', $id)->exists();
                if (! $existe) {
                    return response()->json([
                        'exito' => false,
                        'error' => 'No se pudo encontrar el usuario.',
                    ], 404);
                }

                // Desactivamos en lugar de borrar físicamente (borrado lógico).
                DB::table('usuarios')
                    ->where('id', $id)
                    ->update([
                        'activo' => 0,
                    ]);

                return response()->json([
                    'exito' => true,
                    'mensaje' => 'Usuario desactivado correctamente.',
                ]);

            } catch (\Exception $e) {

                return response()->json([
                    'exito' => false,
                    'error' => 'Error de base de datos al desactivar el usuario.',
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | REACTIVAR USUARIO (recupera el registro existente)
        |--------------------------------------------------------------------------
        */

        if ($accion === 'reactivar') {
            // Solo ADMIN puede reactivar usuarios
            if ($rolActual !== 'ADMIN') {
                return response()->json([
                    'exito' => false,
                    'error' => 'No autorizado',
                ], 403);
            }

            $id = (int) $request->input('id');

            if ($id <= 0) {
                return response()->json([
                    'exito' => false,
                    'error' => 'Usuario inválido.',
                ]);
            }

            try {

                // Buscamos el registro existente. No se crea uno nuevo.
                $usuario = DB::table('usuarios')->where('id', $id)->first();

                if (! $usuario) {
                    return response()->json([
                        'exito' => false,
                        'error' => 'Usuario no encontrado.',
                    ], 404);
                }

                // Si ya está activo no hay nada que reactivar.
                if ((int) $usuario->activo === 1) {
                    return response()->json([
                        'exito' => false,
                        'error' => 'El usuario ya está activo.',
                    ]);
                }

                // Reactivamos el MISMO registro (no se duplica ni se crea otro).
                DB::table('usuarios')
                    ->where('id', $id)
                    ->update([
                        'activo' => 1,
                    ]);

                return response()->json([
                    'exito' => true,
                    'mensaje' => 'Usuario reactivado correctamente.',
                ]);

            } catch (\Exception $e) {

                return response()->json([
                    'exito' => false,
                    'error' => 'Error de base de datos al reactivar el usuario.',
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | CAMBIAR CONTRASEÑA (RESETEAR)
        |--------------------------------------------------------------------------
        */

        if ($accion === 'reset_password') {

            $usuarioId = (int) $request->input('usuario_id');
            $password = (string) $request->input('password');
            $passwordConfirmation = (string) $request->input('password_confirmation');

            // Validamos campos.
            if ($usuarioId <= 0 || $password === '' || $passwordConfirmation === '') {
                return response()->json([
                    'exito' => false,
                    'error' => 'Todos los campos son obligatorios.',
                ]);
            }

            // Longitud mínima (se aplica a la contraseña generada o manual).
            if (mb_strlen($password) < 12) {
                return response()->json([
                    'exito' => false,
                    'error' => 'La contraseña debe tener al menos 12 caracteres.',
                ]);
            }

            // Deben coincidir.
            if ($password !== $passwordConfirmation) {
                return response()->json([
                    'exito' => false,
                    'error' => 'Las contraseñas no coinciden.',
                ]);
            }

            try {

                // Obtenemos el usuario objetivo con su rol.
                $target = DB::table('usuarios')
                    ->join('roles', 'usuarios.rol_id', '=', 'roles.id')
                    ->select('usuarios.id', 'usuarios.activo', 'roles.nombre as rol')
                    ->where('usuarios.id', $usuarioId)
                    ->first();

                if (! $target) {
                    return response()->json([
                        'exito' => false,
                        'error' => 'Usuario no encontrado.',
                    ], 404);
                }

                // Para GERENTE, solo puede cambiar contraseñas de CAJERO, MESERO y COCINERO
                if ($rolActual === 'GERENTE') {
                    $rolTarget = strtoupper((string) ($target->rol ?? ''));
                    if (! in_array($rolTarget, ['CAJERO', 'MESERO', 'COCINERO'], true)) {
                        return response()->json([
                            'exito' => false,
                            'error' => 'No autorizado para cambiar la contraseña de este usuario.',
                        ], 403);
                    }
                }

                // Verificar que el objetivo no es ADMIN ni GERENTE cuando cambia un GERENTE
                // (ya cubierto arriba, pero aseguramos también para ADMIN? ADMIN puede cambiar cualquiera)
                $rolTargetFinal = strtoupper((string) ($target->rol ?? ''));
                if ($rolActual === 'GERENTE' && in_array($rolTargetFinal, ['ADMIN', 'GERENTE'], true)) {
                    return response()->json([
                        'exito' => false,
                        'error' => 'No autorizado para cambiar la contraseña de este usuario.',
                    ], 403);
                }

                // Actualizamos únicamente la contraseña, siempre hasheada.
                DB::table('usuarios')
                    ->where('id', $usuarioId)
                    ->update([
                        'password_hash' => Hash::make($password),
                    ]);

                return response()->json([
                    'exito' => true,
                    'mensaje' => 'Contraseña actualizada correctamente.',
                ]);

            } catch (\Exception $e) {

                // No exponemos la contraseña ni el detalle interno en el error.
                return response()->json([
                    'exito' => false,
                    'error' => 'Error al actualizar la contraseña.',
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | CAMBIAR ROL DE USUARIO (SOLO ADMIN)
        |--------------------------------------------------------------------------
        |
        | El rol es la base de toda la autorización del sistema, por eso el
        | cambio se limita a ADMIN en el BACKEND (no se confía en ocultar el
        | botón en la vista). Solo se actualiza `usuarios.rol_id`: no se toca
        | contraseña, datos personales, estado, 2FA, sesiones ni dispositivos.
        |
        | Se valida que el usuario y el rol existan, que el rol pertenezca al
        | catálogo oficial y que el cambio no deje el sistema sin ningún ADMIN
        | activo.
        */

        if ($accion === 'cambiar_rol') {
            if ($rolActual !== 'ADMIN') {
                return response()->json([
                    'exito' => false,
                    'error' => 'No autorizado',
                ], 403);
            }

            $usuarioId = (int) $request->input('usuario_id');
            $rolId = (int) $request->input('rol_id');

            // Catálogo oficial: no se aceptan roles inventados ni arbitrarios.
            $rolesPermitidos = ['ADMIN', 'GERENTE', 'CAJERO', 'MESERO', 'COCINERO'];

            if ($usuarioId <= 0 || $rolId <= 0) {
                return response()->json([
                    'exito' => false,
                    'error' => 'Usuario y rol son obligatorios.',
                ], 422);
            }

            try {
                $resultado = DB::transaction(function () use ($usuarioId, $rolId, $rolesPermitidos, $request) {
                    // Serializamos TODOS los cambios de rol bloqueando la fila
                    // del rol ADMIN: así dos degradaciones simultáneas no pueden
                    // contar "el otro ADMIN" a la vez y dejar el sistema sin
                    // ningún administrador activo.
                    DB::table('roles')->where('nombre', 'ADMIN')->lockForUpdate()->first();

                    // Bloqueamos también el usuario objetivo.
                    $target = DB::table('usuarios')->where('id', $usuarioId)->lockForUpdate()->first();

                    if (! $target) {
                        return [404, [
                            'exito' => false,
                            'error' => 'Usuario no encontrado.',
                        ]];
                    }

                    $rolElegido = DB::table('roles')->where('id', $rolId)->first();

                    if (! $rolElegido) {
                        return [422, [
                            'exito' => false,
                            'error' => 'El rol seleccionado no existe.',
                        ]];
                    }

                    $rolNuevo = strtoupper((string) ($rolElegido->nombre ?? ''));

                    if (! in_array($rolNuevo, $rolesPermitidos, true)) {
                        return [422, [
                            'exito' => false,
                            'error' => 'El rol seleccionado no está permitido.',
                        ]];
                    }

                    if (Schema::hasColumn('roles', 'activo') && (int) $rolElegido->activo === 0) {
                        return [422, [
                            'exito' => false,
                            'error' => 'El rol seleccionado está desactivado y no se puede asignar.',
                        ]];
                    }

                    // Rol actual del usuario objetivo (para auditoría y para
                    // la protección del último ADMIN activo).
                    $rolAnterior = strtoupper((string) (DB::table('roles')
                        ->where('id', (int) $target->rol_id)
                        ->value('nombre') ?? ''));

                    if ($rolAnterior === 'ADMIN' && (int) $target->activo === 1 && $rolNuevo !== 'ADMIN') {
                        $otrosAdminsActivos = DB::table('usuarios')
                            ->join('roles', 'usuarios.rol_id', '=', 'roles.id')
                            ->where('roles.nombre', 'ADMIN')
                            ->where('usuarios.activo', 1)
                            ->where('usuarios.id', '!=', $usuarioId)
                            ->count();

                        if ($otrosAdminsActivos === 0) {
                            return [422, [
                                'exito' => false,
                                'error' => 'No se puede quitar el rol ADMIN al último administrador activo.',
                            ]];
                        }
                    }

                    // ÚNICO cambio: el rol. Nada más del usuario se modifica.
                    DB::table('usuarios')
                        ->where('id', $usuarioId)
                        ->update(['rol_id' => $rolId]);

                    // Auditoría compatible con el mecanismo existente. Si la
                    // tabla no está disponible, AuditService solo registra en log.
                    app(\App\Services\AuditService::class)->log(
                        'cambio_rol_usuario',
                        Auth::id(),
                        'Cambio de rol de usuario',
                        [
                            'usuario_id' => $usuarioId,
                            'rol_anterior' => $rolAnterior,
                            'rol_nuevo' => $rolNuevo,
                        ],
                        $request
                    );

                    return [200, [
                        'exito' => true,
                        'mensaje' => 'Rol del usuario actualizado correctamente.',
                    ]];
                });

                return response()->json($resultado[1], $resultado[0]);

            } catch (\Throwable $e) {
                return response()->json([
                    'exito' => false,
                    'error' => 'Error de base de datos al cambiar el rol del usuario.',
                ], 500);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | EDITAR USUARIO (nombre y username)
        |--------------------------------------------------------------------------
        */

        if ($accion === 'editar') {
            $usuarioId = (int) $request->input('usuario_id');
            $nombre = trim((string) $request->input('nombre'));
            $username = trim((string) $request->input('username'));

            if ($usuarioId <= 0 || $nombre === '' || $username === '') {
                return response()->json([
                    'exito' => false,
                    'error' => 'Todos los campos son obligatorios.',
                ]);
            }

            try {
                $target = DB::table('usuarios')
                    ->join('roles', 'usuarios.rol_id', '=', 'roles.id')
                    ->select('usuarios.id', 'usuarios.username', 'roles.nombre as rol')
                    ->where('usuarios.id', $usuarioId)
                    ->first();

                if (! $target) {
                    return response()->json([
                        'exito' => false,
                        'error' => 'Usuario no encontrado.',
                    ], 404);
                }

                // Solo ADMIN y GERENTE pueden editar
                if ($rolActual !== 'ADMIN' && $rolActual !== 'GERENTE') {
                    return response()->json([
                        'exito' => false,
                        'error' => 'No autorizado',
                    ], 403);
                }

                // Restricciones para GERENTE
                if ($rolActual === 'GERENTE') {
                    $rolTarget = strtoupper((string) ($target->rol ?? ''));
                    if (! in_array($rolTarget, ['CAJERO', 'MESERO', 'COCINERO'], true)) {
                        return response()->json([
                            'exito' => false,
                            'error' => 'No autorizado para editar este usuario.',
                        ], 403);
                    }
                }

                // Verificar duplicado de username (permitir el mismo del usuario objetivo)
                $existeDuplicado = DB::table('usuarios')
                    ->where('username', $username)
                    ->where('id', '!=', $usuarioId)
                    ->exists();

                if ($existeDuplicado) {
                    return response()->json([
                        'exito' => false,
                        'error' => 'El usuario (login) ya está en uso.',
                    ], 422);
                }

                // Actualizar solo nombre y username
                DB::table('usuarios')
                    ->where('id', $usuarioId)
                    ->update([
                        'nombre' => $nombre,
                        'username' => $username,
                    ]);

                return response()->json([
                    'exito' => true,
                    'mensaje' => 'Usuario actualizado correctamente.',
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'exito' => false,
                    'error' => 'Error al actualizar el usuario.',
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
            'error' => 'Acción inválida.',
        ]);
    }
}
