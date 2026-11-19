<?php

use App\Services\ThemeAssets;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;

// Only the temporary PHP CLI server uses this entry point. Never fall through to its static file handler.
$deny = static function (): never {
    http_response_code(404);
    exit;
};
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || ($_SERVER['HTTP_HOST'] ?? '') !== '127.0.0.1:'.($_SERVER['SERVER_PORT'] ?? '')
    || !is_file((string) getenv('MAILFORM_REVIEW_MAINTENANCE'))) {
    $deny();
}
$path = explode('?', $_SERVER['REQUEST_URI'] ?? '', 2)[0];
$method = $_SERVER['REQUEST_METHOD'] ?? '';
$read = in_array($method, ['GET', 'HEAD'], true);
$public = dirname(__DIR__, 2).'/public';
$static = [
    '/assets/admin.css' => 'text/css', '/assets/admin.js' => 'application/javascript',
    '/assets/form.css' => 'text/css', '/assets/form.js' => 'application/javascript',
    '/assets/themes/stock.css' => 'text/css', '/assets/themes/base.css' => 'text/css',
];
if ($read && isset($static[$path])) {
    header('Content-Type: '.$static[$path]);
    header('X-Content-Type-Options: nosniff');
    if ($method === 'GET') {
        readfile($public.$path);
    }
    return;
}
if ($read && $path === '/admin') {
    header('Location: /admin/themes', true, 302);
    return;
}
$slug = '[A-Za-z][A-Za-z0-9_-]{0,79}';
$allowedRead = $read && (in_array($path, ['/admin/login', '/admin/themes'], true)
    || preg_match("~\A/admin/themes/{$slug}/edit\z~", $path)
    || preg_match('~\A/admin/forms/[1-9][0-9]*/preview\z~', $path));
$allowedPost = $method === 'POST' && (in_array($path, ['/admin/login', '/admin/logout', '/admin/themes'], true)
    || preg_match("~\A/admin/themes/{$slug}/(?:assets|approve)\z~", $path));
$asset = $read && preg_match("~\A/_theme/{$slug}/file/(.+)\z~D", $path, $match);
if (!$allowedRead && !$allowedPost && !$asset) {
    $deny();
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
if ($asset && !ThemeAssets::validPath(rawurldecode($match[1]))) {
    $deny();
}
define('LARAVEL_START', microtime(true));
$_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->afterBootstrapping(LoadConfiguration::class, static function ($app) {
    $app['config']->set('session.files', getenv('MAILFORM_REVIEW_SESSIONS'));
});
$app->handleRequest(Request::capture());
