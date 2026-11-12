<?php

namespace App\Modules\Billing;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Adapters\QrImage;
use App\Modules\Billing\Contracts\VietQrGenerator;
use App\Modules\Billing\Models\PaymentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class PaymentRequestController extends Controller
{
    public function proof(Request $r, PaymentRequest $paymentRequest, int $report, PaymentRequestService $service)
    {
        $service->authorize($r->user(), $paymentRequest, $r->attributes->get('workspace'));
        $record = DB::table('payment_transfer_reports')->where('payment_request_id', $paymentRequest->id)->where('id', $report)->first();
        abort_unless(app(\App\Modules\Subscription\PropertyCloud::class)->active($paymentRequest->workspace_id) || $record && $record->status === 'verified', 403);
        abort_unless($record && $record->evidence_path && str_starts_with($record->evidence_path, 'payment-evidence/') && ! str_contains($record->evidence_path, '..') && Storage::disk('local')->exists($record->evidence_path), 404);

        return response()->file(Storage::disk('local')->path($record->evidence_path), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function reject(Request $r, PaymentRequest $paymentRequest, PaymentRequestService $service)
    {
        $data = $r->validate(['report_id' => 'required|integer|min:1', 'reason' => 'required|string|max:2000']);
        abort_unless($r->attributes->get('workspace'), 403);
        $service->rejectProof($r->user(), $r->attributes->get('workspace'), $paymentRequest, (int) $data['report_id'], $data['reason']);

        return back()->with('status', 'Đã ghi nhận lý do cần kiểm tra lại chứng từ.');
    }

    public function image(Request $r, PaymentRequest $paymentRequest, PaymentRequestService $service, VietQrGenerator $generator)
    {
        $service->authorize($r->user(), $paymentRequest, $r->attributes->get('workspace'));
        abort_unless($service->usable($paymentRequest), 409, 'Yêu cầu đã thay đổi hoặc hết hạn. Vui lòng không chuyển theo mã cũ.');
        $amount = $service->remaining($paymentRequest);
        try {
            $bytes = Cache::remember('vietqr:v3:request:'.$paymentRequest->id.':'.$amount, 3600, function () use ($generator, $paymentRequest, $amount) {
                $paymentRequest->increment('qr_attempts');
                $bytes = $generator->image($paymentRequest->payee_snapshot, $amount, $paymentRequest->transfer_reference);
                $paymentRequest->update(['qr_status' => 'ready', 'qr_generated_at' => now()]);

                return $bytes;
            });

            $type = QrImage::type($bytes);
            $extension = $type === 'image/png' ? 'png' : 'svg';

            return response($bytes, 200, ['Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src data:", 'Content-Type' => $type, 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => ($r->boolean('download') ? 'attachment' : 'inline').'; filename="chuyen-khoan-'.$paymentRequest->id.'.png"']);
        } catch (Throwable) {
            $paymentRequest->update(['qr_status' => 'unavailable']);

            // HTTP exceptions may include bank account numbers/credentials. Never expose or log them.
            return response('Chưa tải được mã QR. Bạn vẫn có thể dùng thông tin chuyển khoản và thử lại sau.', 503, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }
    }

    public function report(Request $r, PaymentRequest $paymentRequest, PaymentRequestService $service)
    {
        $data = $r->validate(['reference' => 'required|string|max:100', 'note' => 'nullable|string|max:1000', 'evidence' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:4096']);
        $service->report($r->user(), $paymentRequest, $data['reference'], $data['note'] ?? null, $r->file('evidence'));

        return back()->with('status', 'Đã gửi thông tin chuyển khoản. Vui lòng chờ đối soát.');
    }

    public function verify(Request $r, PaymentRequest $paymentRequest, PaymentRequestService $service)
    {
        $data = $r->validate(['reference' => 'required|string|max:100', 'amount' => 'required|integer|min:1|max:1000000000', 'reason' => 'nullable|string|max:2000', 'confirm' => 'accepted']);
        abort_unless($r->attributes->get('workspace'), 403);
        $service->verify($r->user(), $r->attributes->get('workspace'), $paymentRequest, $data['reference'], (int) $data['amount'], $data['reason'] ?? null);

        return back()->with('status', 'Đã ghi nhận giao dịch thực nhận. Kiểm tra tình trạng phân bổ và ngoại lệ trong hồ sơ.');
    }
}
