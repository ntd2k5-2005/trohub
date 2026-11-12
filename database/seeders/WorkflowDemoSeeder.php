<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Billing\Models\PaymentRequest;
use App\Modules\Billing\PaymentRequestService;
use App\Modules\Billing\ReceivingAccountService;
use App\Modules\Listing\ListingService;
use App\Modules\Rental\RefundService;
use App\Modules\Rental\RentalPolicy;
use App\Modules\Rental\RentalRequestService;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;

/** Optional visual fixtures. Never auto-run against the application's MySQL database. */
final class WorkflowDemoSeeder extends Seeder
{
    public function run(): void
    {
        $database = str_replace('\\', '/', config('database.connections.sqlite.database'));
        abort_unless(config('database.default') === 'sqlite' && str_starts_with($database, str_replace('\\', '/', base_path('kho/workflow-spec/'))), 403, 'Chỉ chạy dữ liệu minh họa trên SQLite riêng trong kho/workflow-spec.');
        if (PaymentRequest::exists()) {
            return;
        }
        $owner = User::where('email', 'owner@trohub.test')->firstOrFail();
        $workspace = Workspace::where('owner_id', $owner->id)->firstOrFail();
        // Infrastructure boundary only. Business state changes below use the real services.
        Http::fake(['api.vietqr.io/v2/banks' => Http::response(['code' => '00', 'data' => [['bin' => '970415', 'name' => 'Ngân hàng TMCP Công Thương Việt Nam', 'shortName' => 'VietinBank', 'transferSupported' => 1]]])]);
        $accounts = app(ReceivingAccountService::class);
        $account = $accounts->save($owner, $workspace, ['bank_bin' => '970415', 'account_no' => '000000000001', 'account_holder_name' => 'TAI KHOAN MINH HOA', 'label' => 'Nhận tiền minh họa', 'declaration' => 1]);
        $accounts->setDefault($owner, $workspace, $account->id, null);
        $listings = app(ListingService::class)->available()->whereHas('room.property', fn ($q) => $q->where('workspace_id', $workspace->id))->take(5)->get();
        $actors = ['user', 'tenant', 'multi', 'grant', 'other'];
        foreach ($listings as $index => $listing) {
            $user = User::where('email', $actors[$index].'@trohub.test')->firstOrFail();
            $listing->room->update(['deposit' => 1000000, 'rental_policy' => ['partial_deposit' => $index === 2]]);
            $room = $listing->room->fresh();
            $service = app(RentalRequestService::class);
            $request = $service->submit($user, $listing->id, ['starts_at' => today()->toDateString(), 'ends_at' => today()->addYear()->toDateString(), 'people' => 1, 'message' => 'Nhu cầu thuê minh họa', 'accept_terms' => 1, 'quote' => app(RentalPolicy::class)->quote($room)]);
            if ($index === 0) {
                continue;
            }
            $service->decide($owner, $workspace, $request, 'accept', 1, []);
            $payment = PaymentRequest::where('rental_id', $request->fresh()->rental_id)->firstOrFail();
            if ($index === 2) {
                app(PaymentRequestService::class)->verify($owner, $workspace, $payment, 'MINHHOA-PARTIAL', 300000);
            }
            if ($index === 3) {
                app(PaymentRequestService::class)->verify($owner, $workspace, $payment, 'MINHHOA-RECEIVED', 1000000);
                $service->cancel($user, $request->fresh(), 'Đổi kế hoạch thuê');
                $hold = $request->fresh()->reservation;
                $service->reservationAction($owner, $workspace, $hold, 'release', $hold->version, ['reason' => 'Thống nhất xử lý hoàn cọc riêng']);
                app(RefundService::class)->propose($owner, $workspace, $request->fresh()->rental, 0, 'Đề nghị hoàn toàn bộ tiền cọc đã nhận');
            }
            if ($index === 4) {
                app(PaymentRequestService::class)->report($user, $payment, 'MINHHOA-PROOF', 'Đã gửi thông tin, chờ đối soát', null);
            }
        }
        Http::preventStrayRequests(false);
    }
}
