<?php

namespace App\Services;

use App\Services\ThemeAssets\Stylesheet;
use App\Services\ThemeAssets\Svg;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

final class ThemeAssets
{
    public const MAX_CSS = 262144;
    public const MAX_SVG = 524288;
    public const MAX_IMAGE = 4194304;

    private const TYPES = [
        'css' => 'text/css', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'gif' => 'image/gif',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
    ];

    public static function validSlug(string $slug): bool
    {
        return preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,79}\z/', $slug) === 1;
    }

    public static function validPath(string $path): bool
    {
        if (strlen($path) > 512 || !isset(self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))])) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if (strlen($part) > 200 || !preg_match('/\A[\pL\pN_+-][\pL\pM\pN ._+-]*\z/u', $part)) {
                return false;
            }
        }
        return true;
    }

    public static function matchesPath(string $path, mixed $descriptor): bool
    {
        return self::validPath($path) && self::validDescriptor($descriptor)
            && self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] === $descriptor['mime'];
    }

    public static function url(string $slug, string $path): string
    {
        if (!self::validSlug($slug) || !self::validPath($path)) {
            throw new InvalidArgumentException('テーマまたは素材のパスが正しくありません。');
        }
        return PublicFormUrl::form('_theme', $slug.'/file/'.implode('/', array_map('rawurlencode', explode('/', $path))));
    }

    /** Validate source bytes without touching storage. CSS may reference only images accepted earlier in this import. */
    public static function prepare(string $path, string $bytes, array $images, array &$warnings): ?array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $location = in_array($extension, ['css', 'svg'], true) ? $path.':1' : $path;
        if (!self::validPath($path)) {
            $warnings[] = "{$location}：素材名または拡張子を確認してください。";
            return null;
        }
        $mime = self::TYPES[$extension];
        if ($bytes === '' || strlen($bytes) > self::limit($mime)) {
            $warnings[] = "{$location}：空のファイルまたはサイズ上限を超える素材を除外しました。";
            return null;
        }
        if ($mime === 'text/css') {
            $bytes = Stylesheet::clean($path, $bytes, $images, $warnings);
        } elseif ($mime === 'image/svg+xml') {
            $removedLine = null;
            $bytes = Svg::clean($bytes, $removedLine);
            if ($removedLine !== null) {
                $warnings[] = "{$path}:{$removedLine} SVG の動的要素・属性または外部参照を除外しました。";
            }
        } elseif (!self::isRaster($bytes, $mime)) {
            $bytes = null;
        }
        if ($bytes === null || $bytes === '' || strlen($bytes) > self::limit($mime)) {
            $warnings[] = "{$location}：安全に表示できる形式の内容を取得できませんでした。";
            return null;
        }
        return ['hash' => hash('sha256', $bytes), 'mime' => $mime, 'bytes' => $bytes];
    }

    private static function isRaster(string $bytes, string $mime): bool
    {
        $size = @getimagesizefromstring($bytes);
        if (!$size || $size['mime'] !== $mime || min($size[0], $size[1]) < 1
            || max($size[0], $size[1]) > 8192 || $size[0] * $size[1] > 16000000) {
            return false;
        }
        // getimagesize reads the header. Also require the end marker or declared RIFF length.
        return match ($mime) {
            'image/png' => str_ends_with($bytes, "\0\0\0\0IEND\xae\x42\x60\x82"),
            'image/jpeg' => str_ends_with($bytes, "\xff\xd9"),
            'image/gif' => str_ends_with($bytes, "\x3b"),
            'image/webp' => str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP'
                && unpack('V', substr($bytes, 4, 4))[1] === strlen($bytes) - 8,
            default => false,
        };
    }

    private static function limit(string $mime): int
    {
        return match ($mime) {
            'text/css' => self::MAX_CSS, 'image/svg+xml' => self::MAX_SVG, default => self::MAX_IMAGE,
        };
    }

    private static function validDescriptor(mixed $descriptor): bool
    {
        return is_array($descriptor) && is_string($descriptor['hash'] ?? null)
            && preg_match('/\A[0-9a-f]{64}\z/', $descriptor['hash']) === 1
            && in_array($descriptor['mime'] ?? null, self::TYPES, true);
    }

    public static function storagePath(string $hash): string
    {
        if (!preg_match('/\A[0-9a-f]{64}\z/', $hash)) {
            throw new InvalidArgumentException('テーマ素材のハッシュが正しくありません。');
        }
        return 'theme-assets/'.substr($hash, 0, 2).'/'.$hash;
    }

    /** Publish an immutable blob only after its private temporary file is completely written. */
    public static function store(array $asset): array
    {
        if (!self::validDescriptor($asset) || !is_string($asset['bytes'] ?? null) || $asset['bytes'] === ''
            || strlen($asset['bytes']) > self::limit($asset['mime']) || hash('sha256', $asset['bytes']) !== $asset['hash']) {
            throw new InvalidArgumentException('テーマ素材の内容とハッシュが一致しません。');
        }
        $descriptor = ['hash' => $asset['hash'], 'mime' => $asset['mime']];
        $disk = Storage::disk('local');
        $key = self::storagePath($asset['hash']);
        $target = $disk->path($key);
        if (!file_exists($target) && !is_link($target)) {
            if (!$disk->makeDirectory(dirname($key))) {
                throw new RuntimeException('テーマ素材の保存先を作成できません。');
            }
            $temporary = tempnam(dirname($target), '.incoming-');
            if ($temporary === false) {
                throw new RuntimeException('テーマ素材の一時ファイルを作成できません。');
            }
            try {
                if (dirname($temporary) !== realpath(dirname($target))
                    || file_put_contents($temporary, $asset['bytes']) !== strlen($asset['bytes'])) {
                    throw new RuntimeException('テーマ素材の書き込みに失敗しました。');
                }
                // link() never replaces a destination created by a concurrent import.
                if (!@link($temporary, $target) && !is_file($target)) {
                    throw new RuntimeException('テーマ素材を保存できません。');
                }
            } finally {
                unlink($temporary);
            }
        }
        if (!self::intact($descriptor)) {
            throw new RuntimeException('保存済みのテーマ素材がハッシュと一致しません。');
        }
        return $descriptor;
    }

    public static function read(array $descriptor): ?string
    {
        if (!self::validDescriptor($descriptor)) {
            return null;
        }
        $path = Storage::disk('local')->path(self::storagePath($descriptor['hash']));
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $limit = self::limit($descriptor['mime']);
        $bytes = @file_get_contents($path, false, null, 0, $limit + 1);
        return is_string($bytes) && $bytes !== '' && strlen($bytes) <= $limit
            && hash('sha256', $bytes) === $descriptor['hash'] ? $bytes : null;
    }

    public static function intact(array $descriptor): bool
    {
        return self::read($descriptor) !== null;
    }
}
