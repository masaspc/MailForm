<?php

namespace App\Http\Controllers\Admin;

use App\Models\Form;
use App\Models\Theme;
use App\Services\Admin\FormEditor;
use App\Services\EasyMail\JsonValue;
use App\Services\SafeMarkup;
use App\Services\ThemeAssets;
use App\Services\ThemeAssets\StylesheetReport;
use App\Services\ThemeCatalog;
use App\Services\ThemeStructure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ThemeController
{
    public function index()
    {
        return view('admin.themes', [
            'themes' => ThemeCatalog::choices(), 'editing' => null, 'images' => [],
            'reviews' => Theme::whereNotNull('import_report')->pluck('reviewed_at', 'slug'),
        ]);
    }

    public function edit(Request $request, string $slug)
    {
        $choices = ThemeCatalog::choices();
        abort_unless(isset($choices[$slug]), 404);
        $editing = Theme::where('slug', $slug)->first() ?? new Theme(['slug' => $slug, 'layout' => $slug, 'name' => $choices[$slug]]);
        $images = ThemeCatalog::files($editing, 'image/');
        $styles = ThemeCatalog::stylesheets($editing);
        $paths = [...$styles, ...array_keys($images)];
        $selected = $request->query('asset', $paths[0] ?? null);
        abort_if($selected !== null && !in_array($selected, $paths, true), 404);
        $isCss = in_array($selected, $styles, true);
        $css = $isCss ? ThemeAssets::read($editing->assets[$selected]) : null;
        $forms = Form::whereNull('archived_at')->where('definition->settings->theme_name', $slug)
            ->orderBy('name')->get(['id', 'name', 'slug', 'published', 'awaiting_theme_publication']);
        $labels = ThemeStructure::labels();
        $inferred = $editing->import_report['structure'] ?? [];
        $structure = ThemeStructure::clean($editing->structure ?? []);
        $overrides = $editing->editedStructureSlots();
        $component = $request->query('component', array_key_first(array_intersect_key($labels, $inferred)) ?? array_key_first($labels));
        abort_unless(is_string($component) && isset($labels[$component]), 404);
        $states = [];
        foreach (array_keys($labels) as $slot) {
            $states[$slot] = isset($overrides[$slot])
                ? (isset($structure[$slot]) ? '手動設定' : '手動で標準を指定')
                : (isset($inferred[$slot]) ? '自動推定' : '標準を継承');
        }
        $options = ThemeStructure::options($component);

        return view('admin.themes', compact('editing', 'images', 'paths', 'selected', 'isCss', 'css', 'forms',
            'labels', 'inferred', 'structure', 'overrides', 'component', 'states', 'options'));
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'slug' => ['required', 'regex:/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/D'],
            'name' => 'required|string|max:150', 'layout' => ['required', Rule::in(array_keys(FormEditor::THEMES))],
            'header' => 'nullable|string|max:20000', 'footer' => 'nullable|string|max:20000',
            'logo' => 'nullable|string|max:512', 'version' => 'nullable|string|max:64',
        ]);
        try {
            DB::transaction(function () use ($data) {
                $theme = Theme::where('slug', $data['slug'])->lockForUpdate()->first();
                $this->checkVersion($theme, $data['version'] ?? '');
                $theme ??= new Theme(['slug' => $data['slug']]);
                foreach (['header', 'footer'] as $key) {
                    if (SafeMarkup::hasUnsupported($data[$key] ?? '', $theme)) {
                        throw ValidationException::withMessages([$key => '段落、強調、リスト、リンク、登録済みのテーマ画像を使用してください。']);
                    }
                }
                if (array_key_exists('logo', $data)) {
                    if ($data['logo'] !== null && !isset(ThemeCatalog::files($theme, 'image/')[$data['logo']])) {
                        throw ValidationException::withMessages(['logo' => 'テーマに登録した画像からロゴを選んでください。']);
                    }
                    $header = array_key_exists('header', $data) ? $data['header'] : $theme->header;
                    $data['header'] = SafeMarkup::replaceHeaderLogo($header, $theme->logo, $data['logo']);
                }
                $theme->fill(Arr::only($data, ['name', 'layout', 'header', 'footer', 'logo']));
                if ($theme->isDirty()) {
                    $review = $theme->isDirty(['layout', 'header', 'footer', 'logo']);
                    $theme->save();
                    if ($review) {
                        $theme->requireReview();
                    }
                }
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, '同じ識別名のテーマが作成されました。編集画面を開き直してください。');
        }
        return redirect()->route('admin.themes.edit', $data['slug'])->with('notice', 'テーマを保存しました。');
    }

    public function component(Request $request, string $slug)
    {
        $data = $request->validate([
            'version' => 'required|string|size:64',
            'slot' => ['required', 'string', Rule::in(array_keys(ThemeStructure::labels()))],
            'mode' => ['required', 'string', Rule::in(['edit', 'standard', 'source'])],
        ]);
        $slot = $data['slot'];
        DB::transaction(function () use ($request, $data, $slug, $slot) {
            $theme = Theme::where('slug', $slug)->lockForUpdate()->firstOrFail();
            $this->checkVersion($theme, $data['version']);
            $overrides = $theme->editedStructureSlots();
            $structure = $theme->structure ?? [];
            switch ($data['mode']) {
                case 'edit':
                    $structure[$slot] = $this->componentValue($request, $slot);
                    $overrides[$slot] = true;
                    break;
                case 'standard':
                    unset($structure[$slot]);
                    $overrides[$slot] = true;
                    break;
                case 'source':
                    unset($overrides[$slot], $structure[$slot]);
                    $inferred = ThemeStructure::clean($theme->import_report['structure'] ?? []);
                    if (isset($inferred[$slot])) {
                        $structure[$slot] = $inferred[$slot];
                    }
                    break;
            }
            $changed = !JsonValue::same($theme->structure ?? [], $structure);
            if ($changed) {
                $theme->structure = $structure;
            }
            if (!JsonValue::same($theme->structure_overrides ?? [], $overrides)) {
                $theme->structure_overrides = $overrides;
            }
            if ($theme->isDirty()) {
                $theme->save();
                if ($changed) {
                    $theme->requireReview();
                }
            }
        }, 3);
        return redirect()->to(route('admin.themes.edit', ['slug' => $slug, 'component' => $slot]).'#structure')
            ->with('notice', ThemeStructure::labels()[$slot].'の設定を保存しました。');
    }

    private function componentValue(Request $request, string $slot): array|string
    {
        if ($slot === 'address_order') {
            $data = $request->validate(['order' => 'required|list|max:'.count(ThemeStructure::ADDRESS_PARTS), 'order.*' => 'nullable|string']);
            $value = array_values(array_filter($data['order'], fn ($part) => filled($part)));
        } elseif (ThemeStructure::options($slot)) {
            $value = $request->input('option');
        } else {
            $depth = ThemeStructure::MAX_DEPTH - 1;
            $data = $request->validate([
                'wrappers' => 'sometimes|list|max:'.$depth, 'wrappers.*' => 'array:tag,class,id,position',
                'wrappers.*.position' => 'nullable|integer|min:1|max:'.$depth,
                'wrappers.*.tag' => 'nullable|string', 'wrappers.*.class' => 'nullable|string', 'wrappers.*.id' => 'nullable|string',
                'element' => 'required|array:tag,class,id', 'element.tag' => 'required|string',
                'element.class' => 'nullable|string', 'element.id' => 'nullable|string',
            ]);
            $wrappers = [];
            foreach ($data['wrappers'] ?? [] as $index => $row) {
                $position = $row['position'] ?? $index + 1;
                $node = array_filter(Arr::except($row, 'position'), fn ($value) => filled($value));
                if ($node) {
                    if (isset($wrappers[$position])) {
                        throw ValidationException::withMessages(['wrappers' => '外側の要素の順番が重複しています。']);
                    }
                    $wrappers[$position] = $node;
                }
            }
            ksort($wrappers, SORT_NUMERIC);
            $value = [...array_values($wrappers), array_filter($data['element'], fn ($value) => filled($value))];
        }
        if (!ThemeStructure::valid([$slot => $value])) {
            throw ValidationException::withMessages(['component' => 'この部品で使えるタグ・クラス・ID、または選択肢と並び順を確認してください。']);
        }
        return ThemeStructure::clean([$slot => $value])[$slot];
    }

    public function asset(Request $request, string $slug)
    {
        $data = $request->validate([
            'version' => 'required|string|max:64', 'path' => 'required|string|max:512',
            'file' => 'required_without:css|prohibits:css|file|max:4096', 'css' => 'required_without:file|string|max:262144',
        ]);
        $path = $data['path'];
        if (!ThemeAssets::validPath($path)) {
            throw ValidationException::withMessages(['path' => 'テーマ内の相対パスと、対応するCSSまたは画像の拡張子を指定してください。']);
        }
        $upload = $request->file('file');
        if (!$upload && strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'css') {
            throw ValidationException::withMessages(['file' => '画像を追加・更新するにはファイルを選んでください。']);
        }

        DB::transaction(function () use ($slug, $path, $data, $upload) {
            $theme = Theme::where('slug', $slug)->lockForUpdate()->firstOrFail();
            $this->checkVersion($theme, $data['version']);
            $warnings = [];
            $bytes = $upload ? $upload->get() : $data['css'];
            $cssReport = new StylesheetReport(linkedLogo: SafeMarkup::hasLinkedLogo($theme->header));
            $asset = is_string($bytes) ? ThemeAssets::prepare($path, $bytes, ThemeCatalog::files($theme, 'image/'), $warnings, $cssReport) : null;
            array_push($warnings, ...$cssReport->warnings());
            if (!$asset || $warnings) {
                throw ValidationException::withMessages([($upload ? 'file' : 'css') => $warnings ?: ['ファイルの内容を読み込めません。']]);
            }
            $descriptor = ThemeAssets::store($asset);
            $files = $theme->assets ?? [];
            if (($files[$path] ?? null) != $descriptor) {
                $files[$path] = $descriptor;
                $theme->update(['assets' => $files]);
                $theme->requireReview();
            }
        }, 3);
        return redirect()->route('admin.themes.edit', ['slug' => $slug, 'asset' => $path])->with('notice', 'テーマ素材を保存しました。');
    }

    public function approve(Request $request, string $slug)
    {
        $data = $request->validate(['confirmed' => 'accepted', 'version' => 'required|string|max:64'], [
            'confirmed.accepted' => 'プレビューと移行時の差分を確認してください。',
        ]);
        $count = DB::transaction(function () use ($slug, $data) {
            $theme = Theme::where('slug', $slug)->whereNotNull('import_report')->lockForUpdate()->firstOrFail();
            $this->checkVersion($theme, $data['version']);
            return $theme->approveForPublication();
        }, 3);
        return redirect()->route('admin.themes.edit', $slug)->with('notice', "テーマの確認を完了しました。公開を開始したフォームは {$count} 件です。");
    }

    private function checkVersion(?Theme $theme, string $version): void
    {
        abort_unless(($theme?->editorVersion() ?? '') === $version, 409, 'テーマが更新されました。編集画面を開き直してください。');
    }
}
