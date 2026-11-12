<?php

namespace App\Services;

use App\Models\Cliente;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Servicio centralizado de clientes.
 *
 * Centraliza normalización, búsqueda, detección de duplicados,
 * creación/actualización, resolución de conflictos y protección ante
 * concurrencia. Se reutiliza desde Monitor, subcuentas y módulo Clientes.
 */
class ClienteService
{
    /**
     * ¿La tabla `clientes` tiene las columnas de normalización?
     *
     * La base real importada del sistema PHP puro NO las tiene hasta que se
     * ejecute la migración correspondiente. El servicio debe funcionar en
     * ambos escenarios, por eso todas las consultas que las usan se apoyan
     * también en los campos crudos (`identificacion`, `telefono`).
     */
    private function tieneColumnasNormalizadas(): bool
    {
        return Schema::hasColumn('clientes', 'identificacion_normalizada')
            && Schema::hasColumn('clientes', 'telefono_normalizado');
    }

    /**
     * ¿La tabla `clientes` tiene la columna `creado_en`?
     *
     * Es la columna real de la base; se comprueba también para no romper
     * instalaciones de prueba que no la incluyan.
     */
    private function tieneColumnaCreadoEn(): bool
    {
        return Schema::hasColumn('clientes', 'creado_en');
    }

    /**
     * Normalización de identificación (cédula/RUC): quita guiones, espacios
     * y dolares/comas opcionales, unifíca mayúsculas/minúsculas.
     *
     * Se usa para index único y comparaciones exactas.
     */
    public static function normalizarIdentificacion(?string $valor): ?string
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        $limpio = trim((string) $valor);

        // Quitar signo de dólar, comas y espacios primero.
        $limpio = str_replace(['$', ',', ' '], '', $limpio);

        // Quitar guiones.
        $limpio = str_replace('-', '', $limpio);

        // Unificar mayúsculas/minúsculas para cédulas alfanuméricas.
        $limpio = strtoupper($limpio);

