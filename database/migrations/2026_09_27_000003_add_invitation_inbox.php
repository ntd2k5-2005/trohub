<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', fn (Blueprint $t) => $t->timestamp('declined_at')->nullable());
        Schema::table('user_notifications', function (Blueprint $t) {
            $t->string('action_url')->nullable();
            $t->string('action_label')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', fn (Blueprint $t) => $t->dropColumn('declined_at'));
        Schema::table('user_notifications', fn (Blueprint $t) => $t->dropColumn(['action_url', 'action_label']));
    }
};
