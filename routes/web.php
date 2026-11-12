<?php

use App\Http\Controllers\ActionController;
use App\Http\Controllers\UiController;
use App\Modules\Billing\ExportService;
use App\Modules\Billing\PaymentRequestController;
use App\Modules\Chat\ChatController;
use App\Modules\Connection\ConversationController;
use App\Modules\Identity\AuthController;
use App\Modules\Identity\SocialAuthController;
use App\Modules\Identity\EmailActivationController;
use App\Modules\Listing\ListingPreviewController;
use App\Modules\Listing\ListingService;
use App\Modules\Listing\Models\Listing;
use App\Modules\Property\HousingMediaController;
use App\Modules\Workspace\InvitationController;
use App\Modules\Workspace\InvitationService;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\WorkspaceService;
use App\Support\ScopedRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/xac-minh-email', [EmailActivationController::class, 'notice'])->name('email.activation.notice');
Route::post('/xac-minh-email/gui-lai', [EmailActivationController::class, 'resend'])->middleware('throttle:3,1,email-resend-')->name('email.activation.resend');
Route::post('/xac-minh-email/otp', [EmailActivationController::class, 'otp'])->middleware('throttle:6,1,email-otp-')->name('email.activation.otp');
Route::get('/xac-minh-email/{challenge}/{token}', [EmailActivationController::class, 'link'])->whereUuid('challenge')->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:20,1,email-link-')->name('email.activation.link');
Route::post('/xac-minh-email/link', [EmailActivationController::class, 'confirm'])->middleware('throttle:6,1,email-confirm-')->name('email.activation.confirm');

Route::prefix('auth')->name('social.')->group(function (): void {
    Route::get('/complete', [SocialAuthController::class, 'complete'])->middleware('throttle:30,1,social-complete-')->name('complete');
    Route::post('/email', [SocialAuthController::class, 'email'])->middleware('throttle:3,1,social-email-')->name('email');
    Route::post('/verify', [SocialAuthController::class, 'verify'])->middleware('throttle:6,1,social-verify-')->name('verify');
    Route::post('/authenticate', [SocialAuthController::class, 'authenticateExisting'])->middleware('throttle:6,1,social-authenticateExisting-')->name('authenticate');
    Route::post('/cancel', [SocialAuthController::class, 'cancel'])->middleware('throttle:10,1,social-cancel-')->name('cancel');
    Route::get('/{provider}/redirect', [SocialAuthController::class, 'redirect'])->where('provider', 'google|facebook')->middleware('throttle:10,1,social-redirect-')->name('redirect');
    Route::get('/{provider}/callback', [SocialAuthController::class, 'callback'])->where('provider', 'google|facebook')->middleware('throttle:10,1,social-callback-')->name('callback');
    Route::post('/{provider}/link', [SocialAuthController::class, 'link'])->where('provider', 'google|facebook')->middleware(['auth', 'throttle:6,1,social-link-'])->name('link');
    Route::post('/{provider}/unlink', [SocialAuthController::class, 'unlink'])->where('provider', 'google|facebook')->middleware(['auth', 'throttle:6,1,social-unlink-'])->name('unlink');
});

Route::get('/tin/{listing}/xem-truoc', [ListingPreviewController::class, 'show'])->whereNumber('listing')->middleware('auth')->name('listing.preview');

Route::post('/lien-he-phong', [ConversationController::class, 'open'])->middleware(['auth', 'throttle:20,1'])->name('conversation.open');
Route::get('/anh-nha-tro/{media}', [HousingMediaController::class, 'show'])->whereUuid('media')->name('housing.image');
Route::get('/trao-doi/{connection}/tin-nhan', [ConversationController::class, 'messages'])->whereNumber('connection')->middleware(['auth', 'chat.enabled', 'throttle:120,1'])->name('conversation.messages');
Route::post('/trao-doi/{connection}/tin-nhan', [ConversationController::class, 'send'])->whereNumber('connection')->middleware(['auth', 'chat.enabled', 'throttle:30,1'])->name('conversation.send');

Route::get('/tro-chuyen/danh-sach', [ChatController::class, 'inbox'])->middleware(['auth', 'chat.enabled', 'ui.context', 'throttle:120,1'])->name('chat.inbox');
Route::get('/tro-chuyen/{thread}/tin-nhan', [ChatController::class, 'messages'])->whereNumber('thread')->middleware(['auth', 'chat.enabled', 'throttle:120,1'])->name('chat.messages');
Route::post('/tro-chuyen/{thread}/tin-nhan', [ChatController::class, 'send'])->whereNumber('thread')->middleware(['auth', 'chat.enabled', 'throttle:30,1'])->name('chat.send');
Route::post('/tro-chuyen/{thread}/da-xem', [ChatController::class, 'read'])->whereNumber('thread')->middleware(['auth', 'chat.enabled', 'throttle:120,1'])->name('chat.read');

