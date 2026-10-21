<?php

namespace App\Services;

use App\Exceptions\ImportacionProductosException;
use DOMDocument;
use DOMXPath;
use ZipArchive;

/**
 * Genera y lee el libro Excel oficial de importación de productos.
 *
 * El proyecto no usa PhpSpreadsheet ni otra librería de Excel: este servicio
 * escribe y lee el formato OOXML (.xlsx) con la extensión ZIP de PHP, que ya
 * está instalada. Así no se agregan dependencias a composer.
 *
 * El libro tiene exactamente dos hojas:
 *  - Productos: encabezados A:D, instrucciones y ejemplos fuera del área de
 *    importación (columnas F:I).
 *  - Categorías: listado real de categorías activas usado por el desplegable.
 */
class ProductoXlsxService
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** Límite de seguridad para XML descomprimido dentro del .xlsx. */
    private const MAX_XML_BYTES = 25 * 1024 * 1024;

    /**
     * Construye un .xlsx con las categorías activas reales.
     *
     * @param  array<int, string>  $categoriasActivas
     * @param  array<int, array{categoria: string, nombre: string, precio: string|int|float, requiere_cocina: string}>  $filasProductos
     *                                                                                                                                   Filas opcionales de datos. El controlador no las envía; se usan
     *                                                                                                                                   para pruebas aisladas del lector.
     */
    public function generar(array $categoriasActivas, array $filasProductos = []): string
    {
        $categorias = [];

        foreach ($categoriasActivas as $nombre) {
            $nombre = trim((string) $nombre);

            if ($nombre !== '') {
                $categorias[$nombre] = true;
            }
        }

        $categorias = array_keys($categorias);

        return $this->empaquetar(
            $this->hojaProductos($categorias, $filasProductos),
            $this->hojaCategorias($categorias)
        );
    }

    /**
     * Lee exclusivamente la hoja Productos y devuelve sus filas no vacías.
     *
     * @return array<int, array{numero: int, celdas: array<int, string>}>
     */
    public function leerFilas(string $ruta): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new ImportacionProductosException(
                'La extensión ZIP de PHP no está disponible para leer archivos .xlsx.'
            );
        }

        $zip = new ZipArchive;

        if ($zip->open($ruta) !== true) {
            throw new ImportacionProductosException(
                'El archivo .xlsx no es válido o está dañado. Descarga una plantilla nueva.'
            );
        }

        try {
            if ($zip->locateName('[Content_Types].xml') === false
                || $zip->locateName('xl/workbook.xml') === false
                || $zip->locateName('xl/_rels/workbook.xml.rels') === false) {
                throw new ImportacionProductosException(
                    'El archivo no tiene la estructura de un Excel .xlsx válido.'
                );
            }

            $workbook = $this->leerXml($zip, 'xl/workbook.xml');
            $rutaHoja = $this->rutaHojaProductos($zip, $workbook);
            $hoja = $this->leerXml($zip, $rutaHoja);
            $compartidas = $this->leerCadenasCompartidas($zip);

            return $this->extraerFilas($hoja, $compartidas);
        } finally {
            $zip->close();
        }
    }

    /* ------------------------------------------------------------------
     | Generación del libro
     | ------------------------------------------------------------------ */

    /**
     * @param  array<int, string>  $categorias
     * @param  array<int, array{categoria: string, nombre: string, precio: string|int|float, requiere_cocina: string}>  $productos
     */
    private function hojaProductos(array $categorias, array $productos): string
    {
        $porFila = [
            1 => [
                $this->celdaTexto('A1', 'PLANTILLA OFICIAL DE PRODUCTOS', 6),
            ],
            2 => [
                $this->celdaTexto(
                    'A2',
                    'Completa desde la fila 4. Elige la categoría del desplegable, '
                    .'escribe el precio con punto decimal (35.00) e indica SI o NO en requiere_cocina. '
                    .'No cambies los encabezados. Las filas de ejemplo de la derecha NO se importan.',
                    4
                ),
            ],
            3 => [
                $this->celdaTexto('A3', 'categoria', 1),
                $this->celdaTexto('B3', 'nombre', 1),
                $this->celdaTexto('C3', 'precio', 1),
                $this->celdaTexto('D3', 'requiere_cocina', 1),
                $this->celdaTexto('F3', 'EJEMPLOS (NO IMPORTAR)', 5),
            ],
            4 => [
                $this->celdaTexto('F4', 'categoria', 5),
                $this->celdaTexto('G4', 'nombre', 5),
                $this->celdaTexto('H4', 'precio', 5),
                $this->celdaTexto('I4', 'requiere_cocina', 5),
            ],
        ];

        $categoriaEjemplo = $categorias[0] ?? 'SIN CATEGORÍA ACTIVA';
        $ejemplos = [
            [$categoriaEjemplo, 'EJEMPLO - Cerveza rubia', '35.00', 'NO'],
            [$categoriaEjemplo, 'EJEMPLO - Plato fuerte', '180.00', 'SI'],
            [$categoriaEjemplo, 'EJEMPLO - Postre', '45.00', 'SI'],
        ];

        foreach ($ejemplos as $indice => $ejemplo) {
            $numero = $indice + 5;

            $porFila[$numero] = [
                $this->celdaTexto('F'.$numero, $ejemplo[0], 2),
                $this->celdaTexto('G'.$numero, $ejemplo[1], 2),
                $this->celdaNumero('H'.$numero, $ejemplo[2], 3),
                $this->celdaTexto('I'.$numero, $ejemplo[3], 2),
            ];
        }

        foreach ($productos as $indice => $producto) {
            $numero = 4 + $indice;

            $porFila[$numero] ??= [];
            $porFila[$numero][] = $this->celdaTexto('A'.$numero, (string) ($producto['categoria'] ?? ''), 2);
            $porFila[$numero][] = $this->celdaTexto('B'.$numero, (string) ($producto['nombre'] ?? ''), 2);
            $porFila[$numero][] = $this->celdaNumero('C'.$numero, (string) ($producto['precio'] ?? ''), 3);
            $porFila[$numero][] = $this->celdaTexto('D'.$numero, (string) ($producto['requiere_cocina'] ?? ''), 2);
        }

        ksort($porFila);

        $filas = [];

        foreach ($porFila as $numero => $celdas) {
            $alto = match ($numero) {
                1 => 32,
                2 => 54,
                3 => 26,
                default => 22,
            };

            $filas[] = $this->filaXml($numero, $celdas, $alto);
        }

        $validacion = '';

        if ($categorias !== []) {
            $ultima = count($categorias) + 1;
            $formula = "'Categorías'!\$A\$2:\$A\$".$ultima;

            $validacion = '<dataValidations count="1">'
                .'<dataValidation type="list" allowBlank="1" showInputMessage="1" '
                .'showErrorMessage="1" errorStyle="stop" '
                .'errorTitle="Categoría no válida" '
                .'error="Selecciona una categoría activa de la hoja Categorías." '
                .'promptTitle="Categoría" '
                .'prompt="Elige una categoría activa de la lista." '
                .'sqref="A4:A1003">'
                .'<formula1>'.$this->xml($formula).'</formula1>'
                .'</dataValidation></dataValidations>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="'.self::NS_MAIN.'" xmlns:r="'.self::NS_REL.'">'
            .'<dimension ref="A1:I1003"/>'
            .'<sheetViews><sheetView workbookViewId="0">'
            .'<pane ySplit="3" topLeftCell="A4" activePane="bottomLeft" state="frozen"/>'
            .'</sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .'<cols>'
            .'<col min="1" max="1" width="36" customWidth="1"/>'
            .'<col min="2" max="2" width="42" customWidth="1"/>'
            .'<col min="3" max="3" width="14" customWidth="1"/>'
            .'<col min="4" max="4" width="19" customWidth="1"/>'
            .'<col min="5" max="5" width="3" customWidth="1"/>'
            .'<col min="6" max="6" width="28" customWidth="1"/>'
            .'<col min="7" max="7" width="42" customWidth="1"/>'
            .'<col min="8" max="8" width="14" customWidth="1"/>'
            .'<col min="9" max="9" width="19" customWidth="1"/>'
            .'</cols>'
            .'<sheetData>'.implode('', $filas).'</sheetData>'
            .'<autoFilter ref="A3:D1003"/>'
            .'<mergeCells count="3">'
            .'<mergeCell ref="A1:D1"/>'
            .'<mergeCell ref="A2:D2"/>'
            .'<mergeCell ref="F3:I3"/>'
            .'</mergeCells>'
            .$validacion
            .'<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
            .'</worksheet>';
    }

    /**
     * @param  array<int, string>  $categorias
     */
    private function hojaCategorias(array $categorias): string
    {
        $porFila = [
            1 => [
                $this->celdaTexto('A1', 'Categorías activas', 1),
                $this->celdaTexto('C1', 'Instrucciones', 6),
            ],
            2 => [
                $this->celdaTexto(
                    'C2',
                    'Esta hoja se genera desde la base de datos cada vez que descargas una plantilla. '
                    .'Solo contiene categorías activas. No la edites: el desplegable de la hoja Productos '
                    .'usa exactamente esta lista.',
                    4
                ),
            ],
        ];

        if ($categorias === []) {
            $porFila[2][] = $this->celdaTexto(
                'A2',
                'No hay categorías activas. Activa una categoría antes de importar productos.',
                5
            );
        } else {
            foreach ($categorias as $indice => $categoria) {
                $numero = $indice + 2;
                $porFila[$numero] ??= [];
                $porFila[$numero][] = $this->celdaTexto('A'.$numero, $categoria, 2);
            }
        }

        ksort($porFila);

        $filas = [];

        foreach ($porFila as $numero => $celdas) {
            $filas[] = $this->filaXml($numero, $celdas, $numero === 1 ? 26 : ($numero === 2 ? 72 : 20));
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="'.self::NS_MAIN.'" xmlns:r="'.self::NS_REL.'">'
            .'<dimension ref="A1:F'.(max(2, count($categorias) + 1)).'"/>'
            .'<sheetViews><sheetView workbookViewId="0">'
            .'<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            .'</sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .'<cols>'
            .'<col min="1" max="1" width="36" customWidth="1"/>'
            .'<col min="2" max="2" width="3" customWidth="1"/>'
            .'<col min="3" max="3" width="95" customWidth="1"/>'
            .'</cols>'
            .'<sheetData>'.implode('', $filas).'</sheetData>'
            .'<mergeCells count="1"><mergeCell ref="C1:F1"/></mergeCells>'
            .'<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
            .'</worksheet>';
    }

    /**
     * @param  array<int, string>  $celdas
     */
    private function filaXml(int $numero, array $celdas, int $alto): string
    {
        if ($celdas === []) {
            return '';
        }

        // OOXML exige que las celdas de una fila estén en orden de columna.
        // Algunas filas se arman con instrucciones (C) y datos (A); ordenarlas
        // aquí evita que Excel marque el libro como dañado.
        usort($celdas, function (string $a, string $b): int {
            return $this->ordenColumnaXml($a) <=> $this->ordenColumnaXml($b);
        });

        return '<row r="'.$numero.'" ht="'.$alto.'" customHeight="1">'
            .implode('', $celdas)
            .'</row>';
    }

    private function ordenColumnaXml(string $celda): int
    {
        if (preg_match('/^<c r="([A-Z]+)/i', $celda, $coincidencia) !== 1) {
            return 0;
        }

        return $this->indiceColumna($coincidencia[1]) ?? 0;
    }

    private function celdaTexto(string $referencia, string $valor, int $estilo): string
    {
        return '<c r="'.$referencia.'" s="'.$estilo.'" t="inlineStr">'
            .'<is><t xml:space="preserve">'.$this->xml($valor).'</t></is>'
            .'</c>';
    }

    private function celdaNumero(string $referencia, string $valor, int $estilo): string
    {
        $valor = trim($valor);

        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $valor) !== 1) {
            return $this->celdaTexto($referencia, $valor, $estilo);
        }

        return '<c r="'.$referencia.'" s="'.$estilo.'"><v>'.$this->xml($valor).'</v></c>';
    }

    private function empaquetar(string $hojaProductos, string $hojaCategorias): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new ImportacionProductosException(
                'La extensión ZIP de PHP no está disponible para generar la plantilla .xlsx.'
            );
        }

        $archivos = [
            '[Content_Types].xml' => $this->contentTypes(),
            '_rels/.rels' => $this->relsRaiz(),
            'docProps/core.xml' => $this->coreProps(),
            'docProps/app.xml' => $this->appProps(),
            'xl/workbook.xml' => $this->workbookXml(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRels(),
            'xl/styles.xml' => $this->stylesXml(),
            'xl/worksheets/sheet1.xml' => $hojaProductos,
            'xl/worksheets/sheet2.xml' => $hojaCategorias,
        ];

        $ruta = tempnam(sys_get_temp_dir(), 'plantilla_productos_');

        if ($ruta === false) {
            throw new ImportacionProductosException('No se pudo preparar el archivo Excel temporal.');
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($ruta, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
                throw new ImportacionProductosException('No se pudo generar la plantilla Excel.');
            }

            foreach ($archivos as $nombre => $contenido) {
                if (! $zip->addFromString($nombre, $contenido)) {
                    $zip->close();
                    throw new ImportacionProductosException('No se pudo escribir la plantilla Excel.');
                }
            }

            $zip->close();
            $contenido = file_get_contents($ruta);

            if ($contenido === false || $contenido === '') {
                throw new ImportacionProductosException('La plantilla Excel generada está vacía.');
            }

            return $contenido;
        } finally {
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            .'</Types>';
    }

    private function relsRaiz(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>';
    }

    private function workbookXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="'.self::NS_MAIN.'" xmlns:r="'.self::NS_REL.'">'
            .'<sheets>'
            .'<sheet name="Productos" sheetId="1" r:id="rId1"/>'
            .'<sheet name="Categorías" sheetId="2" r:id="rId2"/>'
            .'</sheets>'
            .'</workbook>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="'.self::NS_PKG_REL.'">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="'.self::NS_MAIN.'">'
            .'<fonts count="4">'
            .'<font><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/><family val="2"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="14"/><color rgb="FF34395E"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFC0392B"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF6777EF"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFDEAEA"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left style="thin"><color rgb="FFD9DDE3"/></left>'
            .'<right style="thin"><color rgb="FFD9DDE3"/></right>'
            .'<top style="thin"><color rgb="FFD9DDE3"/></top>'
            .'<bottom style="thin"><color rgb="FFD9DDE3"/></bottom>'
            .'<diagonal/></border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="7">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            .'<xf numFmtId="2" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'<dxfs count="0"/>'
            .'<tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>'
            .'</styleSheet>';
    }

    private function coreProps(): string
    {
        $ahora = gmdate('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            .'xmlns:dc="http://purl.org/dc/elements/1.1/" '
            .'xmlns:dcterms="http://purl.org/dc/terms/" '
            .'xmlns:dcmitype="http://purl.org/dc/dcmitype/" '
            .'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .'<dc:title>Plantilla oficial de productos - El Lobito</dc:title>'
            .'<dc:creator>Sistema POS El Lobito</dc:creator>'
            .'<cp:lastModifiedBy>Sistema POS El Lobito</cp:lastModifiedBy>'
            .'<dcterms:created xsi:type="dcterms:W3CDTF">'.$ahora.'</dcterms:created>'
            .'<dcterms:modified xsi:type="dcterms:W3CDTF">'.$ahora.'</dcterms:modified>'
            .'</cp:coreProperties>';
    }

    private function appProps(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            .'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            .'<Application>Sistema POS El Lobito</Application>'
            .'</Properties>';
    }

    /* ------------------------------------------------------------------
     | Lectura del .xlsx
     | ------------------------------------------------------------------ */

    private function leerXml(ZipArchive $zip, string $ruta): DOMDocument
    {
        $stat = $zip->statName($ruta);

        if ($stat === false) {
            throw new ImportacionProductosException('El archivo .xlsx está incompleto: falta '.$ruta.'.');
        }

        if ((int) ($stat['size'] ?? 0) > self::MAX_XML_BYTES) {
            throw new ImportacionProductosException('El contenido del archivo .xlsx es demasiado grande.');
        }

        $contenido = $zip->getFromName($ruta);

        if ($contenido === false || trim($contenido) === '') {
            throw new ImportacionProductosException('No se pudo leer una parte interna del archivo .xlsx.');
        }

        $documento = new DOMDocument;
        $anterior = libxml_use_internal_errors(true);
        $cargado = $documento->loadXML($contenido, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($anterior);

        if (! $cargado) {
            throw new ImportacionProductosException('El archivo .xlsx contiene XML dañado.');
        }

        return $documento;
    }

    private function rutaHojaProductos(ZipArchive $zip, DOMDocument $workbook): string
    {
        $xpath = new DOMXPath($workbook);
        $xpath->registerNamespace('m', self::NS_MAIN);
        $xpath->registerNamespace('r', self::NS_REL);

        $objetivo = null;
        $relacion = null;

        foreach ($xpath->query('//m:sheets/m:sheet') as $hoja) {
            if ($hoja->getAttribute('name') === 'Productos') {
                $objetivo = $hoja->getAttributeNS(self::NS_REL, 'id');
                break;
            }
        }

        if ($objetivo === null || $objetivo === '') {
            throw new ImportacionProductosException(
                'El archivo .xlsx no contiene la hoja obligatoria "Productos".'
            );
        }

        $rels = $this->leerXml($zip, 'xl/_rels/workbook.xml.rels');
        $xpathRels = new DOMXPath($rels);
        $xpathRels->registerNamespace('p', self::NS_PKG_REL);

        foreach ($xpathRels->query('//p:Relationship') as $nodo) {
            if ($nodo->getAttribute('Id') === $objetivo) {
                $relacion = $nodo->getAttribute('Target');
                break;
            }
        }

        if ($relacion === null || $relacion === '') {
            throw new ImportacionProductosException('No se pudo localizar la hoja Productos dentro del .xlsx.');
        }

        return $this->normalizarRutaInterna($relacion);
    }

    /**
     * @return array<int, string>
     */
    private function leerCadenasCompartidas(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $documento = $this->leerXml($zip, 'xl/sharedStrings.xml');
        $xpath = new DOMXPath($documento);
        $xpath->registerNamespace('m', self::NS_MAIN);

        $cadenas = [];

        foreach ($xpath->query('//m:sst/m:si') as $item) {
            $texto = '';

            foreach ($xpath->query('.//m:t', $item) as $nodo) {
                $texto .= $nodo->textContent;
            }

            $cadenas[] = $texto;
        }

        return $cadenas;
    }

    /**
     * @param  array<int, string>  $compartidas
     * @return array<int, array{numero: int, celdas: array<int, string>}>
     */
    private function extraerFilas(DOMDocument $hoja, array $compartidas): array
    {
        $xpath = new DOMXPath($hoja);
        $xpath->registerNamespace('m', self::NS_MAIN);

        $filas = [];

        foreach ($xpath->query('//m:sheetData/m:row') as $indice => $fila) {
            $numero = (int) $fila->getAttribute('r');

            if ($numero <= 0) {
                $numero = $indice + 1;
            }

            $celdas = [];

            foreach ($xpath->query('m:c', $fila) as $celda) {
                $columna = $this->indiceColumna($celda->getAttribute('r'));

                if ($columna === null) {
                    continue;
                }

                $celdas[$columna] = $this->valorCelda($celda, $compartidas, $xpath);
            }

            if ($celdas !== []) {
                $filas[] = ['numero' => $numero, 'celdas' => $celdas];
            }
        }

        return $filas;
    }

    /**
     * @param  array<int, string>  $compartidas
     */
    private function valorCelda(\DOMElement $celda, array $compartidas, DOMXPath $xpath): string
    {
        $tipo = $celda->getAttribute('t');
        $valor = $xpath->query('m:v', $celda)->item(0);

        if ($tipo === 'inlineStr') {
            $texto = '';

            foreach ($xpath->query('.//m:t', $celda) as $nodo) {
                $texto .= $nodo->textContent;
            }

            return $texto;
        }

        if ($tipo === 's') {
            $indice = (int) ($valor?->textContent ?? -1);

            return $compartidas[$indice] ?? '';
        }

        if ($tipo === 'b') {
            return ($valor?->textContent ?? '') === '1' ? '1' : '0';
        }

        return $valor?->textContent ?? '';
    }

    private function indiceColumna(string $referencia): ?int
    {
        if (preg_match('/^([A-Z]+)/i', $referencia, $coincidencia) !== 1) {
            return null;
        }

        $letras = strtoupper($coincidencia[1]);
        $indice = 0;

        for ($i = 0, $total = strlen($letras); $i < $total; $i++) {
            $indice = ($indice * 26) + (ord($letras[$i]) - 64);
        }

        return $indice - 1;
    }

    private function normalizarRutaInterna(string $ruta): string
    {
        $ruta = str_replace('\\', '/', $ruta);

        if (str_starts_with($ruta, '/')) {
            $partes = explode('/', ltrim($ruta, '/'));
        } else {
            $partes = explode('/', 'xl/'.$ruta);
        }

        $normalizadas = [];

        foreach ($partes as $parte) {
            if ($parte === '' || $parte === '.') {
                continue;
            }

            if ($parte === '..') {
                array_pop($normalizadas);

                continue;
            }

            $normalizadas[] = $parte;
        }

        return implode('/', $normalizadas);
    }

    private function xml(string $texto): string
    {
        return htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
