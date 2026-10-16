<?php

namespace App\Services;

use App\Models\BackupRegistro;
use App\Services\Backup\DatabaseDumper;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Coordina la lógica de negocio del módulo de respaldo:
 * creación, verificación de integridad, historial, descarga y eliminación.
 *
 * La fuente de verdad de las credenciales es la configuración de Laravel
 * (config/database.php + .env); aquí nunca se escriben ni se registran.
 */
class BackupService
{
    public function __construct(private DatabaseDumper $dumper)
    {
    }

    /* ------------------------------------------------------------------
     | Consultas
     | ------------------------------------------------------------------ */

    /**
     * Indica si la herramienta de volcado está disponible.
     */
    public function herramientaDisponible(): bool
    {
        return $this->dumper->isAvailable();
    }

    /**
     * Nombre de la base de datos que se está respaldando.
     */
    public function nombreBaseDatos(): string
    {
        $conn = (string) (config('backup.connection') ?: config('database.default'));

        return (string) (config('database.connections.' . $conn . '.database') ?? 'base_de_datos');
    }

    /**
     * Historial de operaciones del módulo.
     */
    public function historial(int $limite = 200)
    {
        return BackupRegistro::query()
            ->orderByDesc('id')
            ->limit($limite)
            ->get();
    }

    /* ------------------------------------------------------------------
     | Creación de copias
     | ------------------------------------------------------------------ */

    /**
     * Crea una copia de seguridad y la verifica.
     *
     * El usuario se obtiene de la sesión autenticada, nunca del request.
     *
     * @return array{exito: bool, codigo: int, mensaje?: string, error?: string, registro?: BackupRegistro}
     */
    public function registrarCreacion(int $usuarioId, ?string $ip): array
    {
        $lock = $this->obtenerLock();

        if (!$lock->get()) {
            return $this->respuesta(409, 'Ya hay una operación de respaldo en curso. Espera unos segundos e inténtalo de nuevo.');
        }

        $inicio = microtime(true);
        $registro = null;

        try {
            $registro = BackupRegistro::create([
                'usuario_id' => $usuarioId,
                'ip' => $ip,
                'tipo' => 'CREACION',
                'estado' => 'EN_PROCESO',
                'mensaje' => 'Generando copia...',
                'iniciado_en' => now()->toDateTimeString(),
            ]);

            $disk = (string) config('backup.disk', 'local');
            $directorio = (string) config('backup.path', 'backups');

            Storage::disk($disk)->makeDirectory($directorio);

            $nombre = $this->nombreUnico($disk, $directorio);
            $absoluto = Storage::disk($disk)->path($directorio . '/' . $nombre);

            try {
                // Invoca la herramienta de volcado (simulada en las pruebas).
                $this->dumper->dumpTo($absoluto);
            } catch (\Throwable $e) {
                $this->marcarFallo($registro, $e->getMessage(), $inicio, $absoluto);

                return $this->respuesta(500, 'No fue posible generar la copia: ' . $this->mensajeSeguro($e->getMessage()));
            }

            // Verificación de integridad del archivo generado.
            $validacion = $this->validarArchivo($absoluto);

            if (!$validacion['ok']) {
                $this->marcarFallo($registro, $validacion['error'], $inicio, $absoluto);

                return $this->respuesta(500, $validacion['error']);
            }

            $registro->update([
                'estado' => 'COMPLETADO',
                'nombre_archivo' => $nombre,
                'tamano_bytes' => $validacion['tamano'],
                'hash_sha256' => $validacion['hash'],
                'duracion_ms' => (int) round((microtime(true) - $inicio) * 1000),
                'mensaje' => 'Copia generada y verificada.',
                'finalizado_en' => now()->toDateTimeString(),
            ]);

            return $this->respuesta(200, 'Copia de seguridad creada correctamente.', $registro->fresh());
        } catch (\Throwable $e) {
            if ($registro !== null) {
                $this->marcarFallo($registro, $e->getMessage(), $inicio, null);
            }

            return $this->respuesta(500, 'No fue posible crear la copia de seguridad.');
        } finally {
            $lock->release();
        }
    }

    /* ------------------------------------------------------------------
     | Descarga
     | ------------------------------------------------------------------ */

