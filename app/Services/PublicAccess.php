<?php

namespace App\Services;

use Illuminate\Http\Request;

class PublicAccess
{
    public static function isHostRule(string $rule): bool
    {
        $domain = rtrim(strtolower($rule), '.');
        if (str_starts_with($domain, '*.')) {
            $domain = substr($domain, 2);
        }

        return str_contains($domain, '.') && preg_match('/[a-z]/D', $domain)
            && (bool)filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }

    public static function supports(string $rule): bool
    {
        if (filter_var($rule, FILTER_VALIDATE_IP) || self::isHostRule($rule)) {
            return true;
        }

        // EasyMail uses 192.0.2.*. A shorter trailing wildcard also covers whole octets.
        if (!preg_match('/^(?:[0-9]{1,3}\.){1,3}\*$/D', $rule)) {
            return false;
        }
        $parts = explode('.', substr($rule, 0, -2));
        return (bool) filter_var(implode('.', array_pad($parts, 4, '0')), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
    }

    public static function enforce(Request $request, array $settings): void
    {
        $address = (string) $request->ip();
        $message = ($settings['denied_ip_host_error_msg'] ?? '') ?: __('このフォームは利用できません。');
        foreach (preg_split('/\R/u', $settings['denied_ip'] ?? '', flags: PREG_SPLIT_NO_EMPTY) as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }
            abort_unless(self::supports($rule), 404);
            if (self::isHostRule($rule)) {
                // REMOTE_HOST is supplied by the web server after reverse DNS.
                // Host and X-Forwarded-Host are request headers and identify the requested website.
                $remote = rtrim(strtolower((string) $request->server('REMOTE_HOST', '')), '.');
                abort_unless(self::isHostRule($remote) && !str_contains($remote, '*'), 403, $message);
                $host = rtrim(strtolower($rule), '.');
                $matches = str_starts_with($host, '*.')
                    ? str_ends_with($remote, substr($host, 1))
                    : $remote === $host;
            } elseif (str_ends_with($rule, '*')) {
                $matches = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && str_starts_with($address, substr($rule, 0, -1));
            } else {
                $matches = filter_var($address, FILTER_VALIDATE_IP) && inet_pton($rule) === inet_pton($address);
            }
            abort_if($matches, 403, $message);
        }
    }
}
