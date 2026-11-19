<?php

namespace App\Services\EasyMail;

use App\Models\FieldTemplate;
use App\Models\Form;
use Illuminate\Support\Collection;
use App\Models\Submission;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class Importer
{
    private Connection $source;
    private InstallationAudit $installation;
    private ?string $attachmentDirectory;
    private bool $dryRun;
    private string $version;
    private array $sharedIssues;
    private bool $hasSourceFiles;

    public function __construct(private FormAudit $audit, private HistoryReader $reader) {}

    public function run(SourceRelease $release, ?string $attachments, ?int $formId, bool $dryRun): array
    {
        $root = $release->root;
        $this->source = DB::connection('legacy');
        $this->dryRun = $dryRun;
        $this->version = $release->version;
        $this->attachmentDirectory = $attachments;
        $this->installation = new InstallationAudit($root, $release->version);
        $this->hasSourceFiles = $root !== null;
        $schema = $this->source->getSchemaBuilder();
        $required = [
            'forms' => ['id','url','name','theme_name','view_flag','save_data','admin_email','conf_mail_flag','include_submissions','include_submissions_admin_email','denied_ip','google_re_captcha_secret_key'],
            'form_blocks' => ['id','name','form_type','title','html_id','restriction'],
            'form_items' => ['id','form_id','form_block_id','view_no','required','re_enter_html_id'],
            'choices' => ['id','block_id','view_no','choice_type','label_text','delete_flag'],
            'histories' => ['id','form_id','form_name','content','attach_files','created_at','updated_at'],
            'plugins' => ['id','uid','name','version','active_flag'],
        ];
        foreach ($required as $table => $columns) {
            if (!$schema->hasColumns($table, $columns)) {
                throw new RuntimeException("旧DBの {$table} の構造が {$release->version} と一致しません。");
            }
        }
        if ($schema->hasColumns('migrations', ['migration'])) {
            $other = $release->version === '2.00.133' ? '2022_12_14_071441_create_forms_table' : '2020_02_20_174440_create_forms_table';
            if ($this->source->table('migrations')->where('migration', $other)->exists()) {
                throw new RuntimeException('旧DBのマイグレーション履歴と指定した配布版が一致しません。');
            }
        }
        $prefix = $this->source->getTablePrefix();
        $tables = array_map(fn ($t) => Str::after($t['name'], $prefix), $schema->getTables($schema->getCurrentSchemaName()));
        $stockTables = [...array_keys($required), 'html_blocks','migrations','users','sessions','failed_jobs','password_resets','personal_access_tokens','teams','team_user','team_invitations'];
        if ($release->version === '2.00.133') {
            $stockTables[] = 'cache';
        }
        $plugins = $this->source->table('plugins')->orderBy('id')->get(['uid','name','version','active_flag'])->map(fn ($p) => (array) $p)->all();
        $this->sharedIssues = [];
        foreach ($plugins as $plugin) {
            if ($plugin['active_flag']) {
                $this->sharedIssues[] = '有効なプラグインの適用範囲・移植を確認してください: '.($plugin['name'] ?: $plugin['uid']);
            }
        }
        $report = ['version' => $release->version, 'dry_run' => $dryRun, 'plugins' => $plugins, 'extra_tables' => array_values(array_diff($tables, $stockTables)), 'warnings' => [], 'forms' => []];
        if ($root === null) {
            $report['warnings'][] = '--source-root 未指定のため標準テーマ・本体ファイルの変更は未照合です。';
        }
        if ($report['extra_tables']) {
            $report['warnings'][] = '標準構成に含まれないテーブルがあります。プラグインの残枠・連番などは個別に照合してください。';
        }

        $forms = $this->source->table('forms')->when($formId !== null, fn ($q) => $q->where('id', $formId))->lazyById(100);
        foreach ($forms as $row) {
            $report['forms'][] = $this->form((array) $row);
        }
        $orphans = $this->source->table('histories')->whereNotIn('form_id', $this->source->table('forms')->select('id'))
            ->when($formId !== null, fn ($q) => $q->where('form_id', $formId))
            ->select('form_id')->distinct()->orderBy('form_id')->pluck('form_id');
        foreach ($orphans as $id) {
            $report['forms'][] = $this->form(['id' => $id, 'name' => "旧フォーム #{$id}", 'url' => null, 'view_flag' => 0], true);
        }
        if ($formId !== null && !$report['forms']) {
            throw new RuntimeException("旧フォーム #{$formId} が見つかりません。");
        }
        $report['removed_forms'] = $formId === null ? $this->withdrawRemovedForms(array_column($report['forms'], 'legacy_id')) : [];
        if ($report['removed_forms']) {
            $report['warnings'][] = '旧DBから削除されたフォームを公開停止にします（旧ID）: '.implode(', ', $report['removed_forms']);
        }
        $report['library'] = $this->importLibrary($formId, $report['warnings']);
        $report['warnings'] = array_values(array_unique($report['warnings']));
        return $report;
    }

    private function withdrawRemovedForms(array $present): array
    {
        $removed = Form::whereNotNull('legacy_id')->whereIntegerNotInRaw('legacy_id', $present)->orderBy('legacy_id')->pluck('legacy_id')->all();
        if (!$this->dryRun) {
            foreach (array_chunk($removed, 200) as $ids) {
                Form::whereIn('legacy_id', $ids)->where('published', true)->update(['published' => false]);
            }
        }
        return $removed;
    }

    private function form(array $row, bool $orphan = false): array
    {
        $id = (int) $row['id'];
        $slug = (string) $row['url'];
        $issues = $this->sharedIssues;
        $fileIssues = $this->installation->coreIssues();
        if (!$this->hasSourceFiles) {
            $existing = Form::where('legacy_id', $id)->first();
            $fileIssues = $existing?->definition['source_file_issues'] ?? [];
        }
        $issues = array_merge($issues, $fileIssues);
        $warnings = [];
        $validationIssues = [];
        $fields = [];
        if ($orphan) {
            $issues[] = '旧フォーム定義が削除されています。履歴保管用フォームを作成します。';
        } else {
            $fields = $this->fields($id, $issues);
            $themeIssues = $this->installation->forForm((string) $row['theme_name'], $slug);
            if ($this->hasSourceFiles) {
                $fileIssues = array_merge($fileIssues, $themeIssues);
            }
            array_push($issues, ...$themeIssues);
            $validationIssues = $this->audit->inspect($row, $fields, $warnings);
        }

        $reserved = ['admin','login','logout','up','storage','assets','send','thanks','confirm','back'];
        $urlConflict = $this->source->table('forms')->where('id', '!=', $id)
            ->whereRaw("LOWER(COALESCE(url, '')) = LOWER(?)", [$slug])->exists() || $this->occupied($slug, $id);
        if ($orphan || $urlConflict || mb_strlen($slug) > 190 || !preg_match('/^[\pL\pN_-]*$/uD', $slug) || in_array(strtolower($slug), $reserved, true)) {
            $issues[] = '元のURLを使用できないため履歴用の仮URLを割り当てます。';
            $slug = 'legacy-draft-'.$id;
            while ($this->occupied($slug, $id) || $this->source->table('forms')->where('url', $slug)->exists()) {
                $slug .= '-x';
            }
        }
        $settings = array_intersect_key($row, array_flip(FormAudit::SETTINGS));
        if (!empty($settings['google_re_captcha_secret_key']) || !empty($settings['google_re_captcha_site_key'])) {
            $settings['recaptcha_enabled'] = 1;
        }
        unset($settings['google_re_captcha_secret_key'], $settings['google_re_captcha_site_key']);
        $installationIssues = array_values(array_unique($issues));
        $issues = array_values(array_unique([...$installationIssues, ...$validationIssues]));
        $definition = [
            'settings' => $settings, 'fields' => $fields, 'issues' => $issues, 'source_version' => $this->version,
            'source_file_issues' => $fileIssues, 'installation_issues' => $installationIssues, 'validation_issues' => $validationIssues,
        ];
        $name = (string) ($row['name'] ?: "旧フォーム #{$id}");
        $attributes = ['slug' => $slug, 'name' => mb_substr($name, 0, 255), 'published' => (int) $row['view_flag'] === 1 && !$issues, 'definition' => $definition];

        $form = $this->dryRun ? null : DB::transaction(function () use ($id, $attributes, $definition) {
            $form = Form::where('legacy_id', $id)->lockForUpdate()->first() ?? new Form(['legacy_id' => $id]);
            $changed = !$form->exists || $form->definition != $definition;
            if ($changed) {
                $form->revision = ($form->revision ?? 0) + 1;
            }
            if ($form->archived_at !== null) {
                $attributes['published'] = false;
            }
            $form->fill($attributes);
            if ($form->isDirty()) {
                $form->save();
            }
            if ($changed) {
                $form->revisions()->create(['revision' => $form->revision, 'definition' => $definition]);
            }
            return $form;
        });

        if (($form ?? Form::where('legacy_id', $id)->first())?->archived_at !== null) {
            $attributes['published'] = false;
            $warnings[] = '新環境でアーカイブされたフォームの公開停止を維持します。';
        }
        $histories = $attachments = 0;
        foreach ($this->source->table('histories')->where('form_id', $id)->lazyById(200) as $history) {
            $histories++;
            $attachments += $this->history((array) $history, $fields, $form, $warnings);
        }
        return ['legacy_id' => $id, 'form_id' => $form?->id, 'source_url' => (string) $row['url'], 'url' => '/'.$slug, 'published' => $attributes['published'],
            'field_count' => count($fields), 'history_count' => $histories, 'resolved_files' => $attachments, 'issues' => $definition['issues'], 'warnings' => $warnings];
    }

    private function occupied(string $slug, int $id): bool
    {
        return Form::where('slug', $slug)->where(fn ($q) => $q->whereNull('legacy_id')->orWhere('legacy_id', '!=', $id))->exists();
    }

    private const BLOCK_KEYS = [
        'id', 'created_at', 'updated_at', 'view_no', 'name', 'title', 'form_type', 'html_class', 'html_id', 'restriction',
        'min_length', 'max_length', 'initial_value', 'placeholder', 'columns', 'file_type', 'file_capacity_limit', 'delete_flag',
        'length_error_msg', 'edit_flag', 'required_error_msg', 'restriction_error_msg', 're_enter_error_msg', 'forbidden_characters',
        'description_above', 'description_below', 'hankaku_zenkaku_automatic_conversion',
    ];

    private const ITEM_KEYS = ['id', 'created_at', 'updated_at', 'view_no', 'form_id', 'form_block_id', 'required', 'real_time_validation', 're_enter_html_id'];
    private const CHOICE_KEYS = ['id', 'created_at', 'updated_at', 'view_no', 'block_id', 'choice_type', 'label_text', 'image', 'delete_flag'];

    private function fields(int $id, array &$issues): array
    {
        $items = $this->source->table('form_items')->where('form_id', $id)->orderBy('view_no')->orderBy('id')->get();
        $blocks = $this->source->table('form_blocks')->whereIn('id', $items->pluck('form_block_id'))->get();
        $definitions = $this->blocks($blocks, $issues);
        $fields = [];
        foreach ($items as $item) {
            $block = $definitions[$item->form_block_id] ?? null;
            if (!$block) {
                $issues[] = "項目定義が見つかりません: form_items #{$item->id}";
                continue;
            }
            $itemData = $this->knownAttributes((array) $item, self::ITEM_KEYS, 'form_items', $issues);
            $fields[] = array_replace($block, $itemData, ['id' => $block['id'], 'form_item_id' => $item->id]);
        }
        return $fields;
    }

    private function blocks(Collection $blocks, array &$issues): array
    {
        $choices = $this->source->table('choices')->whereIn('block_id', $blocks->pluck('id'))
            ->orderBy('choice_type')->orderBy('view_no')->orderBy('id')->get()->groupBy('block_id');
        $prefChoices = [];
        if ($blocks->contains('form_type', 'zp_address')) {
            $prefBlocks = $this->source->table('form_blocks')->where('name', 'pref')->select('id');
            $prefChoices = $this->source->table('choices')->whereIn('block_id', $prefBlocks)
                ->orderBy('view_no')->orderBy('id')->pluck('label_text')->all();
        }
        $definitions = [];
        foreach ($blocks as $block) {
            $field = $this->knownAttributes((array) $block, self::BLOCK_KEYS, 'form_blocks', $issues);
            if ($block->form_type === 'password') {
                unset($field['initial_value']);
            }
            $field['choices'] = [];
            foreach ($choices[$block->id] ?? [] as $choice) {
                $field['choices'][] = $this->knownAttributes((array) $choice, self::CHOICE_KEYS, 'choices', $issues);
            }
            if ($block->form_type === 'zp_address') {
                $field['pref_choices'] = $prefChoices;
            }
            $definitions[$block->id] = $field;
        }
        return $definitions;
    }

    private function importLibrary(?int $formId, array &$warnings): array
    {
        $stock = json_decode(file_get_contents(resource_path('easymail/presets.json')), true, flags: JSON_THROW_ON_ERROR);
        $defaults = array_column($stock['fields'], null, 'id');
        $counts = ['custom' => 0, 'stock' => 0];
        $blocks = $this->source->table('form_blocks');
        if ($formId !== null) {
            $items = $this->source->table('form_items')->where('form_id', $formId)->select('form_block_id');
            $blocks->whereIn('id', $items);
        }
        $blocks->chunkById(100, function ($rows) use ($defaults, &$counts, &$warnings) {
            foreach ($this->blocks($rows, $warnings) as $id => $field) {
                if (!empty($field['delete_flag'])) {
                    continue;
                }
                $reference = $defaults[$id] ?? null;
                if ($reference && $reference['form_type'] === 'zp_address') {
                    $pref = array_values(array_filter($defaults, fn ($f) => $f['name'] === 'pref'));
                    $reference['pref_choices'] = array_column($pref[0]['choices'] ?? [], 'label_text');
                }
                $same = $reference && $this->comparable($reference) == $this->comparable($field);
                $counts[$same ? 'stock' : 'custom']++;
                if ($this->dryRun || ($same && !FieldTemplate::where('legacy_id', $id)->exists())) {
                    continue;
                }
                FieldTemplate::updateOrCreate(['legacy_id' => $id], ['definition' => $field]);
            }
        });
        return $counts;
    }

    /** Shipped definitions omit empty metadata and generated DB columns. */
    private function comparable(array $field): array
    {
        $choices = $field['choices'];
        unset($field['created_at'], $field['updated_at'], $field['edit_flag'], $field['delete_flag'], $field['choices']);
        $definition = array_filter($field, fn ($v) => $v !== null && $v !== '');
        $definition['choices'] = [];
        foreach ($choices as $choice) {
            if (empty($choice['delete_flag'])) {
                unset($choice['id'], $choice['block_id'], $choice['created_at'], $choice['updated_at'], $choice['delete_flag']);
                $definition['choices'][] = array_filter($choice, fn ($v) => $v !== null && $v !== '');
            }
        }
        return $definition;
    }

    private function knownAttributes(array $record, array $keys, string $table, array &$issues): array
    {
        $keep = array_flip($keys);
        foreach (array_diff_key($record, $keep) as $key => $value) {
            if ($value !== null && $value !== '') {
                $issues[] = "独自の項目設定: {$table}.{$key}";
            }
        }
        return array_intersect_key($record, $keep);
    }

    private function history(array $row, array $fields, ?Form $form, array &$warnings): int
    {
        $import = function () use ($row, $fields, $form, &$warnings) {
            $query = Submission::where('legacy_id', $row['id']);
            $submission = ($this->dryRun ? $query : $query->lockForUpdate())->first();
            if ($submission && (!$submission->retain || $submission->purged_at !== null)) {
                $warnings[] = "履歴 #{$row['id']}: 新環境で消去されたため復元を省きます。";
                return 0;
            }
            $keys = ['id','created_at','updated_at','form_id','form_name','admin_email','user_email','mail_title','content','theme_name','bcc_email','cc_email','conf_mail_flag','include_submissions','include_submissions_admin_email','url','template','attach_files'];
            $row = $this->knownAttributes($row, $keys, 'histories', $warnings);
            [$historicalFields, $answers] = $this->reader->answers($row, $fields, $warnings);
            $files = $this->reader->files($row, $this->attachmentDirectory, $warnings);
            if ($this->dryRun) {
                return count($files);
            }
            $submission ??= new Submission(['legacy_id' => $row['id'], 'token' => (string) Str::uuid()]);
            $fileFields = array_values(array_filter($historicalFields, fn ($f) => $f['form_type'] === 'file'));
            $snapshot = ['legacy_version' => $this->version, 'name' => $row['form_name'], 'legacy_history' => $row, 'definition' => ['fields' => $historicalFields, 'settings' => ['name' => $row['form_name']]]];
            if (!$submission->exists || ($submission->snapshot['legacy_history'] ?? null) != $row) {
                $submission->fill(['form_id' => $form->id, 'snapshot' => $snapshot, 'answers' => $answers, 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]);
            }
            $submission->timestamps = false;
            if ($submission->isDirty()) {
                $submission->save();
            }
            foreach ($files as $name => $path) {
                $meta = $this->reader->copy($path, (string) $name, (int) $row['id']);
                // The legacy row has no original filename or reliable field association.
                $key = count($fileFields) === 1 && count($files) === 1 ? $fileFields[0]['name'] : 'legacy_attachment';
                $submission->attachments()->updateOrCreate(['name' => $name], [...$meta, 'field_key' => $key]);
            }
            return count($files);
        };
        return $this->dryRun ? $import() : DB::transaction($import, attempts: 3);
    }
}
