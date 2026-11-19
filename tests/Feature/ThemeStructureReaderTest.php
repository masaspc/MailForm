<?php

namespace Tests\Feature;

use App\Services\EasyMail\CustomThemeScanner;
use App\Services\EasyMail\ThemeStructureReader;
use App\Services\EasyMail\ThemeTemplateReader;
use App\Services\ThemeStructure;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

class ThemeStructureReaderTest extends TestCase
{
    public function test_it_uses_the_field_branch_and_literal_attributes_across_two_stage_specific_tables(): void
    {
        $scan = (new ThemeStructureReader)->read(['form.blade.php' => <<<'BLADE'
            <section class="paper">
            <p class="introduction">{{ $form->input_form_header_message }}</p>
            <form class="petition js_retired" action="{{ make_target() }}" data-custom="ignored">
            @if(!$isConfirm)
            <table class="matrix"><tbody>
            @foreach($fields as $field)
            <tr class="record"><th class="caption">@if($field->required)<em class="badge">必須</em>@endif {{ $field->title }}</th>
            <td class="answer-space">
            <small class="above">{{ $field->description_above }}</small>
            @if($field->form_type === 'text')
            <div class="baseline"><input type="text" class="steady {{ $dynamic }} extra mixed{{ $suffix }} @if($condition) optional @endif js_old" id="{{ $dynamic }}" onfocus="ignored()"></div>
            @elseif($field->form_type === 'email')
            <input class="mail" type="email">
            @endif
            @error($field->name)<span class="problem">{{ $message }}</span>@enderror
            <small class="below">{{ $field->description_below }}</small>
            </td></tr>
            @endforeach
            </tbody></table>
            @else
            <table class="summary"><tbody>@foreach($fields as $field)
            <tr class="summary-line"><th class="summary-key">{{ $field->title }}</th>
            <td class="summary-answer"><strong class="read-back">{{ $answers[$field->name] }}</strong></td></tr>
            @endforeach</tbody></table>
            @endif
            <button class="forward">確認</button>
            </form></section>
            BLADE]);
        $shape = $scan['structure'];
        $this->assertTrue(ThemeStructure::valid($shape));
        $this->assertSame([['tag' => 'section', 'class' => 'paper']], $shape['form_outer']);
        $this->assertSame([['tag' => 'form', 'class' => 'petition']], $shape['form']);
        $this->assertSame([['tag' => 'p', 'class' => 'introduction']], $shape['description']);
        $this->assertSame([['tag' => 'table', 'class' => 'matrix'], ['tag' => 'tbody']], $shape['rows']);
        $this->assertSame([['tag' => 'tr', 'class' => 'record']], $shape['row']);
        $this->assertSame('form.blade.php:7', $scan['structure_sources']['row']);
        $this->assertSame([['tag' => 'th', 'class' => 'caption']], $shape['label']);
        $this->assertSame([['tag' => 'em', 'class' => 'badge']], $shape['required']);
        $this->assertSame('before', $shape['required_position']);
        $this->assertSame([['tag' => 'td', 'class' => 'answer-space']], $shape['field']);
        $this->assertSame([['tag' => 'div', 'class' => 'baseline'], ['tag' => 'input', 'class' => 'steady extra']], $shape['text']);
        $this->assertSame([['tag' => 'input', 'class' => 'mail']], $shape['email']);
        $this->assertSame([['tag' => 'span', 'class' => 'problem']], $shape['error']);
        $this->assertSame([['tag' => 'small', 'class' => 'above']], $shape['above']);
        $this->assertSame([['tag' => 'small', 'class' => 'below']], $shape['below']);
        $this->assertSame([['tag' => 'table', 'class' => 'summary'], ['tag' => 'tbody']], $shape['confirm_rows']);
        $this->assertSame([['tag' => 'tr', 'class' => 'summary-line']], $shape['confirm_row']);
        $this->assertSame([['tag' => 'th', 'class' => 'summary-key']], $shape['confirm_label']);
        $this->assertSame([['tag' => 'td', 'class' => 'summary-answer']], $shape['confirm_field']);
        $this->assertSame([['tag' => 'strong', 'class' => 'read-back']], $shape['confirm_value']);
        $this->assertSame('inside_form', $shape['buttons_position']);
        $this->assertSame(array_keys($shape), array_keys($scan['structure_sources']));
    }

