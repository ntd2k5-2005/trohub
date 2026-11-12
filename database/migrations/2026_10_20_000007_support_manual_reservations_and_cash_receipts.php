<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_requests', fn (Blueprint $t) => $t->unsignedBigInteger('listing_id')->nullable()->change());
        Schema::table('payment_request_transactions', function (Blueprint $t) {
            $t->unsignedBigInteger('payment_request_id')->nullable()->change();
            $t->foreignId('rental_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('kind', 20)->default('deposit');
            $t->string('method', 20)->default('transfer');
            $t->index(['rental_id', 'kind']);
        });
        DB::table('payment_request_transactions')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $request = DB::table('payment_requests')->find($row->payment_request_id);
                if ($request) {
                    DB::table('payment_request_transactions')->where('id', $row->id)->update(['rental_id' => $request->rental_id, 'kind' => $request->kind]);
                }
            }
        });
    }

    public function down(): void
    {
        // Refuse to discard real cash receipts during a schema rollback.
        if (DB::table('payment_request_transactions')->whereNull('payment_request_id')->exists() || DB::table('rental_requests')->whereNull('listing_id')->exists()) {
            throw new RuntimeException('Cần lưu trữ và chuyển đổi hồ sơ thủ công trước khi hạ phiên bản.');
        }
        Schema::table('payment_request_transactions', function (Blueprint $t) {
            $t->dropIndex(['rental_id', 'kind']);
            $t->dropConstrainedForeignId('rental_id');
            $t->dropColumn(['kind', 'method']);
            $t->unsignedBigInteger('payment_request_id')->nullable(false)->change();
        });
        Schema::table('rental_requests', fn (Blueprint $t) => $t->unsignedBigInteger('listing_id')->nullable(false)->change());
    }
};