    /**
     * Devuelve la ruta absoluta real de un archivo de respaldo, o null si el
     * registro no es válido, el archivo no existe o queda fuera del directorio.
     */
    public function archivoParaDescarga(BackupRegistro $registro): ?string
    {
        if ($registro->estado !== 'COMPLETADO' || empty($registro->nombre_archivo)) {
            return null;
        }

        return $this->rutaAbsolutaValidada($registro->nombre_archivo);
    }

    /* ------------------------------------------------------------------
     | Eliminación manual
     | ------------------------------------------------------------------ */

    /**
     * Elimina manualmente una copia (solo ADMIN/GERENTE lo invoca).
     *
     * Reglas:
     *  - Solo archivos registrados en el módulo y cuyo nombre cumpla el patrón.
     *  - La copia más reciente "disponible" (COMPLETADA y con archivo real en
     *    disco) NO puede eliminarse con este flujo: protege la última copia
     *    que realmente sirve para recuperar datos.
     *  - Un registro COMPLETADO cuyo archivo ya no exista en disco NO cuenta
     *    como copia disponible: puede eliminarse para limpiar el historial.
     *  - La operación queda auditada.
     */
    public function eliminar(int $registroId, int $usuarioId, ?string $ip): array
    {
        $lock = $this->obtenerLock();

        if (!$lock->get()) {
            return $this->respuesta(409, 'Hay una operación de respaldo en curso. Espera unos segundos e inténtalo de nuevo.');
        }

        try {
            $registro = BackupRegistro::find($registroId);

            if (!$registro || $registro->estado !== 'COMPLETADO') {
                return $this->respuesta(404, 'El respaldo no existe o no puede eliminarse.');
            }

            if (!self::nombreValido((string) $registro->nombre_archivo)) {
                return $this->respuesta(422, 'El registro no corresponde a un archivo gestionado por el módulo.');
            }

            // La copia protegida es la más reciente COMPLETADA cuyo archivo
            // existe de verdad en disco (la "última copia disponible"). Se usa
            // el id (orden de inserción) y no una fecha ni el nombre del
            // archivo: así se evita depender de zonas horarias o del formato
            // del nombre, y se excluyen automáticamente las copias incompletas
            // (EN_PROCESO) y fallidas (FALLIDO) del filtro de estado.
            $copiaProtegida = BackupRegistro::query()
                ->where('estado', 'COMPLETADO')
                ->orderByDesc('id')
                ->get()
                ->first(function (BackupRegistro $c) {
                    $ruta = $this->rutaAbsolutaPermitida((string) $c->nombre_archivo);

                    return $ruta !== null && is_file($ruta);
                });

            if ($copiaProtegida !== null && (int) $copiaProtegida->id === (int) $registro->id) {
                return $this->respuesta(409, 'No se puede eliminar la copia más reciente. Genera una nueva copia antes de eliminar esta.');
            }

            // Ruta segura dentro del directorio, sin exigir que el archivo exista.
            $ruta = $this->rutaAbsolutaPermitida((string) $registro->nombre_archivo);

            if ($ruta === null) {
                return $this->respuesta(422, 'El archivo no pertenece al directorio de respaldos.');
            }

            $archivoExistia = is_file($ruta);

            if ($archivoExistia && !$this->eliminarArchivo($ruta)) {
                // La copia más reciente y el historial permanecen intactos:
                // un fallo de borrado NUNCA debe presentarse como éxito.
                return $this->respuesta(500, 'No fue posible eliminar el archivo de la copia.');
            }

            // Si el archivo ya no existía en disco no hay nada que borrar,
            // pero el historial se actualiza igual y se audita la operación.
            $mensaje = $archivoExistia
                ? 'Copia eliminada manualmente.'
                : 'Copia eliminada: el archivo ya no existía en disco.';

            // Se registra la eliminación y se marca el historial original.
            $registro->update([
                'estado' => 'ELIMINADO',
                'mensaje' => $mensaje,
                'finalizado_en' => now()->toDateTimeString(),
            ]);

            BackupRegistro::create([
                'usuario_id' => $usuarioId,
                'ip' => $ip,
                'tipo' => 'ELIMINACION',
                'estado' => 'ELIMINADO',
                'nombre_archivo' => $registro->nombre_archivo,
                'mensaje' => 'Eliminación manual autorizada por ' . $usuarioId . '.',
                'iniciado_en' => now()->toDateTimeString(),
                'finalizado_en' => now()->toDateTimeString(),
            ]);

            return $this->respuesta(200, 'Copia eliminada correctamente.');
        } catch (\Throwable $e) {
            return $this->respuesta(500, 'No fue posible eliminar la copia.');
        } finally {
            $lock->release();
        }
    }

