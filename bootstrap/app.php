<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // nginx in docker-compose.yml binds only to 127.0.0.1:8090 — never
        // directly internet-facing — so any HTTP hop in front of it (a
        // tunnel during local/demo access today, or a reverse proxy/CDN in
        // front of it later) is by definition trusted. Without this,
        // Laravel can't tell it's being served over HTTPS through such a
        // hop and generates http:// asset/Livewire URLs, which browsers
        // then block as mixed content on an https:// page.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
