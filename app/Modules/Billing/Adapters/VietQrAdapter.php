<?php

namespace App\Modules\Billing\Adapters;

use App\Modules\Billing\Contracts\VietQrGenerator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class VietQrAdapter implements VietQrGenerator
{
    public function image(array $payee, int $amount, string $reference): string
    {
        $api = config('vietqr.driver') === 'api';
        if (! preg_match('/^\d{6}$/', $payee['bank_bin']) || ! preg_match($api ? '/^\d{6,19}$/' : '/^[A-Za-z0-9]{1,19}$/', $payee['account_no']) || ! preg_match('/^[A-Z0-9]{12,18}$/', $reference) || $amount <= 0 || $amount > 9999999999999) {
            throw new RuntimeException('VIETQR_INVALID_INPUT');
        }
        $template = config('vietqr.template');
        if (! preg_match('/^[A-Za-z0-9_]{1,80}$/', $template)) {
            throw new RuntimeException('VIETQR_INVALID_TEMPLATE');
        }
        $http = Http::connectTimeout(3)->timeout(config('vietqr.timeout'))->withoutRedirecting()->retry(2, 200, throw: false);
        if ($api) {
            if (! config('vietqr.client_id') || ! config('vietqr.api_key')) {
                throw new RuntimeException('VIETQR_CREDENTIALS_REQUIRED');
            }
            $data = $http->withHeaders(['x-client-id' => config('vietqr.client_id'), 'x-api-key' => config('vietqr.api_key')])->post(config('vietqr.generate_url'), [
                'accountNo' => $payee['account_no'], 'acqId' => (int) $payee['bank_bin'], 'accountName' => Str::upper(Str::ascii($payee['account_holder_name'])),
                'amount' => $amount, 'addInfo' => $reference, 'format' => 'text', 'template' => $template,
            ])->throw()->json();
            $base64 = $data['data']['qrDataURL'] ?? '';
            if (($data['code'] ?? '') !== '00' || ! str_starts_with($base64, 'data:image/png;base64,')) {
                throw new RuntimeException('VIETQR_PROVIDER_DOWN');
            }
            $bytes = base64_decode(substr($base64, 22), true);
        } else {
            if (config('vietqr.driver') !== 'quicklink') {
                throw new RuntimeException('VIETQR_UNAVAILABLE');
            }
            $response = $http->get(config('vietqr.image_base').$payee['bank_bin'].'-'.$payee['account_no'].'-'.$template.'.svg', ['amount' => $amount, 'addInfo' => $reference, 'accountName' => $payee['account_holder_name']])->throw();
            $bytes = $response->body();
        }
        if (! is_string($bytes)) {
            throw new RuntimeException('VIETQR_INVALID_IMAGE');
        }

        return QrImage::validate($bytes);
    }
}
