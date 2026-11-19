<?php

namespace App\Services\EasyMail;

use App\Models\Theme;
use App\Services\Admin\FormEditor;
use App\Services\ThemeAssets;

final class ImportedThemes
{
    public function __construct(private ImportGuard $guard) {}

    /** Merge each editable field and asset against what the previous backup supplied. */
    public function sync(string $name, string $version, array $scan, bool $dry, bool $verify, bool $overwrite, bool $needsAlias): array
    {
        $report = array_intersect_key($scan, array_flip(['layout', 'score', 'changes', 'behaviors', 'warnings', 'errors', 'fingerprint', 'stylesheets', 'background_logo']));
        $report += ['source_name' => $name, 'version' => $version];
        if ($report['errors'] || !$report['layout']) {
            return $report + ['status' => 'blocked', 'slug' => null, 'integrity' => $scan['integrity']];
        }
        $work = function () use ($name, $scan, $report, $dry, $verify, $overwrite, $needsAlias) {
            $key = hash('sha256', $name);
            $query = Theme::where('source_key', $key);
            $theme = ($dry ? $query : $query->lockForUpdate())->first() ?? new Theme(['slug' => $this->slug($name, $key, $needsAlias), 'name' => mb_substr($name, 0, 150)]);
            $prior = $theme->import_report ?? [];
            $values = [];
            $baseline = [];
            $preserved = [];
            foreach (['name', 'layout', 'header', 'footer', 'logo'] as $field) {
                $value = $field === 'name' ? mb_substr($name, 0, 150) : ($scan[$field] ?? null);
                $baseline[$field] = $this->digest($value);
                if (!$overwrite && isset($prior['baseline'][$field]) && $this->digest($theme->$field) !== $prior['baseline'][$field]) {
                    $value = $theme->$field;
                    $preserved[] = $field;
                }
                $values[$field] = $value;
            }
            $assets = [];
            $baseline['assets'] = [];
            $previousAssets = $theme->assets ?? [];
            $incomingAssets = $scan['assets'];
            // A retained local stylesheet or header can still refer to an image the new backup removed.
            $keepImages = (bool) array_intersect($preserved, ['header', 'footer', 'logo']);
            foreach ($previousAssets as $path => $current) {
                if ($current['mime'] === 'text/css' && $current['hash'] !== ($prior['baseline']['assets'][$path] ?? null)) {
                    $keepImages = true;
                }
            }
            $integrity = [];
            foreach (array_unique([...array_keys($incomingAssets), ...array_keys($previousAssets), ...array_keys($prior['baseline']['assets'] ?? [])]) as $path) {
                $file = $incomingAssets[$path] ?? null;
                $descriptor = $file === null ? null : ['hash' => $file['hash'], 'mime' => $file['mime']];
                if ($descriptor !== null) {
                    $baseline['assets'][$path] = $descriptor['hash'];
                }
                $current = $previousAssets[$path] ?? null;
                $edited = $theme->exists && ($current['hash'] ?? null) !== ($prior['baseline']['assets'][$path] ?? null);
                if (!$overwrite && ($edited || ($keepImages && $file === null && str_starts_with($current['mime'] ?? '', 'image/')))) {
                    $descriptor = $current;
                    $preserved[] = $path;
                }
                if ($descriptor === null) {
                    continue;
                }
                $assets[$path] = $descriptor;
                if (!$dry && $file !== null && $file['hash'] === $descriptor['hash']) {
                    ThemeAssets::store($file);
                } elseif (($verify || !$dry) && !ThemeAssets::intact($descriptor)) {
                    $integrity[] = "保存済みのテーマ資産を照合できません: {$path}";
                }
            }
            if (isset($values['logo']) && !isset($assets[$values['logo']])) {
                $values['logo'] = null;
            }
            $newReport = $report + ['baseline' => $baseline];
            $theme->fill($values);
            // MySQL reorders JSON object keys on write. A repeated import must keep its approval.
            if (!JsonValue::same($theme->assets, $assets)) {
                $theme->assets = $assets;
            }
            $changed = !$theme->exists || ($prior['fingerprint'] ?? null) !== $scan['fingerprint']
                || !JsonValue::same($prior['warnings'] ?? [], $report['warnings'])
                || !JsonValue::same($prior['behaviors'] ?? [], $report['behaviors'])
                || $theme->isDirty(['header', 'footer', 'logo', 'layout', 'assets']);
            $reportChanged = !JsonValue::same($prior, $newReport);
            if ($verify && ($changed || $reportChanged)) {
                $integrity[] = '移行元のテーマと保存済みの取込記録が一致しません。';
            }
            if (!$dry) {
                $theme->source_key = $key;
                if ($reportChanged) {
                    $theme->import_report = $newReport;
                }
                if ($theme->isDirty()) {
                    $theme->save();
                }
                if ($changed || $integrity) {
                    $theme->requireReview();
                }
            }
            return $report + ['slug' => $theme->slug, 'status' => $changed || !$theme->reviewed_at || $integrity ? 'pending' : 'reviewed',
                'preserved' => $preserved, 'integrity' => $integrity];
        };
        return $dry ? $work() : $this->guard->transaction($work, attempts: 3);
    }

    private function digest(?string $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function slug(string $name, string $key, bool $needsAlias): string
    {
        $candidate = !$needsAlias && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,79}$/D', $name) ? $name : 'em-'.$key;
        // Preserve an existing administrator-created theme and its references.
        while (isset(FormEditor::THEMES[strtolower($candidate)]) || Theme::whereRaw('LOWER(slug) = ?', [strtolower($candidate)])->exists()) {
            $candidate = 'em-'.hash('sha256', $candidate.$key);
        }
        return $candidate;
    }
}
