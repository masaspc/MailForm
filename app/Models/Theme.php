<?php

namespace App\Models;

use App\Services\EasyMail\JsonValue;
use App\Services\ThemeAssets;
use App\Services\ThemeStructure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Theme extends Model
{
    protected $fillable = ['slug', 'name', 'layout', 'header', 'footer', 'assets', 'logo', 'structure'];

    protected function casts(): array
    {
        return ['assets' => 'array', 'structure' => 'array', 'structure_overrides' => 'array',
            'import_report' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function editorVersion(): string
    {
        return hash('sha256', serialize($this->getAttributes()));
    }

    public function pendingReview(): bool
    {
        return $this->import_report !== null && $this->reviewed_at === null;
    }

    /** A local choice of the standard layout also survives a slot disappearing from later backups. */
    public function editedStructureSlots(): array
    {
        $labels = ThemeStructure::labels();
        $overrides = array_intersect_key($this->structure_overrides ?? [], $labels);
        $inferred = $this->import_report['structure'] ?? [];
        $current = $this->structure ?? [];
        foreach (array_keys($labels) as $slot) {
            if (!JsonValue::same($current[$slot] ?? null, $inferred[$slot] ?? null)) {
                $overrides[$slot] = true;
            }
        }
        return $overrides;
    }

    /** Caller holds the theme row lock. Pause only forms actually published with this theme. */
    public function requireReview(): void
    {
        if ($this->import_report === null) {
            return;
        }
        if ($this->reviewed_at !== null) {
            $this->forceFill(['reviewed_at' => null])->save();
        }
        Form::whereNotNull('legacy_id')->whereNull('archived_at')->where('published', true)
            ->where('definition->settings->theme_name', $this->slug)
            ->update(['published' => false, 'awaiting_theme_publication' => true]);
    }

    public function approveForPublication(): int
    {
        return DB::transaction(function () {
            $theme = self::lockForUpdate()->findOrFail($this->getKey());
            if (!$theme->pendingReview()) {
                return 0;
            }
            if (!is_array($theme->structure ?? []) || !ThemeStructure::valid($theme->structure ?? [])) {
                throw ValidationException::withMessages(['structure' => '部品のタグ・属性・並び順を確認してください。']);
            }
            foreach ($theme->assets ?? [] as $path => $asset) {
                if (!ThemeAssets::matchesPath($path, $asset) || !ThemeAssets::intact($asset)) {
                    throw ValidationException::withMessages(['assets' => "テーマ資産を照合できません: {$path}"]);
                }
            }
            $theme->forceFill(['reviewed_at' => now()])->save();
            $count = 0;
            Form::where('awaiting_theme_publication', true)->whereNotNull('legacy_id')
                ->where('definition->settings->theme_name', $theme->slug)->lockForUpdate()
                ->chunkById(100, function ($forms) use (&$count) {
                    foreach ($forms as $form) {
                        $publish = !$form->archived_at && empty($form->definition['issues'])
                            && empty($form->definition['review_warnings'])
                            && (int) ($form->definition['settings']['view_flag'] ?? 0) === 1;
                        $form->update(['published' => $publish, 'awaiting_theme_publication' => false]);
                        $count += (int) $publish;
                    }
                });
            $this->setRawAttributes($theme->getAttributes(), true);
            return $count;
        });
    }
}
