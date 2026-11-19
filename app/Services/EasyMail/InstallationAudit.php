<?php

namespace App\Services\EasyMail;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class InstallationAudit
{
    private array $manifest;
    private array $themeIssues = [];

    public function __construct(private ?string $root, string $version)
    {
        if ($root !== null && !is_dir($root.'/resources/views/theme')) {
            throw new RuntimeException('--source-root は resources/views/theme を含む EasyMail 設置先を指定してください。');
        }
        $this->manifest = json_decode(file_get_contents(resource_path("easymail/{$version}-sha256.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    private ?array $coreIssues = null;

    public function coreIssues(): array
    {
        if ($this->root === null) {
            return [];
        }
        return $this->coreIssues ??= $this->differences(array_filter($this->manifest, fn ($path) => !str_starts_with($path, 'resources/views/theme/'), ARRAY_FILTER_USE_KEY));
    }

    public function forForm(string $theme, string $slug): array
    {
        if (!in_array($theme, ['gray', 'gradation', 'one_column', 'two_column01', 'two_column02'], true)) {
            return ["未対応のテーマ: {$theme}"];
        }
        if ($this->root === null) {
            return [];
        }
        if (!isset($this->themeIssues[$theme])) {
            $prefix = "resources/views/theme/{$theme}/";
            $files = array_filter($this->manifest, fn ($path) => str_starts_with($path, $prefix), ARRAY_FILTER_USE_KEY);
            $issues = $this->differences($files);
            $dir = $this->root.'/'.$prefix;
            if (is_dir($dir) && realpath($dir) === rtrim($dir, '/')) {
                $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
                foreach ($iter as $file) {
                    $path = substr($file->getPathname(), strlen($this->root) + 1);
                    if (!isset($files[$path]) && !in_array($file->getFilename(), ['.DS_Store', 'Thumbs.db'], true)) {
                        $issues[] = "テーマ追加ファイル: {$path}";
                    }
                }
            }
            $this->themeIssues[$theme] = $issues;
        }

        $issues = $this->themeIssues[$theme];
        if (preg_match('/^[\pL\pN_-]*$/uD', $slug)) {
            $override = 'resources/views/theme/'.($slug === '' ? 'index' : $slug).'.blade.php';
            if (is_file($this->root.'/'.$override)) {
                $issues[] = "URL別テンプレート: {$override}";
            }
        }
        return $issues;
    }

    private function differences(array $expected): array
    {
        $issues = [];
        foreach ($expected as $relative => $hash) {
            $path = $this->root.'/'.$relative;
            $resolved = realpath($path);
            if ($resolved !== $path || !is_file($path) || !is_readable($path)) {
                $issues[] = "設置ファイル欠損またはリンク: {$relative}";
            } elseif (hash_file('sha256', $path) !== $hash) {
                $issues[] = "標準版からの変更: {$relative}";
            }
        }
        return $issues;
    }
}
