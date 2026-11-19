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
    public function test_stock_contact_fixture_preserves_official_message_and_csv_bytes(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 19)->setTime(12, 0));
        $definition = \App\Services\FieldLibrary::presets()['contact'];
        $form = Form::create(['slug' => 'contact', 'name' => 'お問い合わせ', 'published' => true, 'revision' => 1, 'definition' => $definition]);
        $token = $this->token('/contact');
        $this->post('/contact/confirm', [
            'receipt_token' => $token, 'corporation_name' => '日本語の試験', 'staff_name' => '', 'email' => 'example@example.test', 'url' => '', 'tel' => '',
            'zipzp_address' => '1000001', 'prefzp_address' => '東京都', 'addresszp_address' => '千代田区千代田', 'contents' => 'テストです。',
        ])->assertOk();
        $this->post('/contact/send', ['receipt_token' => $token])->assertRedirect();
        $this->assertDatabaseCount('deliveries', 2);
        // Digests of EasyMail 2.01.004 gray's rendered text templates and its csv.js download.
        foreach (Delivery::all() as $delivery) {
            $this->assertSame('99968a15ef8612f68ab5e2d54b0cc903b7564843e7c664c64d58cb4399fbf874', hash('sha256', $delivery->payload['body']));
        }
        $csv = app(\App\Http\Controllers\Admin\HistoryController::class)->csv($form, app(\App\Services\Admin\HistoryRows::class));
        $bytes = \Illuminate\Testing\TestResponse::fromBaseResponse($csv)->streamedContent();
        $this->assertSame('f48e1ecc5a6c72282906e752a761ec1d36f897954cb34f11c00f594b801f7d5d', hash('sha256', $bytes));
    }

    public function test_english_and_chinese_forms_localize_public_steps_errors_and_stock_field_names(): void
    {
        foreach ([
            ['en', 'en', 'Review your answers', 'Please enter Name.', 'Submit', 'Submission complete'],
            ['cn', 'zh-CN', '确认填写内容', '请填写姓名。', '提交', '提交成功'],
        ] as [$locale, $lang, $review, $required, $send, $complete]) {
            $form = $this->form($locale);
            $def = $form->definition;
            $def['settings']['language'] = $locale;
            $def['fields'][] = ['name' => 'office', 'title' => '事業所', 'form_type' => 'zp_address'];
            $def['settings']['thanks_message'] = 'Original custom message';
            $form->update(['definition' => $def]);
            $token = $this->token('/'.$locale);
            $this->get('/'.$locale)->assertSee('lang="'.$lang.'"', false)->assertSee($review);
            $this->post('/'.$locale.'/confirm', ['receipt_token' => $token])->assertUnprocessable()->assertSee($required);
            $this->post('/'.$locale.'/confirm', $this->values($token))->assertOk()->assertSee($send);
            $this->post('/'.$locale.'/send', ['receipt_token' => $token])->assertRedirect('/'.$locale.'/thanks');
            $this->get('/'.$locale.'/thanks')->assertOk()->assertSee($complete)->assertSee('Original custom message');
            $receipt = $form->submissions()->sole();
            $exportLabels = array_column(app(\App\Services\Admin\HistoryRows::class)->cells($receipt), 'title');
            $this->assertContains($locale === 'en' ? 'Address' : '地址', $exportLabels);
        }
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

    public static function addressFormats(): array
    {
        return [
            '2.00.124' => ['2.00.124', '', '住所', '住所'],
            '2.00.125' => ['2.00.125', '', '住所', '住所'],
            '2.00.133' => ['2.00.133', 'zp_address', '送付先', '郵便番号付き住所'],
            '2.01.004' => ['2.01.004', 'zp_address', '送付先', '郵便番号付き住所'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('addressFormats')]
    public function test_address_posts_and_receipts_preserve_the_imported_release_format(string $version, string $suffix, string $mailTitle, string $historyTitle): void
    {
        $form = $this->form('contact');
        $definition = $form->definition;
        $definition['source_version'] = $version;
        $definition['settings']['mail_title'] = '受付［#zp_address#］';
        $definition['fields'] = [[
            'id' => 21, 'name' => 'zp_address', 'title' => '送付先', 'form_type' => 'zp_address',
            'required' => 1, 'pref_choices' => ['東京都'],
        ]];
        $form->update(['definition' => $definition]);

        $this->get('/contact')->assertOk()->assertSee('送付先')
            ->assertSee('name="zip'.$suffix.'"', false)->assertSee('name="pref'.$suffix.'"', false)->assertSee('name="address'.$suffix.'"', false);
        $token = $this->token('/contact');
        $input = ['receipt_token' => $token, 'source_version' => $suffix === '' ? '2.00.133' : '2.00.124',
            'zip'.$suffix => '100-0001', 'pref'.$suffix => '東京都', 'address'.$suffix => '千代田区千代田'];
        $this->post('/contact', array_replace($input, ['pref'.$suffix => '大阪府']))->assertUnprocessable();
        $this->post('/contact', $input)->assertOk()->assertSee('千代田区千代田');
        $this->post('/contact/back', ['receipt_token' => $token])->assertOk()->assertSee('value="100-0001"', false);
        $this->post('/contact/confirm', $input)->assertOk();
        $this->post('/contact/send', ['receipt_token' => $token])->assertRedirect('/contact/thanks');

        $receipt = $form->submissions()->sole();
        $this->assertSame("100-0001\n東京都\n千代田区千代田", $receipt->answers['zp_address']);
        $mail = Delivery::sole()->payload;
        $this->assertSame($suffix === '' ? '受付［］' : '受付［100-0001 東京都 千代田区千代田］', $mail['subject']);
        $this->assertStringContainsString($mailTitle.":\n100-0001\n東京都\n千代田区千代田", $mail['body']);
        $cells = app(\App\Services\Admin\HistoryRows::class)->cells($receipt);
        $this->assertSame([$historyTitle], array_column($cells, 'title'));
        $this->assertSame(['100-0001東京都千代田区千代田'], array_column($cells, 'value'));
    }

    public function test_older_custom_address_tag_uses_the_posted_component_with_that_name(): void
    {
        $form = $this->form();
        $definition = $form->definition;
        $definition['source_version'] = '2.00.124';
        $definition['fields'] = [['name' => 'address', 'title' => '所在地', 'form_type' => 'zp_address']];
        $definition['settings']['thanks_message'] = '番地［#address#］';
        $form->update(['definition' => $definition]);

        $token = $this->token();
        $this->post('/confirm', ['receipt_token' => $token, 'zip' => '100-0001', 'pref' => '東京都', 'address' => '千代田区千代田'])->assertOk();
        $this->post('/send', ['receipt_token' => $token])->assertRedirect('/thanks');
        $this->get('/thanks')->assertOk()->assertSee('番地［千代田区千代田］');
        $cells = app(\App\Services\Admin\HistoryRows::class)->cells($form->submissions()->sole());
        $this->assertSame(['所在地'], array_column($cells, 'title'));
        $this->assertSame(['100-0001東京都千代田区千代田'], array_column($cells, 'value'));
    }

    public function test_admin_publication_detects_field_names_already_used_by_an_older_address(): void
    {
        $form = $this->form('contact');
        $definition = $form->definition;
        $definition['settings'] = ['url' => 'contact', 'admin_email' => 'office@example.test'];
        $definition['fields'] = [
            ['id' => 21, 'name' => 'zp_address', 'title' => '住所', 'form_type' => 'zp_address'],
            ['id' => 4, 'name' => 'zip', 'title' => '別の郵便番号', 'form_type' => 'text'],
        ];
        $definition['source_version'] = '2.00.125';
        $form->update(['published' => false, 'definition' => $definition]);
        $this->actingAs(\App\Models\User::create(['name' => '編集者', 'email' => 'editor@example.test', 'password' => 'test-password', 'role' => 'editor']));
        $edit = route('admin.edit', $form);
        $version = $this->get($edit)->assertOk()->viewData('version');
        $this->post(route('admin.publish', $form), ['version' => $version])
            ->assertRedirect($edit)
            ->assertSessionHasErrors(['definition' => '入力欄の名前またはHTML IDが重複しています: zip']);
        $this->assertFalse($form->refresh()->published);

        $definition['source_version'] = '2.00.133';
        $form->update(['definition' => $definition]);
        $version = $this->get($edit)->assertOk()->viewData('version');
        $this->post(route('admin.publish', $form), ['version' => $version])->assertRedirect($edit)->assertSessionHasNoErrors();
        $this->get('/contact')->assertOk()->assertSee('name="zipzp_address"', false)->assertSee('name="zip"', false);
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
