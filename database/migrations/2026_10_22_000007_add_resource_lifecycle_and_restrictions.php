<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $t) {
            $t->string('lifecycle')->default('active'); $t->unsignedInteger('version')->default(1);
            $t->text('lifecycle_reason')->nullable();
        });
        DB::table('workspaces')->where('status', 'archived')->update(['lifecycle' => 'archived']);
        DB::table('workspaces')->where('status', 'pending')->update(['lifecycle' => 'draft']);
        foreach (['properties', 'rooms'] as $table) Schema::table($table, function (Blueprint $t) {
            $t->timestamp('retired_at')->nullable(); $t->text('retirement_reason')->nullable(); $t->unsignedInteger('version')->default(1);
        });
        Schema::create('workspace_restrictions', function (Blueprint $t) {
            $t->id(); $t->foreignId('workspace_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('cause', 80); $t->string('active_key')->nullable()->unique();
            $t->text('reason'); $t->timestamp('released_at')->nullable(); $t->timestamps();
        });
    }
    public function down(): void { throw new RuntimeException('Không xóa lifecycle, hạn chế hoặc lịch sử ngừng khai thác.'); }
};
