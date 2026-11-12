<?php

namespace App\Http\Middleware;

use App\Modules\Admin\RuntimeSettings;
use Closure;
use Illuminate\Http\Request;

final class LoadRuntimeSettings
{
    public function handle(Request $request, Closure $next)
    {
        app(RuntimeSettings::class)->apply();

        return $next($request);
    }
}
