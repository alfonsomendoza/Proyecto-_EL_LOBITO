<?php

namespace App\Services;

use App\Exceptions\ImportacionProductosException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Importación de productos desde Excel (.xlsx) o CSV.
 *
 * El servicio hace DOS cosas con la MISMA validación:
 *  1. `analizar()` lee y valida el archivo para la vista previa (no escribe).
 *  2. `confirmar()` vuelve a validar las filas guardadas en la sesión y crea
 *     únicamente las que siguen siendo válidas, dentro de una transacción.
 *
 * Columnas reales que se importan (esquema `productos`):
 *  - categoria        : nombre de una categoría EXISTENTE y ACTIVA.
 *  - nombre           : varchar(100).
 *  - precio           : decimal(12,2), >= 0.
 *  - requiere_cocina  : 0/1 o SI/NO.
 *
 * `activo` nace en 1 y `controla_stock` en 0. NO se crean existencias ni
 * movimientos de inventario: esa es una decisión de negocio separada.
 * Un producto existente NUNCA se actualiza ni se duplica.
 */
class ProductoImportService
{
    public const MAX_FILAS = 1000;

    public const MAX_BYTES = 2 * 1024 * 1024;

    /** Columnas obligatorias, en el orden de la plantilla oficial. */
    public const COLUMNAS = ['categoria', 'nombre', 'precio', 'requiere_cocina'];

    public function __construct(private ProductoXlsxService $xlsx) {}

    /**
     * Plantilla oficial .xlsx. Las categorías se consultan en cada descarga:
     * solo se incluyen las que están activas y se usan como desplegable.
     *
     * La hoja Productos deja el área A4:D1003 libre para el usuario y coloca
     * los ejemplos en F:I, fuera del rango que lee el importador.
     */
    public function plantillaXlsx(): string
    {
        $categorias = DB::table('categorias')
            ->where('activa', 1)
            ->orderBy('nombre')
            ->pluck('nombre')
            ->map(fn ($nombre): string => (string) $nombre)
            ->all();

        return $this->xlsx->generar($categorias);
    }

    /**
     * Lee y valida el archivo sin escribir en la base de datos.
     *
     * @return array{filas: array<int, array<string, mixed>>, filas_crudas: array<int, array<string, mixed>>, resumen: array<string, int>}
     */
    public function analizar(UploadedFile $archivo): array
    {
        return $this->evaluar($this->leer($archivo));
    }

    /**
     * Vuelve a validar las filas y crea solo las válidas.
     *
     * La transacción garantiza que un fallo de base de datos no deje una
     * importación a medias: o se insertan todas las filas válidas o ninguna.
     *
     * @param  array<int, array<string, mixed>>  $filasCrudas
     * @return array{importados: int, omitidos: int, resumen: array<string, int>, filas: array<int, array<string, mixed>>}
     */
    public function confirmar(array $filasCrudas): array
    {
        $analisis = $this->evaluar($filasCrudas);
        $validas = array_values(array_filter(
            $analisis['filas'],
            fn (array $fila): bool => $fila['estado'] === 'valida'
        ));

        $importados = 0;

        if ($validas !== []) {
            DB::transaction(function () use ($validas, &$importados): void {
                foreach ($validas as $fila) {
                    DB::table('productos')->insert([
                        'categoria_id' => (int) $fila['categoria_id'],
                        'nombre' => (string) $fila['nombre'],
                        'precio' => (float) $fila['precio'],
                        'requiere_cocina' => (int) $fila['requiere_cocina'] ? 1 : 0,
                        'controla_stock' => 0,
                        'activo' => 1,
                    ]);

                    $importados++;
                }
            });
        }

        return [
            'importados' => $importados,
            'omitidos' => count($analisis['filas']) - $importados,
            'resumen' => $analisis['resumen'],
            'filas' => $analisis['filas'],
        ];
    }

    /* ------------------------------------------------------------------
     | Lectura del archivo
     | ------------------------------------------------------------------ */

