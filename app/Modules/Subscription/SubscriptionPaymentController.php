<?php

namespace App\Modules\Subscription;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Adapters\QrImage;
use App\Modules\Billing\Contracts\VietQrGenerator;
use App\Modules\Subscription\Models\SubscriptionQuote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SubscriptionPaymentController extends Controller
{
    public function image(Request $request, SubscriptionQuote $quote, AccountSubscriptionService $subscriptions, VietQrGenerator $generator)
    {
        $subscriptions->authorizePayer($request->user(), $quote);
        abort_unless($subscriptions->payable($quote), 409, 'Báo giá đã thay đổi hoặc hết hạn. Vui lòng không chuyển theo mã cũ.');
        try {
            $bytes = Cache::remember('vietqr:subscription:'.$quote->id.':'.$quote->transfer_reference, 3600, fn () => $generator->image($quote->payee_snapshot, $quote->amount, $quote->transfer_reference));
            $type = QrImage::type($bytes);

            return response($bytes, 200, ['Content-Type' => $type, 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:", 'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="thanh-toan-goi-'.$quote->id.'.'.($type === 'image/png' ? 'png' : 'svg').'"']);
        } catch (Throwable) {
            return response('Chưa tải được mã QR. Bạn có thể dùng thông tin chuyển khoản hoặc thử lại sau.', 503, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }
    }
}
