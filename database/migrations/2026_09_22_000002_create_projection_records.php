<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projection_records', function (Blueprint $t) {
            $t->id();
            $t->string('store');
            $t->string('event_id');
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->string('event_time');
            $t->json('payload');
            $t->unique(['store', 'event_id']);
            $t->index(['store', 'workspace_id', 'event_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projection_records');
    }
};
