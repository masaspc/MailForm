<?php

namespace App\Services;

use App\Services\EasyMail\JsonValue;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Internal, root-held transfer between the two databases created by the dedicated-host installer. */
final class RehearsalThemes
{
    private const HEADER = ['mailform_rehearsal_themes' => 2];
    private const COLUMNS = ['slug', 'name', 'layout', 'header', 'footer', 'source_key', 'assets', 'logo',
        'structure', 'structure_overrides', 'import_report', 'reviewed_at', 'created_at', 'updated_at'];
    private const LINE_LIMIT = 16 * 1024 * 1024;

    public static function export($output): int
    {
        self::write($output, self::HEADER);
        $sent = [];
        $count = 0;
        $rows = DB::table('themes')->whereNotNull('source_key')->whereNotNull('import_report')
            ->select(self::COLUMNS)->orderBy('slug')->cursor();
        foreach ($rows as $row) {
            $data = (array) $row;
            $assets = self::manifest($data);
            self::write($output, ['theme' => $data]);
            foreach ($assets as $path => $descriptor) {
                $hash = $descriptor['hash'];
                if (isset($sent[$hash])) {
                    continue;
                }
                $bytes = ThemeAssets::read($descriptor);
                if ($bytes === null) {
                    throw new RuntimeException("練習用のテーマ素材を照合できません: {$row->slug}/{$path}");
                }
                self::write($output, ['hash' => $hash, 'data' => base64_encode($bytes)]);
                $sent[$hash] = true;
            }
            $count++;
        }
        self::write($output, ['themes' => $count]);
        return $count;
    }

    public static function restore($input): int
    {
        $header = self::record($input);
        $legacy = $header === ['mailform_rehearsal_themes' => 1];
        if (!$legacy && $header !== self::HEADER) {
            throw new RuntimeException('テーマの引継ぎ形式を確認してください。');
        }
        return DB::transaction(function () use ($input, $legacy) {
            $seen = [];
            $count = 0;
            while (isset(($item = self::record($input))['theme'])) {
                $row = $item['theme'];
                if ($legacy) {
                    $row += ['structure' => null, 'structure_overrides' => null];
                }
                $assets = self::manifest($row);
                foreach ($assets as $path => $descriptor) {
                    $hash = $descriptor['hash'];
                    if (!isset($seen[$hash])) {
                        $blob = self::record($input);
                        $bytes = is_string($blob['data'] ?? null) ? base64_decode($blob['data'], true) : false;
                        if (($blob['hash'] ?? null) !== $hash || $bytes === false || hash('sha256', $bytes) !== $hash) {
                            throw new RuntimeException("引継ぐテーマ素材が壊れています: {$path}");
                        }
                        $warnings = [];
                        $asset = ThemeAssets::prepare($path, $bytes, $assets, $warnings);
                        if ($warnings || ($asset['hash'] ?? null) !== $hash || $asset['mime'] !== $descriptor['mime']) {
                            throw new RuntimeException("引継ぐテーマ素材を再検査できません: {$path}");
                        }
                        ThemeAssets::store($asset);
                        $seen[$hash] = $descriptor['mime'];
                    } elseif ($seen[$hash] !== $descriptor['mime']) {
                        throw new RuntimeException("素材の種類が一致しません: {$path}");
                    }
                }
                $existing = DB::table('themes')->select(self::COLUMNS)->where('source_key', $row['source_key'])->lockForUpdate()->first();
                if ($existing === null) {
                    DB::table('themes')->insert($row);
                } elseif (!JsonValue::same(self::decodedRow((array) $existing), self::decodedRow($row))) {
                    throw new RuntimeException('本番に異なる内容のテーマが既にあります。');
                }
                $count++;
            }
            if ($item !== ['themes' => $count] || fgetc($input) !== false || DB::table('themes')->count() !== $count) {
                throw new RuntimeException('テーマの引継ぎ件数と終端を照合できません。');
            }
            return $count;
        });
    }

    /** MySQL can normalize JSON object order and whitespace when it stores the rehearsal row. */
    private static function decodedRow(array $row): array
    {
        foreach (['assets', 'import_report', 'structure', 'structure_overrides'] as $column) {
            if ($row[$column] !== null) {
                $row[$column] = json_decode($row[$column], true, flags: JSON_THROW_ON_ERROR);
            }
        }
        return $row;
    }

    private static function manifest(array $row): array
    {
        if (array_diff(array_keys($row), self::COLUMNS) || array_diff(self::COLUMNS, array_keys($row))
            || !is_string($row['slug']) || !ThemeAssets::validSlug($row['slug'])) {
            throw new RuntimeException('引継ぐテーマの項目が正しくありません。');
        }
        $row = self::decodedRow($row);
        $report = $row['import_report'];
        $assets = $row['assets'] ?? [];
        if (!is_string($report['source_name'] ?? null) || hash('sha256', $report['source_name']) !== $row['source_key'] || !is_array($assets)) {
            throw new RuntimeException('引継ぐテーマの移行元を識別できません。');
        }
        $structure = $row['structure'] ?? [];
        $overrides = $row['structure_overrides'] ?? [];
        $inferred = $report['structure'] ?? [];
        if (!is_array($structure) || !ThemeStructure::valid($structure) || !is_array($inferred) || !ThemeStructure::valid($inferred)
            || !is_array($overrides) || array_diff_key($overrides, ThemeStructure::labels()) || array_filter($overrides, fn ($flag) => $flag !== true)) {
            throw new RuntimeException('引継ぐテーマの部品設定が正しくありません。');
        }
        foreach ($assets as $path => $asset) {
            if (!is_string($path) || !ThemeAssets::matchesPath($path, $asset)) {
                throw new RuntimeException('引継ぐテーマの素材名または形式が正しくありません。');
            }
        }
        return $assets;
    }

    private static function write($output, array $item): void
    {
        $line = json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
        if (strlen($line) >= self::LINE_LIMIT || fwrite($output, $line) !== strlen($line)) {
            throw new RuntimeException('テーマの引継ぎ記録を書き込めません。');
        }
    }

    private static function record($input): array
    {
        $line = fgets($input, self::LINE_LIMIT + 1);
        if ($line === false || !str_ends_with($line, "\n") || !is_array($item = json_decode($line, true))) {
            throw new RuntimeException('テーマの引継ぎ記録が不完全です。');
        }
        return $item;
    }
}
