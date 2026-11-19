<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class SessionController
{
    public function create()
    {
        return Auth::check() ? redirect()->route('admin.forms') : view('admin.login');
    }

    public function store(Request $request)
    {
        $credentials = $request->validate(['email' => 'required|email|max:254', 'password' => 'required|string|max:1024']);
        $key = 'mailform:login:'.hash('sha256', mb_strtolower($credentials['email']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 5)) {
            abort(429, '試行回数が上限に達しました。1分ほど待ってからお試しください。');
        }

        if (! Auth::attempt($credentials)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'メールアドレスまたはパスワードを確認してください。']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('mailform_role_version', (int) $request->user()->role_version);

        return redirect()->intended(route('admin.forms'));
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
