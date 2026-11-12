<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resident_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained();
            $t->string('name');
            $t->string('email');
            $t->foreignId('user_id')->nullable()->constrained();
            $t->timestamps();
            $t->unique(['workspace_id', 'email']);
        });
        Schema::table('invitations', function (Blueprint $t) {
            $t->string('purpose')->default('staff');
            $t->foreignId('resident_profile_id')->nullable()->constrained();
        });
        Schema::table('rentals', function (Blueprint $t) {
            $t->unsignedBigInteger('tenant_id')->nullable()->change();
            $t->foreignId('resident_profile_id')->nullable()->constrained();
        });
        Schema::table('listings', function (Blueprint $t) {
            $t->string('cover_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('listings', fn (Blueprint $t) => $t->dropColumn('cover_path'));
        Schema::table('rentals', fn (Blueprint $t) => $t->dropConstrainedForeignId('resident_profile_id'));
        Schema::table('invitations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('resident_profile_id');
            $t->dropColumn('purpose');
        });
        Schema::dropIfExists('resident_profiles');
    }
};
