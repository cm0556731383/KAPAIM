<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

/**
 * Laravel skips CSRF verification entirely while running unit tests, so the
 * webhook feature tests can't catch a webhook being blocked by it — this
 * runs the real middleware with that bypass switched off.
 */
class WebhookCsrfTest extends TestCase
{
    public function test_webhooks_are_exempt_from_csrf_but_other_posts_are_not(): void
    {
        $middleware = new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        };

        foreach (['/webhooks/landing-page/lead', '/webhooks/smove/material-opened/1', '/webhooks/summit/standing-order-collected'] as $uri) {
            $request = Request::create($uri, 'POST');
            $request->setLaravelSession($this->app['session.store']);
            $this->assertSame('ok', $middleware->handle($request, fn () => response('ok'))->getContent(), $uri);
        }

        $request = Request::create('/logout', 'POST');
        $request->setLaravelSession($this->app['session.store']);
        $this->expectException(TokenMismatchException::class);
        $middleware->handle($request, fn () => response('ok'));
    }
}
