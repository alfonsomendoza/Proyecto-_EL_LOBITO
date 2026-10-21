<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProductoXlsxService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;
use ZipArchive;

/*
 * IMPORTACIÓN DE PRODUCTOS DESDE EXCEL (.xlsx) Y CSV
 * ==================================================
 *
 * Cubre el flujo completo:
 *  - plantilla oficial .xlsx con hojas Productos y Categorías activas;
 *  - desplegable de categorías y ejemplos fuera del área de importación;
 *  - lectura exclusiva de la hoja Productos (nunca la hoja Categorías);
 *  - vista previa sin escribir en la base;
 *  - confirmación transaccional y de un solo uso;
 *  - validaciones de columnas, categoría, precio y requiere_cocina;
 *  - duplicados dentro del archivo y contra el catálogo;
 *  - permisos de servidor;
 *  - no creación de inventario;
 *  - rollback si la base falla a mitad de la importación;
 *  - compatibilidad con CSV.
 *
 * IMPORTANTE:
 * - SQLite :memory: (phpunit.xml); nunca toca la base MySQL real.
 * - El esquema se construye a mano con CreaEsquemaCompleto.
 * - El .xlsx se genera con el mismo ProductoXlsxService de producción.
 */
class ProductoImportCsvTest extends TestCase
{
    use CreaEsquemaCompleto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName(), 'Las pruebas deben ejecutarse en SQLite.');
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'), 'La base de pruebas debe ser SQLite en memoria.');

        $this->crearEsquemaCompleto();
    }

    /* ------------------------------------------------------------------
     | Utilidades
     | ------------------------------------------------------------------ */

    private function usuario(string $rolNombre): User
    {
        $rolId = DB::table('roles')->insertGetId(['nombre' => $rolNombre]);

        return User::findOrFail(DB::table('usuarios')->insertGetId([
            'nombre' => $rolNombre.' Test',
            'username' => strtolower($rolNombre).'_'.uniqid(),
            'password_hash' => bcrypt('secret'),
            'rol_id' => $rolId,
        ]));
    }

    private function categoria(string $nombre, bool $activa = true): int
    {
        return DB::table('categorias')->insertGetId([
            'nombre' => $nombre,
            'activa' => $activa ? 1 : 0,
        ]);
    }

    private function producto(int $categoriaId, string $nombre, float $precio = 10): int
    {
        return DB::table('productos')->insertGetId([
            'categoria_id' => $categoriaId,
            'nombre' => $nombre,
            'precio' => $precio,
            'requiere_cocina' => 0,
            'controla_stock' => 0,
            'activo' => 1,
        ]);
    }

    private function csv(array $filas, string $cabecera = 'categoria,nombre,precio,requiere_cocina'): string
    {
        return $cabecera."\n".implode("\n", $filas)."\n";
    }

    /**
     * Genera un .xlsx real con las categorías activas de la prueba.
     *
     * @param  array<int, array{categoria: string, nombre: string, precio: string|int|float, requiere_cocina: string}>  $filas
     */
    private function xlsx(array $filas = []): string
    {
        $categorias = DB::table('categorias')
            ->where('activa', 1)
            ->orderBy('nombre')
            ->pluck('nombre')
            ->map(fn ($nombre): string => (string) $nombre)
            ->all();

        return app(ProductoXlsxService::class)->generar($categorias, $filas);
    }

    /**
     * @return array<string, string>
     */
    private function contenidoXlsx(string $binario): array
    {
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        $this->assertNotFalse($ruta);
        file_put_contents($ruta, $binario);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);

        $archivos = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = (string) $zip->getNameIndex($i);
            $archivos[$nombre] = (string) $zip->getFromIndex($i);
        }

        $zip->close();
        @unlink($ruta);

        return $archivos;
    }

    private function modificarXlsx(string $binario, string $entrada, callable $modificar): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx_mod_');
        $this->assertNotFalse($ruta);
        file_put_contents($ruta, $binario);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true);
        $actual = (string) $zip->getFromName($entrada);
        $zip->deleteName($entrada);
        $zip->addFromString($entrada, $modificar($actual));
        $zip->close();

        $resultado = (string) file_get_contents($ruta);
        @unlink($ruta);

        return $resultado;
    }

    private function previsualizar(User $usuario, string $contenido, string $nombre = 'productos.csv')
    {
        return $this->actingAs($usuario)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('catalogo.importar.previsualizar'), [
                'archivo' => UploadedFile::fake()->createWithContent($nombre, $contenido),
            ]);
    }

    private function confirmar(User $usuario, string $token)
    {
        return $this->actingAs($usuario)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('catalogo.importar.confirmar'), ['token' => $token]);
    }

    /* ------------------------------------------------------------------
     | 1. Flujo feliz
     | ------------------------------------------------------------------ */

    public function test_importa_varios_productos_validos_sin_tocar_inventario(): void
    {
        $admin = $this->usuario('ADMIN');
        $bebidas = $this->categoria('Bebidas');
        $cocina = $this->categoria('Cocina');

        $contenido = $this->csv([
            'Bebidas,Coca Cola,25.50,NO',
            'Cocina,Filete de pollo,180,SI',
        ]);

        $preview = $this->previsualizar($admin, $contenido);

        $preview->assertStatus(200)
            ->assertJsonPath('exito', true)
            ->assertJsonPath('resumen.total', 2)
            ->assertJsonPath('resumen.validas', 2)
            ->assertJsonPath('resumen.errores', 0)
            ->assertJsonPath('resumen.duplicados', 0)
            ->assertJsonPath('filas.0.requiere_cocina', 0)
            ->assertJsonPath('filas.1.requiere_cocina', 1);

        // La vista previa NO debe crear productos.
        $this->assertSame(0, DB::table('productos')->count());

        $confirmacion = $this->confirmar($admin, (string) $preview->json('token'));

        $confirmacion->assertStatus(200)
            ->assertJsonPath('exito', true)
            ->assertJsonPath('importados', 2)
            ->assertJsonPath('omitidos', 0);

        $this->assertDatabaseHas('productos', [
            'categoria_id' => $bebidas,
            'nombre' => 'Coca Cola',
            'precio' => 25.50,
            'requiere_cocina' => 0,
            'controla_stock' => 0,
            'activo' => 1,
        ]);

        $this->assertDatabaseHas('productos', [
            'categoria_id' => $cocina,
            'nombre' => 'Filete de pollo',
            'precio' => 180.00,
            'requiere_cocina' => 1,
            'controla_stock' => 0,
            'activo' => 1,
        ]);

        // No se asignan existencias ni movimientos de inventario.
        $this->assertSame(0, DB::table('inventario_producto')->count());
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
    }

    public function test_previsualiza_xlsx_leyendo_solo_la_hoja_productos_sin_escribir(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->categoria('Bebidas');
        $this->categoria('Cocina');
        $this->categoria('Postres', false);

        $binario = $this->xlsx([
            ['categoria' => 'Bebidas', 'nombre' => 'Producto XLSX Uno', 'precio' => '35.50', 'requiere_cocina' => 'NO'],
            ['categoria' => 'Cocina', 'nombre' => 'Producto XLSX Dos', 'precio' => '180.00', 'requiere_cocina' => 'SI'],
        ]);

        $preview = $this->previsualizar($admin, $binario, 'productos.xlsx');

        $preview->assertStatus(200)
            ->assertJsonPath('exito', true)
            ->assertJsonPath('resumen.total', 2)
            ->assertJsonPath('resumen.validas', 2)
            ->assertJsonPath('resumen.errores', 0)
            ->assertJsonPath('resumen.duplicados', 0)
            ->assertJsonPath('filas.0.categoria', 'Bebidas')
            ->assertJsonPath('filas.0.nombre', 'Producto XLSX Uno')
            ->assertJsonPath('filas.0.precio', 35.5)
            ->assertJsonPath('filas.0.requiere_cocina', 0)
            ->assertJsonPath('filas.1.categoria', 'Cocina')
            ->assertJsonPath('filas.1.nombre', 'Producto XLSX Dos')
            ->assertJsonPath('filas.1.precio', 180)
            ->assertJsonPath('filas.1.requiere_cocina', 1);

        // La hoja Categorías no se lee como productos y la vista previa no escribe.
        $this->assertSame(0, DB::table('productos')->count());
        $this->assertSame(0, DB::table('inventario_producto')->count());
        $this->assertSame(0, DB::table('movimientos_inventario')->count());
    }

    public function test_rechaza_xlsx_danado_o_con_encabezados_incorrectos(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->categoria('Bebidas');

        $this->previsualizar($admin, 'esto no es un Excel', 'productos.xlsx')
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $binario = $this->xlsx([
            ['categoria' => 'Bebidas', 'nombre' => 'Producto XLSX', 'precio' => '10.00', 'requiere_cocina' => 'NO'],
        ]);

        $roto = $this->modificarXlsx(
            $binario,
            'xl/worksheets/sheet1.xml',
            fn (string $xml): string => str_replace(
                '<t xml:space="preserve">categoria</t>',
                '<t xml:space="preserve">categorias</t>',
                $xml
            )
        );

        $respuesta = $this->previsualizar($admin, $roto, 'productos.xlsx');

        $respuesta->assertStatus(422)->assertJsonPath('exito', false);
        $this->assertStringContainsString('encabezados', (string) $respuesta->json('error'));

        $this->assertSame(0, DB::table('productos')->count());
    }

    public function test_xlsx_rechaza_categoria_inactiva_y_duplicados(): void
    {
        $admin = $this->usuario('ADMIN');
        $bebidas = $this->categoria('Bebidas');
        $this->categoria('Postres', false);
        $this->producto($bebidas, 'Producto existente');

        $binario = $this->xlsx([
            ['categoria' => 'Postres', 'nombre' => 'Producto inactivo', 'precio' => '10.00', 'requiere_cocina' => 'NO'],
            ['categoria' => 'Bebidas', 'nombre' => 'Producto existente', 'precio' => '10.00', 'requiere_cocina' => 'NO'],
            ['categoria' => 'Bebidas', 'nombre' => 'Producto repetido', 'precio' => '20.00', 'requiere_cocina' => 'SI'],
            ['categoria' => 'Bebidas', 'nombre' => 'Producto repetido', 'precio' => '20.00', 'requiere_cocina' => 'SI'],
        ]);

        $preview = $this->previsualizar($admin, $binario, 'productos.xlsx');

        $preview->assertStatus(200)
            ->assertJsonPath('resumen.total', 4)
            ->assertJsonPath('resumen.validas', 1)
            ->assertJsonPath('resumen.errores', 1)
            ->assertJsonPath('resumen.duplicados', 2);

        $this->assertStringContainsString('inactiva', (string) $preview->json('filas.0.mensajes.0'));
        $this->assertStringContainsString('Ya existe', (string) $preview->json('filas.1.mensajes.0'));
        $this->assertStringContainsString('Duplicado dentro del archivo', (string) $preview->json('filas.3.mensajes.0'));
        $this->assertSame(1, DB::table('productos')->count());
    }

    public function test_la_plantilla_descargable_es_xlsx_con_dos_hojas_y_categorias_activas(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->categoria('Bebidas');
        $this->categoria('Cocina');
        $this->categoria('Postres', false);

        $respuesta = $this->actingAs($admin)->get(route('catalogo.importar.plantilla'));

        $respuesta->assertStatus(200)
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $archivos = $this->contenidoXlsx((string) $respuesta->getContent());

        $this->assertArrayHasKey('xl/worksheets/sheet1.xml', $archivos);
        $this->assertArrayHasKey('xl/worksheets/sheet2.xml', $archivos);

        $workbook = $archivos['xl/workbook.xml'];
        $productos = $archivos['xl/worksheets/sheet1.xml'];
        $categorias = $archivos['xl/worksheets/sheet2.xml'];

        $this->assertStringContainsString('name="Productos"', $workbook);
        $this->assertStringContainsString('name="Categorías"', $workbook);

        // Encabezados oficiales en el orden real.
        foreach (['categoria', 'nombre', 'precio', 'requiere_cocina'] as $columna) {
            $this->assertStringContainsString('>'.$columna.'</t>', $productos);
        }

        // Solo categorías activas en la segunda hoja.
        $this->assertStringContainsString('Bebidas', $categorias);
        $this->assertStringContainsString('Cocina', $categorias);
        $this->assertStringNotContainsString('Postres', $categorias);

        // Desplegable de la columna A limitado a las categorías activas.
        $this->assertStringContainsString('<dataValidation type="list"', $productos);
        $this->assertStringContainsString('&apos;Categorías&apos;!$A$2:$A$3', $productos);

        // Los ejemplos quedan identificados y fuera de A:D.
        $this->assertStringContainsString('EJEMPLOS (NO IMPORTAR)', $productos);
        $this->assertStringContainsString('EJEMPLO - Cerveza rubia', $productos);
    }

    public function test_la_plantilla_sin_categorias_activas_no_genera_desplegable_invalido(): void
    {
        $admin = $this->usuario('ADMIN');

        $respuesta = $this->actingAs($admin)->get(route('catalogo.importar.plantilla'));

        $respuesta->assertStatus(200);

        $archivos = $this->contenidoXlsx((string) $respuesta->getContent());

        $this->assertStringNotContainsString('<dataValidations', $archivos['xl/worksheets/sheet1.xml']);
        $this->assertStringContainsString('No hay categorías activas', $archivos['xl/worksheets/sheet2.xml']);
    }

    public function test_la_plantilla_oficial_no_importa_sus_ejemplos_sin_datos_reales(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->categoria('Bebidas');

        $respuesta = $this->actingAs($admin)->get(route('catalogo.importar.plantilla'));

        $preview = $this->previsualizar($admin, (string) $respuesta->getContent(), 'productos.xlsx');

        $preview->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertSame(0, DB::table('productos')->count());
    }

    public function test_la_interfaz_de_importacion_solo_se_muestra_a_admin_y_gerente(): void
    {
        $admin = $this->usuario('ADMIN');
        $cajero = $this->usuario('CAJERO');

        $this->actingAs($admin)
            ->get(route('catalogo.index'))
            ->assertStatus(200)
            ->assertSee('Importar productos Excel/CSV')
            ->assertSee('Descargar plantilla oficial Excel (.xlsx)');

        $this->actingAs($cajero)
            ->get(route('catalogo.index'))
            ->assertStatus(200)
            ->assertDontSee('Importar productos Excel/CSV')
            ->assertDontSee('Descargar plantilla oficial Excel (.xlsx)');
    }

    /* ------------------------------------------------------------------
     | 2. Validaciones por fila
     | ------------------------------------------------------------------ */

    public function test_rechaza_campos_obligatorios_faltantes_y_no_importa_nada(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->categoria('Bebidas');

        $preview = $this->previsualizar($admin, $this->csv([
            'Bebidas,,10,SI',
            'Bebidas,Sin precio,,NO',
            'Bebidas,Sin cocina,10,',
        ]));

        $preview->assertStatus(200)
            ->assertJsonPath('resumen.validas', 0)
            ->assertJsonPath('resumen.errores', 3);

        $this->assertStringContainsString('nombre', (string) $preview->json('filas.0.mensajes.0'));
        $this->assertStringContainsString('precio', (string) $preview->json('filas.1.mensajes.0'));
        $this->assertStringContainsString('requiere_cocina', (string) $preview->json('filas.2.mensajes.0'));

        $confirmacion = $this->confirmar($admin, (string) $preview->json('token'));

        $confirmacion->assertStatus(200)
            ->assertJsonPath('exito', true)
            ->assertJsonPath('importados', 0)
            ->assertJsonPath('omitidos', 3);

        $this->assertSame(0, DB::table('productos')->count());
    }

    public function test_rechaza_precios_negativos_o_no_numericos(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->categoria('Bebidas');

        $preview = $this->previsualizar($admin, $this->csv([
            'Bebidas,Negativo,-5,SI',
            'Bebidas,Texto,abc,NO',
            'Bebidas,Formato raro,10.999,NO',
        ]));

        $preview->assertStatus(200)
            ->assertJsonPath('resumen.validas', 0)
            ->assertJsonPath('resumen.errores', 3);

        foreach ([0, 1, 2] as $indice) {
            $this->assertStringContainsString(
                'precio',
                (string) $preview->json("filas.$indice.mensajes.0")
            );
        }
    }

    public function test_rechaza_categoria_inexistente_o_inactiva(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->categoria('Bebidas');
        $this->categoria('Postres', false);

        $preview = $this->previsualizar($admin, $this->csv([
            'No Existe,Producto A,10,NO',
            'Postres,Producto B,10,NO',
        ]));

        $preview->assertStatus(200)
            ->assertJsonPath('resumen.validas', 0)
            ->assertJsonPath('resumen.errores', 2);

        $this->assertStringContainsString('no existe', (string) $preview->json('filas.0.mensajes.0'));
        $this->assertStringContainsString('inactiva', (string) $preview->json('filas.1.mensajes.0'));

        $this->confirmar($admin, (string) $preview->json('token'))
            ->assertStatus(200)
            ->assertJsonPath('importados', 0);

        $this->assertSame(0, DB::table('productos')->count());
    }

    public function test_requiere_cocina_acepta_formatos_documentados_y_rechaza_desconocidos(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->categoria('Bebidas');

        $preview = $this->previsualizar($admin, $this->csv([
            'Bebidas,Uno,10,1',
            'Bebidas,Cero,10,0',
            'Bebidas,Si,10,SI',
            'Bebidas,No,10,NO',
            'Bebidas,Desconocido,10,TALVEZ',
        ]));

        $preview->assertStatus(200)
            ->assertJsonPath('resumen.validas', 4)
            ->assertJsonPath('resumen.errores', 1)
            ->assertJsonPath('filas.0.requiere_cocina', 1)
            ->assertJsonPath('filas.1.requiere_cocina', 0)
            ->assertJsonPath('filas.2.requiere_cocina', 1)
            ->assertJsonPath('filas.3.requiere_cocina', 0)
            ->assertJsonPath('filas.4.estado', 'error');

        $this->assertStringContainsString(
            'requiere_cocina',
            (string) $preview->json('filas.4.mensajes.0')
        );

        $this->confirmar($admin, (string) $preview->json('token'))
            ->assertStatus(200)
            ->assertJsonPath('importados', 4)
            ->assertJsonPath('omitidos', 1);
    }

    /* ------------------------------------------------------------------
     | 3. Duplicados y protección del catálogo
     | ------------------------------------------------------------------ */

    public function test_detecta_duplicados_dentro_del_mismo_archivo_y_solo_importa_uno(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->categoria('Bebidas');

        $preview = $this->previsualizar($admin, $this->csv([
            'Bebidas,Coca Cola,25,NO',
            'bebidas,  coca   cola ,30,SI',
        ]));

        $preview->assertStatus(200)
            ->assertJsonPath('resumen.validas', 1)
            ->assertJsonPath('resumen.duplicados', 1)
            ->assertJsonPath('filas.1.estado', 'duplicado');

        $this->assertStringContainsString(
            'Duplicado dentro del archivo',
            (string) $preview->json('filas.1.mensajes.0')
        );

        $this->confirmar($admin, (string) $preview->json('token'))
            ->assertStatus(200)
            ->assertJsonPath('importados', 1)
            ->assertJsonPath('omitidos', 1);

        $this->assertSame(1, DB::table('productos')->count());
    }

    public function test_detecta_duplicado_contra_el_catalogo_y_no_actualiza_el_existente(): void
    {
        $admin = $this->usuario('ADMIN');
        $bebidas = $this->categoria('Bebidas');
        $existenteId = $this->producto($bebidas, 'Coca Cola', 25);

        $preview = $this->previsualizar($admin, $this->csv([
            'Bebidas,coca cola,99,SI',
        ]));

        $preview->assertStatus(200)
            ->assertJsonPath('resumen.validas', 0)
            ->assertJsonPath('resumen.duplicados', 1)
            ->assertJsonPath('filas.0.estado', 'duplicado');

        $this->assertStringContainsString(
            'Ya existe',
            (string) $preview->json('filas.0.mensajes.0')
        );

        $this->confirmar($admin, (string) $preview->json('token'))
            ->assertStatus(200)
            ->assertJsonPath('importados', 0);

        // El producto original conserva precio y requiere_cocina.
        $existente = DB::table('productos')->find($existenteId);
        $this->assertSame(25.0, (float) $existente->precio);
        $this->assertSame(0, (int) $existente->requiere_cocina);
        $this->assertSame(1, DB::table('productos')->count());
    }

    public function test_tambien_detecta_duplicado_contra_un_producto_inactivo(): void
    {
        $admin = $this->usuario('ADMIN');
        $bebidas = $this->categoria('Bebidas');
        $existenteId = $this->producto($bebidas, 'Coca Cola', 25);

        // Producto eliminado lógicamente: sigue ocupando el nombre dentro de
        // la categoría y no debe volver a insertarse desde el CSV.
        DB::table('productos')->where('id', $existenteId)->update(['activo' => 0]);

        $preview = $this->previsualizar($admin, $this->csv([
            'Bebidas,coca cola,99,NO',
        ]));

        $preview->assertStatus(200)
            ->assertJsonPath('resumen.validas', 0)
            ->assertJsonPath('resumen.duplicados', 1)
            ->assertJsonPath('filas.0.estado', 'duplicado');

        $this->assertStringContainsString(
            'inactivo',
            (string) $preview->json('filas.0.mensajes.0')
        );

        $this->confirmar($admin, (string) $preview->json('token'))
            ->assertStatus(200)
            ->assertJsonPath('importados', 0);

        $this->assertSame(1, DB::table('productos')->count());
        $this->assertSame(0, (int) DB::table('productos')->find($existenteId)->activo);
    }

    /* ------------------------------------------------------------------
     | 4. Token de la vista previa y archivos inválidos
     | ------------------------------------------------------------------ */

    public function test_el_token_de_vista_previa_es_de_un_solo_uso_y_no_duplica(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->categoria('Bebidas');

        $preview = $this->previsualizar($admin, $this->csv([
            'Bebidas,Coca Cola,25,NO',
        ]));

        $token = (string) $preview->json('token');

        $this->confirmar($admin, $token)
            ->assertStatus(200)
            ->assertJsonPath('importados', 1);

        // Repetir la misma confirmación no debe volver a insertar ni aceptar
        // un token ya consumido.
        $this->confirmar($admin, $token)
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertSame(1, DB::table('productos')->count());
    }

    public function test_el_token_pertenece_al_usuario_que_genero_la_vista_previa(): void
    {
        $admin = $this->usuario('ADMIN');
        $otroAdmin = $this->usuario('ADMIN');
        $this->categoria('Bebidas');

        $preview = $this->previsualizar($admin, $this->csv([
            'Bebidas,Coca Cola,25,NO',
        ]));

        $this->confirmar($otroAdmin, (string) $preview->json('token'))
            ->assertStatus(403)
            ->assertJsonPath('exito', false);

        $this->assertSame(0, DB::table('productos')->count());
    }

    public function test_la_vista_previa_expira_a_los_30_minutos(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->categoria('Bebidas');

        $preview = $this->previsualizar($admin, $this->csv([
            'Bebidas,Coca Cola,25,NO',
        ]));

        $this->travel(31)->minutes();

        $this->confirmar($admin, (string) $preview->json('token'))
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertSame(0, DB::table('productos')->count());
    }

    /* ------------------------------------------------------------------
     | 5. Archivos inválidos y permisos
     | ------------------------------------------------------------------ */

    public function test_rechaza_archivo_vacio_o_encabezado_incompleto(): void
    {
        $admin = $this->usuario('ADMIN');

        $this->previsualizar($admin, '')
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->previsualizar($admin, "categoria,nombre\nBebidas,Producto\n")
            ->assertStatus(422)
            ->assertJsonPath('exito', false);

        $this->assertSame(0, DB::table('productos')->count());
    }

    public function test_rechaza_extension_no_permitida(): void
    {
        $admin = $this->usuario('ADMIN');

        $this->previsualizar($admin, 'cualquier cosa', 'productos.php')
            ->assertStatus(422)
            ->assertJsonPath('exito', false);
    }

    public function test_rechaza_archivo_demasiado_grande(): void
    {
        $admin = $this->usuario('ADMIN');

        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->post(route('catalogo.importar.previsualizar'), [
                // 3000 KB supera el máximo de 2048 KB.
                'archivo' => UploadedFile::fake()->create('grande.csv', 3000),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('archivo');
    }

    public function test_cajero_y_mesero_no_pueden_importar_ni_descargar_la_plantilla(): void
    {
        foreach (['CAJERO', 'MESERO'] as $rol) {
            $usuario = $this->usuario($rol);

            $this->previsualizar($usuario, $this->csv(['Bebidas,Producto,10,NO']))
                ->assertStatus(403)
                ->assertJsonPath('exito', false);

            $this->confirmar($usuario, str_repeat('A', 48))
                ->assertStatus(403)
                ->assertJsonPath('exito', false);

            $this->actingAs($usuario)
                ->get(route('catalogo.importar.plantilla'))
                ->assertStatus(403);
        }
    }

    /* ------------------------------------------------------------------
     | 6. Atomicidad
     | ------------------------------------------------------------------ */

    public function test_si_la_base_falla_a_mitad_no_deja_una_importacion_parcial(): void
    {
        $admin = $this->usuario('ADMIN');
        $this->categoria('Bebidas');

        $preview = $this->previsualizar($admin, $this->csv([
            'Bebidas,Producto correcto,10,NO',
            'Bebidas,Producto que falla,20,NO',
        ]));

        $preview->assertStatus(200)
            ->assertJsonPath('resumen.validas', 2);

        // Falla simulada SOLO en SQLite de pruebas: el segundo INSERT aborta
        // la transacción completa.
        DB::statement(
            'CREATE TRIGGER fallo_importacion BEFORE INSERT ON productos '
            ."WHEN NEW.nombre = 'Producto que falla' "
            ."BEGIN SELECT RAISE(ABORT, 'fallo simulado'); END"
        );

        $this->confirmar($admin, (string) $preview->json('token'))
            ->assertStatus(500)
            ->assertJsonPath('exito', false);

        // El primer producto tampoco debe quedar guardado.
        $this->assertSame(0, DB::table('productos')->count());
    }
}
