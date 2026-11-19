<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AdminAccess
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Require a new login after role changes, regardless of the configured session storage driver.
        if ($request->session()->has(Auth::guard()->getName())
            && $request->session()->get('mailform_role_version') !== (int) $request->user()->role_version) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('notice', '権限が変更されました。再度ログインしてください。');
        }

        abort_unless(in_array($request->user()?->role, $roles, true), 403);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
