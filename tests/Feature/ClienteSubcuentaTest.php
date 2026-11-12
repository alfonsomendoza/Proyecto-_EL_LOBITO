<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClienteSubcuentaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', (string) config('database.connections.sqlite.database'));

        foreach (['cuenta_detalles','cuentas','venta_detalles','ventas','mesas','clientes','usuarios','roles'] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('roles', function (Blueprint $t) { $t->increments('id'); $t->string('nombre',30); });

        Schema::create('usuarios', function (Blueprint $t) {
            $t->increments('id'); $t->string('nombre',100)->nullable(); $t->string('username',50)->nullable();
            $t->string('password_hash',255)->nullable(); $t->integer('rol_id')->nullable();
        });

        Schema::create('mesas', function (Blueprint $t) {
            $t->increments('id'); $t->string('numero',20)->nullable(); $t->integer('capacidad')->nullable(); $t->string('estado',20)->default('LIBRE');
        });

        Schema::create('clientes', function (Blueprint $t) {
            $t->increments('id'); $t->string('nombre',150); $t->string('telefono',20)->nullable();
            $t->string('identificacion',50)->nullable(); $t->text('direccion')->nullable();
            $t->boolean('activo')->nullable()->default(true); $t->timestamp('creado_en')->nullable();
        });

        Schema::create('ventas', function (Blueprint $t) {
            $t->increments('id'); $t->unsignedBigInteger('mesa_id')->nullable();
            $t->unsignedBigInteger('cliente_id')->nullable(); $t->integer('apertura_id')->nullable();
            $t->string('descripcion')->nullable(); $t->decimal('monto',12,2)->default(0);
            $t->string('metodo_pago',30)->nullable(); $t->string('banco',30)->nullable();
            $t->decimal('monto_recibido',12,2)->nullable(); $t->decimal('tasa_cambio',12,4)->nullable();
            $t->decimal('cambio',12,2)->nullable(); $t->timestamp('fecha')->nullable();
            $t->string('estado',20)->default('ACTIVA'); $t->integer('usuario_id')->nullable();
            $t->decimal('descuento_porcentaje',5,2)->default(0);
        });

        Schema::create('cuentas', function (Blueprint $t) {
            $t->bigIncrements('id'); $t->integer('venta_id'); $t->integer('cliente_id')->nullable();
            $t->string('nombre',255); $t->decimal('total',10,2)->default(0);
            $t->string('estado',255)->default('ABIERTA'); $t->timestamps();
        });

        DB::table('mesas')->insert(['id'=>1,'numero'=>'Mesa 1','estado'=>'OCUPADA']);
        DB::table('clientes')->insert(['id'=>1,'nombre'=>'Cliente General','activo'=>1,'creado_en'=>now()]);
        DB::table('clientes')->insert(['id'=>2,'nombre'=>'Cliente A','activo'=>1,'creado_en'=>now()]);
        DB::table('clientes')->insert(['id'=>3,'nombre'=>'Cliente B','activo'=>1,'creado_en'=>now()]);
        DB::table('ventas')->insert(['id'=>1,'mesa_id'=>1,'cliente_id'=>1,'descripcion'=>'Consumo','monto'=>0,'fecha'=>now(),'estado'=>'ACTIVA','usuario_id'=>null,'descuento_porcentaje'=>0]);
        DB::table('cuentas')->insert(['id'=>1,'venta_id'=>1,'cliente_id'=>null,'nombre'=>'Cuenta 1','total'=>0,'estado'=>'ABIERTA','created_at'=>now(),'updated_at'=>now()]);
        DB::table('cuentas')->insert(['id'=>2,'venta_id'=>1,'cliente_id'=>null,'nombre'=>'Cuenta 2','total'=>0,'estado'=>'ABIERTA','created_at'=>now(),'updated_at'=>now()]);
    }

    private function usuario(string $rol): User
    {
        $rolId=DB::table('roles')->insertGetId(['nombre'=>$rol]);
        $uid=DB::table('usuarios')->insertGetId(['nombre'=>$rol,'username'=>strtolower($rol).uniqid(),'password_hash'=>bcrypt('secret'),'rol_id'=>$rolId]);
        return User::findOrFail($uid);
    }

    public function test_admin_can_change_one_account_without_affecting_other(): void
    {
        $admin=$this->usuario('ADMIN');
        $r=$this->actingAs($admin)->post(route('mesas.cuentas.cliente',['mesaId'=>1,'cuentaId'=>1]), ['cliente_id'=>2]);
        $r->assertStatus(200)->assertJson(['exito'=>true]);
        $this->assertDatabaseHas('cuentas',['id'=>1,'cliente_id'=>2]);
        $this->assertDatabaseHas('cuentas',['id'=>2,'cliente_id'=>null]);
    }

    public function test_clear_client_sets_null(): void
    {
        $admin=$this->usuario('ADMIN');
        DB::table('cuentas')->where('id',1)->update(['cliente_id'=>2]);
        $r=$this->actingAs($admin)->post(route('mesas.cuentas.cliente',['mesaId'=>1,'cuentaId'=>1]), ['quitar_cliente'=>1]);
        $r->assertStatus(200)->assertJson(['exito'=>true,'cliente_id'=>null]);
        $this->assertDatabaseHas('cuentas',['id'=>1,'cliente_id'=>null]);
    }

    public function test_mesero_cannot_change_client(): void
    {
        $mesero=$this->usuario('MESERO');
        $r=$this->actingAs($mesero)->post(route('mesas.cuentas.cliente',['mesaId'=>1,'cuentaId'=>1]), ['cliente_id'=>2]);
        $r->assertStatus(403);
    }
}
