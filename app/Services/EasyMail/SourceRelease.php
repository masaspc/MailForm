<?php

namespace App\Services\EasyMail;

use RuntimeException;

final readonly class SourceRelease
{
    public const VERSIONS = ['2.00.124', '2.00.125', '2.00.133', '2.01.004'];

    private function __construct(public string $version, public ?string $root) {}

    public static function usesUnsuffixedAddress(?string $version): bool
    {
        return in_array($version, ['2.00.124', '2.00.125'], true);
    }

    /** Read only the static version marker. Backup PHP and .env files are never executed. */
    public static function identify(?string $root, ?string $declared): self
    {
        if ($declared !== null && !in_array($declared, self::VERSIONS, true)) {
            throw new RuntimeException('対応版は '.implode(' / ', self::VERSIONS).' です。');
        }
        if ($root === null) {
            if ($declared === null) {
                throw new RuntimeException('DB単独の移行は --source-version に設置版（'.implode(' / ', self::VERSIONS).'）を指定してください。DB列だけでは版を特定できません。');
            }
            return new self($declared, null);
        }
        if (is_dir($root.'/em_laravel') && realpath($root.'/em_laravel') === $root.'/em_laravel') {
            $root .= '/em_laravel';
        }
        $config = $root.'/config/app.php';
        $source = realpath($config) === $config && is_file($config) && is_readable($config) ? file_get_contents($config, length: 262144) : '';
        if (!$source || !preg_match('~[\'"]version[\'"]\s*=>\s*env\(\s*[\'"]APP_VERSION[\'"]\s*,\s*[\'"]([0-9.]+)[\'"]\s*\)~', $source, $match)) {
            throw new RuntimeException('設置ファイル config/app.php から版を特定できません。DB単独の場合は --source-version を指定してください。');
        }
        $version = $match[1];
        if (!in_array($version, self::VERSIONS, true) || ($declared !== null && $declared !== $version)) {
            throw new RuntimeException("設置版 {$version} は指定版または対応版と一致しません。");
        }
        return new self($version, $root);
    }
}
