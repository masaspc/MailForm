<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Delivery;
use App\Models\Form;
use App\Models\FormRevision;
use App\Models\Submission;
use App\Services\EasyMail\ImportGuard;
use App\Services\ReceiptPurge;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
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
        $this->assertSame('still_published', $this->verify(2, ['--form' => 6])['forms'][0]['form_state']);
        $report = $this->runImport(['--dry-run' => true]);
        $this->assertEquals([6], $report['removed_forms']);
        $this->assertTrue(Form::where('legacy_id', 6)->sole()->published);
        $this->runImport();
        $this->assertFalse(Form::where('legacy_id', 6)->sole()->published);
        $this->assertSame('withdrawn', $this->verify(0, ['--form' => 6])['forms'][0]['form_state']);
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

    #[TestWith(['2.00.124'])]
    #[TestWith(['2.00.125'])]
    #[TestWith(['2.00.133'])]
    public function test_database_only_and_mismatched_backups_require_identification(string $version): void
    {
        $this->assertSame(1, Artisan::call('easymail:import', ['--dry-run' => true]));
        $this->assertStringContainsString('--source-version', Artisan::output());
        $this->assertSame(1, Artisan::call('easymail:import', ['--source-version' => '2.00.132']));
        $root = $this->dir.'/em_laravel';
        mkdir($root.'/config', 0700, true);
        mkdir($root.'/resources/views/theme', 0700, true);
        file_put_contents($root.'/config/app.php', "<?php return ['version' => env( 'APP_VERSION', '$version' )];");
        $this->legacy->statement('CREATE TABLE migrations (migration TEXT)');
        $this->legacy->table('migrations')->insert(['migration' => '2020_02_20_174440_create_forms_table']);
        $this->legacy->statement('CREATE TABLE cache (key TEXT, value TEXT, expiration INTEGER)');
        $this->assertSame(1, Artisan::call('easymail:import', ['--source-root' => $this->dir, '--source-version' => '2.01.004']));
        $this->assertSame(1, Artisan::call('easymail:import', ['--source-version' => '2.01.004', '--dry-run' => true]));
        $this->assertSame(0, Artisan::call('easymail:import', ['--source-root' => $this->dir, '--dry-run' => true, '--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($version, $report['version']);
        $this->assertSame([], $report['extra_tables']);
        $this->assertFalse($report['forms'][0]['published']);
        $this->assertStringContainsString('app/Library/Common.php', implode(' ', $report['forms'][0]['issues']));
        $this->assertSame(2, Artisan::call('easymail:import', ['--source-root' => $this->dir, '--dry-run' => true, '--strict' => true]));
        $this->assertSame($version, $this->runImport(['--dry-run' => true, '--source-version' => $version])['version']);
        $this->legacy->table('migrations')->insert(['migration' => '2022_12_14_071441_create_forms_table']);
        $this->assertSame(1, Artisan::call('easymail:import', ['--source-root' => $this->dir, '--dry-run' => true]));
        $this->assertDatabaseCount('forms', 0);
    }

    public function test_final_snapshot_accounts_for_arrivals_edits_removals_tombstones_and_native_receipts(): void
    {
        $rows = $this->legacy->table('histories');
        $original = (array) $rows->first();
        $rows->update(['mail_title' => '001']);
        $rows->insert([[...$original, 'id' => 81], [...$original, 'id' => 82]]);
        $before = $this->verify(2)['forms'][0];
        $this->assertSame('missing', $before['form_state']);
        $this->assertSame(3, $before['source']['missing']);
        $this->assertDatabaseCount('forms', 0);

        $this->runImport();
        $this->assertTrue($this->verify()['ok']);
        $form = Form::sole();
        $token = Submission::where('legacy_id', 80)->sole()->token;
        $form->submissions()->create(['token' => 'staging-receipt', 'answers' => ['test' => 'local'], 'snapshot' => []]);
        $localForm = Form::create(['slug' => 'new-local', 'name' => '新規', 'published' => false, 'definition' => []]);
        $localForm->submissions()->create(['token' => 'new-form-receipt', 'answers' => [], 'snapshot' => []]);
        $tombstone = Submission::where('legacy_id', 82)->sole();
        $tombstone->update(['retain' => false, 'answers' => [], 'snapshot' => ['retain' => false]]);
        $this->assertSame(1, $this->verify(2)['forms'][0]['source']['pending_purge']);
        app(ReceiptPurge::class)->purge($tombstone->id);

        // A late arrival and an edit without an updated_at change both belong to the final snapshot.
        $this->legacy->table('histories')->where('id', 80)->update(['mail_title' => '1']);
        $this->legacy->table('histories')->where('id', 81)->delete();
        $this->legacy->table('histories')->insert([...$original, 'id' => 83]);
        $this->legacy->statement('PRAGMA query_only = ON');
        $checks = $this->verify(2)['forms'];
        $this->assertSame(['histories' => 3, 'matched' => 0, 'changed' => 1, 'missing' => 1, 'pending_purge' => 0, 'purged' => 1], $checks[0]['source']);
        $this->assertSame(83, $checks[0]['last_source_id']);
        $this->assertSame([81], $checks[0]['examples']['absent_from_source']);
        $this->assertSame(1, $checks[0]['new_only']['retained']);
        $this->assertSame('new_only', $checks[1]['form_state']);
        $this->assertSame(1, $checks[1]['new_only']['retained']);
        $this->assertSame('001', Submission::where('legacy_id', 80)->sole()->snapshot['legacy_history']['mail_title']);
        $this->assertDatabaseMissing('submissions', ['legacy_id' => 83]);

        $this->runImport();
        $checks = $this->verify(2)['forms'][0];
        $this->assertSame(2, $checks['source']['matched']);
        $this->assertSame(['absent_from_source' => 1], $checks['differences']);
        $this->assertSame(['absent_from_source' => [81]], $checks['examples']);
        $retained = Submission::where('legacy_id', 81)->sole();
        $this->assertSame($original['content'], $retained->snapshot['legacy_history']['content']);
        $retained->update(['retain' => false, 'answers' => [], 'snapshot' => ['retain' => false]]);
        app(ReceiptPurge::class)->purge($retained->id);
        $verified = $this->verify();
        $this->assertTrue($verified['ok']);
        $this->assertSame(1, $verified['forms'][0]['absent_from_source']['purged']);
        $this->assertCount(1, $this->verify(0, ['--form' => 5])['forms']);
        $this->runImport();
        $this->assertSame($verified, $this->verify());
        $this->assertSame($token, Submission::where('legacy_id', 80)->sole()->token);
        $this->assertSame('1', Submission::where('legacy_id', 80)->sole()->snapshot['legacy_history']['mail_title']);
        $this->assertSame(['test' => 'local'], Submission::where('token', 'staging-receipt')->sole()->answers);
        $this->assertSame([], $tombstone->fresh()->answers);
        $this->assertDatabaseCount('submissions', 6);
        $this->assertDatabaseCount('deliveries', 0);
        Mail::assertNothingSent();
    }

    public function test_verification_hashes_both_file_copies_and_retry_retires_only_obsolete_files(): void
    {
        $rows = $this->legacy->table('histories');
        $rows->update(['attach_files' => 'a.txt,A.txt,kept.txt,obsolete.txt']);
        foreach (['a.txt' => 'old', 'A.txt' => 'old', 'kept.txt' => 'keep', 'obsolete.txt' => 'drop'] as $name => $bytes) {
            file_put_contents($this->dir.'/'.$name, $bytes);
        }
        // Separate case-variant names can share bytes on case-insensitive filesystems and DB collations.
        $this->runImport();
        $this->assertSame(4, Attachment::count());
        $this->assertSame(4, $this->verify()['forms'][0]['files']['verified']);
        $disk = Storage::disk('local');
        $old = 'easymail/80/'.hash('sha256', 'old');
        $obsolete = 'easymail/80/'.hash('sha256', 'drop');
        $kept = 'easymail/80/'.hash('sha256', 'keep');
        $rows->update(['attach_files' => 'a.txt,kept.txt']);
        file_put_contents($this->dir.'/a.txt', 'new');
        unlink($this->dir.'/kept.txt');
        $pre = $this->verify(2)['forms'][0];
        $this->assertSame(1, $pre['files']['changed']);
        $this->assertSame(1, $pre['files']['source_unavailable']);
        $this->assertSame(2, $pre['files']['extra_links']);
        $disk->assertExists([$old, $obsolete, $kept]);

        $this->runImport();
        $this->assertSame(2, Attachment::count());
        $disk->assertMissing([$old, $obsolete]);
        $disk->assertExists($kept);
        $partial = $this->verify(2)['forms'][0]['files'];
        $this->assertSame(1, $partial['source_unavailable']);
        $this->assertSame(0, $partial['extra_links']);
        $this->assertSame(1, $partial['verified']);

        file_put_contents($this->dir.'/kept.txt', 'keep');
        $disk->put($kept, 'xxxx');
        $abandoned = 'easymail/80/'.hash('sha256', 'abandoned');
        $disk->put($abandoned, 'abandoned');
        $bad = $this->verify(2)['forms'][0]['files'];
        $this->assertSame(1, $bad['changed']);
        $this->assertSame(1, $bad['unlinked_copies']);
        $disk->assertExists($abandoned);
        $this->assertSame('xxxx', $disk->get($kept));
        $this->runImport();
        $this->assertSame(2, $this->verify()['forms'][0]['files']['verified']);
        $disk->assertMissing($abandoned);
        $disk->delete($kept);
        $this->assertSame(1, $this->verify(2)['forms'][0]['files']['target_missing']);
        $this->runImport();
        $this->assertSame('keep', $disk->get($kept));
        $this->assertTrue($this->verify()['ok']);
    }

    public function test_verify_compares_form_values_and_saved_answers_even_when_all_ids_and_timestamps_match(): void
    {
        $this->legacy->table('forms')->update(['mail_title' => '001']);
        $this->runImport();
        $this->legacy->table('forms')->update(['mail_title' => '1']);
        $receipt = Submission::sole();
        $receipt->timestamps = false;
        $receipt->update(['answers' => ['email' => 'changed']]);
        $pre = $this->verify(2)['forms'][0];
        $this->assertSame('changed', $pre['form_state']);
        $this->assertSame(1, $pre['source']['changed']);
        $this->runImport();
        $this->assertSame('visitor@example.org', $receipt->fresh()->answers['email']);
        $this->assertSame(2, Form::sole()->revision);
        $this->assertTrue($this->verify()['ok']);

        $this->legacy->table('form_blocks')->where('id', 8)->update(['name' => 'renamed_email']);
        $this->runImport();
        $this->assertSame('visitor@example.org', $receipt->fresh()->answers['email']);
        $this->assertTrue($this->verify()['ok']);
    }

    public function test_an_unassignable_history_fails_full_handoff_before_any_import(): void
    {
        $this->legacy->table('histories')->update(['form_id' => null]);
        foreach ([[], ['--verify-only' => true]] as $mode) {
            $this->assertSame(1, Artisan::call('easymail:import', ['--source-version' => '2.01.004', ...$mode]));
            $this->assertStringContainsString('旧履歴 #80 のフォームID', Artisan::output());
        }
        $this->assertDatabaseCount('forms', 0);
    }

    public function test_a_moved_receipt_preserves_its_identity_and_an_erased_receipt_moves_only_its_mapping(): void
    {
        $source = (array) $this->legacy->table('histories')->first();
        $this->legacy->table('histories')->insert([...$source, 'id' => 81]);
        $this->runImport();
        $erased = Submission::where('legacy_id', 80)->sole();
        $erased->update(['retain' => false, 'answers' => [], 'snapshot' => ['retain' => false]]);
        app(ReceiptPurge::class)->purge($erased->id);
        $active = Submission::where('legacy_id', 81)->sole();
        $this->legacy->table('histories')->update(['form_id' => 42]);
        $before = $this->verify(2)['forms'];
        $this->assertSame([80], $before[1]['examples']['wrong_form']);
        $this->assertSame([81], $before[1]['examples']['changed']);
        $this->runImport();
        $target = Form::where('legacy_id', 42)->sole();
        $this->assertSame($target->id, $erased->fresh()->form_id);
        $this->assertSame($target->id, $active->fresh()->form_id);
        $this->assertSame($active->token, $active->fresh()->token);
        $this->assertSame([], $erased->fresh()->answers);
        $this->assertSame(['retain' => false], $erased->fresh()->snapshot);
        $this->assertTrue($this->verify()['ok']);
    }

    public function test_same_sqlite_file_under_an_alias_is_rejected_and_overlapping_commands_fail_until_release(): void
    {
        $path = $this->dir.'/test.sqlite';
        touch($path);
        symlink($path, $this->dir.'/alias.sqlite');
        $default = DB::getDefaultConnection();
        $targetConfig = ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'username' => 'writer'];
        config(['database.connections.handoff_target' => $targetConfig]);
        DB::setDefaultConnection('handoff_target');
        try {
            $target = DB::connection();
            $guard = app(ImportGuard::class);
            $guard->run($this->legacy, $target, function () {
                $this->assertSame(1, Artisan::call('easymail:import', ['--verify-only' => true, '--source-version' => '2.01.004']));
                $this->assertStringContainsString('実行中', Artisan::output());
            });
            $this->assertTrue($guard->run($this->legacy, $target, fn () => true));
            DB::purge('legacy');
            config(['database.connections.legacy' => [...$targetConfig, 'database' => $this->dir.'/alias.sqlite', 'username' => 'reader']]);
            foreach ([[], ['--dry-run' => true], ['--verify-only' => true]] as $mode) {
                $this->assertSame(1, Artisan::call('easymail:import', ['--source-version' => '2.01.004', ...$mode]));
                $this->assertStringContainsString('同一のDB', Artisan::output());
            }
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('handoff_target');
        }
    }

    public function test_reconnected_target_cannot_enter_a_write_transaction_or_report_a_successful_check(): void
    {
        $default = DB::getDefaultConnection();
        config(['database.connections.handoff_reconnect' => DB::connection()->getConfig()]);
        DB::setDefaultConnection('handoff_reconnect');
        $target = DB::connection();
        $guard = app(ImportGuard::class);
        $entered = false;
        try {
            $guard->run($this->legacy, $target, function () use ($target, $guard, &$entered) {
                $original = $target->getPdo();
                DB::reconnect('handoff_reconnect');
                $this->assertNotSame($original, $target->getPdo());
                try {
                    $guard->transaction(function () use (&$entered) {
                        $entered = true;
                    });
                    $this->fail('A replaced DB session entered the write transaction.');
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('排他接続が切れました', $e->getMessage());
                }
            });
            $this->fail('A run without its original DB session reported success.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('排他接続が切れました', $e->getMessage());
            $this->assertFalse($entered);
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('handoff_reconnect');
        }
    }

    private function verify(int $expected = 0, array $options = []): array
    {
        $code = Artisan::call('easymail:import', ['--verify-only' => true, '--json' => true, '--source-version' => '2.01.004', '--attachments' => $this->dir, ...$options]);
        $output = Artisan::output();
        $this->assertSame($expected, $code, $output);
        return json_decode($output, true, flags: JSON_THROW_ON_ERROR)['reconciliation'];
    }

    private function runImport(array $options = []): array
    {
        $code = Artisan::call('easymail:import', ['--json' => true, '--source-version' => '2.01.004', '--attachments' => $this->dir, ...$options]);
        $output = Artisan::output();
        $this->assertSame(0, $code, $output);
        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }
}
