<?php

namespace App\Modules\Connection;

use App\Support\ProductPresenter;
use Illuminate\Http\Request;

final class ConversationController
{
    public function open(Request $r, ConversationService $service)
    {
        $data = $r->validate(['listing_id' => 'required|integer', 'intent' => 'required|in:chat,viewing,rent', 'message' => 'nullable|string|max:2000', 'viewing_at' => 'required_if:intent,viewing|nullable|date|after:now']);
        if (! config('features.internal_chat')) {
            abort_unless($data['intent'] !== 'chat', 404);
            $connection = app(ConnectionService::class)->create($r->user(), $data['listing_id'], ($data['message'] ?? null) ?: 'Tôi muốn hẹn xem phòng.');
            if ($data['intent'] === 'viewing') {
                app(ConnectionService::class)->transition($r->user(), $connection, 'viewing_scheduled', $data['viewing_at']);
            }

            return redirect()->route('ui.show', ['slug' => 'connections', 'context' => 'personal'])->with('status', 'Đã gửi yêu cầu. Bạn có thể theo dõi phản hồi tại đây.');
        }
        $c = $service->open($r->user(), $data['listing_id'], $data['intent'], $data['message'] ?? null, $data['viewing_at'] ?? null);

        return redirect()->route('ui.show', ['slug' => 'conversations', 'context' => 'personal', 'id' => app(ConversationRepository::class)->thread($c->id)->id])->with('status', match ($data['intent']) {
            'viewing' => 'Đã đề xuất lịch xem. Vui lòng chờ bên cho thuê xác nhận.',
            'rent' => 'Đã gửi yêu cầu thuê. Hai bên cần thống nhất và hoàn tất hồ sơ thuê.',
            default => 'Đã mở cuộc trò chuyện.',
        });
    }

    public function messages(Request $r, int $connection, ConversationService $service, ConversationRepository $repository)
    {
        $c = $service->authorized($r->user(), $connection);
        $data = $r->validate(['after' => 'nullable|integer|min:0', 'before' => 'nullable|integer|min:1']);
        $messages = $repository->messages($c->id, $r->user()->id, $data['after'] ?? 0, $data['before'] ?? null);

        return response()->json(['messages' => $messages, 'status' => ProductPresenter::label($c->status), 'can_send' => ! in_array($c->status, ['cancelled', 'rejected'])])->header('Cache-Control', 'no-store, private');
    }

    public function send(Request $r, int $connection, ConversationService $service)
    {
        $data = $r->validate(['body' => 'required|string|max:2000', 'client_id' => 'required|uuid']);
        $service->send($r->user(), $connection, $data['body'], $data['client_id']);

        return response()->json(['saved' => true]);
    }
}
