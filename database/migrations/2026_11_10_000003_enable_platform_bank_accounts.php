<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receiving_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('workspace_id')->nullable()->change();
            $table->string('platform_fingerprint', 64)->nullable()->unique();
            $table->string('platform_default', 20)->nullable()->unique();
        });
        Schema::table('subscription_quotes', fn (Blueprint $table) => $table->foreignId('receiving_account_id')->nullable()->constrained('receiving_accounts')->restrictOnDelete());
    }

    public function down(): void { throw new RuntimeException('Giữ lịch sử ngân hàng và báo giá; chỉ sửa tiến sau kiểm chứng.'); }
};
