<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', fn (Blueprint $table) => $table->foreignId('viewing_proposed_by')->nullable()->constrained('users'));
    }

    public function down(): void
    {
        Schema::table('connections', fn (Blueprint $table) => $table->dropConstrainedForeignId('viewing_proposed_by'));
    }
};
