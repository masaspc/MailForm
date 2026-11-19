<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\Admin\FormEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'editor'): User
    {
        return User::create(['name' => '運用担当', 'email' => $role.'@example.test', 'password' => 'correct-test-pass', 'role' => $role]);
    }

    private function data(string $name = '申込窓口'): array
    {
        return [
            'preset' => 'contact', 'editor_complete' => '1', 'name' => $name, 'slug' => 'apply', 'theme_name' => 'gray',
            'admin_email' => 'staff@example.test', 'save_data' => '1', 'mail_title' => '受付',
            'fields' => [
                ['name' => 'choice', 'title' => 'ご希望', 'form_type' => 'checkbox', 'choices_text' => "青\n黄"],
                ['name' => 'email', 'title' => 'メール', 'form_type' => 'email', 'required' => 1],
            ],
        ];
    }

    private function version(Form $form): string
    {
        return app(FormEditor::class)->version($form->refresh());
    }

    public function test_login_is_rate_limited_and_logout_requires_a_session(): void
    {
        $admin = $this->user('admin');
        $this->get('/admin/history')->assertRedirect(route('login'));
        $this->get('/admin/login')->assertOk()->assertSee('ログイン');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['email' => $admin->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        }
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'correct-test-pass'])->assertStatus(429);
        $this->travel(61)->seconds();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'correct-test-pass'])->assertRedirect(route('admin.history'));
        $this->assertAuthenticatedAs($admin);
        $this->post('/admin/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_published_edits_are_isolated_and_stale_operations_are_rejected(): void
    {
        $this->actingAs($this->user());
        foreach (['contact', 'event', 'questionnaire'] as $preset) {
            $this->get('/admin/forms/new?preset='.$preset)->assertOk()->assertSee('項目を追加')->assertSee('editor@example.test');
        }
        $this->post('/admin/forms', $this->data())->assertSessionHasNoErrors();
        $form = Form::sole();
        $this->assertFalse($form->published);
        $this->get(route('admin.edit', $form))->assertOk()->assertSee('申込窓口');
        $this->post(route('admin.publish', $form), ['version' => $this->version($form)])->assertSessionHasNoErrors();
        $this->assertTrue($form->refresh()->published);
        $this->assertSame(1, $form->revision);
        $version = $this->version($form);
        $data = $this->data('新しい名前');
        $data['fields'] = array_reverse($data['fields']);
        $this->put(route('admin.update', $form), $data + ['version' => $version])->assertSessionHasNoErrors();
        $this->assertSame('申込窓口', $form->refresh()->name);
        $this->assertSame('メール', $form->draft_definition['fields'][0]['title']);
        $this->post(route('admin.publish', $form), ['version' => $version])->assertConflict();
        $this->post(route('admin.publish', $form), ['version' => $this->version($form)])->assertSessionHasNoErrors();
        $this->assertSame('新しい名前', $form->refresh()->name);
        $this->assertSame(2, $form->revisions()->count());
        $this->get('/admin')->assertOk()->assertSee('新しい名前');
        $this->post(route('admin.copy', $form))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('forms', 2);
        $this->assertFalse(Form::latest('id')->first()->published);
        $this->post(route('admin.unpublish', $form), ['version' => $this->version($form)])->assertRedirect();
        $this->assertFalse($form->refresh()->published);
    }

    public function test_migration_blockers_survive_draft_edits_and_copy(): void
    {
        $this->actingAs($this->user());
        $this->post('/admin/forms', $this->data());
        $form = Form::sole();
        $def = $form->definition;
        $def['issues'] = ['独自の受付制限'];
        $form->update(['definition' => $def]);
        $this->put(route('admin.update', $form), $this->data() + ['version' => $this->version($form)])->assertSessionHasNoErrors();
        $this->post(route('admin.publish', $form), ['version' => $this->version($form)])->assertSessionHasErrors('definition');
        $this->post(route('admin.copy', $form));
        $copy = Form::latest('id')->first();
        $this->post(route('admin.publish', $copy), ['version' => $this->version($copy)])->assertSessionHasErrors('definition');
        $this->assertFalse($copy->refresh()->published);
    }

    public function test_editing_an_imported_setting_resolves_only_validation_findings_with_provenance(): void
    {
        $this->actingAs($this->user());
        $this->post('/admin/forms', $this->data());
        $form = Form::sole();
        $def = $form->definition;
        $def['settings']['mail_title'] = '#obsolete#';
        $def['installation_issues'] = ['設置ファイルの変更'];
        $def['validation_issues'] = ['未対応の置換タグ: mail_title #obsolete#'];
        $def['issues'] = [...$def['installation_issues'], ...$def['validation_issues']];
        $form->update(['definition' => $def]);
        $this->put(route('admin.update', $form), $this->data() + ['version' => $this->version($form)])->assertSessionHasNoErrors();
        $this->assertSame(['設置ファイルの変更'], $form->refresh()->definition['issues']);
        $this->assertSame([], $form->definition['validation_issues']);
    }

    public function test_viewer_can_read_retained_snapshots_but_cannot_access_ephemeral_or_cross_submission_files(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user());
        $this->post('/admin/forms', $this->data());
        $form = Form::sole();
        $create = fn ($retain) => Submission::create([
            'form_id' => $form->id, 'token' => (string) Str::uuid(), 'retain' => $retain,
            'answers' => ['choice' => ['青', '黄'], 'email' => $retain ? '=unsafe' : 'temporary@example.test'],
            'snapshot' => ['definition' => $form->definition],
        ]);
        $visible = $create(true);
        $private = $create(false);
        Storage::disk('local')->put('tests/demo', 'example bytes');
        $file = $visible->attachments()->create(['field_key' => 'upload', 'path' => 'tests/demo', 'name' => '受付.pdf', 'mime' => 'application/pdf', 'size' => 13]);
        $viewer = $this->user('viewer');
        $this->actingAs($viewer);
        $this->get(route('admin.history', ['form_id' => $form->id]))->assertOk()->assertSee('CSVダウンロード');
        $this->get(route('admin.submission', $visible))->assertOk()->assertSee('ご希望')->assertSee('受付.pdf');
        $this->get(route('admin.submission', $private))->assertNotFound();
        $this->get(route('admin.attachment', [$private, $file]))->assertNotFound();
        $download = $this->get(route('admin.attachment', [$visible, $file]))->assertDownload();
        $this->assertStringContainsString(rawurlencode('受付.pdf'), $download->headers->get('Content-Disposition'));
        $csv = $this->get(route('admin.csv', $form))->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF\"日時\",\"ご希望\",\"メール\"\r\n", $csv);
        $this->assertStringContainsString("\"青\u{00A0}黄\",\"'=unsafe\"", $csv);
        $this->assertStringNotContainsString('temporary', $csv);
        $this->get(route('admin.edit', $form))->assertForbidden();
        $this->post(route('admin.publish', $form))->assertForbidden();
        $this->post(route('admin.copy', $form))->assertForbidden();
        $this->get(route('admin.users'))->assertForbidden();
        $this->get(route('admin.deliveries'))->assertOk();
    }

    public function test_form_archive_requires_unpublishing_and_retains_history_for_restoration(): void
    {
        $editor = $this->user();
        $this->actingAs($editor)->post('/admin/forms', $this->data());
        $form = Form::sole();
        $this->post(route('admin.publish', $form), ['version' => $this->version($form)]);
        $this->post(route('admin.archive', $form))->assertForbidden();
        $this->actingAs($this->user('admin'));
        $this->post(route('admin.archive', $form), ['version' => $this->version($form), 'confirmed' => 1])->assertSessionHasErrors('form');
        $this->post(route('admin.unpublish', $form), ['version' => $this->version($form)]);
        $this->post(route('admin.archive', $form), ['version' => $this->version($form)])->assertSessionHasErrors('confirmed');
        $this->post(route('admin.archive', $form), ['version' => $this->version($form), 'confirmed' => 1])->assertSessionHasNoErrors();
        $this->get('/admin')->assertOk()->assertDontSee('申込窓口');
        $this->get('/admin?archived=1')->assertOk()->assertSee('申込窓口');
        $this->get(route('admin.edit', $form))->assertNotFound();
        $this->post(route('admin.publish', $form), ['version' => $this->version($form)])->assertNotFound();
        $this->post(route('admin.restore', $form), ['version' => $this->version($form)])->assertRedirect(route('admin.edit', $form));
        $this->assertFalse($form->refresh()->published);
        $this->assertNull($form->archived_at);
        $this->assertSame('apply', $form->slug);
    }

    public function test_history_erasure_waits_for_delivery_resolution_and_keeps_the_receipt_token(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user())->post('/admin/forms', $this->data());
        $form = Form::sole();
        $receipt = Submission::create(['form_id' => $form->id, 'token' => (string) Str::uuid(), 'answers' => ['email' => 'person@example.test'],
            'snapshot' => ['definition' => $form->definition], 'legacy_id' => 43]);
        $delivery = $receipt->deliveries()->create(['kind' => 'admin', 'status' => 'pending', 'payload' => []]);
        Storage::disk('local')->put('attachments/test', 'demo');
        $receipt->attachments()->create(['field_key' => 'file', 'path' => 'attachments/test', 'name' => '資料.pdf', 'size' => 4, 'mime' => 'application/pdf']);
        $this->delete(route('admin.history.destroy', $receipt), ['confirmed' => 1])->assertForbidden();
        $this->actingAs($this->user('admin'));
        $this->delete(route('admin.history.destroy', $receipt))->assertSessionHasErrors('confirmed');
        foreach (['pending', 'sending', 'unknown', 'failed'] as $state) {
            $delivery->update(['status' => $state]);
            $this->delete(route('admin.history.destroy', $receipt), ['confirmed' => 1])->assertSessionHasErrors('delivery');
            $this->assertTrue($receipt->refresh()->retain);
            Storage::disk('local')->assertExists('attachments/test');
        }
        $delivery->update(['status' => 'sent']);
        $this->delete(route('admin.history.destroy', $receipt), ['confirmed' => 1])->assertSessionHasNoErrors();
        $receipt->refresh();
        $this->assertFalse($receipt->retain);
        $this->assertNotNull($receipt->purged_at);
        $this->assertSame([], $receipt->answers);
        $this->assertSame(['retain' => false], $receipt->snapshot);
        $this->assertDatabaseHas('submissions', ['token' => $receipt->token, 'legacy_id' => 43]);
        $this->assertDatabaseHas('deliveries', ['id' => $delivery->id, 'status' => 'sent']);
        Storage::disk('local')->assertMissing('attachments/test');
        $this->assertSame(0, $receipt->attachments()->count());
        $this->get(route('admin.submission', $receipt))->assertNotFound();
    }

    public function test_role_changes_require_existing_sessions_to_reauthenticate(): void
    {
        $editor = $this->user();
        $this->post('/admin/login', ['email' => $editor->email, 'password' => 'correct-test-pass'])->assertRedirect();
        $this->get('/admin')->assertOk();
        $editor->forceFill(['role' => 'admin', 'role_version' => 1])->save();
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->get('/admin/users')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post('/admin/login', ['email' => $editor->email, 'password' => 'correct-test-pass'])->assertRedirect();
        $this->get('/admin/users')->assertOk();
    }

    public function test_only_administrators_manage_roles_and_last_admin_is_preserved(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin);
        $this->get('/admin/users')->assertOk();
        $this->put(route('admin.users.update', $admin), ['role' => 'viewer'])->assertSessionHasErrors('role');
        $this->post('/admin/users', ['name' => '閲覧担当', 'email' => 'account@example.test', 'role' => 'viewer', 'password' => 'test-password-abc'])->assertSessionHasNoErrors();
        $user = User::where('email', 'account@example.test')->sole();
        $this->put(route('admin.users.update', $user), ['role' => 'editor'])->assertSessionHasNoErrors();
        $this->assertSame('editor', $user->refresh()->role);
        $this->actingAs($user)->post('/admin/users', [])->assertForbidden();
    }
}
