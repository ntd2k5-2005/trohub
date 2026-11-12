<?php

use App\Http\Middleware\EnforceEmailActivation;
use App\Http\Middleware\EnforceSocialSession;
use App\Http\Middleware\LoadRuntimeSettings;
use App\Http\Middleware\PrivateNoStore;
use App\Http\Middleware\RequireInternalChat;
use App\Http\Middleware\ResolveUiContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('web', LoadRuntimeSettings::class);
        $middleware->appendToGroup('web', EnforceEmailActivation::class);
        $middleware->appendToGroup('web', EnforceSocialSession::class);
        $middleware->appendToGroup('api', LoadRuntimeSettings::class);
        $middleware->appendToGroup('web', PrivateNoStore::class);
        $middleware->appendToGroup('api', PrivateNoStore::class);
        $middleware->alias([
            'ui.context' => ResolveUiContext::class,
            'chat.enabled' => RequireInternalChat::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['otp', 'token', 'code']);
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
        // Đăng ký quy tắc xử lý ngoại lệ dùng chung tại đây khi backend được nối thật.
    })
    ->create();