Route::get('/dang-nhap', function (Request $request) {
    $data = $request->validate(['listing' => 'nullable|integer|min:1']);
    if (! empty($data['listing'])) {
        app(ListingService::class)->available()->findOrFail($data['listing']);
        $request->session()->put('url.intended', route('ui.show', ['slug' => 'listing-detail', 'context' => 'public', 'id' => $data['listing']]));
    }

    return redirect()->route('ui.show', ['slug' => 'login', 'context' => 'public']);
})->name('login');
Route::post('/dang-nhap', [AuthController::class, 'login'])->middleware('throttle:6,1,auth-login-store-')->name('login.store');
Route::post('/dang-ky', [AuthController::class, 'register'])->middleware('throttle:6,1,auth-register-store-')->name('register.store');
Route::post('/quen-mat-khau', [AuthController::class, 'forgot'])->middleware('throttle:3,1,auth-password-email-')->name('password.email');
Route::get('/dat-lai-mat-khau/{token}', fn (Request $r, string $token) => redirect()->route('ui.show', ['slug' => 'recover-access', 'context' => 'public', 'token' => $token, 'email' => $r->query('email')]))->name('password.reset');
Route::post('/dat-lai-mat-khau', [AuthController::class, 'reset'])->middleware('throttle:6,1,auth-password-update-')->name('password.update');
Route::post('/dang-xuat', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::get('/tin/{listing}/anh', function (int $listing) {
    $record = app(ListingService::class)->available()->find($listing);
    if (! $record && auth()->check()) {
        $record = Listing::findOrFail($listing);
        app(ScopedRepository::class)->managedRoom(auth()->user(), $record->room_id, 'listing.view');
    }
    abort_unless($record, 404);
    abort_unless($record->cover_path && Storage::disk('local')->exists($record->cover_path), 404);

    return response()->file(Storage::disk('local')->path($record->cover_path), ['X-Content-Type-Options' => 'nosniff']);
})->name('listing.cover');
Route::middleware('ui.context')->group(function (): void {
    Route::get('/quan-tri/kinh-doanh/bao-cao.csv', \App\Modules\Subscription\BusinessReportController::class)->middleware(['auth','throttle:10,1'])->name('business.report.export');
    Route::get('/thanh-toan-goi/{quote}/ma-qr', [\App\Modules\Subscription\SubscriptionPaymentController::class, 'image'])->whereNumber('quote')->middleware(['auth', 'throttle:30,1'])->name('subscription-payment.qr');
    Route::get('/ban-giao/{case}/tai-lieu/{evidence}',\App\Modules\Workspace\OwnershipEvidenceController::class)->whereNumber(['case','evidence'])->middleware('auth')->name('ownership.evidence');
    Route::get('/thanh-toan/{paymentRequest}/chung-tu/{report}', [PaymentRequestController::class, 'proof'])->whereNumber(['paymentRequest', 'report'])->middleware('auth')->name('payment-request.proof');
    Route::post('/thanh-toan/{paymentRequest}/kiem-tra-lai', [PaymentRequestController::class, 'reject'])->whereNumber('paymentRequest')->middleware(['auth', 'throttle:10,1'])->name('payment-request.reject');
    Route::get('/thanh-toan/{paymentRequest}/ma-qr', [PaymentRequestController::class, 'image'])->whereNumber('paymentRequest')->middleware(['auth', 'throttle:30,1'])->name('payment-request.qr');
    Route::post('/thanh-toan/{paymentRequest}/bao-chuyen-khoan', [PaymentRequestController::class, 'report'])->whereNumber('paymentRequest')->middleware(['auth', 'throttle:10,1'])->name('payment-request.report');
    Route::post('/thanh-toan/{paymentRequest}/doi-soat', [PaymentRequestController::class, 'verify'])->whereNumber('paymentRequest')->middleware(['auth', 'throttle:10,1'])->name('payment-request.verify');
    Route::get('/xuat-du-lieu/{type}', function (Request $r, string $type) {
        return app(ExportService::class)->download($r->user(), $r->attributes->get('workspace'), $type);
    })->where('type', 'invoice|payment')->middleware(['auth', 'throttle:10,1'])->name('billing.export');
    Route::get('/khong-gian', [UiController::class, 'enter'])->name('ui.enter');
    Route::post('/actions/{action}', ActionController::class)->middleware(['auth', 'throttle:60,1'])->where('action', '[a-z._]+')->name('action');
    Route::post('/ho-so', [AuthController::class, 'profile'])->middleware('auth')->name('profile.update');
    Route::get('/', [UiController::class, 'home'])->name('home');

    Route::get('/ui/{slug?}', [UiController::class, 'show'])
        ->where('slug', '[A-Za-z0-9\-]+')
        ->name('ui.show');
});

Route::get('/loi-moi-nhan/{invitation}', [InvitationController::class, 'show'])->whereNumber('invitation')->middleware('auth')->name('invitation.show');
Route::post('/loi-moi-nhan/{invitation}', [InvitationController::class, 'respond'])->whereNumber('invitation')->middleware(['auth', 'throttle:10,1'])->name('invitation.respond');
Route::get('/loi-moi/{token}', function (Request $request, string $token) {
    $invite = app(InvitationService::class)->preview($request->user(), $token);

    return view('product.invitation', ['declineUrl' => $request->url(), 'token' => $token, 'invite' => $invite, 'workspace' => Workspace::findOrFail($invite->workspace_id), 'permissionNames' => app(InvitationService::class)->permissionNames($invite)]);
})->middleware('auth')->name('invitation.accept');
Route::post('/loi-moi/{token}', function (Request $r, string $token) {
    $r->validate(['decision' => 'sometimes|in:accept,decline']);
    if ($r->input('decision') === 'decline') {
        app(InvitationService::class)->declineToken($r->user(), $token);

        return redirect()->route('ui.show', ['slug' => 'notifications', 'context' => 'personal'])->with('status', 'Đã từ chối lời mời.');
    }
    app(WorkspaceService::class)->accept($r->user(), $token);

    return redirect()->route('ui.show', ['slug' => 'personal-dashboard', 'context' => 'personal'])->with('status', 'Đã chấp nhận lời mời.');
})->middleware(['auth', 'throttle:10,1']);
