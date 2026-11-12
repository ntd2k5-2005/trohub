<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_quotes', function (Blueprint $table) {
            // Existing quotes remain unfilled; never infer their payee or receipt.
            $table->text('payee_snapshot')->nullable();
            $table->string('transfer_reference', 30)->nullable()->unique();
            $table->timestamp('payer_reported_at')->nullable();
            $table->string('payer_bank_reference', 100)->nullable();
            $table->text('payer_note')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Không xóa thông tin chuyển khoản và đối soát của báo giá đã phát hành.');
    }
};
