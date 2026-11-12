<?php

namespace Tests\Feature;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\Workspace\Models\Workspace;
use Database\Seeders\{DatabaseSeeder, TroHubDemoV1Seeder};
use Database\Seeders\Support\{DemoV1Import, DemoV1Validator};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Hash, Http, Mail, Schema, Storage};
use Tests\TestCase;

class DemoV1DatasetTest extends TestCase
{
    use RefreshDatabase;

    public function test_bounded_import_preserves_existing_data_and_is_idempotent_with_real_permissions(): void
    {
        Mail::fake(); Http::preventStrayRequests();
        $this->seed(DatabaseSeeder::class);
        $baseline=[];
        foreach(Schema::getTableListing() as $table){$table=preg_replace('/^main\./','',$table);if(in_array($table,['audit_integrity_head','demo_dataset_records','demo_dataset_runs']))continue;$baseline[$table]=DB::table($table)->get()->all();}
        // External file boundary only; business data still runs through the real importer.
        Storage::fake('local');
        for($i=1;$i<=70;$i++)foreach([0,1]as$v)Storage::disk('local')->put("housing-images/demo-v1/property-{$i}-{$v}.png",base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9Zl1sAAAAASUVORK5CYII='));
        config(['demo_v1.password'=>'Testing-only-password','demo_v1.reference_date'=>'2026-11-02']);
        $this->travelTo(\Carbon\Carbon::parse('2026-11-02 12:00:00'));
        $this->seed(TroHubDemoV1Seeder::class);
        $result=(new DemoV1Validator)->validate('Testing-only-password');
        $this->assertTrue($result['passed'],json_encode($result['checks']));
        foreach($baseline as$table=>$rows)foreach($rows as$row){if(isset($row->id))$this->assertEquals($row,DB::table($table)->where('id',$row->id)->first(),$table.' existing row changed');}
        $fingerprint=$this->fingerprint();$this->seed(TroHubDemoV1Seeder::class);$this->assertSame($fingerprint,$this->fingerprint());
        $owner=User::where('email','user1.demo@example.com')->firstOrFail();$workspace=Workspace::where('owner_id',$owner->id)->firstOrFail();
        $staff=User::where('email','user231.demo@example.com')->firstOrFail();$properties=$workspace->properties()->orderBy('id')->pluck('id');
        $access=app(Authorization::class);$this->assertTrue($access->allowed($staff,'property.view',$workspace,$properties[0]));$this->assertFalse($access->allowed($staff,'property.view',$workspace,$properties[1]));
        $tenant=User::where('email','user36.demo@example.com')->firstOrFail();$this->assertCount(1,app(\App\Support\ScopedRepository::class)->currentRoomIds($tenant));
        $this->assertFalse($access->global($owner,'subscription.approve'));
        $candidate=User::where('email','user226.demo@example.com')->firstOrFail();$this->assertTrue($access->personal($candidate,'tenant.confirm'));
        $this->assertSame([],app(\App\Support\ScopedRepository::class)->currentRoomIds($candidate));
        $this->post('/dang-nhap',['email'=>$tenant->email,'password'=>'Testing-only-password'])->assertRedirect();$this->assertAuthenticatedAs($tenant);
        $this->get('/ui/my-invoices?context=personal')->assertOk();
        $this->get('/khong-gian?context=workspace&workspace_id='.$workspace->id)->assertForbidden();
        Mail::assertNothingOutgoing();
    }
    private function fingerprint():string
    {
        $data=[];foreach(Schema::getTableListing()as$t){$t=preg_replace('/^main\./','',$t);$data[$t]=DB::table($t)->get()->all();}return hash('sha256',json_encode($data));
    }
    public function test_import_refuses_non_sqlite_without_explicit_approval():void
    {
        config(['database.default'=>'mysql']);
        $this->expectException(\RuntimeException::class);$this->expectExceptionMessage('explicit manager approval');
        try { (new DemoV1Import)->run(); } finally { config(['database.default'=>'sqlite']); }
    }
    public function test_existing_unowned_demo_email_is_never_adopted_or_overwritten():void
    {
        $this->seed(DatabaseSeeder::class);$u=User::factory()->create(['email'=>'user1.demo@example.com']);$hash=$u->password;
        config(['demo_v1.password'=>'Testing-only-password']);
        try{(new DemoV1Import)->run();$this->fail('Collision must abort');}catch(\RuntimeException$e){$this->assertStringContainsString('collision',$e->getMessage());}
        $this->assertSame($hash,$u->fresh()->password);$this->assertSame(0,DB::table('demo_dataset_records')->count());$this->assertSame(0,DB::table('demo_dataset_runs')->count());
    }
}
