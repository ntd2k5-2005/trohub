<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safety_events', fn (Blueprint $t) => $t->foreignId('maintenance_request_id')->nullable()->constrained());
    }

    public function down(): void
    {
        Schema::table('safety_events', fn (Blueprint $t) => $t->dropConstrainedForeignId('maintenance_request_id'));
    }
};
