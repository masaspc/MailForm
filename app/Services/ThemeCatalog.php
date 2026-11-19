<?php

namespace App\Services;

use App\Models\Theme;
use App\Services\Admin\FormEditor;

class ThemeCatalog
{
    public static function choices(): array
    {
        return array_replace(FormEditor::THEMES, Theme::orderBy('name')->pluck('name', 'slug')->all());
    }

    /** Return manifest entries that can be served under this theme and match the requested MIME type. */
    public static function files(?Theme $theme, string $mimePrefix): array
    {
        if (!$theme || !ThemeAssets::validSlug($theme->slug)) {
            return [];
        }
        return array_filter($theme->assets ?? [], static fn ($asset, $path) =>
            is_string($path) && is_array($asset) && ThemeAssets::matchesPath($path, $asset)
            && str_starts_with($asset['mime'], $mimePrefix), ARRAY_FILTER_USE_BOTH);
    }

    public static function stylesheets(?Theme $theme): array
    {
        $files = array_keys(self::files($theme, 'text/css'));
        $ordered = array_unique(array_intersect($theme?->import_report['stylesheets'] ?? [], $files));
        $others = array_diff($files, $ordered);
        sort($others, SORT_STRING);
        return [...$ordered, ...$others];
    }

    /** Apply the administrator's logo choice inside the original responsive link. */
    public static function logoOverride(Theme $theme, string $stylesheet): string
    {
        $original = $theme->import_report['background_logo'] ?? null;
        if ($original === null || $theme->logo === $original || !SafeMarkup::hasLinkedLogo($theme->header)) {
            return '';
        }
        $sheets = self::stylesheets($theme);
        if (end($sheets) !== $stylesheet) {
            return '';
        }
        $selected = $theme->logo;
        $declaration = $selected !== null && isset(self::files($theme, 'image/')[$selected])
            ? 'background-image:url("'.ThemeAssets::url($theme->slug, $selected).'") !important;'
            : 'background-image:none !important; text-indent:0 !important;';
        return "\n.site-banner .".SafeMarkup::LINKED_LOGO_CLASS.' a { '.$declaration.' }';
    }
}
