<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Models\AuthMailDelivery;
use App\Modules\Identity\Models\EmailChallenge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\MailFake;

class SendAuthMail implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout = 30;

    public function __construct(public string $deliveryId)
    {
        $this->tries = (int) config('email_auth.tries');
    }

    public function backoff(): array
    {
        return config('email_auth.backoff');
    }

    public function handle(): void
    {
        $lease = (string) Str::uuid();
        $mail = DB::transaction(function () use ($lease) {
            $row = AuthMailDelivery::whereKey($this->deliveryId)->lockForUpdate()->first();
            if (! $row || in_array($row->status, ['accepted', 'cancelled'], true)) {
                return null;
            }
            if ($row->expires_at->lessThanOrEqualTo(now()) || ! $row->payload) {
                $row->update(['status' => 'cancelled', 'payload' => null]);

                return null;
            }
            if ($row->challenge_id && ! EmailChallenge::whereKey($row->challenge_id)->whereNotNull('active_user_id')->whereNull('consumed_at')->whereNull('revoked_at')->exists()) {
                $row->update(['status' => 'cancelled', 'payload' => null]);

                return null;
            }
            if ($row->status === 'sending' && $row->processing_at?->greaterThan(now()->subSeconds(config('email_auth.lease_seconds')))) {
                return 'busy';
            }
            if ($row->attempts >= $this->tries) {
                return null;
            }
            $row->update(['status' => 'sending', 'lease' => $lease, 'processing_at' => now(), 'attempts' => $row->attempts + 1]);

            return $row;
        });
        if ($mail === 'busy') {
            $this->release(config('email_auth.lease_seconds'));

            return;
        }
        if (! $mail) {
            return;
        }
        try {
            $payload = $mail->payload;
            $mocked = Mail::getFacadeRoot() instanceof MailFake || app()->runningUnitTests();
            $sent = Mail::to($payload['email'])->send(new AuthEmail($payload, $mail->id));
            if (! $mocked && $sent === null) {
                throw new \RuntimeException('Thư chưa được chuyển cho dịch vụ gửi email.');
            }
            $transport = config('mail.mailers.'.config('mail.default').'.transport');
            $acceptance = $mocked ? 'MOCK_TEST_PASSED' : ($transport === 'smtp' ? 'SMTP_ACCEPTED' : 'LOCAL_TRANSPORT_ONLY');
            AuthMailDelivery::whereKey($mail->id)->where('lease', $lease)->where('status', 'sending')->update(['status' => 'accepted', 'acceptance' => $acceptance, 'accepted_at' => now(), 'payload' => null, 'lease' => null]);
        } catch (\Throwable) {
            AuthMailDelivery::whereKey($mail->id)->where('lease', $lease)->where('status', 'sending')->update(['status' => 'failed', 'lease' => null]);
            // Do not preserve the raw transport exception: it may contain credentials or mail content.
            throw new \RuntimeException('Không gửi được thư xác thực. Vui lòng thử lại sau.');
        }
    }
}
