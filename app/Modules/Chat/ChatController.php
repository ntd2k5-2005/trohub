<?php

namespace App\Modules\Chat;

use Illuminate\Http\Request;

final class ChatController
{
    public function inbox(Request $r, ChatRepository $repository)
    {
        $data = $r->validate(['q' => 'nullable|string|max:150', 'page' => 'nullable|integer|min:1']);

        return response()->json($repository->inbox($r->user(), $r->attributes->get('workspace'), $data['q'] ?? '', $data['page'] ?? 1))->header('Cache-Control', 'no-store, private');
    }

    public function messages(Request $r, int $thread, ChatService $service)
    {
        $data = $r->validate(['after' => 'nullable|integer|min:0', 'before' => 'nullable|integer|min:1']);

        return response()->json($service->state($r->user(), $service->authorized($r->user(), $thread), $data['after'] ?? 0, $data['before'] ?? null))->header('Cache-Control', 'no-store, private');
    }

    public function send(Request $r, int $thread, ChatService $service)
    {
        $data = $r->validate(['body' => 'required|string|max:2000', 'client_id' => 'required|uuid']);
        $service->send($r->user(), $thread, $data['body'], $data['client_id']);

        return response()->json(['saved' => true]);
    }

    public function read(Request $r, int $thread, ChatService $service)
    {
        $data = $r->validate(['through' => 'required|integer|min:1']);
        $service->read($r->user(), $thread, $data['through']);

        return response()->json(['saved' => true]);
    }
}
