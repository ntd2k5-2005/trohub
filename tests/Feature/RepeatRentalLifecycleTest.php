<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Billing\BillingService;
use App\Modules\Listing\ListingService;
use App\Modules\Property\PropertyService;
use App\Modules\Rental\RentalPolicy;
use App\Modules\Rental\RentalRequestService;
use App\Modules\Rental\RentalService;
use App\Modules\Workspace\Models\Workspace;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepeatRentalLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_request_same_room_again_after_settlement_without_changing_history(): void
    {
        $this->seed(DatabaseSeeder::class);
        $owner=User::where('email','owner@trohub.test')->firstOrFail();
        $workspace=Workspace::where('owner_id',$owner->id)->firstOrFail();
        config(['business.limits.'.$workspace->plan.'.properties'=>10]);
        $tenant=User::factory()->create();
        $property=app(PropertyService::class)->saveProperty($owner,$workspace,['name'=>'Nhà kiểm thử thuê lại','address'=>'Địa chỉ thử nghiệm','district'=>'Thủ Đức']);
        $room=app(PropertyService::class)->saveRoom($owner,$property,['name'=>'Phòng thuê lại','area'=>20,'rent'=>1000000,'deposit'=>0,'capacity'=>2]);
        $listing=app(ListingService::class)->save($owner,$room->id,['title'=>'Phòng thuê lại','description'=>'Phòng thử nghiệm','status'=>'published']);
        $input=['starts_at'=>today()->toDateString(),'ends_at'=>today()->addMonth()->toDateString(),'people'=>1,'accept_terms'=>true,'quote'=>app(RentalPolicy::class)->quote($room)];
        $request=app(RentalRequestService::class)->submit($tenant,$listing->id,$input);
        app(RentalRequestService::class)->decide($owner,$workspace,$request,'accept',1,[]);
        $rental=$request->fresh()->rental;
        app(RentalService::class)->act($tenant,$rental,'confirm',['terms_quote'=>app(RentalService::class)->reviewQuote($rental),'confirm_terms'=>true]);
        app(RentalService::class)->act($owner,$rental,'activate');
        app(RentalService::class)->act($tenant,$rental,'request_move_out',['move_out_at'=>today()->toDateString()]);
        $invoice=app(BillingService::class)->generate($owner,$rental->fresh(),today()->format('Y-m'),today()->toDateString());
        app(BillingService::class)->issue($owner,$invoice);
        app(BillingService::class)->pay($owner,$invoice,['amount'=>1000000,'method'=>'cash','reference'=>'REPEAT-RENT-RECEIPT']);
        app(RentalService::class)->act($owner,$rental,'close',['handover_complete'=>true]);
        app(ListingService::class)->status($owner,$listing->id,'published');
        $next=app(RentalRequestService::class)->submit($tenant,$listing->id,array_replace($input,['starts_at'=>today()->addDay()->toDateString(),'quote'=>app(RentalPolicy::class)->quote($room->fresh())]));
        $this->assertNotSame($request->id,$next->id,'A settled lease must not force the user back to an old accepted request');
        $this->assertSame('submitted',$next->fresh()->status);
        $this->assertNull($request->fresh()->active_key);
        $this->assertSame('accepted',$request->fresh()->status);
        $this->assertSame('closed',$rental->fresh()->status);
        $this->assertSame(1000000,$invoice->fresh()->paid);
        $this->assertSame(1,$invoice->payments()->count());
    }
}
