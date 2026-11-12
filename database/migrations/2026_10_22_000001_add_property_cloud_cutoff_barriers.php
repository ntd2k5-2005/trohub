<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $t) {
            $t->unsignedBigInteger('cloud_epoch')->default(1);
            $t->timestamp('cloud_cutoff_at')->nullable();
            $t->timestamp('cloud_resumed_at')->nullable();
        });
        Schema::table('listings', fn (Blueprint $t) => $t->unsignedBigInteger('cloud_epoch')->default(1)->index());
        foreach (['invoices', 'room_reservations', 'connections', 'rentals'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('cloud_epoch')->default(1));
        }
        Schema::table('user_notifications', function (Blueprint $t) {
            // Old unscoped records are preserved, but must not be guessed into account scope.
            $t->string('scope_kind', 24)->default('legacy_unknown')->index();
            $t->foreignId('workspace_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('cloud_epoch')->nullable();
            $t->timestamp('suppressed_at')->nullable();
            $t->index(['user_id', 'workspace_id', 'cloud_epoch']);
        });
        DB::table('user_notifications')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $key = $row->dedupe_key ?? '';
                if (! preg_match('/^(invoice|payment-request|payment|rental-request|viewing|conversation|hold-due|rental-end|invitation):(\d+):?/', $key, $match)) continue;
                $id = (int) $match[2];
                $workspace = match ($match[1]) {
                    'payment', 'payment-request' => DB::table('payment_requests')->where('id', $id)->value('workspace_id'),
                    'rental-request' => DB::table('rental_requests')->where('id', $id)->value('workspace_id'),
                    'invitation' => DB::table('invitations')->where('id', $id)->value('workspace_id'),
                    'conversation', 'viewing' => DB::table('connections')->join('listings', 'listings.id', '=', 'connections.listing_id')->join('rooms', 'rooms.id', '=', 'listings.room_id')->join('properties', 'properties.id', '=', 'rooms.property_id')->where('connections.id', $id)->value('properties.workspace_id'),
                    'invoice' => DB::table('invoices')->join('rentals', 'rentals.id', '=', 'invoices.rental_id')->join('rooms', 'rooms.id', '=', 'rentals.room_id')->join('properties', 'properties.id', '=', 'rooms.property_id')->where('invoices.id', $id)->value('properties.workspace_id'),
                    'hold-due' => DB::table('room_reservations')->join('rooms', 'rooms.id', '=', 'room_reservations.room_id')->join('properties', 'properties.id', '=', 'rooms.property_id')->where('room_reservations.id', $id)->value('properties.workspace_id'),
                    'rental-end' => DB::table('rentals')->join('rooms', 'rooms.id', '=', 'rentals.room_id')->join('properties', 'properties.id', '=', 'rooms.property_id')->where('rentals.id', $id)->value('properties.workspace_id'),
                };
                if ($workspace) DB::table('user_notifications')->where('id', $row->id)->update(['scope_kind' => 'property', 'workspace_id' => $workspace, 'cloud_epoch' => 1]);
            }
        });
    }

    public function down(): void
    {
        // Removing these fields would remove the barrier against replaying old jobs/alerts.
        throw new RuntimeException('RC-12: giữ các cột chặn phát lại khi rollback source; cần kế hoạch rollback dữ liệu được duyệt riêng.');
    }
};
