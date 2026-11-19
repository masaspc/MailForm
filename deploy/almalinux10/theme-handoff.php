<?php

use App\Services\RehearsalThemes;
use Illuminate\Contracts\Console\Kernel;

if (PHP_SAPI !== 'cli' || $argc !== 2 || !in_array($argv[1], ['export', 'restore'], true)) {
    exit(1);
}
try {
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $expected = $argv[1] === 'export' ? 'mailform_stage' : 'mailform';
    $db = $app->make('db')->connection();
    if ($db->getDriverName() !== 'mariadb' || $db->getDatabaseName() !== $expected || $db->scalar('SELECT DATABASE()') !== $expected
        || $db->getConfig('unix_socket') !== '/var/lib/mysql/mysql.sock' || $db->scalar('SELECT CURRENT_USER()') !== 'mailform@localhost'
        || config('mailform.delivery_enabled') !== false) {
        throw new RuntimeException('引継ぎ元・先の DB、専用ユーザーと配信停止を確認してください。');
    }
    $count = $argv[1] === 'export' ? RehearsalThemes::export(STDOUT) : RehearsalThemes::restore(STDIN);
    fwrite(STDERR, "引継ぎテーマ {$count} 件を照合しました。\n");
} catch (Throwable $e) {
    fwrite(STDERR, "テーマ引継ぎ失敗: ".$e->getMessage()."\n");
    exit(1);
}
