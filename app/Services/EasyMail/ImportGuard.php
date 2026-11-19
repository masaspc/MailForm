<?php

namespace App\Services\EasyMail;

use Closure;
use Illuminate\Database\Connection;
use RuntimeException;

final class ImportGuard
{
    public function run(Connection $source, Connection $target, Closure $work): mixed
    {
        if ($source->getReadPdo() === $target->getPdo()) {
            $this->sameDatabase();
        }
        if ($target->getDriverName() === 'sqlite') {
            return $this->sqlite($source, $target, $work);
        }
        if (!in_array($target->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new RuntimeException('移行先DBには MariaDB、MySQL、SQLite を指定してください。');
        }

        $database = $target->selectOne('SELECT DATABASE() AS db, @@lower_case_table_names AS fold_names', [], false);
        $normalize = fn ($name) => $database->fold_names ? mb_strtolower($name) : $name;
        $name = $normalize($database->db);
        if (in_array($source->getDriverName(), ['mysql', 'mariadb'], true) && $normalize($source->scalar('SELECT DATABASE()')) === $name) {
            // A random connection-scoped lock identifies the same server across host aliases and users.
            $probe = 'mailform-probe-'.bin2hex(random_bytes(16));
            $this->mysqlLock($target, $probe, function () use ($source, $probe) {
                if ($source->scalar('SELECT IS_USED_LOCK(?)', [$probe]) !== null) {
                    $this->sameDatabase();
                }
            });
        }
        return $this->mysqlLock($target, 'mailform-easymail-'.sha1($name), $work);
    }

    private function mysqlLock(Connection $target, string $name, Closure $work): mixed
    {
        $acquired = $target->scalar('SELECT GET_LOCK(?, 0)', [$name], false);
        if ($acquired === null) {
            throw new RuntimeException('移行先DBの取込ロックを取得できません。接続とDB設定を確認してください。');
        }
        if ((int) $acquired !== 1) {
            $this->busy();
        }
        try {
            return $work();
        } finally {
            $target->scalar('SELECT RELEASE_LOCK(?)', [$name], false);
        }
    }

    private function sqlite(Connection $source, Connection $target, Closure $work): mixed
    {
        $path = $target->selectOne('PRAGMA database_list', [], false)->file;
        if ($source->getDriverName() === 'sqlite') {
            $other = $source->selectOne('PRAGMA database_list')->file;
            if ($path !== '' && $other !== '' && fileinode($path) === fileinode($other) && stat($path)['dev'] === stat($other)['dev']) {
                $this->sameDatabase();
            }
            if ($path === '' && $other === '' && $source->getDatabaseName() !== ':memory:'
                && explode('?', $source->getDatabaseName())[0] === explode('?', $target->getDatabaseName())[0]
                && str_contains($source->getDatabaseName(), 'cache=shared') && str_contains($target->getDatabaseName(), 'cache=shared')) {
                $this->sameDatabase();
            }
        }
        if ($path === '') {
            return $work(); // Separate CLI processes cannot share an in-memory SQLite destination.
        }
        $handle = @fopen($path.'.easymail-import.lock', 'c');
        if (!$handle) {
            throw new RuntimeException('移行先SQLiteと同じディレクトリの取込ロックを開けません。');
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                $this->busy();
            }
            return $work();
        } finally {
            fclose($handle);
        }
    }

    private function sameDatabase(): never
    {
        throw new RuntimeException('移行元と移行先が同一のDBです。旧DBの複製とMailFormのDBを別々に指定してください。');
    }

    private function busy(): never
    {
        throw new RuntimeException('同じ移行先で別の取込・診断・照合を実行中です。終了後に再実行してください。');
    }
}