    public function test_it_separates_two_choice_layouts_and_address_parts_in_source_order(): void
    {
        $scan = (new ThemeStructureReader)->read(['form.blade.php' => <<<'BLADE'
            <form>@foreach($fields as $field)<fieldset>
            <legend>{{ $field->title }} @if($field->required)<small>必須</small>@endif</legend>
            <div class="controls">@switch($field->form_type)
            @case('radio')
            <section class="circles">@foreach($options as $option)
            <div class="round"><label class="item"><input class="dot" type="radio"><em class="caption">{{ $option }}</em></label></div>
            @endforeach</section>@break
            @case('checkbox')
            <ul class="squares">@foreach($options as $option)
            <li class="square"><label class="item"><input class="tick" type="checkbox">{{ $option }}</label></li>
            @endforeach</ul>@break
            @case('zp_address')
            <div class="region"><select name="pref{{ $field->name }}" class="province"></select></div>
            <p class="code"><input type="text" name="zip{{ $field->name }}" class="postal"></p>
            <input type="text" name="address{{ $field->name }}" class="street">
            <input type="text" name="building{{ $field->name }}" class="unit">
            @break
            @case('multi_select')<select multiple class="several"></select>@break
            @endswitch</div></fieldset>@endforeach</form>
            BLADE]);
        $shape = $scan['structure'];
        $this->assertSame('after', $shape['required_position']);
        $this->assertSame('circles', $shape['radio_choices'][0]['class']);
        $this->assertSame('squares', $shape['checkbox_choices'][0]['class']);
        $this->assertSame([['tag' => 'div', 'class' => 'round']], $shape['radio_choice']);
        $this->assertSame([['tag' => 'li', 'class' => 'square']], $shape['checkbox_choice']);
        $this->assertSame([['tag' => 'label', 'class' => 'item']], $shape['choice_label']);
        $this->assertSame([['tag' => 'em', 'class' => 'caption']], $shape['radio_choice_text']);
        $this->assertArrayNotHasKey('choice_text', $shape);
        $this->assertArrayNotHasKey('checkbox_choice_text', $shape);
        $this->assertSame([['tag' => 'input', 'class' => 'tick']], $shape['checkbox']);
        $this->assertSame([['tag' => 'select', 'class' => 'several']], $shape['multi_select']);
        $this->assertSame(['pref', 'zip', 'address', 'building'], $shape['address_order']);
        $this->assertSame([['tag' => 'div', 'class' => 'region'], ['tag' => 'select', 'class' => 'province']], $shape['pref']);
        $this->assertSame([['tag' => 'p', 'class' => 'code'], ['tag' => 'input', 'class' => 'postal']], $shape['zip']);
        $this->assertSame('unit', $shape['building'][0]['class']);
        $this->assertArrayNotHasKey('text', $shape);
    }

    public function test_it_splits_shared_and_unique_page_chains_recognizes_steps_and_ignores_opaque_source(): void
    {
        $templates = [
            'layout.blade.php' => '<html><body><div id="outside"><main>@yield("content")</main></div></body></html>',
            'index.blade.php' => <<<'BLADE'
                @section('content')
                <!-- <h1 class="comment">{{ $form_title }}</h1> -->
                @php $unused = '<h1 class="logic">{{ $form_title }}</h1>'; @endphp
                <?php if ($flag): ?><h1 class="guarded">{{ $form_title }}</h1><?php endif; ?>
                @privateOnly()<h1 class="secret">{{ $form_title }}</h1>@endprivateOnly
                <script type="text/plain"><h1 class="script">{{ $form_title }}</h1></script>
                <section class="sheet"><article class="inset">
                <header class="band"><div class="in-band"><h3 class="caption">{{ $form_title }}</h3></div></header>
                <nav aria-label="手順"><ol class="trail"><li class="point">入力</li><li aria-current="step" class="point here">確認</li><li>完了</li></ol></nav>
                <aside class="leaf"><div class="blank">@include('design.form')</div></aside>
                </article></section>
                @endsection
                BLADE,
            'thanks.blade.php' => '<section class="receipt"><article class="copy"><h4>{{ $form->thanks_message }}</h4></article></section>',
        ];
        $scan = (new ThemeStructureReader)->read($templates);
        $shape = $scan['structure'];
        $this->assertSame(['div', 'main', 'section', 'article'], array_column($shape['page'], 'tag'));
        $this->assertSame('outside', $shape['page'][0]['id']);
        $this->assertSame(['header', 'div', 'h3'], array_column($shape['title'], 'tag'));
        $this->assertSame('index.blade.php:8', $scan['structure_sources']['title']);
        $this->assertSame(['aside', 'div'], array_column($shape['body'], 'tag'));
        $this->assertSame([['tag' => 'nav'], ['tag' => 'ol', 'class' => 'trail']], $shape['steps']);
        $this->assertSame('point', $shape['step'][0]['class']);
        $this->assertSame('point here', $shape['step_current'][0]['class']);
        $this->assertSame('visible', $shape['steps_visibility']);
        $this->assertSame(['div', 'main', 'section', 'article'], array_column($shape['thanks_page'], 'tag'));
        $this->assertSame([['tag' => 'h4']], $shape['thanks_message']);
        $json = json_encode($shape, JSON_THROW_ON_ERROR);
        foreach (['comment', 'logic', 'guarded', 'secret', 'script', 'php', 'form_title'] as $excluded) {
            $this->assertStringNotContainsString($excluded, $json);
        }
    }

