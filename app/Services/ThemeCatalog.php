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
}
