<?php

namespace App\Modules\Billing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

final class BankCatalog
{
    public function list(): array
    {
        $cached = Cache::get('vietqr:banks');
        if ($cached && $cached['fetched_at'] > now()->subSeconds(config('vietqr.catalog_ttl'))->timestamp) {
            return $cached + ['stale' => false];
        }
        try {
            $response = Http::connectTimeout(3)->timeout(config('vietqr.timeout'))->get(config('vietqr.banks_url'))->throw()->json();
            if (($response['code'] ?? null) !== '00' || ! is_array($response['data'] ?? null)) {
                throw new \RuntimeException('Invalid bank catalog');
            }
            $banks = collect($response['data'])->filter(fn ($bank) => preg_match('/^\d{6}$/', (string) ($bank['bin'] ?? '')) && (int) ($bank['transferSupported'] ?? 0) === 1)
                ->mapWithKeys(fn ($bank) => [(string) $bank['bin'] => ['bin' => (string) $bank['bin'], 'name' => (string) $bank['name'], 'short_name' => (string) $bank['shortName']]])->all();
            if (! $banks) {
                throw new \RuntimeException('Empty bank catalog');
            }
            $value = ['banks' => $banks, 'fetched_at' => now()->timestamp];
            Cache::put('vietqr:banks', $value, config('vietqr.catalog_stale_ttl'));

            return $value + ['stale' => false];
        } catch (Throwable) {
            return ($cached ?? ['banks' => [], 'fetched_at' => null]) + ['stale' => true];
        }
    }

    public function bank(string $bin): array
    {
        $bank = $this->list()['banks'][$bin] ?? null;
        if (! $bank) {
            throw ValidationException::withMessages(['bank_bin' => 'Chưa xác định được ngân hàng hỗ trợ. Vui lòng tải lại danh sách và chọn ngân hàng.']);
        }

        return $bank;
    }
}
