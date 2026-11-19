<?php

namespace App\Services;

use App\Models\FieldTemplate;

class FieldLibrary
{
    private static function stock(): array
    {
        return json_decode(file_get_contents(resource_path('easymail/presets.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function all(): array
    {
        $custom = FieldTemplate::orderBy('id')->get();
        $overridden = $custom->pluck('legacy_id')->filter()->all();
        $stock = array_filter(self::stock()['fields'], fn ($field) => !in_array($field['id'], $overridden));
        return [...array_values($stock), ...$custom->pluck('definition')->all()];
    }

    public static function presets(): array
    {
        $stock = self::stock();
        $byId = array_column($stock['fields'], null, 'id');
        $result = [];
        foreach ($stock['presets'] as $slug => $preset) {
            $fields = [];
            foreach ($preset['items'] as $item) {
                $fields[] = array_replace($byId[$item['form_block_id']], $item);
            }
            $result[$slug] = ['settings' => $preset['settings'], 'fields' => $fields, 'issues' => []];
        }
        return $result;
    }
}
