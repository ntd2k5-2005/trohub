<?php

namespace App\Modules\Billing;

use App\Contracts\Authorization;
use App\Models\User;
use App\Modules\Billing\Models\ReceivingAccount;
use App\Modules\Workspace\Models\Workspace;

final class ReceivingAccountRepository
{
    public function visible(User $user, Workspace $workspace, string $permission = 'bank_account.view')
    {
        $access = app(Authorization::class);
        $all = $access->allowed($user, $permission, $workspace);
        $properties = $access->propertyIds($user, $workspace, $permission);

        return ReceivingAccount::where('workspace_id', $workspace->id)->when(! $all, fn ($q) => $q->whereIn('property_id', $properties));
    }
}