    /**
     * @return array<int, array{numero: int, categoria: string, nombre: string, precio: string, requiere_cocina: string, error_estructura: ?string}>
     */
    private function leer(UploadedFile $archivo): array
    {
        if (! $archivo->isValid()) {
            throw new ImportacionProductosException('El archivo no se cargó correctamente. Vuelve a seleccionarlo.');
        }

        $ruta = $archivo->getRealPath();
        $tamano = ($ruta !== false && is_readable($ruta)) ? filesize($ruta) : false;

        if ($ruta === false || ! is_readable($ruta) || $tamano === false) {
            throw new ImportacionProductosException('No se pudo leer el archivo.');
        }

        if ($tamano === 0) {
            throw new ImportacionProductosException('El archivo está vacío.');
        }

        if ($tamano > self::MAX_BYTES) {
            throw new ImportacionProductosException('El archivo supera el tamaño máximo de 2 MB.');
        }

        $extension = strtolower((string) $archivo->getClientOriginalExtension());

        if (! in_array($extension, ['csv', 'txt', 'xlsx'], true)) {
            throw new ImportacionProductosException('El archivo debe ser .xlsx, .csv o .txt.');
        }

        // El Excel se lee por su estructura interna (ZIP + XML), no por el
        // nombre: un archivo renombrado a .xlsx no pasa esta validación.
        if ($extension === 'xlsx') {
            return $this->leerXlsx($ruta);
        }

        $handle = @fopen($ruta, 'rb');

        if ($handle === false) {
            throw new ImportacionProductosException('No se pudo abrir el archivo.');
        }

        try {
            $cabecera = fgetcsv($handle, 0, ',', '"', '\\');

            if ($cabecera === false) {
                throw new ImportacionProductosException('El archivo no contiene un encabezado CSV válido.');
            }

            $cabecera = array_map(fn ($valor): string => $this->limpiarCabecera($valor), $cabecera);

            // Excel suele guardar el BOM UTF-8 al inicio de la primera celda.
            if (isset($cabecera[0])) {
                $cabecera[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cabecera[0]) ?? $cabecera[0];
            }

            // Se ignoran columnas vacías al final (coma final accidental).
            while ($cabecera !== [] && end($cabecera) === '') {
                array_pop($cabecera);
            }

            if ($cabecera === [] || ! $this->esUtf8($cabecera)) {
                throw new ImportacionProductosException('El encabezado del archivo no está en UTF-8 o está vacío.');
            }

            if (in_array('', $cabecera, true)) {
                throw new ImportacionProductosException('Hay una columna del encabezado sin nombre.');
            }

            if (count($cabecera) !== count(array_unique($cabecera))) {
                throw new ImportacionProductosException('El encabezado tiene columnas repetidas.');
            }

            $faltantes = array_values(array_diff(self::COLUMNAS, $cabecera));

            if ($faltantes !== []) {
                throw new ImportacionProductosException(
                    'Faltan columnas obligatorias: '.implode(', ', $faltantes).'.'
                );
            }

            $sobrantes = array_values(array_diff($cabecera, self::COLUMNAS));

            if ($sobrantes !== []) {
                throw new ImportacionProductosException(
                    'El archivo contiene columnas no reconocidas: '.implode(', ', $sobrantes).'.'
                );
            }

            $indices = array_flip($cabecera);
            $filas = [];
            $numero = 1;

            while (($datos = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $numero++;

                // Las líneas totalmente vacías no son filas de producto.
                if ($datos === [null] || (count($datos) === 1 && trim((string) $datos[0]) === '')) {
                    continue;
                }

                $errorEstructura = null;

                if (count($datos) !== count($cabecera)) {
                    $errorEstructura = 'Se esperaban '.count($cabecera)
                        .' columnas y se encontraron '.count($datos).'.';
                }

                $celdas = [];

                foreach (self::COLUMNAS as $columna) {
                    $celdas[$columna] = $this->limpiarCelda($datos[$indices[$columna]] ?? '');
                }

                if (! $this->esUtf8($celdas)) {
                    $errorEstructura = trim(
                        ($errorEstructura !== null ? $errorEstructura.' ' : '')
                        .'El contenido de la fila no está en UTF-8.'
                    );
                }

                $filas[] = [
                    'numero' => $numero,
                    'categoria' => $celdas['categoria'],
                    'nombre' => $celdas['nombre'],
                    'precio' => $celdas['precio'],
                    'requiere_cocina' => $celdas['requiere_cocina'],
                    'error_estructura' => $errorEstructura,
                ];

                if (count($filas) > self::MAX_FILAS) {
                    throw new ImportacionProductosException(
                        'El archivo supera el máximo de '.self::MAX_FILAS.' filas de productos.'
                    );
                }
            }

            if ($filas === []) {
                throw new ImportacionProductosException('El archivo no contiene filas de productos.');
            }

            return $filas;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Lee la hoja Productos y descarta todo lo que esté fuera de A:D.
     *
     * El Excel oficial lleva instrucciones arriba y ejemplos en F:I. Por eso
     * se localiza la fila de encabezados (en la plantilla es la 3) y solo se
     * leen las columnas A, B, C y D desde la fila siguiente.
     *
     * @return array<int, array{numero: int, categoria: string, nombre: string, precio: string, requiere_cocina: string, error_estructura: ?string}>
     */
    private function leerXlsx(string $ruta): array
    {
        $filasExcel = $this->xlsx->leerFilas($ruta);

        if ($filasExcel === []) {
            throw new ImportacionProductosException('El archivo .xlsx no contiene datos.');
        }

        $posicionCabecera = null;

        foreach ($filasExcel as $indice => $fila) {
            $cabecera = [];

            for ($columna = 0; $columna < count(self::COLUMNAS); $columna++) {
                $cabecera[] = $this->limpiarCabecera($fila['celdas'][$columna] ?? '');
            }

            if ($cabecera === self::COLUMNAS) {
                $posicionCabecera = $indice;
                break;
            }
        }

        if ($posicionCabecera === null) {
            throw new ImportacionProductosException(
                'No se encontró la fila de encabezados categoria, nombre, precio, requiere_cocina '
                .'en la hoja Productos.'
            );
        }

        $filas = [];

        foreach (array_slice($filasExcel, $posicionCabecera + 1) as $fila) {
            $celdas = [];

            for ($columna = 0; $columna < count(self::COLUMNAS); $columna++) {
                $celdas[self::COLUMNAS[$columna]] = $this->limpiarCelda($fila['celdas'][$columna] ?? '');
            }

            // Las filas con A:D vacías son espacios en blanco o los ejemplos
            // que la plantilla coloca en F:I. No son productos.
            if (implode('', $celdas) === '') {
                continue;
            }

            $errorEstructura = null;

            if (! $this->esUtf8($celdas)) {
                $errorEstructura = 'El contenido de la fila no está en UTF-8.';
            }

            $filas[] = [
                'numero' => (int) $fila['numero'],
                'categoria' => $celdas['categoria'],
                'nombre' => $celdas['nombre'],
                'precio' => $celdas['precio'],
                'requiere_cocina' => $celdas['requiere_cocina'],
                'error_estructura' => $errorEstructura,
            ];

            if (count($filas) > self::MAX_FILAS) {
                throw new ImportacionProductosException(
                    'El archivo supera el máximo de '.self::MAX_FILAS.' filas de productos.'
                );
            }
        }

        if ($filas === []) {
            throw new ImportacionProductosException('El archivo no contiene filas de productos.');
        }

        return $filas;
    }

    /* ------------------------------------------------------------------
     | Validación de filas
     | ------------------------------------------------------------------ */

    /**
     * @param  array<int, array<string, mixed>>  $filasCrudas
     * @return array{filas: array<int, array<string, mixed>>, filas_crudas: array<int, array<string, mixed>>, resumen: array<string, int>}
     */
    private function evaluar(array $filasCrudas): array
    {
        // Categorías reales: el nombre debe coincidir (sin distinguir
        // mayúsculas/minúsculas) y la categoría debe estar ACTIVA.
        $categoriasPorNombre = [];

        foreach (DB::table('categorias')->get(['id', 'nombre', 'activa']) as $categoria) {
            $clave = $this->normalizar((string) $categoria->nombre);
            $categoriasPorNombre[$clave] ??= $categoria;
        }

        // Productos existentes (activos e inactivos). Se comparan por
        // categoría + nombre normalizado, que son los identificadores reales
        // disponibles: `productos` no tiene código ni descripción.
        $existentes = [];

        foreach (DB::table('productos')->get(['id', 'categoria_id', 'nombre', 'activo']) as $producto) {
            $clave = $this->claveDuplicado((int) $producto->categoria_id, (string) $producto->nombre);
            $existentes[$clave] = $producto;
        }

        $vistos = [];
        $resultado = [];
        $resumen = [
            'total' => 0,
            'validas' => 0,
            'errores' => 0,
            'duplicados' => 0,
        ];

        foreach ($filasCrudas as $filaCruda) {
            $resumen['total']++;
            $numero = (int) ($filaCruda['numero'] ?? 0);
            $categoriaTexto = $this->limpiarCelda($filaCruda['categoria'] ?? '');
            $nombre = $this->limpiarCelda($filaCruda['nombre'] ?? '');
            $precioTexto = $this->limpiarCelda($filaCruda['precio'] ?? '');
            $requiereCocinaTexto = $this->limpiarCelda($filaCruda['requiere_cocina'] ?? '');
            $mensajes = [];

            if (! empty($filaCruda['error_estructura'])) {
                $mensajes[] = (string) $filaCruda['error_estructura'];
            }

            $categoria = null;
            $categoriaId = null;

            if ($categoriaTexto === '') {
                $mensajes[] = 'La categoría es obligatoria.';
            } else {
                $categoria = $categoriasPorNombre[$this->normalizar($categoriaTexto)] ?? null;

                if ($categoria === null) {
                    $mensajes[] = 'La categoría "'.$categoriaTexto.'" no existe.';
                } elseif ((int) $categoria->activa !== 1) {
                    $mensajes[] = 'La categoría "'.$categoria->nombre.'" está inactiva.';
                } else {
                    $categoriaId = (int) $categoria->id;
                }
            }

            if ($nombre === '') {
                $mensajes[] = 'El nombre es obligatorio.';
            } elseif (mb_strlen($nombre, 'UTF-8') > 100) {
                $mensajes[] = 'El nombre no puede superar 100 caracteres.';
            }

            $precio = $this->parsearPrecio($precioTexto);

            if ($precioTexto === '') {
                $mensajes[] = 'El precio es obligatorio.';
            } elseif ($precio === null) {
                $mensajes[] = 'El precio debe ser un número entre 0.00 y 9999999999.99 (usa punto decimal).';
            }

            $requiereCocina = $this->parsearRequiereCocina($requiereCocinaTexto);

            if ($requiereCocinaTexto === '') {
                $mensajes[] = 'requiere_cocina es obligatorio.';
            } elseif ($requiereCocina === null) {
                $mensajes[] = 'requiere_cocina debe ser 0, 1, SI o NO.';
            }

            $estado = $mensajes === [] ? 'valida' : 'error';
            $mensajeDuplicado = null;

            if ($estado === 'valida') {
                $clave = $this->claveDuplicado((int) $categoriaId, $nombre);

                if (isset($vistos[$clave])) {
                    $estado = 'duplicado';
                    $mensajeDuplicado = 'Duplicado dentro del archivo: misma categoría y nombre que la fila '
                        .$vistos[$clave].'.';
                } else {
                    $vistos[$clave] = $numero;

                    if (isset($existentes[$clave])) {
                        $estado = 'duplicado';
                        $existente = $existentes[$clave];
                        $mensajeDuplicado = 'Ya existe un producto con ese nombre en la categoría (id '
                            .(int) $existente->id.', '
                            .((int) $existente->activo === 1 ? 'activo' : 'inactivo')
                            .'). No se actualiza ni se duplica.';
                    }
                }
            }

            if ($mensajeDuplicado !== null) {
                $mensajes[] = $mensajeDuplicado;
            }

            if ($estado === 'valida') {
                $resumen['validas']++;
            } elseif ($estado === 'duplicado') {
                $resumen['duplicados']++;
            } else {
                $resumen['errores']++;
            }

            $resultado[] = [
                'numero' => $numero,
                'estado' => $estado,
                'categoria' => $categoriaTexto,
                'categoria_id' => $categoriaId,
                'nombre' => $nombre,
                'precio' => $precio !== null ? round($precio, 2) : null,
                'precio_texto' => $precioTexto,
                'requiere_cocina' => $requiereCocina,
                'requiere_cocina_texto' => $requiereCocinaTexto,
                'mensajes' => $mensajes,
            ];
        }

        return [
            'filas' => $resultado,
            'filas_crudas' => $filasCrudas,
            'resumen' => $resumen,
        ];
    }

    /* ------------------------------------------------------------------
     | Utilidades
     | ------------------------------------------------------------------ */

    private function limpiarCabecera(mixed $valor): string
    {
        $texto = mb_strtolower($this->limpiarCelda($valor), 'UTF-8');
        $texto = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'Á', 'É', 'Í', 'Ó', 'Ú'],
            ['a', 'e', 'i', 'o', 'u', 'a', 'e', 'i', 'o', 'u'],
            $texto
        );
        $texto = preg_replace('/[^a-z0-9_]+/u', '_', $texto) ?? $texto;

        return trim($texto, '_');
    }

    private function limpiarCelda(mixed $valor): string
    {
        return trim(str_replace("\0", '', (string) $valor));
    }

    private function esUtf8(array $valores): bool
    {
        foreach ($valores as $valor) {
            if (! mb_check_encoding((string) $valor, 'UTF-8')) {
                return false;
            }
        }

        return true;
    }

    private function normalizar(string $texto): string
    {
        $texto = preg_replace('/\s+/u', ' ', trim($texto)) ?? trim($texto);

        return mb_strtolower($texto, 'UTF-8');
    }

    private function claveDuplicado(int $categoriaId, string $nombre): string
    {
        return $categoriaId.'|'.$this->normalizar($nombre);
    }

    private function parsearPrecio(string $valor): ?float
    {
        $texto = trim($valor);
        $texto = preg_replace('/^(?:C\$|USD|\$)\s*/iu', '', $texto) ?? $texto;
        $texto = str_replace(["\xC2\xA0", ' '], '', $texto);

        if ($texto === '' || ! preg_match('/^\d+(?:\.\d{1,2})?$/', $texto)) {
            return null;
        }

        $numero = (float) $texto;

        if (! is_finite($numero) || $numero < 0 || $numero > 9999999999.99) {
            return null;
        }

        return round($numero, 2);
    }

    private function parsearRequiereCocina(string $valor): ?int
    {
        $texto = mb_strtoupper(trim($valor), 'UTF-8');

        return match ($texto) {
            '1', 'SI', 'SÍ' => 1,
            '0', 'NO' => 0,
            default => null,
        };
    }
}
