<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Chat\ChatRepository;
use App\Modules\Chat\Models\Thread;
use App\Modules\Connection\ConversationRepository;
use App\Modules\Connection\ConversationService;
use App\Modules\Listing\ListingService;
use App\Modules\Workspace\Models\Membership;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharedChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function user(string $name): User
    {
        return User::where('email', $name.'@trohub.test')->firstOrFail();
    }

    private function contact()
    {
        $l = app(ListingService::class)->available()->whereHas('room.property.workspace', fn ($w) => $w->where('owner_id', $this->user('owner')->id))->firstOrFail();

        return app(ConversationService::class)->open($this->user('tenant'), $l->id, 'chat', null, null);
    }

    public function test_shared_inbox_uses_account_names_and_links_room_card(): void
    {
        $c = $this->contact();
        $thread = Thread::firstOrFail();
        $this->actingAs($this->user('tenant'))->get('/ui/conversations?context=personal&id='.$thread->id)->assertOk()->assertSee('messenger-inbox')->assertSee('messenger-room-card');
        $this->getJson('/tro-chuyen/danh-sach?context=personal')->assertOk()->assertJsonPath('items.0.name', $this->user('owner')->name);
        $state = $this->getJson('/tro-chuyen/'.$thread->id.'/tin-nhan')->assertOk()->json();
        $this->assertSame('context', $state['messages'][0]['kind']);
        $this->assertStringContainsString('listing-detail', $state['messages'][0]['card']['url']);
        $c->listing->update(['moderated' => true]);
        $this->getJson('/tro-chuyen/'.$thread->id.'/tin-nhan')->assertOk()->assertJsonPath('messages.0.card.url', null);
    }

    public function test_reads_require_explicit_acknowledgement_and_never_move_backwards(): void
    {
        $c = $this->contact();
        $thread = Thread::first();
        $last = DB::table('chat_messages')->max('id');
        $this->actingAs($this->user('owner'))->getJson('/tro-chuyen/'.$thread->id.'/tin-nhan')->assertOk();
        $this->assertDatabaseCount('chat_reads', 0);
        $this->postJson('/tro-chuyen/'.$thread->id.'/da-xem', ['through' => $last])->assertOk();
        $this->postJson('/tro-chuyen/'.$thread->id.'/da-xem', ['through' => $last - 1])->assertOk();
        $this->assertSame($last, (int) DB::table('chat_reads')->value('last_message_id'));
        $this->actingAs($this->user('tenant'))->getJson('/tro-chuyen/'.$thread->id.'/tin-nhan')->assertJsonPath('peer_read_through', $last);
        $this->postJson('/tro-chuyen/'.$thread->id.'/da-xem', ['through' => 999999])->assertUnprocessable();
        $this->actingAs($this->user('other'))->postJson('/tro-chuyen/'.$thread->id.'/da-xem', ['through' => $last])->assertForbidden();
    }

    public function test_unread_inbox_and_idempotent_send_follow_scope_and_revocation(): void
    {
        $c = $this->contact();
        $thread = Thread::first();
        $w = $c->listing->room->property->workspace_id;
        $this->actingAs($this->user('owner'))->getJson('/tro-chuyen/danh-sach?context=workspace&workspace_id='.$w)->assertOk()->assertJsonPath('items.0.name', $this->user('tenant')->name)->assertJsonPath('items.0.unread', 2);
        $body = ['body' => 'Chào bạn', 'client_id' => (string) Str::uuid()];
        $this->postJson('/tro-chuyen/'.$thread->id.'/tin-nhan', $body)->assertOk();
        $this->postJson('/tro-chuyen/'.$thread->id.'/tin-nhan', $body)->assertOk();
        $this->assertSame(1, DB::table('chat_messages')->where('client_id', $body['client_id'])->count());
        $this->actingAs($this->user('staff'))->getJson('/tro-chuyen/'.$thread->id.'/tin-nhan')->assertOk();
        Membership::where('user_id', $this->user('staff')->id)->update(['permissions' => '[]']);
        $this->getJson('/tro-chuyen/'.$thread->id.'/tin-nhan')->assertForbidden();
        $this->getJson('/tro-chuyen/danh-sach?context=workspace&workspace_id='.$w)->assertForbidden();
        $this->actingAs($this->user('accountant'))->get('/ui/conversations?context=workspace&id='.$thread->id)->assertForbidden();
    }

    public function test_history_migration_preserves_old_messages_without_reinserting_intro(): void
    {
        $c = $this->contact();
        $client = (string) Str::uuid();
        DB::table('connection_messages')->insert(['connection_id' => $c->id, 'sender_id' => $c->user_id, 'client_id' => $client, 'body' => 'Nội dung cũ cần giữ', 'created_at' => now(), 'updated_at' => now()]);
        $migration = require database_path('migrations/2026_09_28_000002_create_shared_chat.php');
        $migration->down();
        $migration->up();
        $thread = app(ConversationRepository::class)->thread($c->id);
        $this->assertSame(2, DB::table('chat_messages')->where('thread_id', $thread->id)->count());
        $this->assertDatabaseHas('chat_messages', ['client_id' => $client, 'body' => 'Nội dung cũ cần giữ']);
        $this->assertDatabaseHas('connection_messages', ['client_id' => $client]);
    }

    public function test_message_pagination_and_inbox_search_are_scoped(): void
    {
        $c = $this->contact();
        $thread = Thread::first();
        $repo = app(ChatRepository::class);
        $tenant = $this->user('tenant');
        for ($i = 0; $i < 45; $i++) {
            $repo->append($thread, $tenant, 'requester', 'Tin '.$i, (string) Str::uuid());
        }
        $this->actingAs($tenant);
        $rows = $this->getJson('/tro-chuyen/'.$thread->id.'/tin-nhan')->assertOk()->json('messages');
        $this->assertCount(config('chat.page_size'), $rows);
        $this->getJson('/tro-chuyen/'.$thread->id.'/tin-nhan?before='.$rows[0]['id'])->assertOk()->assertJsonCount(7, 'messages');
        $this->getJson('/tro-chuyen/danh-sach?context=personal&q=khongtontai')->assertJsonPath('total', 0);
        $this->getJson('/tro-chuyen/danh-sach?context=personal&q='.urlencode($this->user('owner')->name))->assertJsonPath('total',1);
    }
}
