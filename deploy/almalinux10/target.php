<?php

use Illuminate\Contracts\Console\Kernel;

// Run as mailform in the installed release. Only the fingerprint goes to stdout.
if ($argc !== 4 || !in_array($argv[1], ['mailform', 'mailform_stage'], true)) {
    file_put_contents('php://stderr', "取込先DB・ローカルソケット・設置URLを指定してください。\n");
    exit(1);
}
[, $target, $socket, $baseUrl] = $argv;
$production = $target === 'mailform';
$expectedUrl = $production ? $baseUrl : 'http://127.0.0.1:8008'.parse_url($baseUrl, PHP_URL_PATH);
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $db = $app->make('db')->connection();
    $url = rtrim((string) config('app.url'), '/');
    $environment = config('app.env');
    if (!in_array($db->getDriverName(), ['mariadb', 'mysql'], true) || $db->getDatabaseName() !== $target
        || $db->getConfig('username') !== 'mailform' || $db->getConfig('unix_socket') !== $socket
        || config('mailform.delivery_enabled') !== false || $url !== rtrim($expectedUrl, '/')
        || $environment !== ($production ? 'production' : 'staging')) {
        throw new RuntimeException();
    }
    $session = $db->selectOne('SELECT DATABASE() AS db, CURRENT_USER() AS account, @@socket AS socket', [], false);
    if ($session->db !== $target || $session->account !== 'mailform@localhost' || $session->socket !== $socket) {
        throw new RuntimeException();
    }
    $identity = json_encode([$db->getDriverName(), $session->db, $session->account, $session->socket, $url, $environment], JSON_THROW_ON_ERROR);
    echo hash_hmac('sha256', $identity, $app->make('encrypter')->getKey()), PHP_EOL;
} catch (Throwable) {
    file_put_contents('php://stderr', "アプリ鍵、取込先のDB・ソケット・専用ユーザー、配信停止、環境名と設置URLを確認してください。\n");
    exit(1);
}
