<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Delivery;
use App\Models\Form;
use App\Models\FormRevision;
use App\Models\Submission;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EasyMailImportTest extends TestCase
{
    use RefreshDatabase;

    private Connection $legacy;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.legacy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $this->legacy = DB::connection('legacy');
        $this->legacy->getPdo()->exec(file_get_contents(base_path('tests/Fixtures/easymail-2.01.004.sql')));
        Storage::fake('local');
        Mail::fake();
        $this->dir = sys_get_temp_dir().'/easymail-test-'.bin2hex(random_bytes(12));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_dry_run_and_repeat_import_keep_historical_columns_and_private_files(): void
    {
        $a = str_repeat('a', 40).'.txt';
        $b = str_repeat('B', 40).'.txt';
        file_put_contents($this->dir.'/'.$a, 'one');
        file_put_contents($this->dir.'/'.$b, 'two');
        $this->legacy->table('histories')->update(['attach_files' => $a.$b]);
        $source = $this->legacy->table('histories')->first();
        $this->legacy->statement('PRAGMA query_only = ON');

        $report = $this->runImport(['--dry-run' => true]);
        $this->assertSame(2, $report['forms'][0]['resolved_files']);
        $this->assertSame([], $report['forms'][0]['issues']);
        $this->assertDatabaseCount('forms', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->runImport();
        $this->runImport();
        $form = Form::sole();
        $this->assertTrue($form->published);
        $this->assertSame('', $form->slug);
        $this->assertSame(5, (int) $form->legacy_id);
        $this->assertSame([9, 8, 13], array_column($form->definition['fields'], 'id'));
        $this->assertSame(['東京', '福岡'], array_column($form->definition['fields'][0]['choices'], 'label_text'));
        $this->assertSame(1, FormRevision::count());
        $this->assertDatabaseCount('field_templates', 3);
        $submission = Submission::sole();
        $this->assertSame(80, (int) $submission->legacy_id);
        $this->assertSame('2023-03-04 15:16:17', $submission->created_at->format('Y-m-d H:i:s'));
        $this->assertSame(['東京', '福岡'], $submission->answers['options']);
        $this->assertSame('0', $submission->answers['__legacy_field_3']);
        $this->assertSame(['メール','質問','廃止済み'], array_column($submission->snapshot['definition']['fields'], 'title'));
        $this->assertSame($source->content, $submission->snapshot['legacy_history']['content']);
        $this->assertSame(2, Attachment::count());
        $this->assertSame('two', Storage::disk('local')->get(Attachment::where('name', $b)->sole()->path));
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->assertEquals($source, $this->legacy->table('histories')->first());
        $this->assertSame(0, Delivery::count());
        Mail::assertNothingSent();
    }

    public function test_migrated_receipts_recover_missing_files_and_changed_settings_create_one_revision(): void
    {
        $this->legacy->table('histories')->update(['attach_files' => 'missing.txt']);
        $report = $this->runImport();
        $this->assertStringContainsString('missing.txt', implode(' ', $report['forms'][0]['warnings']));
        $this->assertSame(0, Attachment::count());
        file_put_contents($this->dir.'/missing.txt', 'recovered');
        $this->legacy->table('forms')->update(['view_flag' => 0]);
        $this->runImport();
        $this->runImport();
        $this->assertFalse(Form::sole()->published);
        $this->assertSame(2, Form::sole()->revision);
        $this->assertSame(1, Attachment::count());
        $this->assertSame(1, Submission::count());
    }

    public function test_active_plugins_unknown_tags_and_custom_settings_keep_form_unpublished(): void
    {
        $this->legacy->table('plugins')->insert(['uid' => 'ticket', 'name' => '受付枠', 'version' => '1', 'active_flag' => 1]);
        $this->legacy->statement('CREATE TABLE ticket_limits (id INTEGER)');
        $this->legacy->statement('ALTER TABLE forms ADD COLUMN plugin_secret TEXT');
        $this->legacy->table('forms')->update(['theme_name' => 'my_theme', 'mail_title' => '#MISSING#', 'google_re_captcha_secret_key' => 'secret-value', 'plugin_secret' => 'secret-value']);
        $report = $this->runImport();
        $this->assertContains('ticket_limits', $report['extra_tables']);
        $form = Form::sole();
        $this->assertFalse($form->published);
        $issues = implode(' ', $form->definition['issues']);
        foreach (['受付枠', 'my_theme', '#MISSING#', 'reCAPTCHA', 'plugin_secret'] as $word) {
            $this->assertStringContainsString($word, $issues);
        }
        $this->assertStringNotContainsString('secret-value', json_encode($report).json_encode($form->definition));
    }

    public function test_orphan_history_unsafe_files_and_duplicate_urls_are_diagnosed(): void
    {
        $this->legacy->table('forms')->insert([...((array) $this->legacy->table('forms')->first()), 'id' => 6]);
        $this->legacy->table('histories')->update(['form_id' => 42, 'content' => '旧独自本文', 'attach_files' => '../unsafe.txt,link']);
        symlink(dirname($this->dir), $this->dir.'/link');
        $report = $this->runImport();
        $this->assertCount(3, $report['forms']);
        $this->assertSame(0, Form::where('published', true)->count());
        $this->assertSame(['legacy-draft-5', 'legacy-draft-6', 'legacy-draft-42'], Form::orderBy('legacy_id')->pluck('slug')->all());
        $this->assertSame([], Submission::sole()->answers);
        $this->assertSame('旧独自本文', Submission::sole()->snapshot['legacy_history']['content']);
        $this->assertCount(3, $report['forms'][2]['warnings']);
        $this->assertSame(0, Attachment::count());
    }

    public function test_reusable_library_includes_unassigned_active_custom_blocks(): void
    {
        $this->legacy->statement('ALTER TABLE form_blocks ADD COLUMN delete_flag INTEGER DEFAULT 0');
        $this->legacy->table('form_blocks')->insert([
            ['id' => 87, 'form_type' => 'text', 'name' => 'later', 'title' => '次回用', 'delete_flag' => 0],
            ['id' => 88, 'form_type' => 'text', 'name' => 'removed', 'title' => '削除', 'delete_flag' => 1],
        ]);
        $report = $this->runImport(['--form' => '5']);
        $this->assertSame(3, $report['library']['custom']);
        $this->assertDatabaseMissing('field_templates', ['legacy_id' => 87]);
        $this->runImport();
        $this->assertDatabaseHas('field_templates', ['legacy_id' => 87]);
        $this->assertDatabaseMissing('field_templates', ['legacy_id' => 88]);
        $this->assertDatabaseCount('field_templates', 4);
    }

    public function test_backup_differences_persist_until_files_are_checked_again(): void
    {
        mkdir($this->dir.'/config');
        file_put_contents($this->dir.'/config/app.php', "<?php return ['version' => env('APP_VERSION', '2.01.004')];");
        $theme = $this->dir.'/resources/views/theme/gray';
        mkdir($theme, 0700, true);
        file_put_contents($theme.'/custom.blade.php', 'template');
        $report = $this->runImport(['--source-root' => $this->dir]);
        $this->assertStringContainsString('gray/custom.blade.php', implode(' ', $report['forms'][0]['issues']));
        $form = Form::sole();
        $this->assertFalse($form->published);
        $this->assertNotEmpty($form->definition['source_file_issues']);
        $this->assertSame([], $form->definition['validation_issues']);
        $this->assertNotEmpty($this->runImport()['forms'][0]['issues']);
        $this->assertFalse(Form::sole()->published);
        $this->assertSame(1, Form::sole()->revision);
    }

    public function test_recaptcha_uses_new_environment_keys_and_stored_keys_are_removed(): void
    {
        $this->legacy->table('forms')->update(['google_re_captcha_secret_key' => 'legacy-secret']);
        $this->runImport();
        $form = Form::sole();
        $this->assertFalse($form->published);
        $this->assertSame(1, $form->definition['settings']['recaptcha_enabled']);
        $this->assertStringNotContainsString('legacy-secret', json_encode($form->definition));
        config(['mailform.recaptcha_site_key' => 'new-site', 'mailform.recaptcha_secret' => 'new-secret']);
        $this->runImport();
        $form->refresh();
        $this->assertTrue($form->published);
        $this->assertSame(1, $form->definition['settings']['recaptcha_enabled']);
        $this->assertStringNotContainsString('new-secret', json_encode($form->definition));
    }

    public function test_full_import_unpublishes_removed_forms_but_dry_run_and_scoped_import_do_not(): void
    {
        $this->legacy->table('forms')->insert([...((array) $this->legacy->table('forms')->first()), 'id' => 6, 'url' => 'second']);
        foreach ($this->legacy->table('form_items')->get() as $row) {
            $this->legacy->table('form_items')->insert([...((array) $row), 'id' => 200 + $row->id, 'form_id' => '6']);
        }
        $this->runImport();
        $this->assertTrue(Form::where('legacy_id', 6)->sole()->published);
        $this->legacy->table('forms')->where('id', 6)->delete();
        $this->runImport(['--form' => 5]);
        $this->assertTrue(Form::where('legacy_id', 6)->sole()->published);
        $report = $this->runImport(['--dry-run' => true]);
        $this->assertEquals([6], $report['removed_forms']);
        $this->assertTrue(Form::where('legacy_id', 6)->sole()->published);
        $this->runImport();
        $this->assertFalse(Form::where('legacy_id', 6)->sole()->published);
    }

    public function test_archived_form_and_erased_history_remain_withdrawn_on_reimport(): void
    {
        $this->runImport();
        Form::sole()->forceFill(['archived_at' => now(), 'published' => false])->save();
        Submission::sole()->forceFill(['answers' => [], 'snapshot' => [], 'retain' => false, 'purged_at' => now()])->save();
        $this->legacy->table('histories')->update(['attach_files' => 'unavailable.txt']);
        $report = $this->runImport()['forms'][0];
        $this->assertFalse($report['published']);
        $this->assertSame(0, $report['resolved_files']);
        $this->assertStringNotContainsString('unavailable.txt', implode(' ', $report['warnings']));
        $this->assertFalse(Form::sole()->published);
        $this->assertNotNull(Form::sole()->archived_at);
        $this->assertSame([], Submission::sole()->answers);
        $this->assertSame([], Submission::sole()->snapshot);
        $this->assertSame(0, Attachment::count());
    }

    public function test_database_only_and_mismatched_backups_require_identification(): void
    {
        $this->assertSame(1, Artisan::call('easymail:import', ['--dry-run' => true]));
        $this->assertStringContainsString('--source-version', Artisan::output());
        $this->assertSame(1, Artisan::call('easymail:import', ['--source-version' => '2.00.132']));
        $root = $this->dir.'/em_laravel';
        mkdir($root.'/config', 0700, true);
        mkdir($root.'/resources/views/theme', 0700, true);
        file_put_contents($root.'/config/app.php', "<?php return ['version' => env('APP_VERSION', '2.00.133')];");
        $this->assertSame(1, Artisan::call('easymail:import', ['--source-root' => $this->dir, '--source-version' => '2.01.004']));
        $this->assertSame(0, Artisan::call('easymail:import', ['--source-root' => $this->dir, '--dry-run' => true, '--json' => true]));
        $this->assertSame('2.00.133', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['version']);
        $this->legacy->statement('CREATE TABLE migrations (migration TEXT)');
        $this->legacy->table('migrations')->insert(['migration' => '2022_12_14_071441_create_forms_table']);
        $this->assertSame(1, Artisan::call('easymail:import', ['--source-root' => $this->dir, '--dry-run' => true]));
        $this->assertDatabaseCount('forms', 0);
    }

    private function runImport(array $options = []): array
    {
        $code = Artisan::call('easymail:import', ['--json' => true, '--source-version' => '2.01.004', '--attachments' => $this->dir, ...$options]);
        $output = Artisan::output();
        $this->assertSame(0, $code, $output);
        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }
}