    public function test_compound_name_styles_follow_only_the_known_field_branch_and_literal_key(): void
    {
        $source = <<<'BLADE'
            <form>@foreach($fields as $item)
            @if($item->form_type === 'name_and_furigana')
            <dl><dt>{{ $item->title }}</dt><dd><div class="primary-name"><input name="your_name" class="long"></div></dd></dl>
            <dl><dt>かな</dt><dd><input name="name_and_furigana" class="short"></dd></dl>
            @else
            <fieldset><legend>{{ $item->title }}</legend><div>
            @switch($item->form_type)
            @case('text')<input type="text" class="plain">@break
            @case('first_last_name')
            <span class="surname"><input name="last_name" class="family"></span><input name="first_name" class="given">
            <input name="{{ $unknown }}" class="ignored">
            @break
            @endswitch
            </div></fieldset>
            @endif @endforeach</form>
            BLADE;
        $reader = new ThemeStructureReader;
        $scan = $reader->read(['form.blade.php' => $source]);
        $shape = $scan['structure'];
        $this->assertSame('separate', $shape['kana_rows']);
        $this->assertSame([['tag' => 'div', 'class' => 'primary-name'], ['tag' => 'input', 'class' => 'long']], $shape['your_name']);
        $this->assertSame([['tag' => 'input', 'class' => 'short']], $shape['name_and_furigana']);
        $this->assertSame([['tag' => 'span', 'class' => 'surname'], ['tag' => 'input', 'class' => 'family']], $shape['last_name']);
        $this->assertSame([['tag' => 'input', 'class' => 'given']], $shape['first_name']);
        $this->assertSame([['tag' => 'input', 'class' => 'plain']], $shape['text']);
        $this->assertStringNotContainsString('ignored', json_encode($shape));
        $sameRow = str_replace("</dd></dl>\n<dl><dt>かな</dt><dd>", '', $source);
        $this->assertSame('together', $reader->read(['form.blade.php' => $sameRow])['structure']['kana_rows']);
        $ambiguous = str_replace(['<dl>', '</dl>'], ['<div>', '</div>'], $source);
        $this->assertArrayNotHasKey('kana_rows', $reader->read(['form.blade.php' => $ambiguous])['structure']);
    }

    public function test_a_missing_step_include_keeps_the_visible_fallback(): void
    {
        $templates = [
            'index.blade.php' => '<main><h1>{{ $form_title }}</h1>@include("theme.steps")@include("theme.form")</main>',
            'form.blade.php' => '<form><dl><dt>{{ $item->title }}</dt><dd><input type="text"></dd></dl></form>',
        ];
        $reader = new ThemeStructureReader;
        $this->assertArrayNotHasKey('steps_visibility', $reader->read($templates)['structure']);
        $templates['index.blade.php'] = str_replace('@include("theme.steps")', '', $templates['index.blade.php']);
        $this->assertSame('accessible', $reader->read($templates)['structure']['steps_visibility']);
        unset($templates['form.blade.php']);
        $this->assertArrayNotHasKey('steps_visibility', $reader->read($templates)['structure']);
    }

    public function test_one_unreadable_structure_template_preserves_other_files_and_styles_in_the_scanner(): void
    {
        $root = sys_get_temp_dir().'/mailform-shape-'.bin2hex(random_bytes(8));
        $theme = $root.'/resources/views/theme/example';
        mkdir($theme.'/css', 0700, true);
        try {
            file_put_contents($theme.'/base.txt', 'unchanged');
            file_put_contents($theme.'/css/layout.css', 'p { color: green }');
            file_put_contents($theme.'/index.blade.php', '<main><h1 class="kept">{{ $form_title }}</h1>@include("theme.form")</main>');
            file_put_contents($theme.'/thanks.blade.php', '<section><p class="receipt">{{ $form->thanks_message }}</p></section>');
            $manifest = ['resources/views/theme/gray/base.txt' => hash('sha256', 'unchanged')];
            $scanner = new CustomThemeScanner($root, $manifest);
            foreach ([
                [str_repeat('a', ThemeTemplateReader::MAX_BYTES + 1), '256 KiB'],
                [str_repeat('<span></span>', 4097), '4096'],
                ['<form><div>{{ $field->name', '式の終端'],
                ['<form>@if($mode)<input type="text"></form>', '閉じていない'],
                ["<form>\0</form>", 'UTF-8'],
                ['<p>Unrecognized structure</p>', '項目の行'],
            ] as [$source, $detail]) {
                file_put_contents($theme.'/form.blade.php', $source);
                $scan = $scanner->scan('example');
                $this->assertSame([], $scan['errors']);
                $this->assertSame('kept', $scan['structure']['title'][0]['class']);
                $this->assertSame('receipt', $scan['structure']['thanks_message'][0]['class']);
                $this->assertArrayNotHasKey('form', $scan['structure']);
                $this->assertArrayNotHasKey('text', $scan['structure']);
                $this->assertSame(['css/layout.css'], $scan['stylesheets']);
                $this->assertArrayHasKey('css/layout.css', $scan['assets']);
                $warnings = implode("\n", $scan['warnings']);
                $this->assertStringContainsString($detail, $warnings);
                $this->assertStringContainsString('標準の表示', $warnings);
                $this->assertStringNotContainsString('form.blade.php:1 動作ファイル全体', $warnings);
            }
        } finally {
            (new Filesystem)->deleteDirectory($root);
        }
    }
}
