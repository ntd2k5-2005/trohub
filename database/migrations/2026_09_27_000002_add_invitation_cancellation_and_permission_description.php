<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', fn (Blueprint $t) => $t->timestamp('cancelled_at')->nullable());
        Schema::table('permissions', fn (Blueprint $t) => $t->text('description')->nullable());
    }

    public function down(): void
    {
        Schema::table('invitations', fn (Blueprint $t) => $t->dropColumn('cancelled_at'));
        Schema::table('permissions', fn (Blueprint $t) => $t->dropColumn('description'));
    }
};
