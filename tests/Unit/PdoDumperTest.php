<?php

namespace Tests\Unit;

use App\Services\Backup\PdoDumper;
use PHPUnit\Framework\TestCase;

class PdoDumperTest extends TestCase
{
    public function test_ident_escapa_backticks(): void
    {
        $this->assertSame('`tabla`', PdoDumper::ident('tabla'));
        $this->assertSame('`ta``bla`', PdoDumper::ident('ta`bla'));
        $this->assertSame('`a``b``c`', PdoDumper::ident('a`b`c'));
        $this->assertSame('`columna`', PdoDumper::ident('columna'));
    }
}