<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PrivateNoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        if ($engine = $request->attributes->get('search.engine')) $response->headers->set('X-TroHub-Search-Engine', $engine);
        if ($engine = $request->attributes->get('history.engine')) $response->headers->set('X-TroHub-History-Engine', $engine);
        return $response;
    }
}
