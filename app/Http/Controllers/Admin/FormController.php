<?php

namespace App\Http\Controllers\Admin;

use App\Models\Form;
use App\Services\FieldLibrary;
use App\Services\Admin\FormEditor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FormController
{
    public function __construct(private FormEditor $editor) {}

    public function index(Request $request)
    {
        $archived = $request->boolean('archived');
        abort_if($archived && $request->user()->role !== 'admin', 403);
        $forms = Form::when($archived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->withCount(['submissions' => fn ($q) => $q->where('retain', true)])
            ->latest('updated_at')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('admin.forms', compact('forms', 'archived'));
    }

    public function create(Request $request)
    {
        $preset = $request->query('preset', 'contact');
        abort_unless(is_string($preset), 404);
        $definition = $this->editor->preset($preset, $request->user()->email);
        $slug = $definition['settings']['url'] ?? $preset;
        if (Form::where('slug', $slug)->exists()) {
            $definition['settings']['url'] = $slug.'-'.strtolower(Str::random(6));
        }

        return view('admin.editor', [
            'definition' => $definition, 'form' => null, 'version' => null, 'preset' => $preset,
            'library' => FieldLibrary::all(),
        ]);
    }

    public function store(Request $request)
    {
        $definition = $this->editor->read($request, $this->editor->preset($request->validate(['preset' => 'required|string'])['preset'], $request->user()->email));
        $form = new Form(['revision' => 0, 'published' => false, 'definition' => $definition]);
        $this->saveIdentity($form, $definition);

        return redirect()->route('admin.edit', $form)->with('notice', '下書きを作成しました。');
    }

    public function edit(Form $form)
    {
        abort_if($form->archived_at, 404);
        return view('admin.editor', [
            'form' => $form, 'library' => FieldLibrary::all(), 'definition' => $this->editor->draft($form), 'version' => $this->editor->version($form),
        ]);
    }

    public function update(Request $request, Form $form)
    {
        DB::transaction(function () use ($request, $form) {
            $form = Form::lockForUpdate()->findOrFail($form->id);
            abort_if($form->archived_at, 404);
            $this->editor->checkVersion($request, $form);
            $definition = $this->editor->read($request, $this->editor->draft($form), $form);
            if ($form->published) {
                $form->update(['draft_definition' => $definition]);
            } else {
                $form->fill(['definition' => $definition, 'draft_definition' => null]);
                $this->saveIdentity($form, $definition);
            }
        });

        return redirect()->route('admin.edit', $form)->with('notice', '下書きを保存しました。');
    }

    public function copy(Form $form)
    {
        abort_if($form->archived_at, 404);
        $definition = $this->editor->draft($form);
        $settings = &$definition['settings'];
        $settings['name'] = Str::limit($settings['name'] ?? $form->name, 180, '').'（複製）';
        $settings['url'] = Str::limit(($settings['url'] ?? $form->slug) ?: 'form', 170, '').'-'.strtolower(Str::random(8));
        $settings['view_flag'] = 0;
        $copy = new Form(['revision' => 0, 'published' => false, 'definition' => $definition]);
        $this->saveIdentity($copy, $definition);

        return redirect()->route('admin.edit', $copy)->with('notice', 'フォームを下書きとして複製しました。');
    }

    public function publish(Request $request, Form $form)
    {
        DB::transaction(function () use ($request, $form) {
            $form = Form::lockForUpdate()->findOrFail($form->id);
            abort_if($form->archived_at, 404);
            $this->editor->checkVersion($request, $form);
            if ($form->published && $form->draft_definition === null) {
                return;
            }
            $definition = $this->editor->draft($form);
            $this->editor->checkPublish($definition, $form);
            $definition['settings']['view_flag'] = 1;
            $form->fill(['revision' => $form->revision + 1, 'definition' => $definition, 'draft_definition' => null, 'published' => true]);
            $this->saveIdentity($form, $definition);
            $form->revisions()->create(['revision' => $form->revision, 'definition' => $definition]);
        });

        return redirect()->route('admin.edit', $form)->with('notice', '保存済みの内容を公開しました。');
    }

    public function unpublish(Request $request, Form $form)
    {
        DB::transaction(function () use ($request, $form) {
            $form = Form::lockForUpdate()->findOrFail($form->id);
            abort_if($form->archived_at, 404);
            $this->editor->checkVersion($request, $form);
            $form->update(['published' => false]);
        });

        return redirect()->route('admin.edit', $form)->with('notice', '公開を停止しました。');
    }

    public function archive(Request $request, Form $form)
    {
        $request->validate(['confirmed' => 'accepted'], ['confirmed.accepted' => '保管するフォームをご確認ください。']);
        DB::transaction(function () use ($form, $request) {
            $current = Form::lockForUpdate()->findOrFail($form->id);
            $this->editor->checkVersion($request, $current);
            if ($current->published) {
                throw ValidationException::withMessages(['form' => '先にフォームの公開を停止してください。']);
            }
            $current->forceFill(['archived_at' => now()])->save();
        });

        return redirect()->route('admin.forms')->with('notice', 'フォームを保管しました。受信履歴と配信記録は引き続き確認できます。');
    }

    public function restore(Request $request, Form $form)
    {
        DB::transaction(function () use ($form, $request) {
            $current = Form::lockForUpdate()->findOrFail($form->id);
            $this->editor->checkVersion($request, $current);
            abort_unless($current->archived_at, 404);
            $current->forceFill(['archived_at' => null, 'published' => false])->save();
        });

        return redirect()->route('admin.edit', $form)->with('notice', '下書きとしてフォーム一覧に戻しました。');
    }

    private function saveIdentity(Form $form, array $definition): void
    {
        try {
            $form->name = $definition['settings']['name'] ?? $form->name;
            $form->slug = $definition['settings']['url'] ?? $form->slug ?? '';
            $form->save();
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['slug' => 'このURLは別のフォームで使用されています。']);
        }
    }
}
