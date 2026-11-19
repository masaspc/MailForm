<?php

namespace App\Http\Controllers\Admin;

use App\Models\FieldTemplate;
use App\Services\Admin\FormEditor;
use App\Services\FieldLibrary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FieldTemplateController
{
    public function index()
    {
        return view('admin.library', ['all' => FieldLibrary::all(), 'custom' => FieldTemplate::orderBy('id')->get(), 'template' => null]);
    }

    public function edit(FieldTemplate $template)
    {
        return view('admin.library', ['all' => [], 'custom' => [], 'template' => $template]);
    }

    private function definition(Request $request, FormEditor $editor): array
    {
        // Use the same validation and settings as the form editor. The temporary slug never becomes a real form.
        $data = $request->validate(['fields' => 'required|array|size:1']);
        $data['fields'][0]['id'] = null;
        $input = Request::create('/', 'POST', $data + [
            'name' => '共通項目', 'slug' => 'field-'.bin2hex(random_bytes(10)), 'theme_name' => 'gray', 'editor_complete' => '1',
        ]);
        $draft = $editor->read($input, ['settings' => [], 'fields' => [], 'issues' => []]);
        return $draft['fields'][0];
    }

    public function store(Request $request, FormEditor $editor)
    {
        FieldTemplate::create(['definition' => $this->definition($request, $editor)]);
        return redirect()->route('admin.fields')->with('notice', '共通項目を登録しました。');
    }

    public function update(Request $request, FormEditor $editor, FieldTemplate $template)
    {
        $value = $this->definition($request, $editor);
        DB::transaction(function () use ($template, $request, $value) {
            $current = FieldTemplate::lockForUpdate()->findOrFail($template->id);
            abort_unless(hash_equals(hash('sha256', serialize($current->definition)), (string) $request->input('version')), 409);
            $current->update(['definition' => $value]);
        });
        return redirect()->route('admin.fields')->with('notice', '共通項目を更新しました。');
    }

    public function destroy(FieldTemplate $template)
    {
        $template->delete();
        return redirect()->route('admin.fields')->with('notice', '共通項目を削除しました。');
    }
}
