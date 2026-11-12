<?php
namespace Tests\Feature;

use App\Services\ConfiguracionService;
use App\Services\FiscalService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\CreaEsquemaCompleto;
use Tests\TestCase;

class FiscalServiceTest extends TestCase
{
    use CreaEsquemaCompleto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crearEsquemaCompleto();
    }

    public function test_iva_15_por_ciento(): void
    {
        DB::table('configuraciones')->insert(['clave' => ConfiguracionService::CLAVE_IVA, 'valor' => '1', 'created_at' => now(), 'updated_at' => now()]);
        $svc = new FiscalService(new ConfiguracionService());
        $r = $svc->calcular(1000, 0, 0, false);
        $this->assertEquals(150.0, $r['iva']);
        $this->assertEquals(1150.0, $r['total_factura']);
        $this->assertEquals(1150.0, $r['neto']);
    }

    public function test_iva_desactivado(): void
    {
        $svc = new FiscalService(new ConfiguracionService());
        $r = $svc->calcular(1000, 0, 0, false);
        $this->assertEquals(0.0, $r['iva']);
        $this->assertEquals(1000.0, $r['total_factura']);
    }
}
