<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $t) {
            $t->string('module')->nullable();
            $t->string('action')->nullable();
            $t->json('scopes')->nullable();
            $t->text('conditions')->nullable();
        });
        Schema::table('access_groups', function (Blueprint $t) {
            $t->string('key')->nullable()->unique();
            $t->boolean('protected')->default(false);
            $t->foreignId('workspace_id')->nullable()->constrained();
        });
        Schema::create('group_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('access_group_id')->constrained();
            $t->foreignId('workspace_id')->nullable()->constrained();
            $t->foreignId('property_id')->nullable()->constrained();
            $t->foreignId('room_id')->nullable()->constrained();
            $t->string('scope_key');
            $t->string('origin')->default('explicit');
            $t->foreignId('grantor_id')->nullable()->constrained('users');
            $t->timestamp('valid_from')->nullable();
            $t->timestamp('valid_until')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'access_group_id', 'scope_key']);
            $t->index(['workspace_id', 'user_id']);
        });
        Schema::table('memberships', function (Blueprint $t) {
            $t->json('room_ids')->nullable();
            $t->string('permissions_mode')->default('replace');
        });
        Schema::table('invitations', function (Blueprint $t) {
            $t->json('room_ids')->nullable();
            $t->json('access_group_ids')->nullable();
        });
        Schema::table('direct_grants', function (Blueprint $t) {
            $t->foreignId('room_id')->nullable()->constrained();
            $t->string('scope_type')->default('auto');
        });
        Schema::create('system_settings', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->json('value');
            $t->timestamps();
        });
        Schema::table('devices', function (Blueprint $t) {
            $t->string('kind')->default('safety_sensor');
        });
    }

    public function down(): void
    {
        Schema::table('devices', fn (Blueprint $t) => $t->dropColumn('kind'));
        Schema::dropIfExists('system_settings');
        Schema::table('direct_grants', fn (Blueprint $t) => $t->dropColumn('scope_type'));
        Schema::table('direct_grants', fn (Blueprint $t) => $t->dropConstrainedForeignId('room_id'));
        Schema::table('invitations', fn (Blueprint $t) => $t->dropColumn(['room_ids', 'access_group_ids']));
        Schema::table('memberships', fn (Blueprint $t) => $t->dropColumn(['room_ids', 'permissions_mode']));
        Schema::dropIfExists('group_assignments');
        Schema::table('access_groups', function (Blueprint $t) {
            $t->dropConstrainedForeignId('workspace_id');
            $t->dropColumn(['key', 'protected']);
        });
        Schema::table('permissions', fn (Blueprint $t) => $t->dropColumn(['module', 'action', 'scopes', 'conditions']));
    }
};
