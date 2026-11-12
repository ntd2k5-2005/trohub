<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_cycles', function (Blueprint $table) {
            // Earlier code applied every verified cycle immediately. Preserve
            // that meaning without inventing an application timestamp for it.
            $table->string('application_state', 20)->default('applied');
            $table->timestamp('applied_at')->nullable();
            $table->index(['application_state', 'starts_at']);
        });
        Schema::table('subscription_quotes', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
        });
        Schema::create('legacy_subscription_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->constrained('users')->restrictOnDelete();
            $table->string('decision', 24);
            $table->string('source_hash', 64);
            $table->json('snapshot');
            $table->text('note');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Không xóa lịch áp dụng kỳ gói và kết quả đối chiếu đã ghi nhận.');
    }
};
