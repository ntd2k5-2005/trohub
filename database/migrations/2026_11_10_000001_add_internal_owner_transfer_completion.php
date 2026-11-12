<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_transfer_cases', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable();
            $table->json('completion_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Không xóa lịch sử bàn giao quyền quản lý đã hoàn tất.');
    }
};
