<?php

namespace App\Http\Controllers\Admin;

use App\Models\Theme;
use App\Services\Admin\FormEditor;
use App\Services\SafeMarkup;
use App\Services\ThemeCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ThemeController
{
    public function index()
    {
        return view('admin.themes', ['themes' => ThemeCatalog::choices(), 'editing' => null]);
    }

    public function edit(string $slug)
    {
        $themes = ThemeCatalog::choices();
        abort_unless(isset($themes[$slug]), 404);
        $editing = Theme::where('slug', $slug)->first() ?? new Theme(['slug' => $slug, 'layout' => $slug, 'name' => $themes[$slug]]);
        return view('admin.themes', compact('themes', 'editing'));
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'slug' => ['required', 'regex:/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/D'],
            'name' => 'required|string|max:150', 'layout' => ['required', Rule::in(array_keys(FormEditor::THEMES))],
            'header' => 'nullable|string|max:20000', 'footer' => 'nullable|string|max:20000',
            'version' => 'nullable|string|max:64',
        ]);
        foreach (['header', 'footer'] as $key) {
            if (SafeMarkup::hasUnsupported($data[$key] ?? '')) {
                throw ValidationException::withMessages([$key => '表示できるHTMLは段落、強調、リスト、リンクなどの書式です。']);
            }
        }
        DB::transaction(function () use ($data) {
            $record = Theme::where('slug', $data['slug'])->lockForUpdate()->first();
            if ($record && ($data['version'] ?? '') !== hash('sha256', serialize($record->getAttributes()))) {
                abort(409, '同じ識別名のテーマが更新されています。編集画面を開き直してください。');
            }
            ($record ?? new Theme())->fill($data)->save();
        });
        return redirect()->route('admin.themes.edit', $data['slug'])->with('notice','テーマを保存しました。');
    }
}
