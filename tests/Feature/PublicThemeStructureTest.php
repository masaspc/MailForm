<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Submission;
use App\Models\Theme;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PublicThemeStructureTest extends TestCase
{
    use RefreshDatabase;

    private function form(array $overrides = [], bool $pending = false): Form
    {
        $structure = array_replace([
            'page' => [['tag' => 'div', 'class' => 'frame'], ['tag' => 'main', 'id' => 'subject-id']],
            'title' => [['tag' => 'div', 'class' => 'title-box'], ['tag' => 'h1']],
            'body' => [['tag' => 'section', 'class' => 'body-box']],
            'form_outer' => [['tag' => 'div', 'class' => 'form-box']],
            'form' => [['tag' => 'form', 'id' => 'entry-form', 'class' => 'entry']],
            'description' => [['tag' => 'p', 'class' => 'lead']],
            'rows' => [['tag' => 'section', 'class' => 'fields']],
            'row' => [['tag' => 'dl', 'id' => 'repeated-row', 'class' => 'field-row']],
            'label' => [['tag' => 'dt']], 'required' => [['tag' => 'abbr', 'class' => 'mandatory']],
            'required_position' => 'before', 'field' => [['tag' => 'dd']],
            'select' => [['tag' => 'div', 'class' => 'select-box'], ['tag' => 'select', 'class' => 'select-style']],
            'radio_choices' => [['tag' => 'div', 'class' => 'radio-list']],
            'radio_choice' => [['tag' => 'div', 'class' => 'radio-item']],
            'radio_choice_label' => [['tag' => 'label', 'class' => 'radio-label']],
            'radio_choice_text' => [['tag' => 'span', 'class' => 'option-name']],
            'radio' => [['tag' => 'input', 'class' => 'radio-control']],
            'zip' => [['tag' => 'input', 'class' => 'postal']],
            'pref' => [['tag' => 'div', 'class' => 'pref-box'], ['tag' => 'select']],
            'address' => [['tag' => 'input', 'class' => 'street']],
            'building' => [['tag' => 'input', 'class' => 'building']],
            'address_order' => ['pref', 'address', 'zip', 'building'],
            'above' => [['tag' => 'span', 'class' => 'intro-hint']], 'error' => [['tag' => 'small', 'class' => 'field-error']],
            'buttons_position' => 'after_form_outer', 'buttons' => [['tag' => 'div', 'class' => 'controls']],
            'confirm_wrap' => [['tag' => 'p', 'class' => 'verify']], 'confirm_button' => [['tag' => 'input', 'class' => 'go']],
            'send_wrap' => [['tag' => 'p', 'class' => 'submit-wrap']], 'send_button' => [['tag' => 'button', 'class' => 'finish']],
            'confirm_value' => [['tag' => 'strong', 'class' => 'answer']],
            'thanks_page' => [['tag' => 'main', 'class' => 'receipt']],
            'thanks_message' => [['tag' => 'div', 'class' => 'result'], ['tag' => 'p']],
        ], $overrides);
        (new Theme())->forceFill(['name' => '窓口', 'slug' => 'desk', 'layout' => 'one_column',
            'import_report' => [], 'reviewed_at' => $pending ? null : now(), 'structure' => $structure])->save();
        return Form::create(['name' => '連絡用', 'slug' => 'contact', 'published' => true, 'revision' => 1, 'definition' => [
            'fields' => [
                ['name' => 'subject', 'html_id' => 'subject-id', 'form_type' => 'text', 'title' => '件名', 'required' => true,
                    'html_class' => 'authored js_ignored', 'description_above' => '短くご記入ください', 'initial_value' => 'お問い合わせ'],
                ['name' => 'topic', 'html_id' => 'err-subject-id', 'form_type' => 'select', 'title' => '分類', 'html_class' => 'authored-select',
                    'choices' => [['label_text' => '資料']]],
                ['name' => 'reply', 'html_id' => 'response', 'form_type' => 'radio', 'title' => '返信', 'html_class' => 'author-radio',
                    'choices' => [['label_text' => '希望する'], ['label_text' => '不要']]],
                ['name' => 'reference', 'html_id' => 'response-0', 'form_type' => 'textarea', 'title' => '備考'],
                ['name' => 'location', 'html_id' => 'postal-group', 'form_type' => 'zp_address', 'title' => '住所', 'required' => true],
            ],
            'settings' => ['theme_name' => 'desk', 'input_form_header_message' => 'フォームの説明', 'thanks_message' => '#subject# を受け付けました。',
                'admin_email' => 'desk@example.test', 'save_data' => 1],
        ]]);
    }

    private function dom(TestResponse $response): DOMXPath
    {
        $doc = new DOMDocument();
        $doc->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        $xpath = new DOMXPath($doc);
        $ids = array_map(fn ($node) => $node->nodeValue, iterator_to_array($xpath->query('//*[@id]/@id')));
        $this->assertSame($ids, array_values(array_unique($ids)), 'Every rendered ID must be unique.');
        return $xpath;
    }

    public function test_themed_rows_associate_native_fields_messages_and_external_submitters_through_a_receipt(): void
    {
        $this->form();
        $xp = $this->dom($this->get('/contact')->assertOk());
        $this->assertCount(1, $xp->query('//body/div[@class="frame"]/main/div[@class="title-box"]/h1'));
        $body = '//main/section[@class="body-box"]';
        $rows = $body.'/div[@class="form-box"]/form[@action="/contact/confirm"]/section[@class="fields"]/dl';
        $this->assertCount(5, $xp->query($rows));
        $this->assertCount(1, $xp->query($rows.'/dt/abbr[@class="mandatory" and following-sibling::text()[contains(., "件名")]]'));
        $this->assertCount(1, $xp->query($rows.'/dd/input[@id="subject-id" and @class="authored" and @required]'));
        $this->assertCount(1, $xp->query($rows.'/dd/div[@class="select-box"]/select[@id="err-subject-id" and @class="select-style authored-select"]'));
        $choice = $rows.'/dd/div[@class="radio-list" and @id="response"]/div[@class="radio-item"]/label';
        $this->assertCount(2, $xp->query($choice.'/input[@class="radio-control author-radio" and @id=../@for]'));
        $this->assertCount(2, $xp->query($choice.'/span[@class="option-name"]'));
        $parts = $xp->query($rows.'[@id="postal-group"]/dd//*[@data-address-part]/@data-address-part');
        $this->assertSame(['pref', 'address', 'zip', 'building'], array_map(fn ($node) => $node->nodeValue, iterator_to_array($parts)));
        $this->assertCount(1, $xp->query($rows.'/dd/div[@class="pref-box"]/select[@name="preflocation"]'));
        $this->assertCount(1, $xp->query($rows.'/dd/input[@data-address-part="building" and not(@required)]'));
        $this->assertCount(1, $xp->query($body.'/div[@class="controls"]/p[@class="verify"]/input[@type="submit" and @form="entry-form"]'));
        $this->assertCount(1, $xp->query('//form[@id="entry-form"]/input[@name="_token"]'));
        $token = $xp->evaluate('string(//form/input[@name="receipt_token"]/@value)');
        $bad = $this->dom($this->post('/contact/confirm', ['receipt_token' => $token])->assertUnprocessable());
        $describedBy = explode(' ', $bad->evaluate('string(//input[@id="subject-id" and @aria-invalid="true"]/@aria-describedby)'));
        $this->assertCount(2, $describedBy);
        $this->assertSame('intro-hint', $bad->document->getElementById($describedBy[0])->getAttribute('class'));
        $this->assertSame('field-error', $bad->document->getElementById($describedBy[1])->getAttribute('class'));

        $data = ['receipt_token' => $token, 'subject' => '見学', 'topic' => '資料', 'reply' => '不要',
            'ziplocation' => '1000001', 'preflocation' => '東京都', 'addresslocation' => '千代田一丁目', 'buildinglocation' => '二階'];
        $conf = $this->dom($this->post('/contact', $data)->assertOk());
        $this->assertCount(1, $conf->query('//form[@action="/contact/send"]/section[@class="fields"]/dl/dd/strong[text()="見学"]'));
        $this->assertCount(1, $conf->query($body.'/div[@class="controls"]/button[@formaction="/contact/back" and @form="entry-form"]'));
        $this->assertCount(1, $conf->query($body.'/div[@class="controls"]/p/button[@form="entry-form" and contains(concat(" ", @class, " "), " finish ")]'));
        $editing = $this->dom($this->post('/contact/back', ['receipt_token' => $token])->assertOk());
        $this->assertSame('二階', $editing->evaluate('string(//input[@name="buildinglocation"]/@value)'));
        $this->post('/contact/confirm', $data)->assertOk();
        $this->post('/contact/send', ['receipt_token' => $token])->assertRedirect('/contact/thanks');
        $thanks = $this->dom($this->get('/contact/thanks')->assertOk());
        $this->assertSame('見学 を受け付けました。', $thanks->evaluate('string(//main[@class="receipt"]/div[@class="result"]/p)'));
        $this->assertStringContainsString('二階', Submission::sole()->answers['location']);
    }

    public function test_admin_can_preview_each_pending_stage_without_a_draft_or_public_action(): void
    {
        $form = $this->form(pending: true);
        $definition = $form->definition;
        $definition['settings']['recaptcha_enabled'] = 1;
        $form->update(['definition' => $definition]);
        $url = route('admin.preview', $form);
        $this->get('/contact')->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'editor']))->get($url.'?stage=confirm')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach (['input', 'confirm', 'thanks'] as $stage) {
            $response = $this->get($url.'?version=current&stage='.$stage)->assertOk()->assertSessionMissing('public_drafts')
                ->assertDontSee('name="receipt_token"', false)->assertDontSee('name="_token"', false)
                ->assertDontSee('/contact/send')->assertDontSee('/contact/back')->assertDontSee('/contact/confirm')
                ->assertDontSee('data-sitekey')->assertDontSee('captcha.js');
            $xp = $this->dom($response);
            $this->assertCount(1, $xp->query('//nav[@aria-label="管理者プレビュー"]/a[@aria-current="page"]'));
            $this->assertCount(0, $xp->query('//form[@method="post"] | //input[not(@disabled)] | //button[not(@disabled)] | //textarea[not(@disabled)] | //select[not(@disabled)]'));
            if ($stage === 'confirm') {
                $this->assertSame('お問い合わせ', $xp->evaluate('string(//strong[@class="answer"])'));
            } elseif ($stage === 'thanks') {
                $this->assertSame('お問い合わせ を受け付けました。', $xp->evaluate('string(//main[@class="receipt"]//p)'));
            }
        }
        $this->get($url.'?stage=unknown')->assertNotFound();
        $this->assertDatabaseEmpty('submissions');
    }

    public function test_an_open_receipt_keeps_its_address_fields_through_a_theme_change(): void
    {
        $this->form();
        $theme = Theme::sole();
        $separate = $theme->structure;
        $combined = $separate;
        unset($combined['building'], $combined['address_order']);
        $data = ['subject' => '受付', 'ziplocation' => '1000001', 'preflocation' => '東京都', 'addresslocation' => '千代田',
            'buildinglocation' => '北棟'];
        $token = $this->dom($this->get('/contact')->assertOk())->evaluate('string(//input[@name="receipt_token"]/@value)');
        $this->post('/contact/confirm', $data + ['receipt_token' => $token])->assertOk()->assertSee('北棟');
        $theme->update(['structure' => $combined]);
        $this->post('/contact/back', ['receipt_token' => $token])->assertOk()->assertSee('value="北棟"', false);
        $this->post('/contact/confirm', $data + ['receipt_token' => $token])->assertOk()->assertSee('北棟');
        $this->post('/contact/send', ['receipt_token' => $token])->assertRedirect('/contact/thanks');
        $this->assertStringContainsString('北棟', Submission::whereToken($token)->sole()->answers['location']);

        $token = $this->dom($this->get('/contact')->assertOk()->assertDontSee('name="buildinglocation"', false))
            ->evaluate('string(//input[@name="receipt_token"]/@value)');
        $theme->update(['structure' => $separate]);
        $this->post('/contact/confirm', $data + ['receipt_token' => $token])->assertOk()->assertDontSee('北棟');
        $this->post('/contact/back', ['receipt_token' => $token])->assertOk()->assertDontSee('name="buildinglocation"', false);
        $this->post('/contact/confirm', $data + ['receipt_token' => $token])->assertOk();
        $this->post('/contact/send', ['receipt_token' => $token])->assertRedirect('/contact/thanks');
        $this->assertStringNotContainsString('北棟', Submission::whereToken($token)->sole()->answers['location']);
    }

    public function test_table_rows_and_split_name_readings_keep_native_field_types_and_captcha(): void
    {
        $form = $this->form([
            'rows' => [['tag' => 'table'], ['tag' => 'tbody']], 'row' => [['tag' => 'tr']],
            'label' => [['tag' => 'th']], 'field' => [['tag' => 'td']],
            'kana_rows' => 'separate', 'your_name' => [['tag' => 'input', 'class' => 'full-name']],
            'name_and_furigana' => [['tag' => 'input', 'class' => 'reading', 'id' => 'reading-input']],
            'first_name' => [['tag' => 'input', 'class' => 'given']], 'last_name' => [['tag' => 'input', 'class' => 'family']],
            'buttons_position' => 'outside_form',
        ]);
        $definition = $form->definition;
        $definition['settings']['recaptcha_enabled'] = 1;
        $definition['fields'] = [
            ['name' => 'visitor', 'title' => '来訪者氏名', 'form_type' => 'name_and_furigana', 'restriction' => 'hiragana',
                'required' => 1, 'html_id' => 'visitor-group', 'description_below' => '本人の氏名を記入'],
            ['name' => 'signature', 'title' => '署名', 'form_type' => 'first_last_name'],
        ];
        $types = ['email', 'tel', 'number', 'url', 'date', 'datetime', 'month', 'time', 'textarea', 'multi_select', 'checkbox', 'file'];
        foreach ($types as $type) {
            $definition['fields'][] = ['name' => 'field_'.$type, 'form_type' => $type, 'title' => $type,
                'html_id' => 'custom-'.$type, 'html_class' => 'custom-control', 'choices' => [['label_text' => '希望']]];
        }
        $form->update(['definition' => $definition]);
        $input = $this->dom($this->get('/contact')->assertOk());
        $row = '//form/table/tbody/tr';
        $this->assertCount(count($types) + 3, $input->query($row));
        $this->assertCount(1, $input->query($row.'[@id="visitor-group" and th[contains(., "来訪者氏名")]]/td/input[@data-kana-for="reading-input" and @class="full-name"]'));
        $this->assertCount(1, $input->query($row.'[th[contains(., "ふりがな")]]/td/input[@id="reading-input" and @name="name_and_furigana" and @class="reading"]'));
        $this->assertCount(1, $input->query($row.'/td/*[text()="本人の氏名を記入"]'));
        $this->assertCount(1, $input->query($row.'/td[input[@class="given" and @name="first_name"]]/input[@class="family" and @name="last_name"]'));
        foreach ($types as $type) {
            $name = 'field_'.$type.($type === 'multi_select' || $type === 'checkbox' ? '[]' : '');
            $node = $input->query('//*[@name="'.$name.'"]')->item(0);
            $this->assertNotNull($node, $type);
            $this->assertContains('custom-control', explode(' ', $node->getAttribute('class')));
            $this->assertSame(match ($type) { 'multi_select' => 'select', 'textarea' => 'textarea', default => 'input' }, $node->tagName);
            if ($node->tagName === 'input') {
                $this->assertSame($type === 'datetime' ? 'datetime-local' : $type, $node->getAttribute('type'));
            }
        }
        $token = $input->evaluate('string(//input[@name="receipt_token"]/@value)');
        $confirmed = $this->dom($this->post('/contact/confirm', [
            'receipt_token' => $token, 'your_name' => '山田', 'name_and_furigana' => 'やまだ',
        ])->assertOk());
        $this->assertCount(1, $confirmed->query($row.'[th[contains(., "来訪者氏名")]]/td/strong[text()="山田"]'));
        $this->assertCount(1, $confirmed->query($row.'[th[contains(., "ふりがな")]]/td/strong[text()="やまだ"]'));
        $this->assertCount(1, $confirmed->query('//form/input[@name="g-recaptcha-token"]'));
        $this->assertCount(1, $confirmed->query('//div[@class="form-box"]/div[@class="controls"]//button[@formaction="/contact/back" and @form="entry-form"]'));
    }
}
