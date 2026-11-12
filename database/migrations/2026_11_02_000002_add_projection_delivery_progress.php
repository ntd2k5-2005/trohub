<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projection_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('domain_event_id')->constrained()->restrictOnDelete();
            $table->string('target', 16);
            $table->string('driver', 16);
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 200)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->unique(['domain_event_id', 'target']);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Không xóa tiến độ đồng bộ và lịch sử lỗi của dữ liệu dẫn xuất.');
    }
};
