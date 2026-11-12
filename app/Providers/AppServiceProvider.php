<?php

namespace App\Providers;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\AccessControl\AccessService;
use App\Modules\AccessControl\GroupAssignments;
use App\Modules\Billing\Adapters\VietQrAdapter;
use App\Modules\Billing\CashDepositService;
use App\Modules\Billing\Contracts\VietQrGenerator;
use App\Modules\Billing\ReservationPayments;
use App\Modules\Billing\SettlementLedger;
use App\Modules\DataPlatform\Adapters\ResilientCache;
use App\Modules\DataPlatform\Contracts\HotCache;
use App\Modules\Notification\Events\InboxOpened;
use App\Modules\Property\HousingMedia;
use App\Modules\Rental\Contracts\CashDepositRecorder;
use App\Modules\Workspace\Events\InvitationUpdated;
use App\Modules\Workspace\InvitationNotifications;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(\App\Modules\Subscription\PropertyCloud::class);
        $this->app->scoped(\App\Modules\Trust\ReviewDetailsService::class);
        $this->app->scoped(\App\Modules\IoT\SafetyHistoryRepository::class);
        $this->app->bind(\App\Contracts\WorkAssignments::class, \App\Modules\Workspace\WorkAssignmentService::class);
        $this->app->scoped(HousingMedia::class, fn () => new HousingMedia);
        $this->app->bind(VietQrGenerator::class, VietQrAdapter::class);
        $this->app->bind(\App\Modules\Rental\Contracts\ReservationPayments::class, ReservationPayments::class);
        $this->app->bind(CashDepositRecorder::class, CashDepositService::class);
        $this->app->bind(Authorization::class, AccessService::class);
        $this->app->bind(HotCache::class, ResilientCache::class);
        $this->app->bind(\App\Modules\DataPlatform\Contracts\DocumentStore::class, \App\Modules\DataPlatform\Adapters\MongoDocuments::class);
        $this->app->bind(\App\Modules\Rental\Contracts\SettlementLedger::class, SettlementLedger::class);
    }

    public function boot(): void
    {
        Paginator::useTailwind();
        \App\Modules\Listing\ListingIndexUpdates::register();
        User::created(fn ($u) => app(GroupAssignments::class)->defaults($u));
        Workspace::created(fn ($w) => app(GroupAssignments::class)->owner($w));
        Event::listen(InboxOpened::class, [InvitationNotifications::class, 'inboxOpened']);
        Event::listen(InvitationUpdated::class, [InvitationNotifications::class, 'changed']);
        Event::listen(Login::class, [InvitationNotifications::class, 'signedIn']);
        ResetPassword::toMailUsing(function ($user, $token) {
            return (new MailMessage)
                ->subject('Khôi phục mật khẩu · '.config('product.name'))
                ->greeting('Xin chào '.$user->name.',')
                ->line('Bạn vừa yêu cầu khôi phục mật khẩu tài khoản.')
                ->action('Đặt lại mật khẩu', route('password.reset', ['token' => $token, 'email' => $user->email]))
                ->line('Nếu bạn không gửi yêu cầu này, bạn có thể bỏ qua email.');
        });
    }
}
