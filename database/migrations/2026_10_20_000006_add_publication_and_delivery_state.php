<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $t) {
            $t->json('published_snapshot')->nullable();
            $t->unsignedInteger('content_version')->default(0);
        });
        Schema::create('listing_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('listing_id')->constrained();
            $t->unsignedInteger('version');
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->json('snapshot');
            $t->timestamps();
            $t->unique(['listing_id', 'version']);
        });
        Schema::table('user_notifications', fn (Blueprint $t) => $t->unsignedSmallInteger('priority')->default(0)->index());
        Schema::table('payment_requests', function (Blueprint $t) {
            $t->string('qr_status', 20)->default('pending');
            $t->unsignedInteger('qr_attempts')->default(0);
            $t->timestamp('qr_generated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', fn (Blueprint $t) => $t->dropColumn(['qr_status', 'qr_attempts', 'qr_generated_at']));
        Schema::table('user_notifications', fn (Blueprint $t) => $t->dropColumn('priority'));
        Schema::dropIfExists('listing_versions');
        Schema::table('listings', fn (Blueprint $t) => $t->dropColumn(['published_snapshot', 'content_version']));
    }
};
