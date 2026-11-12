<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Billing\CashDepositService;
use App\Modules\Billing\Models\PaymentRequest;
use App\Modules\Billing\PaymentRequestService;
use App\Modules\Billing\ReceivingAccountService;
use App\Modules\Connection\Models\Connection;
use App\Modules\DataPlatform\DataStoreHealth;
use App\Modules\Listing\ListingService;
use App\Modules\Property\Models\Room;
use App\Modules\Property\PropertyService;
use App\Modules\Rental\Contracts\ReservationPayments;
use App\Modules\Rental\Models\DepositRefundDecision;
use App\Modules\Rental\Models\RoomReservation;
use App\Modules\Rental\RefundService;
use App\Modules\Rental\RentalPolicy;
use App\Modules\Rental\RentalRequestService;
use App\Modules\Rental\RentalService;
use App\Modules\Workspace\Models\Workspace;
use Database\Factories\RoomFactory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReceivingAccountsAndReservationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['api.vietqr.io/v2/banks' => Http::response(['code' => '00', 'data' => [['bin' => '970415', 'name' => 'Ngân hàng kiểm thử', 'shortName' => 'Kiểm thử', 'transferSupported' => 1]]])]);
        $this->seed(DatabaseSeeder::class);
    }

    private function u(string $key): User
    {
        return User::where('email', $key.'@trohub.test')->firstOrFail();
    }

    private function w(): Workspace
    {
        return Workspace::where('owner_id', $this->u('owner')->id)->firstOrFail();
    }

    private function bank(string $number = '000000123456')
    {
        $service = app(ReceivingAccountService::class);
        $account = $service->save($this->u('owner'), $this->w(), ['bank_bin' => '970415', 'account_no' => $number, 'account_holder_name' => 'TAI KHOAN KIEM THU', 'label' => 'Nhận tiền thử', 'declaration' => '1']);
        $service->setDefault($this->u('owner'), $this->w(), $account->id, null);

        return $account;
    }

    public function test_manual_rental_requires_confirmed_timed_hold_and_cash_keeps_a_partial_ledger(): void
    {
        $owner = $this->u('owner');
        $room = Room::whereHas('property', fn ($q) => $q->where('workspace_id', $this->w()->id))->where('status', 'vacant')->firstOrFail();
        $room->update(['rental_policy' => ['partial_deposit' => true]]);
        $rental = app(RentalService::class)->create($owner, ['room_id' => $room->id, 'tenant_id' => $this->u('user')->id, 'starts_at' => today()->toDateString(), 'ends_at' => today()->addYear()->toDateString(), 'rent' => 3000000, 'deposit' => 1000000, 'terms' => 'Hoàn cọc theo thỏa thuận đã xác nhận']);
        $this->assertNotNull(app(RentalService::class)->activationBlocker($rental));
        $this->actingAs($owner)->post('/actions/rental.reserve', ['context' => 'workspace', 'workspace_id' => $this->w()->id, 'id' => $rental->id])->assertConflict();
        app(RentalService::class)->act($this->u('user')->fresh(), $rental, 'confirm');
        app(RentalRequestService::class)->reserveManual($owner, $this->w(), $rental->fresh());
        $hold = RoomReservation::where('rental_id', $rental->id)->firstOrFail();
        $this->assertEqualsWithDelta(48, now()->diffInHours($hold->hold_until, false), 0.01);
        $this->assertNull($hold->request->listing_id);
        $cash = app(CashDepositService::class);
        $cash->record($owner, $rental, ['amount' => 300000, 'reference' => 'CASH-ONE', 'confirm' => 1]);
        $cash->record($owner, $rental, ['amount' => 300000, 'reference' => 'CASH-ONE', 'confirm' => 1]);
        $this->assertSame(300000, app(ReservationPayments::class)->received($rental));
        $this->assertSame(0, $rental->deposits()->count());
        $cash->record($owner, $rental, ['amount' => 700000, 'reference' => 'CASH-TWO', 'confirm' => 1]);
        $this->assertSame(2, DB::table('payment_request_transactions')->where('rental_id', $rental->id)->count());
        $this->assertSame(1000000, app(ReservationPayments::class)->received($rental));
        $this->assertSame('deposit_confirmed', $hold->fresh()->status);
        $this->assertSame('draft', $rental->fresh()->status);
        app(RentalService::class)->act($owner, $rental->fresh(), 'activate');
        $this->assertSame('occupied', $room->fresh()->status);
        $this->assertNull($hold->fresh()->active_room_id);
        $this->assertNull($hold->request->fresh()->active_key);
    }

    private function request(bool $partial = false)
    {
        $listing = app(ListingService::class)->available()->whereHas('room.property', fn ($q) => $q->where('workspace_id', $this->w()->id))->firstOrFail();
        $listing->room->update(['rental_policy' => ['partial_deposit' => $partial], 'deposit' => 1000000]);

        return app(RentalRequestService::class)->submit($this->u('user'), $listing->id, ['starts_at' => today()->toDateString(), 'ends_at' => today()->addYear()->toDateString(), 'people' => 1, 'accept_terms' => '1', 'quote' => app(RentalPolicy::class)->quote($listing->room->fresh())]);
    }

    public function test_bank_scope_masking_encryption_and_default_snapshot_are_preserved(): void
    {
        $account = $this->bank();
        $this->assertNotSame($account->account_no, DB::table('receiving_accounts')->where('id', $account->id)->value('account_no'));
        $request = $this->request();
        app(RentalRequestService::class)->decide($this->u('owner'), $this->w(), $request, 'accept', 1, []);
        $payment = PaymentRequest::where('rental_id', $request->fresh()->rental_id)->firstOrFail();
        $snapshot = $payment->payee_snapshot;
        $this->bank('000000999999');
        $this->assertSame($snapshot, $payment->fresh()->payee_snapshot);
        $this->assertSame('awaiting_payment', $payment->fresh()->status);
        $this->actingAs($this->u('other'))->get('/thanh-toan/'.$payment->id.'/ma-qr?context=personal')->assertForbidden();
        $this->actingAs($this->u('accountant'))->post('/actions/bank.default', ['context' => 'workspace', 'workspace_id' => $this->w()->id, 'id' => $account->id])->assertForbidden();
        $this->actingAs($this->u('owner'))->get('/ui/workspace-settings?context=workspace&workspace_id='.$this->w()->id.'&section=banking')->assertOk()->assertSee('Tài khoản nhận tiền');
    }

    public function test_partial_deposits_are_real_transactions_and_duplicates_do_not_double_count(): void
    {
        $this->bank();
        $request = $this->request(true);
        app(RentalRequestService::class)->decide($this->u('owner'), $this->w(), $request, 'accept', 1, []);
        $rental = $request->fresh()->rental;
        $payment = PaymentRequest::where('rental_id', $rental->id)->firstOrFail();
        $service = app(PaymentRequestService::class);
        $service->verify($this->u('owner'), $this->w(), $payment, 'PART-1', 300000);
        $service->verify($this->u('owner'), $this->w(), $payment, 'PART-1', 300000);
        $this->assertSame(700000, $service->remaining($payment->fresh()));
        $this->assertFalse($rental->deposits()->exists());
        $service->verify($this->u('owner'), $this->w(), $payment->fresh(), 'PART-2', 700000);
        $this->assertSame(2, DB::table('payment_request_transactions')->count());
        $this->assertSame(1000000, $rental->deposits()->sum('amount'));
        $this->assertSame('draft', $rental->fresh()->status);
        $this->assertSame('deposit_confirmed', RoomReservation::where('rental_id', $rental->id)->first()->status);
        $this->actingAs($this->u('user'))->get('/ui/rental-detail?context=personal&id='.$rental->id)->assertOk()->assertSee('Chuyển khoản tiền cọc');
    }

    public function test_expiry_releases_unpaid_hold_and_late_money_does_not_restore_it(): void
    {
        $this->bank();
        $request = $this->request();
        $service = app(RentalRequestService::class);
        $service->decide($this->u('owner'), $this->w(), $request, 'accept', 1, []);
        $payment = PaymentRequest::where('rental_id', $request->fresh()->rental_id)->firstOrFail();
        $this->travel(49)->hours();
        $this->assertSame(1, $service->expire());
        $this->assertSame('vacant', $request->room->fresh()->status);
        $this->assertNull($request->fresh()->reservation->active_room_id);
        app(PaymentRequestService::class)->verify($this->u('owner'), $this->w(), $payment, 'LATE', 1000000, 'Tiền đến sau hạn giữ phòng');
        $this->assertSame('vacant', $request->room->fresh()->status);
        $this->assertSame('cancelled', $request->fresh()->rental->status);
        $this->assertDatabaseHas('payment_request_transactions', ['reference' => 'LATE', 'exception' => true]);
    }

    public function test_pending_proof_gets_bounded_exception_and_never_auto_forfeits(): void
    {
        $this->bank();
        $request = $this->request();
        $service = app(RentalRequestService::class);
        $service->decide($this->u('owner'), $this->w(), $request, 'accept', 1, []);
        $payment = PaymentRequest::where('rental_id', $request->fresh()->rental_id)->firstOrFail();
        app(PaymentRequestService::class)->report($this->u('user'), $payment, 'CLAIM', 'Chờ đối soát', null);
        $this->assertDatabaseCount('payment_request_transactions', 0);
        $this->travel(49)->hours();
        $service->expire();
        $this->assertSame('review_pending', $request->fresh()->reservation->status);
        $this->travel(25)->hours();
        $service->expire();
        $this->assertSame('manual_review', $request->fresh()->reservation->status);
        $this->assertNotNull($request->fresh()->reservation->active_room_id);
        $this->assertDatabaseCount('deposit_refund_decisions', 0);
    }

    public function test_exclusive_hold_rejects_second_acceptance_and_extension_is_limited(): void
    {
        $this->bank();
        $one = $this->request();
        $listing = app(ListingService::class)->available()->findOrFail($one->listing_id);
        $two = app(RentalRequestService::class)->submit($this->u('tenant'), $listing->id, ['starts_at' => today()->toDateString(), 'ends_at' => today()->addYear()->toDateString(), 'people' => 1, 'accept_terms' => '1', 'quote' => app(RentalPolicy::class)->quote($listing->room)]);
        $service = app(RentalRequestService::class);
        $service->decide($this->u('owner'), $this->w(), $one, 'accept', 1, []);
        $hold = $one->fresh()->reservation;
        $until = $hold->hold_until->copy();
        $service->reservationAction($this->u('owner'), $this->w(), $hold, 'extend', 1, ['hours' => 24, 'reason' => 'Đợi người thuê hoàn tất']);
        $this->assertTrue($hold->fresh()->hold_until->equalTo($until->addHours(24)));
        $this->actingAs($this->u('owner'))->post('/actions/reservation.action', ['context' => 'workspace', 'workspace_id' => $this->w()->id, 'id' => $hold->id, 'decision' => 'extend', 'version' => 2, 'hours' => 1, 'reason' => 'Thử lần nữa'])->assertConflict();
        $this->post('/actions/rental_request.decide', ['context' => 'workspace', 'workspace_id' => $this->w()->id, 'id' => $two->id, 'decision' => 'accept', 'version' => 1])->assertConflict();
        $this->assertSame(1, RoomReservation::whereNotNull('active_room_id')->count());
    }

    public function test_no_deposit_still_requires_tenant_confirmation_and_owner_activation(): void
    {
        $request = $this->request();
        $terms = $request->terms_snapshot;
        $terms['deposit'] = 0;
        $request->update(['terms_snapshot' => $terms]);
        app(RentalRequestService::class)->decide($this->u('owner'), $this->w(), $request, 'accept', 1, []);
        $rental = $request->fresh()->rental;
        $this->assertDatabaseCount('payment_requests', 0);
        $this->assertSame('reserved', $request->room->fresh()->status);
        $this->actingAs($this->u('owner'))->post('/actions/rental.activate', ['context' => 'workspace', 'workspace_id' => $this->w()->id, 'id' => $rental->id])->assertSessionHasErrors();
        app(RentalService::class)->act($this->u('user'), $rental, 'confirm');
        app(RentalService::class)->act($this->u('owner'), $rental->fresh(), 'activate');
        $this->assertSame('active', $rental->fresh()->status);
        $this->assertNotNull($rental->fresh()->signed_at);
        $this->assertNotNull($rental->fresh()->residency_started_at);
        $this->assertSame('occupied', $request->room->fresh()->status);
        $this->assertSame('completed', $request->fresh()->reservation->status);
    }

    public function test_cancelled_paid_hold_keeps_money_and_disputed_refund_cannot_be_marked_paid(): void
    {
        $this->bank();
        $request = $this->request();
        $service = app(RentalRequestService::class);
        $service->decide($this->u('owner'), $this->w(), $request, 'accept', 1, []);
        $payment = PaymentRequest::where('rental_id', $request->fresh()->rental_id)->firstOrFail();
        app(PaymentRequestService::class)->verify($this->u('owner'), $this->w(), $payment, 'CANCEL-PAID', 1000000);
        $service->cancel($this->u('user'), $request->fresh(), 'Không còn nhu cầu');
        $this->assertNull($request->fresh()->rental->deposits()->first()->settled_at);
        $hold = $request->fresh()->reservation;
        $service->reservationAction($this->u('owner'), $this->w(), $hold, 'release', $hold->version, ['reason' => 'Hai bên xử lý hoàn cọc riêng']);
        $rental = $request->fresh()->rental;
        $refunds = app(RefundService::class);
        $refunds->propose($this->u('owner'), $this->w(), $rental, 100000, 'Đề nghị khấu trừ cần thống nhất theo điều khoản');
        $decision = DepositRefundDecision::firstOrFail();
        $refunds->respond($this->u('user'), $decision, false, 'Tôi chưa đồng ý khoản khấu trừ');
        $this->actingAs($this->u('owner'))->post('/actions/refund.paid', ['context' => 'workspace', 'workspace_id' => $this->w()->id, 'id' => $decision->id, 'reference' => 'REFUND-1', 'confirm' => 1])->assertConflict();
        $refunds->propose($this->u('owner'), $this->w(), $rental, 0, 'Hai bên thống nhất hoàn đầy đủ');
        $decision = DepositRefundDecision::latest('id')->firstOrFail();
        $refunds->respond($this->u('user'), $decision, true, null);
        $refunds->paid($this->u('owner'), $this->w(), $decision->fresh(), 'REFUND-2');
        $refunds->paid($this->u('owner'), $this->w(), $decision->fresh(), 'REFUND-2');
        $this->assertSame('paid', $decision->fresh()->status);
        $this->assertDatabaseCount('payment_request_transactions', 1);
        $this->assertDatabaseHas('deposit_refund_decisions', ['id' => $decision->id, 'deduction' => 0, 'refund_reference' => 'REFUND-2']);
    }

    public function test_vietqr_failure_and_partial_policy_do_not_mark_money_received(): void
    {
        $this->bank();
        $request = $this->request();
        app(RentalRequestService::class)->decide($this->u('owner'), $this->w(), $request, 'accept', 1, []);
        $payment = PaymentRequest::where('rental_id', $request->fresh()->rental_id)->firstOrFail();
        Http::fake(['img.vietqr.io/*' => Http::response('Không khả dụng', 503)]);
        $this->actingAs($this->u('user'))->get('/thanh-toan/'.$payment->id.'/ma-qr?context=personal')->assertStatus(503)->assertSee('Chưa tải được mã QR');
        $this->assertDatabaseCount('payment_request_transactions', 0);
        $this->assertSame('draft', $request->fresh()->rental->status);
        $this->actingAs($this->u('owner'))->post('/thanh-toan/'.$payment->id.'/doi-soat', ['context' => 'workspace', 'workspace_id' => $this->w()->id, 'reference' => 'PARTIAL-DENIED', 'amount' => 500000, 'confirm' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('payment_request_transactions', 0);
    }

    public function test_scoped_gallery_uploads_and_amenities_are_persisted_and_foreign_photos_are_denied(): void
    {
        Storage::fake('local');
        $room = $this->w()->properties()->first()->rooms()->where('status', 'vacant')->firstOrFail();
        $service = app(PropertyService::class);
        $service->saveRoom($this->u('owner'), $room->property, ['amenity_ids' => ['window', 'bed'], 'custom_amenities' => ['  Kệ sách  ', 'kệ sách'], '_amenities_present' => 1, 'gallery' => [UploadedFile::fake()->image('phong.jpg')]], $room->id);
        $this->assertSame(['window', 'bed'], $room->fresh()->amenity_ids);
        $this->assertSame(['Kệ sách'], $room->fresh()->custom_amenities);
        $media = DB::table('housing_media')->first();
        $room->listings()->update(['status' => 'draft']);
        $this->actingAs($this->u('other'))->get('/anh-nha-tro/'.$media->id)->assertNotFound();
        $this->actingAs($this->u('owner'))->get('/anh-nha-tro/'.$media->id)->assertOk();
        $this->post('/actions/room.save', ['context' => 'workspace', 'workspace_id' => $this->w()->id, 'id' => $room->id, 'property_id' => $room->property_id, 'name' => $room->name, 'area' => 20, 'rent' => 1000000, 'deposit' => 0, 'capacity' => 2, 'remove_images' => ['00000000-0000-0000-0000-000000000000']])->assertForbidden();
    }

    public function test_roommate_room_link_requires_current_residency_and_chat_off_has_a_real_fallback(): void
    {
        $foreignRoom = $this->w()->properties()->first()->rooms()->where('status', 'vacant')->firstOrFail();
        $this->actingAs($this->u('user'))->post('/actions/roommate.save', ['context' => 'personal', 'mode' => 'has_room', 'room_id' => $foreignRoom->id, 'district' => 'Bình Thạnh', 'budget' => 1000000, 'move_in' => today()->toDateString(), 'people_needed' => 1, 'lifestyle' => 'Yên tĩnh', 'expires_at' => today()->addMonth()->toDateString()])->assertForbidden();
        config(['features.internal_chat' => false]);
        $this->get('/ui/conversations?context=personal')->assertNotFound();
        $listing = app(ListingService::class)->available()->whereHas('room.property', fn ($q) => $q->where('workspace_id', $this->w()->id))->firstOrFail();
        $this->post('/lien-he-phong', ['listing_id' => $listing->id, 'intent' => 'viewing', 'viewing_at' => now()->addDay()->toDateTimeString()])->assertRedirect('/ui/connections?context=personal');
        $this->assertDatabaseHas('connections', ['listing_id' => $listing->id, 'user_id' => $this->u('user')->id, 'status' => 'viewing_scheduled']);
    }

    public function test_private_preview_and_moderation_preserve_versions_and_room_price_edits_pause_publication(): void
    {
        config(['housing.listing_review_required' => true]);
        $listing = app(ListingService::class)->available()->whereHas('room.property', fn ($q) => $q->where('workspace_id', $this->w()->id))->firstOrFail();
        $service = app(ListingService::class);
        $service->status($this->u('owner'), $listing->id, 'paused');
        $service->status($this->u('owner'), $listing->id, 'published');
        $this->assertSame('pending_review', $listing->fresh()->status);
        $this->actingAs($this->u('owner'))->get('/tin/'.$listing->id.'/xem-truoc')->assertOk()->assertSee('Bản xem trước tin cho thuê');
        $this->actingAs($this->u('other'))->get('/tin/'.$listing->id.'/xem-truoc')->assertForbidden();
        $this->get('/ui/listing-detail?context=public&id='.$listing->id)->assertNotFound();
        $this->actingAs($this->u('platform'))->post('/actions/listing.review', ['context' => 'platform-admin', 'id' => $listing->id, 'decision' => 'approve', 'reason' => 'Thông tin và phòng phù hợp'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('published', $listing->fresh()->status);
        $this->assertSame(2, DB::table('listing_versions')->where('listing_id', $listing->id)->count());
        $oldRent = $listing->room->rent;
        app(PropertyService::class)->saveRoom($this->u('owner'), $listing->room->property, ['rent' => $oldRent + 100000], $listing->room_id);
        $this->assertSame('paused', $listing->fresh()->status);
        $this->assertSame($oldRent, $listing->fresh()->published_snapshot['room']['rent']);
    }

    public function test_waiver_requires_permission_and_preserves_explicit_contract_confirmation(): void
    {
        $this->bank();
        $request = $this->request();
        $service = app(RentalRequestService::class);
        $service->decide($this->u('owner'), $this->w(), $request, 'accept', 1, []);
        $hold = $request->fresh()->reservation;
        $this->actingAs($this->u('staff'))->post('/actions/reservation.action', ['context' => 'workspace', 'workspace_id' => $this->w()->id, 'id' => $hold->id, 'decision' => 'waive', 'version' => 1, 'reason' => 'Miễn tiền cọc'])->assertForbidden();
        $service->reservationAction($this->u('owner'), $this->w(), $hold, 'waive', 1, ['reason' => 'Hai bên thống nhất miễn tiền cọc']);
        $this->assertSame(0, $request->fresh()->rental->deposit);
        $this->assertNull($request->fresh()->rental->confirmed_at);
        $this->assertSame('draft', $request->fresh()->rental->status);
        $this->assertDatabaseHas('payment_requests', ['rental_id' => $hold->rental_id, 'status' => 'cancelled']);
    }

    public function test_operational_health_is_observed_separately_and_reminder_jobs_are_idempotent(): void
    {
        $health = app(DataStoreHealth::class)->check();
        foreach ($health as $row) {
            $this->assertSame('disabled', $row['status']);
            $this->assertNotEmpty($row['checked_at']);
        }
        $this->actingAs($this->u('user'))->post('/actions/data.health', ['context' => 'personal'])->assertForbidden();
        $this->artisan('workflows:remind')->assertSuccessful();
        $count = DB::table('user_notifications')->count();
        $this->artisan('workflows:remind')->assertSuccessful();
        $this->assertSame($count, DB::table('user_notifications')->count());
        $this->artisan('rentals:expire-holds')->assertSuccessful();
        $this->artisan('roommate:expire')->assertSuccessful();
    }

    public function test_viewing_reminders_reach_both_parties_once_and_use_the_updated_appointment(): void
    {
        $listing = app(ListingService::class)->available()->whereHas('room.property', fn ($q) => $q->where('workspace_id', $this->w()->id))->firstOrFail();
        $lead = Connection::create(['listing_id' => $listing->id, 'user_id' => $this->u('user')->id, 'status' => 'viewing_confirmed', 'viewing_at' => now()->addHours(2), 'message' => 'Hẹn xem']);
        $this->artisan('workflows:remind')->assertSuccessful();
        $rows = DB::table('user_notifications')->where('dedupe_key', 'like', 'viewing:'.$lead->id.':%')->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing([$this->u('user')->id, $this->u('owner')->id], $rows->pluck('user_id')->all());
        $this->artisan('workflows:remind')->assertSuccessful();
        $this->assertSame(2, DB::table('user_notifications')->where('dedupe_key', 'like', 'viewing:'.$lead->id.':%')->count());
        $lead->update(['viewing_at' => now()->addHours(3)]);
        $this->artisan('workflows:remind')->assertSuccessful();
        $this->assertSame(4, DB::table('user_notifications')->where('dedupe_key', 'like', 'viewing:'.$lead->id.':%')->count());
        $this->assertSame(2, DB::table('user_notifications')->where('dedupe_key', 'like', 'viewing:'.$lead->id.':%')->whereNull('read_at')->count());
        $lead->update(['status' => 'cancelled']);
        $this->artisan('workflows:remind')->assertSuccessful();
        $this->assertSame(0, DB::table('user_notifications')->where('dedupe_key', 'like', 'viewing:'.$lead->id.':%')->whereNull('read_at')->count());
    }

    public function test_five_hundred_rooms_remain_paginated_and_foreign_properties_stay_hidden(): void
    {
        $property = $this->w()->properties()->firstOrFail();
        RoomFactory::new()->count(500)->sequence(fn ($sequence) => ['name' => 'Phòng kiểm thử phân trang '.$sequence->index])->create(['property_id' => $property->id]);
        $response = $this->actingAs($this->u('owner'))->get('/ui/properties?context=workspace&workspace_id='.$this->w()->id.'&q='.urlencode('Phòng kiểm thử phân trang'));
        $response->assertOk();
        $screen = $response->viewData('screen');
        $this->assertSame(500, $screen['pagination']->total());
        $this->assertCount(config('business.pagination'), $screen['records']);
        $foreign = Room::whereHas('property', fn ($q) => $q->where('workspace_id', '!=', $this->w()->id))->firstOrFail();
        $this->get('/ui/room-detail?context=workspace&workspace_id='.$this->w()->id.'&id='.$foreign->id)->assertNotFound();
        $this->get('/ui/properties?context=workspace&workspace_id='.$this->w()->id.'&q='.urlencode('Phòng kiểm thử phân trang').'&page=2')->assertOk();
    }

    public function test_manual_contract_review_pins_policy_and_rejects_a_changed_terms_quote(): void
    {
        $room = Room::whereHas('property', fn ($q) => $q->where('workspace_id', $this->w()->id))->where('status', 'vacant')->firstOrFail();
        $service = app(RentalService::class);
        $rental = $service->create($this->u('owner'), ['room_id' => $room->id, 'tenant_id' => $this->u('user')->id, 'starts_at' => today()->toDateString(), 'ends_at' => today()->addYear()->toDateString(), 'rent' => 3000000, 'deposit' => 0, 'terms' => 'Điều khoản được xem trước khi đồng ý']);
        $quote = $service->reviewQuote($rental);
        $room->update(['rental_policy' => ['hold_hours' => 12, 'partial_deposit' => true]]);
        $this->assertSame(48, $service->reviewPolicy($rental)['hold_hours']);
        $rental->update(['terms' => 'Điều khoản đã được thay đổi']);
        $this->actingAs($this->u('user')->fresh())->post('/actions/rental.confirm', ['context' => 'personal', 'id' => $rental->id, 'terms_quote' => $quote, 'confirm_terms' => 1])->assertSessionHasErrors('rental');
        $this->assertNull($rental->fresh()->confirmed_at);
        $this->post('/actions/rental.confirm', ['context' => 'personal', 'id' => $rental->id, 'terms_quote' => $service->reviewQuote($rental->fresh()), 'confirm_terms' => 1])->assertRedirect()->assertSessionHasNoErrors();
        app(RentalRequestService::class)->reserveManual($this->u('owner'), $this->w(), $rental->fresh());
        $hold = RoomReservation::where('rental_id', $rental->id)->firstOrFail();
        $this->assertSame(48, $hold->policy_snapshot['hold_hours']);
        $this->assertFalse($hold->policy_snapshot['partial_deposit']);
    }
}
