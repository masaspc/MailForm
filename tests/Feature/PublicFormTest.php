<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Form;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicFormTest extends TestCase
{
    use RefreshDatabase;

    private function form(string $slug = ''): Form
    {
        $fields = [
            ['name' => 'name', 'title' => '氏名', 'form_type' => 'text', 'required' => 1, 'max_length' => 20],
            ['name' => 'email', 'title' => 'メール', 'html_id' => 'mailbox', 'form_type' => 'email', 'required' => 1],
            ['name' => 'conf_email', 'title' => '再入力', 'form_type' => 'email', 're_enter_html_id' => 'mailbox'],
            ['name' => 'interest', 'title' => '選択', 'form_type' => 'checkbox', 'choices' => [['label_text' => '甲'], ['label_text' => '乙']]],
            ['name' => 'file', 'title' => '資料', 'form_type' => 'file', 'file_type' => '.txt', 'file_capacity_limit' => 32],
        ];
        return Form::create(['slug' => $slug, 'name' => 'お問い合わせ', 'published' => true, 'revision' => 1, 'definition' => [
            'issues' => [], 'fields' => $fields, 'settings' => [
                'save_data' => 1, 'admin_email' => 'office@example.test', 'cc_email' => 'cc@example.test, cc2@example.test', 'bcc_email' => 'bcc@example.test',
                'conf_mail_flag' => 1, 'include_submissions_admin_email' => 1, 'include_submissions' => 0, 'mail_title' => '#name# 様 #YYYY#',
                'reply_mail_header_message' => '#MM#月#DD#日 #KWE# #interest#', 'reply_mail_footer_message' => '会社',
                'thanks_message' => '#name# 様、受付しました。',
            ],
        ]]);
    }

    private function token(string $path = '/'): string
    {
        $response = $this->get($path)->assertOk();
        $this->assertSame(1, preg_match('/name="receipt_token" value="([^"]+)"/', $response->getContent(), $match));
        return $match[1];
    }

    private function values(string $token): array
    {
        return ['receipt_token' => $token, 'name' => '山田', 'email' => 'user@example.test', 'conf_email' => 'user@example.test', 'interest' => ['甲', '乙']];
    }

    public function test_confirm_back_and_duplicate_submission_store_one_snapshot_and_two_distinct_messages(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 19));
        Storage::fake('local');
        $form = $this->form('contact');
        $token = $this->token('/contact');
        $input = $this->values($token);
        $this->post('/contact', $input + ['file' => UploadedFile::fake()->createWithContent('案内.txt', 'plain data')])
            ->assertOk()->assertSee('入力内容の確認')->assertSee('案内.txt');
        $this->post('/contact/back', ['receipt_token' => $token])->assertOk()->assertSee('user@example.test')->assertSee('案内.txt');
        $this->post('/contact/send', ['receipt_token' => $token])->assertConflict();
        $this->post('/contact/confirm', $input)->assertOk();
        $this->post('/contact/send', ['receipt_token' => $token, 'name' => '改変'])->assertRedirect('/contact/thanks');
        $this->post('/contact/send', ['receipt_token' => $token])->assertRedirect('/contact/thanks');
        $this->get('/contact/thanks')->assertOk()->assertSee('山田 様、受付しました。');
        $this->assertDatabaseCount('submissions', 1);
        $submission = Submission::firstOrFail();
        $this->assertEquals($form->definition, $submission->snapshot['definition']);
        $this->assertSame(array_column($form->definition['fields'], 'name'), array_column($submission->snapshot['definition']['fields'], 'name'));
        $this->assertSame(['甲', '乙'], $submission->answers['interest']);
        $file = $submission->attachments()->sole();
        Storage::disk('local')->assertExists($file->path);
        $this->assertSame('plain data', Storage::disk('local')->get($file->path));
        $this->assertDatabaseCount('deliveries', 2);
        $admin = Delivery::where('kind', 'admin')->sole()->payload;
        $reply = Delivery::where('kind', 'reply')->sole()->payload;
        $this->assertSame('山田 様 2026', $admin['subject']);
        $this->assertSame(['cc@example.test', 'cc2@example.test'], $admin['cc']);
        $this->assertSame(['bcc@example.test'], $admin['bcc']);
        $this->assertSame([$file->id], $admin['attachments']);
        $this->assertStringContainsString("11月19日 木 甲 乙\n\n氏名:山田", $admin['body']);
        $this->assertSame(['user@example.test'], $reply['to']);
        $this->assertSame([], $reply['attachments']);
        $this->assertStringNotContainsString('氏名:', $reply['body']);
        $this->assertStringNotContainsString('再入力:', $admin['body']);
    }

    public function test_validation_checks_required_choices_re_entry_and_malformed_values_at_root(): void
    {
        $this->form();
        $token = $this->token();
        $this->post('/confirm', ['receipt_token' => $token, 'name' => ['配列'], 'email' => 'user@example.test', 'conf_email' => 'other@example.test', 'interest' => ['存在しない']])
            ->assertUnprocessable()->assertSee('一覧から選んでください')->assertSee('再入力した内容が一致しません');
        $this->post('/', ['receipt_token' => $token])->assertUnprocessable()->assertSee('氏名を入力してください');
        $this->post('/send', ['receipt_token' => $token])->assertConflict();
        $this->get('/thanks')->assertNotFound();
        $this->post('/confirm', $this->values($token))->assertOk();
        $this->post('/send', ['receipt_token' => $token])->assertRedirect('/thanks');
        $this->post('/thanks')->assertOk();
    }

    public function test_changed_revision_unpublished_form_and_cross_form_tokens_cannot_accept(): void
    {
        $form = $this->form();
        $other = $this->form('other');
        $token = $this->token();
        $this->post('/other/confirm', $this->values($token))->assertStatus(419);
        $this->post('/confirm', $this->values($token))->assertOk();
        $form->update(['revision' => 2]);
        $this->post('/send', ['receipt_token' => $token])->assertConflict();
        $other->update(['published' => false]);
        $this->get('/other')->assertNotFound();
        $this->assertDatabaseEmpty('submissions');
    }

    public function test_forbidden_ip_is_checked_at_each_step_and_retention_can_be_disabled(): void
    {
        $form = $this->form();
        $definition = $form->definition;
        $definition['settings']['save_data'] = 0;
        $definition['settings']['conf_mail_flag'] = 0;
        $form->update(['definition' => $definition]);
        $token = $this->token();
        $this->post('/confirm', $this->values($token))->assertOk();
        $definition['settings']['denied_ip'] = '127.0.0.*';
        $form->update(['definition' => $definition]);
        $this->post('/send', ['receipt_token' => $token])->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->post('/send', ['receipt_token' => $token])->assertRedirect();
        $this->assertFalse(Submission::sole()->retain);
        $this->assertDatabaseCount('deliveries', 1);
    }
    public function test_hostname_rules_use_server_identity_and_deny_when_identity_is_unavailable(): void
    {
        $form = $this->form();
        $definition = $form->definition;
        $definition['settings']['denied_ip'] = "*.blocked.example\n192.0.2.*";
        $form->update(['definition' => $definition]);
        $this->get('/')->assertForbidden();
        $this->withServerVariables(['REMOTE_HOST' => 'visitor.blocked.example.'])->get('/')->assertForbidden();
        $this->withServerVariables(['REMOTE_HOST' => 'unrelated.example', 'REMOTE_ADDR' => '192.0.2.5'])->get('/')->assertForbidden();
        $this->withServerVariables(['REMOTE_HOST' => 'notblocked.example', 'REMOTE_ADDR' => '2001:db8::1'])->get('/')->assertOk();
        $definition['settings']['denied_ip'] = '2001:db8:0:0:0:0:0:1';
        $form->update(['definition' => $definition]);
        $this->get('/')->assertForbidden();
    }

    public function test_rendered_controls_have_distinct_required_and_escaped_text_attributes(): void
    {
        $form = $this->form();
        $def = $form->definition;
        $placeholder = '「例」 "ABC" & <会社>';
        $def['fields'][0]['placeholder'] = $placeholder;
        $form->update(['definition' => $def]);
        $markup = $this->get('/')->assertOk()->getContent();
        $document = new \DOMDocument();
        $document->loadHTML($markup, LIBXML_NOERROR | LIBXML_NOWARNING);
        $control = $document->getElementById('name');
        $this->assertTrue($control->hasAttribute('required'));
        $this->assertSame('default', $control->getAttribute('data-width'));
        $this->assertSame($placeholder, $control->getAttribute('placeholder'));
    }

    public function test_compound_fields_validate_and_preserve_order_japanese_restrictions_and_zero(): void
    {
        $form = $this->form();
        $def = $form->definition;
        $def['fields'] = [
            ['form_type' => 'zp_address', 'name' => 'office', 'title' => '住所', 'required' => 1],
            ['form_type' => 'name_and_furigana', 'name' => 'name_and_furigana', 'title' => '氏名', 'required' => 1, 'restriction' => 'katakana'],
            ['form_type' => 'number', 'name' => 'quantity', 'title' => '個数', 'required' => 1, 'restriction' => 'num', 'hankaku_zenkaku_automatic_conversion' => 'hankaku'],
        ];
        $def['settings']['mail_title'] = '#name_and_furigana# / #quantity#';
        $form->update(['definition' => $def]);
        $token = $this->token();
        $input = ['receipt_token' => $token, 'zipoffice' => '100-0001', 'prefoffice' => '東京都', 'addressoffice' => '千代田区千代田',
            'your_name' => '試験 太郎', 'name_and_furigana' => 'シケン タロウ', 'quantity' => '０'];
        $this->post('/confirm', array_replace($input, ['prefoffice' => '自由入力', 'name_and_furigana' => 'abc']))->assertUnprocessable();
        $this->post('/confirm', $input)->assertOk()->assertSee('千代田区千代田')->assertSee('シケン タロウ');
        $this->post('/send', ['receipt_token' => $token])->assertRedirect();
        $this->assertSame("100-0001\n東京都\n千代田区千代田", Submission::sole()->answers['office']);
        $this->assertSame('0', Submission::sole()->answers['quantity']);
        $this->assertSame('試験 太郎 / 0', Delivery::sole()->payload['subject']);
    }

    public function test_limits_and_upload_format_error_leave_the_receipt_unconfirmed(): void
    {
        Storage::fake('local');
        $this->form();
        $token = $this->token();
        $input = $this->values($token);
        $this->post('/confirm', $input + ['file' => UploadedFile::fake()->createWithContent('資料.txt', str_repeat('a', 33000))])
            ->assertUnprocessable()->assertSee('32 KB');
        $file = tmpfile();
        fwrite($file, '<html><body>sample</body></html>');
        $upload = new UploadedFile(stream_get_meta_data($file)['uri'], '資料.txt', 'text/plain', null, true);
        $this->post('/confirm', $input + ['file' => $upload])
            ->assertUnprocessable()->assertSee('指定されたファイル形式');
        $this->post('/confirm', array_replace($input, ['name' => str_repeat('a', 65537)]))->assertStatus(413);
        $this->post('/send', ['receipt_token' => $token])->assertConflict();
        $this->assertDatabaseEmpty('attachments');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_outbox_failure_rolls_back_receipt_and_attachment_then_same_token_can_retry(): void
    {
        Storage::fake('local');
        $this->form();
        $token = $this->token();
        $input = $this->values($token);
        $this->post('/confirm', $input + ['file' => UploadedFile::fake()->createWithContent('案内.txt', 'attachment')])->assertOk();

        $fail = true;
        Delivery::creating(function (Delivery $delivery) use (&$fail) {
            if ($fail && $delivery->kind === 'reply') {
                throw new \RuntimeException('outbox test failure');
            }
        });
        $this->post('/send', ['receipt_token' => $token])->assertServerError();
        $this->assertDatabaseEmpty('submissions');
        $this->assertDatabaseEmpty('deliveries');
        $this->assertDatabaseEmpty('attachments');
        $this->assertSame([], Storage::disk('local')->allFiles('attachments'));
        $this->assertCount(1, Storage::disk('local')->allFiles('form-drafts'));
        $fail = false;
        $this->post('/send', ['receipt_token' => $token])->assertRedirect();
        $this->assertDatabaseCount('deliveries', 2);
        $this->assertSame([], Storage::disk('local')->allFiles('form-drafts'));
    }
}
