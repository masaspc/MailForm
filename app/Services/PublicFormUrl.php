<?php

namespace App\Services;

class PublicFormUrl
{
    private const RESERVED = [
        'admin', 'login', 'logout', 'up', 'storage', 'assets', 'send', 'thanks', 'confirm', 'back',
        'index.php', 'favicon.ico', 'robots.txt',
    ];

    /** EasyMail stores the decoded route parameter. The empty value selects the installation root. */
    public static function isSegment(string $slug): bool
    {
        return mb_check_encoding($slug, 'UTF-8') && mb_strlen($slug) <= 255
            && ! in_array($slug, ['.', '..'], true)
            && strpbrk($slug, "/\\") === false && preg_match('/\p{Cc}/u', $slug) === 0;
    }

    public static function problem(string $slug): ?string
    {
        if (! self::isSegment($slug)) {
            return '公開パスには255文字以内の1区間を指定してください。スラッシュ、制御文字、相対パスは使用できません。';
        }
        if ($slug !== '' && (str_starts_with($slug, '.') || in_array(strtolower($slug), self::RESERVED, true) || file_exists(public_path($slug)))) {
            return '管理・送信ルートまたは公開ファイルと競合する公開パスです。';
        }

        return null;
    }

    public static function entry(string $base, string $slug): string
    {
        return rtrim($base, '/').'/'.rawurlencode($slug);
    }

    /** Browsers encode Unicode and spaces in an installation path; keep existing escapes intact. */
    public static function encodeInstallationPath(string $path): string
    {
        return preg_replace_callback('/[ \x80-\xFF]+/', static fn ($match) => rawurlencode($match[0]), $path);
    }

    /** Generate same-origin actions independently of the incoming host, scheme and query string. */
    public static function form(string $slug, string $action = ''): string
    {
        $base = self::basePath();
        if (str_ends_with(request()->getBaseUrl(), '/index.php') || str_ends_with(self::configuredPath(), '/index.php')) {
            $base .= '/index.php';
        }
        $entry = self::entry($base, $slug);

        return $action === '' ? $entry : rtrim($entry, '/').'/'.$action;
    }

    public static function asset(string $file): string
    {
        return self::basePath().'/assets/'.$file;
    }

    private static function configuredPath(): string
    {
        return rtrim((string) parse_url(config('app.url'), PHP_URL_PATH), '/');
    }

    private static function basePath(): string
    {
        $base = self::configuredPath();
        if ($base === '') {
            $base = request()->getBasePath();
        }
        $base = trim(preg_replace('~/index\.php$~', '', $base), '/');

        return $base === '' ? '' : '/'.self::encodeInstallationPath($base);
    }
}
