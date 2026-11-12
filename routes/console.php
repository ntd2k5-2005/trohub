<?php

use App\Modules\Billing\BillingService;
use App\Modules\DataPlatform\ProjectionManager;
use App\Modules\Notification\WorkflowReminders;
use App\Modules\Rental\RentalRequestService;
use App\Modules\Roommate\RoommateService;
use App\Modules\Subscription\SubscriptionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('permissions:review-legacy',function(){
    $this->line(json_encode(app(\App\Modules\AccessControl\GrantExplanation::class)->report(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
})->purpose('Phân loại nguồn quyền theo chứng cứ, không thay đổi quyền');

Artisan::command('audit:verify',function(){
    $result=app(\App\Modules\DataPlatform\AuditIntegrity::class)->verify();
    $this->line(json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    return $result['valid']?0:1;
})->purpose('Kiểm tra tính toàn vẹn nhật ký, không sửa dữ liệu');

Artisan::command('subscriptions:review-legacy {owner}',function(){
    $this->line(json_encode(app(\App\Modules\Subscription\LegacySubscriptionReview::class)->report((int)$this->argument('owner')),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
});

Artisan::command('data:project {--limit=100}', function () {
    $this->info('Sự kiện đã đồng bộ: '.app(ProjectionManager::class)->drain((int) $this->option('limit')));
});
Artisan::command('data:replay {workspace} {--from=} {--until=} {--limit=100}', function () {
    $service = app(ProjectionManager::class);
    $workspace = (int) $this->argument('workspace');
    $this->info('Sự kiện đưa vào hàng đợi: '.$service->replay($workspace, $this->option('from') ?: '1970-01-01 00:00:00', $this->option('until') ?: now()->toDateTimeString()));
    $this->info('Sự kiện đã đồng bộ: '.$service->drain(max(1, min(10000, (int) $this->option('limit'))), $workspace));
});
Artisan::command('subscriptions:expire', function () {
    app(\App\Modules\Subscription\AccountSubscriptionService::class)->activateDue();
    app(\App\Modules\Subscription\AccountSubscriptionService::class)->expireAccounts();
    app(SubscriptionService::class)->expire();
    $this->info('Đã kiểm tra thời hạn gói.');
});
Artisan::command('subscriptions:purge-trials', function () {
    $this->info('Dữ liệu dùng thử được bảo toàn. Không thực hiện xóa dữ liệu.');
});
Schedule::command('subscriptions:expire')->everyMinute()->withoutOverlapping();
Schedule::command('data:project')->everyMinute()->withoutOverlapping()->runInBackground();
Artisan::command('billing:remind', function () {
    $this->info('Thông báo nhắc hạn đã tạo: '.app(BillingService::class)->remindDueInvoices());
});
Schedule::command('billing:remind')->dailyAt('08:00')->withoutOverlapping();
Artisan::command('rentals:expire-holds', function () {
    $this->info('Hồ sơ giữ phòng đã xử lý: '.app(RentalRequestService::class)->expire());
});
Schedule::command('rentals:expire-holds')->everyMinute()->withoutOverlapping();
Artisan::command('roommate:expire', function () {
    $this->info('Nhu cầu ở ghép đã hết hạn: '.app(RoommateService::class)->expire());
});
Schedule::command('roommate:expire')->hourly()->withoutOverlapping();
Artisan::command('workflows:remind', function () {
    app(WorkflowReminders::class)->run();
    $this->info('Đã kiểm tra lịch xem, hợp đồng và thời hạn giữ phòng.');
});
Schedule::command('workflows:remind')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('trohub:about', function (): void {
    $this->info(config('product.name').' — '.config('product.tagline'));
    $this->line('Tìm phòng, quản lý thuê, tài chính và an toàn nhà trọ.');
})->purpose('Hiển thị thông tin ngắn về dự án');

Artisan::command('listings:reindex {--repair}', function () {
    if (! config('data_stores.meilisearch.enabled')) { $this->info('Tìm kiếm mở rộng đang tắt; hệ thống sử dụng dữ liệu chính.'); return 0; }
    $index = app(\App\Modules\Listing\ListingSearchIndex::class);
    if ($this->option('repair') && ! $index->dirty()) return 0;
    try {
        $this->info('Đã cập nhật chỉ mục cho '.$index->rebuild().' tin công khai.');
        return 0;
    } catch (\Throwable) {
        $this->warn('Chưa cập nhật được chỉ mục. Tìm phòng vẫn sử dụng dữ liệu chính; hãy thử lại khi dịch vụ sẵn sàng.');
        return 1;
    }
})->purpose('Xây dựng lại chỉ mục từ các tin đủ điều kiện công khai');
Schedule::command('listings:reindex --repair')->everyMinute()->withoutOverlapping();