    /**
     * Elimina físicamente el archivo del respaldo.
     *
     * Está separado para poder simular fallos de borrado en las pruebas
     * sobrescribiendo este método sin tocar la lógica de negocio.
     */
    protected function eliminarArchivo(string $ruta): bool
    {
        if (!is_file($ruta)) {
            return true;
        }

        return unlink($ruta);
    }

    /* ------------------------------------------------------------------
     | Validaciones reutilizables (públicas para pruebas Unit)
     | ------------------------------------------------------------------ */

    /**
     * Nombre de archivo controlado, generado Y validado en el servidor.
     *
     * Ejemplo: backup_sistemadecaja_20261012_163000.sql
     */
    public static function generarNombre(string $database, \DateTimeInterface $fecha): string
    {
        $db = preg_replace('/[^A-Za-z0-9_]/', '', $database) ?: 'base_de_datos';

        return 'backup_' . $db . '_' . $fecha->format('Ymd_His') . '.sql';
    }

    /**
     * Valida que un nombre de archivo sea seguro y pertenezca al patrón del módulo.
     */
    public static function nombreValido(string $nombre): bool
    {
        if ($nombre === '' || $nombre !== basename($nombre)) {
            return false;
        }

        if (str_contains($nombre, '..') || str_contains($nombre, '/') || str_contains($nombre, '\\')) {
            return false;
        }

        return (bool) preg_match('/^backup_[A-Za-z0-9_]+_\d{8}_\d{6}(_\d+)?\.sql$/', $nombre);
    }

