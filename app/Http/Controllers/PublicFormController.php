<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Form;
use App\Models\Submission;
use App\Models\Theme;
use App\Services\Admin\FormEditor;
use App\Services\PublicAccess;
use App\Services\PublicFields;
use App\Services\PublicMessage;
use App\Services\PublicLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PublicFormController extends Controller
{
    public function __construct(private PublicFields $fields, private PublicMessage $message) {}

    private function published(Request $request, string $slug): Form
    {
        $form = Form::where('slug', $slug)->where('published', true)->whereNull('archived_at')->firstOrFail();
        $theme = $this->theme($form->definition);
        abort_unless($form->hasPublicEntry($theme), 404);
        $form->setRelation('theme', $theme);
        PublicLocale::activate($form->definition['settings']['language'] ?? null);
        PublicAccess::enforce($request, $form->definition['settings'] ?? []);
        return $form;
    }

    private function theme(array $definition): ?Theme
    {
        return Theme::where('slug', $definition['settings']['theme_name'] ?? 'one_column')->first();
    }

    private function draft(Request $request, Form $form): array
    {
        $token = $request->input('receipt_token');
        abort_unless(is_string($token) && Str::isUuid($token), 419);
        $draft = $request->session()->get('public_drafts.'.$token);
        abort_unless($draft && $draft['form'] === $form->id && $draft['expires'] > time(), 419);
        abort_if(!isset($draft['completed']) && $draft['revision'] !== $form->revision, 409, __('フォームが更新されました。最初の画面から入力し直してください。'));
        return $draft;
    }

    private function persist(Request $request, array $draft): void
    {
        $request->session()->put('public_drafts.'.$draft['token'], $draft);
    }

    private function input(Form $form, array $draft, ?MessageBag $problems = null, bool $preview = false)
    {
        $definition = $form->definition;
        $controls = $this->fields->controls($definition['fields'], $definition['source_version'] ?? null);
        return response()->view('public.input', [
            'form' => $form, 'draft' => $draft, 'controls' => collect($controls)->groupBy('field'),
            'problems' => $problems ?? new MessageBag(), 'preview' => $preview, 'controlIds' => array_column($controls, 'id'),
        ], $problems ? 422 : 200)->header('Cache-Control', 'no-store');
    }

    public function preview(Request $request, Form $form, FormEditor $editor)
    {
        abort_if($form->archived_at, 404);
        $definition = $request->query('version') === 'current' ? $form->definition : $editor->draft($form);
        $theme = $this->theme($definition);
        abort_if($theme?->pendingReview() && $request->user()->role !== 'admin', 403);
        $form->setRelation('theme', $theme);
        $form->definition = $definition;
        $form->name = $definition['settings']['name'] ?? $form->name;
        PublicLocale::activate($definition['settings']['language'] ?? null);
        return $this->input($form, ['values' => [], 'files' => []], preview: true);
    }

    public function show(Request $request, string $slug = '')
    {
        $form = $this->published($request, $slug);
        $drafts = array_filter($request->session()->get('public_drafts', []), fn ($draft) => $draft['expires'] > time());
        $request->session()->put('public_drafts', array_slice($drafts, -19, null, true));
        $draft = ['token' => (string) Str::uuid(), 'form' => $form->id, 'revision' => $form->revision,
            'expires' => time() + min(config('session.lifetime') * 60, 86400), 'values' => [], 'files' => [], 'confirmed' => false];
        $this->persist($request, $draft);
        return $this->input($form, $draft);
    }

    public function confirm(Request $request, string $slug = '')
    {
        $form = $this->published($request, $slug);
        $draft = $this->draft($request, $form);
        if (isset($draft['completed'])) {
            return $this->redirectThanks($form);
        }

        $definition = $form->definition;
        $sourceVersion = $definition['source_version'] ?? null;
        $controls = $this->fields->controls($definition['fields'], $sourceVersion);
        $disk = Storage::disk('local');
        $previousPaths = array_column($draft['files'], 'path');
        foreach (array_keys($draft['files']) as $key) {
            if (in_array($key, (array) $request->input('remove_files', []), true)) {
                unset($draft['files'][$key]);
            }
        }

        [$values, $validator] = $this->fields->validate($request, $controls, $draft['files']);
        $valid = $validator->passes();
        $sizes = array_column($draft['files'], 'size', 'path');
        foreach ($controls as $key => $control) {
            if ($control['type'] === 'file' && $values[$key] && !$validator->errors()->has($key)) {
                unset($sizes[$draft['files'][$key]['path'] ?? '']);
                $sizes[$key] = $values[$key]->getSize();
            }
        }
        abort_if(count($sizes) > PublicFields::MAX_FILES || array_sum($sizes) > PublicFields::TOTAL_FILE_BYTES, 413, __('添付は合計20 MB、10件以内にしてください。'));
        $draft['confirmed'] = false;
        foreach ($controls as $key => $control) {
            if ($control['type'] === 'file') {
                $file = $values[$key];
                if ($file && !$validator->errors()->has($key)) {
                    $path = $file->storeAs('form-drafts/'.$draft['token'], (string) Str::uuid(), 'local');
                    throw_if(!$path, RuntimeException::class, __('添付ファイルを保存できません。'));
                    $draft['files'][$key] = [
                        'path' => $path, 'name' => mb_substr(preg_replace('/[[:cntrl:]]/u', '', $file->getClientOriginalName()), 0, 255),
                        'mime' => $file->getMimeType() ?: 'application/octet-stream', 'size' => $file->getSize(),
                    ];
                }
                unset($values[$key]);
            } elseif (is_array($values[$key])) {
                // Invalid nested values must never reach an HTML attribute or a session as an object.
                $values[$key] = in_array($control['type'], ['checkbox', 'multi_select'], true)
                    ? array_values(array_filter($values[$key], 'is_string')) : '';
            }
        }
        $draft['values'] = $values;
        $draft['confirmed'] = $valid;
        $this->persist($request, $draft);
        foreach (array_diff($previousPaths, array_column($draft['files'], 'path')) as $oldPath) {
            $disk->delete($oldPath);
        }
        if (!$valid) {
            return $this->input($form, $draft, $validator->errors());
        }
        $answers = $this->fields->answers($definition['fields'], $values, $draft['files'], $sourceVersion);
        return response()->view('public.confirm', compact('form', 'draft', 'answers'))->header('Cache-Control', 'no-store');
    }

    public function back(Request $request, string $slug = '')
    {
        $form = $this->published($request, $slug);
        $draft = $this->draft($request, $form);
        if (isset($draft['completed'])) {
            return $this->redirectThanks($form);
        }
        $draft['confirmed'] = false;
        $this->persist($request, $draft);
        return $this->input($form, $draft);
    }

    public function submit(Request $request, string $slug = '')
    {
        $form = $this->published($request, $slug);
        $draft = $this->draft($request, $form);
        if (isset($draft['completed'])) {
            return $this->redirectThanks($form);
        }
        abort_unless($draft['confirmed'], 409, __('入力内容を確認してから送信してください。'));
        app(\App\Services\SpamProtection::class)->verify($request, $form);
        $definition = $form->definition;
        $sourceVersion = $definition['source_version'] ?? null;
        $answers = $this->fields->answers($definition['fields'], $draft['values'], $draft['files'], $sourceVersion);
        $tags = $this->message->tags($definition['fields'], $answers, $request, $sourceVersion, $draft['values']);
        $thanks = strtr($definition['settings']['thanks_message'] ?? '', $tags);
        $disk = Storage::disk('local');
        $copied = [];
        try {
            DB::transaction(function () use ($form, $draft, $definition, $answers, $tags, $disk, &$copied) {
                $current = Form::lockForUpdate()->findOrFail($form->id);
                abort_unless($current->revision === $draft['revision'] && $current->hasPublicEntry($this->theme($current->definition)), 409);
                if (Submission::where('token', $draft['token'])->exists()) {
                    return;
                }
                $submission = Submission::create(['form_id' => $form->id, 'token' => $draft['token'],
                    'answers' => $answers, 'snapshot' => ['definition' => $definition, 'revision' => $form->revision,
                        'compound_labels' => PublicLocale::compoundLabels($definition['fields'], $definition['source_version'] ?? null),
                        'retain' => !empty($definition['settings']['save_data'])]]);
                $ids = [];
                foreach ($draft['files'] as $key => $file) {
                    $path = 'attachments/'.$draft['token'].'/'.Str::uuid();
                    throw_unless($disk->copy($file['path'], $path), RuntimeException::class, __('添付ファイルを保存できません。'));
                    $copied[] = $path;
                    $attachment = Attachment::create(['submission_id' => $submission->id, 'field_key' => $key, 'path' => $path] + $file);
                    $ids[] = $attachment->id;
                }
                $this->message->queue($submission, $definition, $answers, $tags, $ids);
            });
        } catch (Throwable $error) {
            foreach ($copied as $path) {
                $disk->delete($path);
            }
            throw $error;
        }
        $disk->deleteDirectory('form-drafts/'.$draft['token']);
        unset($draft['values'], $draft['files']);
        $draft['completed'] = true;
        $this->persist($request, $draft);
        $request->session()->put('public_thanks.'.$form->id, ['message' => $thanks, 'expires' => $draft['expires']]);
        return $this->redirectThanks($form);
    }

    private function redirectThanks(Form $form)
    {
        return new RedirectResponse($form->publicPath('thanks'), 303);
    }

    public function thanks(Request $request, string $slug = '')
    {
        $form = $this->published($request, $slug);
        $receipt = $request->session()->get('public_thanks.'.$form->id);
        abort_unless($receipt && $receipt['expires'] > time(), 404);
        return response()->view('public.thanks', ['form' => $form, 'message' => $receipt['message']])->header('Cache-Control', 'no-store');
    }
}
