<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user deactivated while logged in is logged out on her next request —
 * login() refuses an inactive user, but a session opened before that would
 * otherwise keep working.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::user() && ! Auth::user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson() || $request->hasHeader('X-Livewire')
                ? response()->json(['message' => 'המשתמשת אינה פעילה.'], 401)
                : redirect()->route('login');
        }

        return $next($request);
    }
}