    /**
     * Indica si el contenido tiene estructura de volcado MySQL.
     * No basta el tamaño ni la extensión: se inspecciona el contenido.
     */
    public static function pareceVolcadoMysql(string $absoluto): bool
    {
        $manejador = @fopen($absoluto, 'rb');

        if ($manejador === false) {
            return false;
        }

        $muestra = fread($manejador, 65536);
        fclose($manejador);

        if ($muestra === false || $muestra === '') {
            return false;
        }

        foreach (['MySQL dump', 'CREATE TABLE', 'INSERT INTO', 'LOCK TABLES'] as $marca) {
            if (stripos($muestra, $marca) !== false) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------
     | Métodos internos
     | ------------------------------------------------------------------ */

    private function obtenerLock(): Lock
    {
        return Cache::lock('el-lobito:backup:operacion', (int) config('backup.lock_seconds', 60));
    }

    private function respuesta(int $codigo, string $mensaje, ?BackupRegistro $registro = null): array
    {
        $base = ['exito' => $codigo >= 200 && $codigo < 300, 'codigo' => $codigo];

        if ($codigo >= 200 && $codigo < 300) {
            $base['mensaje'] = $mensaje;
        } else {
            $base['error'] = $mensaje;
        }

        if ($registro !== null) {
            $base['registro'] = $registro;
        }

        return $base;
    }

    /**
     * Genera un nombre único dentro del directorio (agrega sufijos _1, _2...).
     */
    private function nombreUnico(string $disk, string $directorio): string
    {
        $base = self::generarNombre($this->nombreBaseDatos(), now());
        $nombre = $base;
        $i = 1;

        while (Storage::disk($disk)->exists($directorio . '/' . $nombre)) {
            $nombre = preg_replace('/\.sql$/', '_' . $i . '.sql', $base);
            $i++;
        }

        return $nombre;
    }

    /**
     * Verifica tamaño mínimo, hash SHA-256 y contenido tipo MySQL.
     *
     * @return array{ok: bool, error?: string, tamano?: int, hash?: string}
     */
    private function validarArchivo(string $absoluto): array
    {
        if (!is_file($absoluto)) {
            return ['ok' => false, 'error' => 'El archivo de respaldo no fue creado.'];
        }

        $tamano = filesize($absoluto);
        $minimo = (int) config('backup.min_bytes', 2048);

        if ($tamano === false || $tamano < $minimo) {
            return ['ok' => false, 'error' => 'El archivo de respaldo está vacío o es demasiado pequeño.'];
        }

        $hash = hash_file('sha256', $absoluto);

        if ($hash === false) {
            return ['ok' => false, 'error' => 'No fue posible calcular la integridad del archivo.'];
        }

        if (!self::pareceVolcadoMysql($absoluto)) {
            return ['ok' => false, 'error' => 'El archivo no tiene estructura de volcado MySQL.'];
        }

        return ['ok' => true, 'tamano' => $tamano, 'hash' => $hash];
    }

    /**
     * Marca un registro como FALLIDO, elimina el archivo parcial y cierra tiempos.
     */
    private function marcarFallo(BackupRegistro $registro, string $mensaje, float $inicio, ?string $absoluto): void
    {
        if ($absoluto !== null && is_file($absoluto)) {
            @unlink($absoluto);
        }

        $registro->update([
            'estado' => 'FALLIDO',
            'mensaje' => Str::limit($this->mensajeSeguro($mensaje), 490),
            'duracion_ms' => (int) round((microtime(true) - $inicio) * 1000),
            'finalizado_en' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Devuelve la ruta absoluta real de un archivo solo si:
     *  - el nombre es válido según el patrón del módulo;
     *  - el archivo existe;
     *  - la ruta resuelta queda dentro del directorio de respaldos.
     */
    private function rutaAbsolutaValidada(string $nombre): ?string
    {
        $permitida = $this->rutaAbsolutaPermitida($nombre);

        if ($permitida === null || !is_file($permitida)) {
            return null;
        }

        return $permitida;
    }

    /**
     * Devuelve la ruta absoluta de un archivo dentro del directorio de
     * respaldos, sin exigir que el archivo exista (a diferencia de
     * rutaAbsolutaValidada). Requiere:
     *  - nombre válido según el patrón del módulo;
     *  - que la ruta resuelta quede dentro del directorio de respaldos.
     *
     * Permite distinguir "el archivo ya no existe en disco" (ruta válida pero
     * is_file(...) === false) de "el archivo no pertenece al directorio".
     */
    private function rutaAbsolutaPermitida(string $nombre): ?string
    {
        if (!self::nombreValido($nombre)) {
            return null;
        }

        $disk = (string) config('backup.disk', 'local');
        $directorio = (string) config('backup.path', 'backups');
        $absoluto = Storage::disk($disk)->path($directorio . '/' . $nombre);

        // Si el directorio no existe todavía, no hay contra qué normalizar la
        // ruta; nombreValido ya bloquea cualquier intento de salida del patrón.
        $realDirectorio = realpath(Storage::disk($disk)->path($directorio));

        if ($realDirectorio === false) {
            return $absoluto;
        }

        $realArchivo = realpath($absoluto);
        $candidata = $realArchivo === false
            ? $this->rutaHijaDeDirectorio($absoluto, $realDirectorio)
            : $realArchivo;

        if ($candidata === null) {
            return null;
        }

        $separador = DIRECTORY_SEPARATOR;

        if ($candidata !== $realDirectorio && !str_starts_with($candidata, rtrim($realDirectorio, $separador) . $separador)) {
            return null;
        }

        return $candidata;
    }

    /**
     * Para un archivo que no existe todavía, deriva su ruta desde el directorio
     * padre REAL (normalizado) reuniendo el nombre, de modo que la comparación
     * contra realpath del directorio sea consistente en cualquier sistema.
     */
    private function rutaHijaDeDirectorio(string $absoluto, string $realDirectorio): ?string
    {
        $realPadre = realpath(dirname($absoluto));

        if ($realPadre === false) {
            return null;
        }

        $separador = DIRECTORY_SEPARATOR;
        $prefijo = rtrim($realDirectorio, $separador) . $separador;

        if ($realPadre !== rtrim($realDirectorio, $separador) && !str_starts_with($realPadre, $prefijo)) {
            return null;
        }

        return $realPadre . $separador . basename($absoluto);
    }

    /**
     * Sanitiza un mensaje antes de guardarlo: sin saltos de línea ni colapsos.
     */
    private function mensajeSeguro(string $mensaje): string
    {
        return trim(preg_replace('/\s+/', ' ', $mensaje) ?? '') ?: 'Error desconocido.';
    }
}