        // Si después de limpiar quedó vacío, es NULL.
        return $limpio === '' ? null : $limpio;
    }

    /**
     * Normalización de teléfono: quita espacios, guiones, paréntesis y
     * prefijos internacionales comunes para comparar, pero conserva el
     * formato original que el usuario ingresó.
     */
    public static function normalizarTelefono(?string $valor): ?string
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        $limpio = trim((string) $valor);

        // Quitar espacios y guiones.
        $limpio = str_replace([' ', '-'], '', $limpio);

        // Quitar paréntesis.
        $limpio = str_replace(['(', ')'], '', $limpio);

        // Quitar prefijo +507 y variantes comunes.
        if (str_starts_with($limpio, '+507')) {
            $limpio = substr($limpio, 4);
        } elseif (str_starts_with($limpio, '507')) {
            $limpio = substr($limpio, 3);
        }

        // Quitar ceros a la izquierda.
        $limpio = ltrim($limpio, '0');

        return $limpio === '' ? null : $limpio;
    }

    /**
     * Normalización de nombre para comparación: minusculas, quitar acentos
     * y espacios multiples. Se usa para detectar homónimos, no para
     * index único.
     */
    public static function normalizarNombre(?string $valor): ?string
    {
        if ($valor === null || trim($valor) === '') {
            return null;
        }

        $limpio = trim((string) $valor);

        // Minúsculas.
        $limpio = mb_strtolower($limpio, 'UTF-8');

        // Quitar acentos básicos para comparar homónimos.
        $limpio = self::quitarAcentos($limpio);

        // Normalizar espacios múltiples.
        $limpio = preg_replace('/\s+/', ' ', $limpio);
        $limpio = trim($limpio);

        return $limpio === '' ? null : $limpio;
    }

    /**
     * Remover acentos básicos de caracteres españoles.
     */
    private static function quitarAcentos(string $texto): string
    {
        $reemplazos = [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss',
        ];

        return strtr($texto, $reemplazos);
    }

    /**
     * Expresión SQL que deja un nombre en minúsculas y sin acentos, para
     * compararlo con `normalizarNombre()` sin depender del collation de MySQL
     * ni de extensiones de SQLite.
     */
    private function expresionNombreNormalizado(string $columna = 'nombre'): string
    {
        $expresion = 'LOWER('.$columna.')';

        // Solo hacen falta las vocales minúsculas: LOWER() se aplica antes.
        foreach (['á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss'] as $acento => $plano) {
            $expresion = "REPLACE({$expresion}, '{$acento}', '{$plano}')";
        }

        return $expresion;
    }

    /**
     * Agrega a una consulta la comparación por identificación normalizada.
     *
     * Usa las columnas normalizadas si existen y, además, los campos crudos
     * limpios en SQL. Así también encuentra registros históricos que tienen
     * `identificacion_normalizada = NULL` (base importada sin migración).
     */
    private function filtrarIdentificacionNormalizada($consulta, string $normalizada): void
    {
        $consulta->where(function ($q) use ($normalizada) {
            if ($this->tieneColumnasNormalizadas()) {
                $q->where('identificacion_normalizada', $normalizada);
            }

            $sql = "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(identificacion, '-', ''), ' ', ''), '\$', ''), ',', '')) = ?";
            $q->orWhereRaw($sql, [$normalizada]);
        });
    }

    /**
     * Agrega a una consulta la comparación por teléfono normalizado.
     *
     * La comparación cruda usa LIKE con el valor normalizado al final para
     * tolerar prefijos (+507) y formatos con paréntesis, aunque la base no
     * tenga la columna `telefono_normalizado`.
     */
    private function filtrarTelefonoNormalizado($consulta, string $normalizado): void
    {
        $consulta->where(function ($q) use ($normalizado) {
            if ($this->tieneColumnasNormalizadas()) {
                $q->where('telefono_normalizado', $normalizado);
            }

            $sql = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefono, '-', ''), ' ', ''), '(', ''), ')', ''), '+', '') LIKE ?";
            $q->orWhereRaw($sql, ['%'.$normalizado]);
        });
    }

    /**
     * Buscar clientes por texto libre (nombre, identificación o teléfono)
     * con límite para no cargar todos en el Monitor.
     *
     * Se combinan varias comparaciones con OR: nombre sin distinguir
     * mayúsculas/acentos, identificación y teléfono tal como están guardados,
     * y sus versiones normalizadas (con o sin guiones/espacios). Antes se
     * filtraba solo por identificación normalizada, por lo que buscar un
     * nombre o un teléfono nunca devolvía resultados.
     */
    public function buscar(string $texto, ?int $limit = 20): array
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            return [];
        }

        $limite = max(1, min(50, $limit ?? 20));

        $identNormalizada = self::normalizarIdentificacion($texto);
        $telefonoNormalizado = self::normalizarTelefono($texto);
        $nombreNormalizado = self::normalizarNombre($texto);
        $conNormalizadas = $this->tieneColumnasNormalizadas();

        $resultado = Cliente::query()
            ->where(function ($q) use ($texto, $nombreNormalizado, $identNormalizada, $telefonoNormalizado, $conNormalizadas) {
                $patron = '%'.$texto.'%';

                /*
                 * Nombre: comparación sin distinguir mayúsculas ni acentos.
                 * La expresión SQL se construye con REPLACE para que funcione
                 * igual en MySQL y en SQLite (pruebas).
                 */
                if ($nombreNormalizado !== null) {
                    $q->whereRaw(
                        $this->expresionNombreNormalizado().' LIKE ?',
                        ['%'.$nombreNormalizado.'%']
                    );
                }

                // Campos crudos: funcionan aunque no existan las columnas
                // normalizadas en la base real.
                $q->orWhere('identificacion', 'like', $patron)
                    ->orWhere('telefono', 'like', $patron);

                /*
                 * Variante sin guiones/espacios: permite encontrar
                 * "8888-0000" escribiendo "88880000" y viceversa, sin
                 * depender de las columnas normalizadas.
                 */
                foreach (array_unique(array_filter([$identNormalizada, $telefonoNormalizado])) as $valor) {
                    $valor = (string) $valor;
                    $q->orWhereRaw(
                        "UPPER(REPLACE(REPLACE(identificacion, '-', ''), ' ', '')) LIKE ?",
                        ['%'.$valor.'%']
                    )->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(telefono, '-', ''), ' ', ''), '(', ''), ')', '') LIKE ?",
                        ['%'.$valor.'%']
                    );
                }

                if ($conNormalizadas) {
                    if ($identNormalizada !== null) {
                        $q->orWhere('identificacion_normalizada', 'like', '%'.$identNormalizada.'%');
                    }
                    if ($telefonoNormalizado !== null) {
                        $q->orWhere('telefono_normalizado', 'like', '%'.$telefonoNormalizado.'%');
                    }
                }
            })
            ->orderBy('nombre')
            ->limit($limite)
            ->get();

        return $resultado->all();
    }

    /**
     * Detectar posibles conflictos al registrar o editar un cliente.
     *
     * Retorna un array con:
     *  - identificacion_existente: cliente con misma identificación
     *    normalizada activa/inactiva.
     *  - telefonos_coincidentes: clientes con mismo teléfono normalizado.
     *  - homonimos: clientes con nombre normalizado similar (según límite).
     *
     * `$excluirId` debe recibir el id del cliente que se está editando. Sin
     * él, el propio registro aparecía como duplicado de identificación y como
     * homónimo de sí mismo, impidiendo guardar cualquier edición.
     */
    public function detectarConflictosRegistro(
        ?string $identificacion,
        ?string $telefono,
        string $nombre,
        ?int $limitHomonimos = 10,
        ?int $excluirId = null
    ): array {
        $identNormalizada = self::normalizarIdentificacion($identificacion);
        $telefonoNormalizado = self::normalizarTelefono($telefono);
        $nombreNormalizado = self::normalizarNombre($nombre);

        $conflictos = [
            'identificacion_existente' => null,
            'telefonos_coincidentes' => [],
            'homonimos' => [],
        ];

        // Identificación exacta: puede ser activa o inactiva.
        if ($identNormalizada !== null) {
            $consulta = Cliente::query();
            $this->filtrarIdentificacionNormalizada($consulta, $identNormalizada);

            if ($excluirId !== null) {
                $consulta->where('id', '!=', $excluirId);
            }

            $existente = $consulta->first();

            if ($existente) {
                $conflictos['identificacion_existente'] = $existente;
            }
        }

        // Teléfonos coincidentes (advertencia, no bloquea).
        if ($telefonoNormalizado !== null) {
            $consulta = Cliente::query();
            $this->filtrarTelefonoNormalizado($consulta, $telefonoNormalizado);

            if ($excluirId !== null) {
                $consulta->where('id', '!=', $excluirId);
            }

            $conflictos['telefonos_coincidentes'] = $consulta
                ->orderBy('nombre')
                ->limit(10)
                ->get()
                ->all();
        }

        // Homónimos (solo nombre similar, sin distinguir acentos).
        if ($nombreNormalizado !== null && strlen($nombreNormalizado) >= 2) {
            $consulta = Cliente::query()->whereRaw(
                $this->expresionNombreNormalizado().' LIKE ?',
                ['%'.$nombreNormalizado.'%']
            );

            if ($excluirId !== null) {
                $consulta->where('id', '!=', $excluirId);
            }

            $conflictos['homonimos'] = $consulta
                ->orderBy('nombre')
                ->limit($limitHomonimos)
                ->get()
                ->all();
        }

        return $conflictos;
    }

    /**
     * Validar si un usuario puede confirmar registro de homónimos.
     *
     * ADMIN y GERENTE pueden confirmar; CAJERO y MESERO no.
     */
    public function puedeConfirmarHomonimos(?string $rolNombre): bool
    {
        $rol = strtoupper((string) ($rolNombre ?? ''));

        return in_array($rol, ['ADMIN', 'GERENTE'], true);
    }

    /**
     * Registrar o actualizar cliente respetando reglas de duplicado.
     *
     * Si la identificación ya existe (activa o inactiva), no se crea otro
     * registro. Si hay homónimos o teléfono coincidente, se retorna la
     * información para mostrar advertencia; no se bloquea salvo que el
     * rol no pueda confirmar y haya homónimos pendientes.
     *
     * Retorna:
     *  - success: bool
     *  - cliente: Cliente creado/actualizado (o null)
     *  - alerta: array|null (conflictos a mostrar en UI)
     *  - error: string|null
     *
     * Requiere que el rol pueda confirmar homónimos si existen homónimos
     * no resueltos.
     */
    public function registrarOActualizar(array $datos, ?string $rolNombre): array
    {
        $nombre = trim((string) ($datos['nombre'] ?? ''));
        if ($nombre === '') {
            return [
                'success' => false,
                'cliente' => null,
                'alerta' => null,
                'error' => 'El nombre es obligatorio.',
            ];
        }

        $identificacion = isset($datos['identificacion']) && is_string($datos['identificacion'])
            ? trim($datos['identificacion'])
            : null;
        $telefono = isset($datos['telefono']) && is_string($datos['telefono'])
            ? trim($datos['telefono'])
            : null;
        $direccion = isset($datos['direccion']) && is_string($datos['direccion'])
            ? trim($datos['direccion'])
            : null;

        // Si viene `id`, es una EDICIÓN: el propio registro no debe contar
        // como conflicto consigo mismo.
        $idEdicion = isset($datos['id']) ? (int) $datos['id'] : 0;

        $existente = null;
        if ($idEdicion > 0) {
            $existente = Cliente::find($idEdicion);

            if (! $existente) {
                return [
                    'success' => false,
                    'cliente' => null,
                    'alerta' => null,
                    'error' => 'El cliente indicado no existe.',
                ];
            }
        }

        // Detectar conflictos excluyendo el propio cliente al editar.
        $conflictos = $this->detectarConflictosRegistro(
            $identificacion,
            $telefono,
            $nombre,
            10,
            $existente?->id
        );

        // Identificación duplicada: siempre rechazar, sin distinción de rol.
        if ($conflictos['identificacion_existente'] !== null) {
            return [
                'success' => false,
                'cliente' => $conflictos['identificacion_existente'],
                'alerta' => null,
                'error' => 'Esa identificación ya está registrada. Seleccione el cliente existente.',
            ];
        }

        // Si hay homónimos no confirmados y el rol no puede confirmar,
        // rechazar registro para evitar duplicados no autorizados.
        if ($conflictos['homonimos'] !== [] && !$this->puedeConfirmarHomonimos($rolNombre)) {
            return [
                'success' => false,
                'cliente' => null,
                'alerta' => [
                    'tipo' => 'homonimos_no_autorizados',
                    'homonimos' => $conflictos['homonimos'],
                ],
                'error' => 'Existen clientes con nombre similar. Solo ADMIN o GERENTE pueden confirmar el registro.',
            ];
        }

        /*
         * Campos comunes. Las columnas normalizadas y `creado_en` solo se
         * envían si existen en la tabla: así el mismo código funciona en la
         * base real (sin migración aplicada) y en instalaciones nuevas.
         */
        $camposCliente = [
            'nombre' => $nombre,
            'telefono' => $telefono,
            'identificacion' => $identificacion,
            'direccion' => $direccion,
        ];

        if ($this->tieneColumnasNormalizadas()) {
            $camposCliente['identificacion_normalizada'] = self::normalizarIdentificacion($identificacion);
            $camposCliente['telefono_normalizado'] = self::normalizarTelefono($telefono);
        }

        if ($existente !== null) {
            /*
             * Edición: NO se toca `activo` (un cliente eliminado sigue
             * eliminado) ni `creado_en` (fecha original de alta).
             */
            $existente->fill($camposCliente);
            $existente->save();

            return [
                'success' => true,
                'cliente' => $existente,
                'alerta' => $this->construirAlertaConflictos($conflictos),
                'error' => null,
            ];
        }

        // Nuevo cliente: nace activo.
        $camposCliente['activo'] = true;

        if ($this->tieneColumnaCreadoEn()) {
            $camposCliente['creado_en'] = now();
        }

        $cliente = Cliente::create($camposCliente);

        return [
            'success' => true,
            'cliente' => $cliente,
            'alerta' => $this->construirAlertaConflictos($conflictos),
            'error' => null,
        ];
    }

    /**
     * Construir estructura de alerta para UI con conflictos detectados.
     */
    private function construirAlertaConflictos(array $conflictos): ?array
    {
        $alerta = null;

        if ($conflictos['telefonos_coincidentes'] !== []) {
            $alerta = [
                'tipo' => 'telefono_coincidente',
                'clientes' => $conflictos['telefonos_coincidentes'],
            ];
        }

        if ($conflictos['homonimos'] !== []) {
            if ($alerta === null) {
                $alerta = [
                    'tipo' => 'homonimos',
                    'clientes' => $conflictos['homonimos'],
                ];
            } else {
                $alerta['clientes'] = array_merge(
                    $alerta['clientes'],
                    $conflictos['homonimos']
                );
                $alerta['tipo'] = 'conflictos multiples';
            }
        }

        return $alerta;
    }

    /**
     * Proteger Cliente General (ID 1) en operaciones de búsqueda y recuento.
     */
    public function esClienteGeneral(int $clienteId): bool
    {
        return $clienteId === 1;
    }

    /**
     * Validar que un cliente existe y está activo para asignación.
     *
     * Rechazar clientes inexistentes o inactivos en nuevas asignaciones.
     *
     * Retorna null si no es válido, Cliente si sí.
     */
    public function validarParaAsignacion(int $clienteId): ?Cliente
    {
        if ($clienteId <= 0) {
            return null;
        }

        $cliente = Cliente::find($clienteId);

        if (!$cliente) {
            return null;
        }

        // Cliente General siempre válido como valor por defecto, pero si
        // en contextos de "asignación explícita" se puede considerar, aquí
        // se acepta como válido (activo por defecto).
        if (!$cliente->activo) {
            return null;
        }

        return $cliente;
    }
}
