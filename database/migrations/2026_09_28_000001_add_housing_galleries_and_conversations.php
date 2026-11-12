<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['properties', 'rooms', 'listings'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->json('image_urls')->nullable());
        }
        Schema::table('properties', fn (Blueprint $t) => $t->text('description')->nullable());
        Schema::table('connections', fn (Blueprint $t) => $t->timestamp('rental_requested_at')->nullable());
        Schema::create('connection_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('connection_id')->constrained()->cascadeOnDelete();
            $t->foreignId('sender_id')->constrained('users');
            $t->uuid('client_id');
            $t->text('body');
            $t->timestamps();
            $t->unique(['connection_id', 'sender_id', 'client_id']);
            $t->index(['connection_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connection_messages');
        Schema::table('connections', fn (Blueprint $t) => $t->dropColumn('rental_requested_at'));
        Schema::table('properties', fn (Blueprint $t) => $t->dropColumn('description'));
        foreach (['properties', 'rooms', 'listings'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('image_urls'));
        }
    }
};
