<?php

namespace Database\Factories;

use App\Modules\Billing\Models\ReceivingAccount;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReceivingAccountFactory extends Factory
{
    protected $model = ReceivingAccount::class;

    public function definition(): array
    {
        return ['workspace_id' => WorkspaceFactory::new(), 'bank_bin' => '970415', 'bank_name' => 'VietinBank', 'account_no' => fake()->unique()->numerify('0000########'), 'account_fingerprint' => fn (array $attributes) => hash_hmac('sha256', $attributes['bank_bin'].':'.$attributes['account_no'], config('app.key')), 'account_holder_name' => 'TAI KHOAN MINH HOA', 'label' => 'Tài khoản nhận tiền', 'status' => 'active', 'verification_status' => 'not_checked', 'created_by' => fn (array $attributes) => Workspace::findOrFail($attributes['workspace_id'])->owner_id];
    }
}
