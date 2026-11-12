<?php

use App\Modules\AccessControl\Models\AccessGroup;
use App\Modules\DataPlatform\Journal;
use Database\Seeders\AccessSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Catalog sync preserves edits to existing permissions and custom groups.
        app(AccessSeeder::class)->run();
        $new = ['deposit.waive', 'bank_account.view', 'bank_account.create', 'bank_account.update', 'bank_account.disable', 'bank_account.set_default', 'payment_request.issue', 'payment.verify', 'reservation.extend', 'reservation.resolve'];
        DB::transaction(function () use ($new) {
            foreach (AccessGroup::where('key', 'owner')->whereNull('workspace_id')->get() as $group) {
                $before = $group->permissions;
                $after = array_values(array_unique([...$before, ...$new]));
                if ($before !== $after) {
                    $group->update(['permissions' => $after]);
                    app(Journal::class)->record(null, 'access.workflow_catalog_synced', $group, null, ['before' => $before, 'after' => $after]);
                }
            }
        });
    }

    public function down(): void
    {
        // Financial permission history is retained. Restore the checkpoint for a full rollback.
    }
};
