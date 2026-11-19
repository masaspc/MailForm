<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($next($request));
    }

    public static function apply(Response $response): Response
    {
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Cache-Control', 'private, no-store');
        $external = '';
        $frames = "'none'";
        if (\App\Services\SpamProtection::configured() && !request()->is('admin', 'admin/*')) {
            $external = ' https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/';
            $frames = 'https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/';
        }
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'{$external}; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'{$external}; frame-src {$frames}; form-action 'self'; frame-ancestors 'self'; base-uri 'self'; object-src 'none'");
        return $response;
    }
}
