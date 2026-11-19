<?php

namespace Tests\Feature;

use App\Services\EasyMail\CustomThemeScanner;
use App\Services\ThemeAssets;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportedStylesheetTest extends TestCase
{
    public function test_vendor_spellings_keep_their_grammar_declaration_order_and_priority(): void
    {
        $source = <<<'CSS'
            .form {
                -webkit-box-sizing: border-box;
                -moz-box-sizing: border-box;
                -ms-transform: translateX(8px);
                -webkit-transform: scale(0.9);
                position: -webkit-sticky;
                background-image: -webkit-linear-gradient(red, navy);
                display: -webkit-box;
                display: -moz-inline-box;
                display: -ms-flexbox;
                display: -webkit-flex;
                display: flex;
                -webkit-box-orient: vertical;
                -webkit-box-direction: reverse;
                -webkit-box-pack: justify;
                -moz-box-align: center;
                -webkit-box-flex: 1;
                -ms-flex-pack: distribute;
                -ms-flex-align: center;
                -ms-flex-item-align: auto;
                -ms-flex-line-pack: stretch;
                -ms-flex-positive: 2;
                -ms-flex-preferred-size: 3em;
                -webkit-appearance: none !important;
                -webkit-text-size-adjust: 100%;
            }
            CSS;
        $warnings = [];
        $style = ThemeAssets::prepare('css/form.css', $source, [], $warnings);
        $this->assertSame([], $warnings);
        foreach (['-webkit-box-sizing:border-box', '-moz-box-sizing:border-box', '-ms-transform:translateX(8px)',
            '-webkit-transform:scale(0.9)', 'position:-webkit-sticky', 'background-image:-webkit-linear-gradient(red, navy)',
            '-webkit-box-orient:vertical', '-webkit-box-direction:reverse', '-webkit-box-pack:justify',
            '-moz-box-align:center', '-webkit-box-flex:1', '-ms-flex-pack:distribute', '-ms-flex-align:center',
            '-ms-flex-item-align:auto', '-ms-flex-line-pack:stretch', '-ms-flex-positive:2', '-ms-flex-preferred-size:3em',
            '-webkit-appearance:none !important', '-webkit-text-size-adjust:100%'] as $declaration) {
            $this->assertStringContainsString($declaration, $style['bytes']);
        }
        $this->assertMatchesRegularExpression('/display:-webkit-box;\s*display:-moz-inline-box;\s*display:-ms-flexbox;\s*display:-webkit-flex;\s*display:flex;/', $style['bytes']);
        $this->assertSame($style, ThemeAssets::prepare('css/form.css', $style['bytes'], [], $warnings));
        $this->assertSame([], $warnings);
    }

    public function test_invalid_vendor_values_unknown_properties_and_indirect_resources_stay_excluded(): void
    {
        $warnings = [];
        $style = ThemeAssets::prepare('css/form.css', <<<'CSS'
            .widget {
                color: navy;
                -webkit-box-sizing: sideways;
                -ms-flex-pack: stretch;
                -webkit-appearance: none extra;
                -webkit-text-size-adjust: -7%;
                display: -webkit-box extra;
                -other-transform: none;
                -webkit-unknown: none;
                -webkit-background-image: url(../images/absent.svg);
                background-image: -webkit-image-set("unapproved.png" 1x);
                -webkit-transform: rotate(var(--angle));
                color: red !-webkit-important;
            }
            CSS, [], $warnings);
        $this->assertSame('.widget { color:navy; }', $style['bytes']);
        $this->assertNotEmpty($warnings);
    }

    public function test_a_media_list_retains_only_whole_supported_alternatives_and_the_manifest_logo(): void
    {
        $warnings = [];
        $image = ThemeAssets::prepare('images/logo_main.svg', '<svg xmlns="http://www.w3.org/2000/svg"><path d="M0 0L1 1"/></svg>', [], $warnings);
        $assets = ['images/logo_main.svg' => $image];
        $style = ThemeAssets::prepare('css/form.css', <<<'CSS'
            @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 2dppx) {
                .retina { background-image: url(../images/logo_main.svg); }
            }
            @media screen and (unsupported: 1), not (unsupported: 2), print, (min-width: 40em) {
                .paper { color: black }
            }
            @media not (unsupported: 3) { .wrong { color: red } }
            @media (unsupported: 4), (unsupported: 5) { .also-wrong { color: red } }
            CSS, $assets, $warnings);
        $this->assertNotNull($style);
        $this->assertMatchesRegularExpression('~@media\s*\(min-resolution:\s*2dppx\)\s*\{\s*\.retina\s*\{\s*background-image:url\("\.\./images/logo_main\.svg"\)~', $style['bytes']);
        $this->assertMatchesRegularExpression('~@media\s+print,\s*\(min-width:\s*40em\)\s*\{\s*\.paper~', $style['bytes']);
        foreach (['unsupported', 'device-pixel-ratio', 'screen', '.wrong', '.also-wrong'] as $removed) {
            $this->assertStringNotContainsString($removed, $style['bytes']);
        }
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('6 件', $warnings[0]);
        $this->assertStringContainsString('css/form.css:1:', $warnings[0]);
        $editedWarnings = [];
        $this->assertSame($style, ThemeAssets::prepare('css/form.css', $style['bytes'], $assets, $editedWarnings));
        $this->assertSame([], $editedWarnings);
    }

    public function test_theme_css_diagnostics_count_each_kind_across_files_without_truncating_occurrences(): void
    {
        $root = sys_get_temp_dir().'/mailform-css-'.bin2hex(random_bytes(8));
        $directory = $root.'/resources/views/theme/custom';
        mkdir($directory.'/css', 0700, true);
        try {
            file_put_contents($directory.'/stock.txt', 'stock');
            file_put_contents($directory.'/css/a.css', ".one { color: green;\n".str_repeat("ime-mode: auto;\n", 31)."}\nselect::-ms-expand { display:none }");
            file_put_contents($directory.'/css/b.css', "p { font-feature-setting: normal; background:transparent no-scroll }\np::-moz-selection { color:red }");
            $scanner = new CustomThemeScanner($root, ['resources/views/theme/one_column/stock.txt' => hash('sha256', 'stock')]);
            $scan = $scanner->scan('custom');
            $this->assertSame([], $scan['errors']);
            $this->assertCount(3, $scan['warnings']);
            $warnings = implode("\n", $scan['warnings']);
            $this->assertMatchesRegularExpression('/^(?=.*32 件)(?=.*css\/a\.css:2:).*$/m', $warnings);
            $this->assertMatchesRegularExpression('/^(?=.*2 件)(?=.*css\/a\.css:34:).*$/m', $warnings);
            $this->assertMatchesRegularExpression('/^(?=.*1 件)(?=.*css\/b\.css:1:).*$/m', $warnings);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
