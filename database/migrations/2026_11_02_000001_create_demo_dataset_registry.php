<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_dataset_records', function (Blueprint $table): void {
            $table->id();
            $table->string('dataset', 64);
            $table->string('table_name', 64);
            $table->string('demo_key', 191);
            $table->string('record_id', 191);
            $table->timestamp('created_at');
            $table->unique(['dataset', 'table_name', 'demo_key'], 'demo_record_key');
            $table->unique(['dataset', 'table_name', 'record_id'], 'demo_record_id');
        });
        Schema::create('demo_dataset_runs', function (Blueprint $table): void {
            $table->string('dataset', 64)->primary();
            $table->date('reference_date');
            $table->string('status', 24);
            $table->json('counts')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('demo_dataset_records')->exists()) {
            throw new RuntimeException('Phải kiểm kê dữ liệu demo trước khi gỡ registry.');
        }
        Schema::dropIfExists('demo_dataset_records');
        Schema::dropIfExists('demo_dataset_runs');
    }
};
