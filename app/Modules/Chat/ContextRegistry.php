<?php

namespace App\Modules\Chat;

use App\Modules\Chat\Contracts\ChatContext;

final class ContextRegistry
{
    public function get(string $type): ChatContext
    {
        $class = config('chat.contexts.'.$type);
        abort_unless($class && is_a($class, ChatContext::class, true), 404);

        return app($class);
    }
}
