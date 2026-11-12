<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class RequireInternalChat
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('features.internal_chat'), 404, 'Trò chuyện hiện chưa khả dụng. Bạn vẫn có thể gửi yêu cầu liên hệ hoặc lịch xem.');

        return $next($request);
    }
}
