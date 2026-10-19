<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
| - Consultar (ADMIN) el catálogo oficial con su estado, ordenado por ID.
| - Crear (ADMIN) un rol oficial que falte.
| - Desactivar / reactivar (ADMIN) un rol oficial, con baja LÓGICA: el rol
|   nunca se borra, conserva su id y su nombre, y las asignaciones de
|   `usuarios.rol_id` no se modifican. Desactivado no concede acceso
|   (login y peticiones se bloquean); reactivarlo lo devuelve.
|
| No existe ninguna acción de ELIMINAR o EDITAR roles: el nombre y los IDs de
| los cinco roles oficiales no pueden alterarse por la interfaz ni por
| peticiones manipuladas. El rol ADMIN tampoco puede desactivarse.
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

        // ¿Ya está aplicada la migración que añade `roles.activo`? Si todavía
        // no, el catálogo sigue funcionando: todos los roles se muestran como
        // activos y las acciones de estado avisan en vez de fallar con SQL.
        $tieneEstado = Schema::hasColumn('roles', 'activo');

        // Filas reales indexadas por nombre normalizado: necesitamos el ID
        // real (para desactivar/reactivar) y el estado, no solo si el nombre
        // existe en la tabla.
        $filas = [];
        foreach (DB::table('roles')->get() as $fila) {
            $filas[$this->normalizar((string) $fila->nombre)] = $fila;
        }

        // Usuarios asociados a cada rol: se informa antes de desactivar.
        $usuariosPorRol = DB::table('usuarios')
            ->selectRaw('rol_id, count(*) as total')
            ->groupBy('rol_id')
            ->pluck('total', 'rol_id');

        // Construimos la lista COMPLETA de roles oficiales con su estado y,
        // aparte, la lista de los que faltan (siempre ordenados por ID).
        $rolesOficiales = [];
        $faltantes = [];

        foreach (self::ROLES_OFICIALES as $nombre => $idEsperado) {
            $fila = $filas[$nombre] ?? null;
            $existe = $fila !== null;

            $rolesOficiales[] = [
                'id' => $existe ? (int) $fila->id : $idEsperado,
                'id_esperado' => $idEsperado,
                'nombre' => $nombre,
                'existe' => $existe,
                // Sin la columna (migración pendiente) se considera ACTIVO.
                'activo' => $existe && (! $tieneEstado || (int) $fila->activo === 1),
                'usuarios' => $existe ? (int) ($usuariosPorRol[$fila->id] ?? 0) : 0,
            ];

            if (! $existe) {
                $faltantes[] = [
                    'id' => $idEsperado,
                    'nombre' => $nombre,
                ];
            }
        }

        $catalogoCompleto = empty($faltantes);

        return view('roles.index', compact(
            'rolesOficiales',
            'faltantes',
            'catalogoCompleto',
            'tieneEstado'
        ));
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

        $accion = (string) $request->input('accion');

        // Acciones de estado (baja lógica reversible). Se resuelven aparte
        // para no mezclarlas con la creación.
        if (in_array($accion, ['desactivar', 'reactivar'], true)) {
            return $this->cambiarEstadoRol($accion, $request);
        }

        // La única acción restante es crear. Cualquier otra (eliminar, editar,
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
    | API: DESACTIVAR / REACTIVAR ROL (BAJA LÓGICA REVERSIBLE)
    |--------------------------------------------------------------------------
    |
    | Autorización: ya se comprobó que el actor es ADMIN en `api()`.
    |
    | Reglas:
    |  - Solo se modifican roles del catálogo oficial y NUNCA ADMIN.
    |  - El rol no se borra, conserva id y nombre.
    |  - `usuarios.rol_id` no se toca: las asignaciones se conservan.
    |  - Bloquear el acceso es responsabilidad del middleware (login y
    |    sesiones abiertas), no de esta acción.
    |  - Se registra quién y cuándo (última operación de cada tipo).
    |
    */
    private function cambiarEstadoRol(string $accion, Request $request)
    {
        // Sin la columna de estado no se puede desactivar ni reactivar:
        // informamos con claridad en vez de provocar un error SQL.
        if (! Schema::hasColumn('roles', 'activo')) {
            return response()->json([
                'exito' => false,
                'error' => 'El estado de los roles todavía no está disponible (falta aplicar la migración de roles.activo).',
            ], 409);
        }

        $id = (int) $request->input('id');

        if ($id <= 0) {
            return response()->json([
                'exito' => false,
                'error' => 'Rol inválido.',
            ], 422);
        }

        $rol = DB::table('roles')->where('id', $id)->first();

        if (! $rol) {
            return response()->json([
                'exito' => false,
                'error' => 'El rol no existe.',
            ], 404);
        }

        $nombre = $this->normalizar((string) $rol->nombre);

        // Solo se administran los roles del catálogo oficial.
        if (! array_key_exists($nombre, self::ROLES_OFICIALES)) {
            return response()->json([
                'exito' => false,
                'error' => 'El rol no pertenece al catálogo oficial y no se modifica.',
            ], 422);
        }

        // ADMIN nunca se desactiva: garantiza que siempre haya un
        // administrador operativo capaz de reactivar el resto de roles.
        if ($nombre === 'ADMIN') {
            return response()->json([
                'exito' => false,
                'error' => 'El rol ADMIN no puede desactivarse: el sistema necesita siempre un administrador operativo.',
            ], 422);
        }

        $activar = $accion === 'reactivar';
        $yaActivo = (int) $rol->activo === 1;

        // Idempotencia: se informa sin volver a tocar la fila.
        if ($activar && $yaActivo) {
            return response()->json([
                'exito' => false,
                'error' => 'El rol ya está activo.',
            ], 422);
        }

        if (! $activar && ! $yaActivo) {
            return response()->json([
                'exito' => false,
                'error' => 'El rol ya está desactivado.',
            ], 422);
        }

        // Red de seguridad adicional: nunca dejar el sistema sin un
        // administrador operativo (aunque ADMIN ya está protegido arriba).
        if (! $activar && ! $this->hayAdminOperativo()) {
            return response()->json([
                'exito' => false,
                'error' => 'No se puede desactivar: el sistema quedaría sin un administrador operativo.',
            ], 422);
        }

        // Usuarios asociados: se informa aunque no se modifiquen.
        $usuarios = (int) DB::table('usuarios')->where('rol_id', $id)->count();

        $datos = ['activo' => $accion === 'reactivar' ? 1 : 0];

        // Auditoría (si la migración la incluye): QUIÉN y CUÁNDO hizo esta
        // última operación. No es un histórico completo de todas ellas.
        $prefijo = $accion === 'reactivar' ? 'reactivado' : 'desactivado';
        if (Schema::hasColumn('roles', $prefijo.'_por')) {
            $datos[$prefijo.'_por'] = Auth::id();
        }
        if (Schema::hasColumn('roles', $prefijo.'_en')) {
            $datos[$prefijo.'_en'] = now();
        }

        try {
            DB::table('roles')->where('id', $id)->update($datos);
        } catch (\Throwable $e) {
            return response()->json([
                'exito' => false,
                'error' => 'No se pudo cambiar el estado del rol.',
            ], 500);
        }

        $mensaje = $accion === 'reactivar'
            ? 'Rol reactivado. Los usuarios con este rol recuperan el acceso.'
            : 'Rol desactivado. Los usuarios con este rol no podrán iniciar sesión ni operar mientras siga inactivo.';

        return response()->json([
            'exito' => true,
            'mensaje' => $mensaje,
            'accion' => $accion,
            'rol' => $nombre,
            'usuarios' => $usuarios,
            // Quién ejecutó la operación (informativo; no es un historial).
            'operador' => (string) (Auth::user()->username ?? ''),
        ]);
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

        return $this->normalizar((string) ($usuario->rol->nombre ?? ''));
    }

    /*
     * Normaliza un nombre de rol: mayúsculas y sin espacios extremos, para
     * comparar de forma segura contra el catálogo oficial.
     */
    private function normalizar(string $valor): string
    {
        return strtoupper(trim($valor));
    }

    /*
     * ¿Existe al menos un rol ADMIN operativo (activo)? Es la garantía de que
     * el sistema nunca queda sin administrador.
     */
    private function hayAdminOperativo(): bool
    {
        $consulta = DB::table('roles')->where('nombre', 'ADMIN');

        if (Schema::hasColumn('roles', 'activo')) {
            $consulta->where('activo', 1);
        }

        return $consulta->exists();
    }

    /*
     * Nombres de los roles guardados en la base, normalizados a mayúsculas
     * y sin espacios, para comparaciones seguras.
     */
    private function nombresExistentesNormalizados(): array
    {
        return DB::table('roles')
            ->pluck('nombre')
            ->map(fn ($nombre) => $this->normalizar((string) $nombre))
            ->all();
    }
}
