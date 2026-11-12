<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housing_media', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('workspace_id')->constrained();
            $t->string('resource_type', 20);
            $t->unsignedBigInteger('resource_id');
            $t->string('path');
            $t->unsignedSmallInteger('position')->default(0);
            $t->timestamps();
            $t->index(['resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housing_media');
    }
};
