<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_threads', function (Blueprint $t) {
            $t->id();
            $t->string('context_type', 60);
            $t->unsignedBigInteger('context_id');
            $t->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['context_type', 'context_id']);
        });
        Schema::create('chat_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('thread_id')->constrained('chat_threads')->cascadeOnDelete();
            $t->foreignId('sender_id')->constrained('users');
            $t->string('sender_side', 40);
            $t->string('kind', 30)->default('text');
            $t->text('body');
            $t->string('client_id', 80);
            $t->timestamps();
            $t->unique(['thread_id', 'sender_id', 'client_id']);
            $t->index(['thread_id', 'id']);
        });
        Schema::create('chat_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('thread_id')->constrained('chat_threads')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->string('side', 40);
            $t->unsignedBigInteger('last_message_id')->default(0);
            $t->timestamps();
            $t->unique(['thread_id', 'user_id']);
        });
        // Copy history without deleting the original records; safe for existing MySQL data.
        DB::table('connections')->orderBy('id')->chunkById(100, function ($connections) {
            foreach ($connections as $c) {
                $w = DB::table('listings')->join('rooms', 'rooms.id', '=', 'listings.room_id')->join('properties', 'properties.id', '=', 'rooms.property_id')->where('listings.id', $c->listing_id)->value('properties.workspace_id');
                $thread = DB::table('chat_threads')->insertGetId(['context_type' => 'rental_contact', 'context_id' => $c->id, 'workspace_id' => $w, 'created_at' => $c->created_at, 'updated_at' => $c->updated_at]);
                DB::table('chat_messages')->insert(['thread_id' => $thread, 'sender_id' => $c->user_id, 'sender_side' => 'requester', 'kind' => 'context', 'body' => '', 'client_id' => 'initial-context', 'created_at' => $c->created_at, 'updated_at' => $c->created_at]);
                $hasMessages = false;
                foreach (DB::table('connection_messages')->where('connection_id', $c->id)->orderBy('id')->cursor() as $m) {
                    $hasMessages = true;
                    DB::table('chat_messages')->insert(['thread_id' => $thread, 'sender_id' => $m->sender_id, 'sender_side' => $m->sender_id === $c->user_id ? 'requester' : 'management', 'kind' => 'text', 'body' => $m->body, 'client_id' => $m->client_id, 'created_at' => $m->created_at, 'updated_at' => $m->updated_at]);
                }
                if (! $hasMessages && $c->message) {
                    DB::table('chat_messages')->insert(['thread_id' => $thread, 'sender_id' => $c->user_id, 'sender_side' => 'requester', 'kind' => 'text', 'body' => $c->message, 'client_id' => 'initial-message', 'created_at' => $c->created_at, 'updated_at' => $c->created_at]);
                }
                foreach (DB::table('user_notifications')->where('dedupe_key', 'like', 'conversation:'.$c->id.':%')->get() as $notice) {
                    $personal = $notice->user_id === $c->user_id;
                    DB::table('user_notifications')->where('id', $notice->id)->update(['action_url' => route('ui.show', ['slug' => 'conversations', 'context' => $personal ? 'personal' : 'workspace', 'id' => $thread] + ($personal ? [] : ['workspace_id' => $w]), false)]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_reads');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_threads');
    }
};
