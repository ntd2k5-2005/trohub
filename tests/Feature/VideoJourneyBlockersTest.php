<?php
namespace Tests\Feature;
use App\Models\User;
use App\Modules\Billing\Models\{Invoice,Payment};
use App\Modules\Property\Models\Room;
use App\Modules\Rental\{ObligationGuard};
use App\Modules\Rental\Models\Rental;
use App\Modules\Roommate\Models\RoommateNeed;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class VideoJourneyBlockersTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->freezeTime();Mail::fake();$this->seed(DatabaseSeeder::class);}
    private function user(string $key):User{return User::where('email',$key.'@trohub.test')->firstOrFail();}
    private function need(int $room):array{return ['context'=>'personal','mode'=>'has_room','room_id'=>$room,'district'=>'Bình Thạnh','budget'=>1350000,'people_needed'=>1,'move_in'=>today()->toDateString(),'lifestyle'=>'Gọn gàng, không hút thuốc.','expires_at'=>today()->addDays(20)->toDateString()];}
    public function test_current_resident_can_publish_a_need_for_their_own_room():void
    {
        $tenant=$this->user('tenant');$this->actingAs($tenant)->post('/actions/roommate.save',$this->need(1))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('roommate_needs',['user_id'=>$tenant->id,'room_id'=>1,'mode'=>'has_room']);
    }
    public function test_personal_roommate_permission_cannot_publish_for_someone_elses_room():void
    {
        $count=RoommateNeed::count();$this->actingAs($this->user('tenant'))->post('/actions/roommate.save',$this->need(2))->assertForbidden();$this->assertSame($count,RoommateNeed::count());
    }
    public function test_a_need_owned_by_another_user_cannot_be_updated():void
    {
        $other=RoommateNeed::create(['user_id'=>$this->user('user')->id,'district'=>'Bình Thạnh','budget'=>1000000,'move_in'=>today(),'lifestyle'=>'Yên tĩnh','expires_at'=>today()->addMonth(),'mode'=>'search_together']);
        $this->actingAs($this->user('tenant'))->post('/actions/roommate.save',$this->need(1)+['id'=>$other->id])->assertForbidden();$this->assertSame(1000000,$other->fresh()->budget);
    }
    public function test_empty_retirement_scope_does_not_reference_a_computed_payment_column():void
    {
        app(ObligationGuard::class)->settled([]);$this->assertTrue(true);
    }
    public function test_retirement_checks_actual_payment_totals_and_keeps_financial_records():void
    {
        $r=Rental::create(['room_id'=>2,'tenant_id'=>$this->user('user')->id,'status'=>'closed','starts_at'=>today()->subMonth()->toDateString(),'ends_at'=>today()->toDateString(),'rent'=>1000,'deposit'=>0,'terms'=>'Đã hoàn tất']);
        $invoice=Invoice::create(['rental_id'=>$r->id,'period'=>today()->format('Y-m'),'due_at'=>today(),'issued_at'=>now(),'total'=>1000]);
        Payment::create(['invoice_id'=>$invoice->id,'amount'=>600,'method'=>'cash','reference'=>'PARTIAL','paid_at'=>now(),'actor_id'=>$this->user('owner')->id]);
        try{app(ObligationGuard::class)->settled([2]);$this->fail('Outstanding debt must block retirement');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){$this->assertSame(409,$e->getStatusCode());}
        Payment::create(['invoice_id'=>$invoice->id,'amount'=>400,'method'=>'cash','reference'=>'REMAINDER','paid_at'=>now(),'actor_id'=>$this->user('owner')->id]);
        app(ObligationGuard::class)->settled([2]);$this->assertSame(2,$invoice->payments()->count());$this->assertSame(0,$invoice->fresh()->outstanding);
    }
    public function test_room_can_be_retired_and_restored_without_republishing_its_listing():void
    {
        $room=Room::findOrFail(2);$this->actingAs($this->user('owner'));
        $data=['context'=>'workspace','workspace_id'=>$room->property->workspace_id,'id'=>$room->id,'asset_type'=>'room','version'=>$room->version,'reason'=>'Kiểm tra nội thất','confirm'=>1];
        $this->post('/actions/asset.retire',$data)->assertRedirect()->assertSessionHasNoErrors();$this->assertNotNull($room->fresh()->retired_at);
        $data['version']=$room->fresh()->version;$this->post('/actions/asset.restore',$data)->assertRedirect()->assertSessionHasNoErrors();$this->assertNull($room->fresh()->retired_at);$this->assertSame('vacant',$room->fresh()->status);$this->assertFalse($room->listings()->where('status','published')->exists());
    }
}
