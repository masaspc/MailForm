<?php

namespace App\Http\Controllers\Admin;

use App\Models\Form;
use App\Models\Theme;
use App\Services\Admin\FormEditor;
use App\Services\SafeMarkup;
use App\Services\ThemeAssets;
use App\Services\ThemeAssets\StylesheetReport;
use App\Services\ThemeCatalog;
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

        return view('admin.themes', compact('editing', 'images', 'paths', 'selected', 'isCss', 'css', 'forms'));
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
