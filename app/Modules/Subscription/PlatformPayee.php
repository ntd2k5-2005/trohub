<?php

namespace App\Modules\Subscription;

use Illuminate\Support\Facades\Validator;

/** Operator-declared platform payee; never a landlord default or proof of receipt. */
final class PlatformPayee
{
    public function details(): ?array
    {
        $banks = app(\App\Modules\Billing\PlatformBankService::class);
        if ($banks->managed()) {
            $account = $banks->selected(\Illuminate\Support\Facades\DB::transactionLevel()>0);
            return $account ? $account->only(['bank_bin','bank_name','account_no','account_holder_name']) + ['receiving_account_id'=>$account->id] : null;
        }
        return $this->configured();
    }

    public function configured(): ?array
    {
        $data = config('subscriptions.platform_payee', []);
        $validator = Validator::make($data, [
            'bank_bin' => ['required', 'string', 'regex:/^\d{6}$/'],
            'bank_name' => 'required|string|max:200',
            'account_no' => ['required', 'string', 'regex:/^[A-Za-z0-9]{1,19}$/'],
            'account_holder_name' => 'required|string|min:2|max:100',
        ]);

        return $validator->fails() ? null : $validator->validated();
    }

    public function summary(): ?array
    {
        $payee = $this->details();

        return $payee ? [
            'bank_name' => $payee['bank_name'],
            'account_holder_name' => $payee['account_holder_name'],
            'masked_number' => '•••• '.substr($payee['account_no'], -4),
        ] : null;
    }
}
