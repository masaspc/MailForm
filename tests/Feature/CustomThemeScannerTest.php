<?php

namespace Tests\Feature;

use App\Services\EasyMail\CustomThemeScanner;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CustomThemeScannerTest extends TestCase
{
    private string $root;
    private string $themes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/mailform-theme-'.bin2hex(random_bytes(8));
        $this->themes = $this->root.'/resources/views/theme';
        mkdir($this->themes, 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function writeThemeFile(string $path, string $bytes, string $theme = '受付.2020'): void
    {
        $file = $this->themes.'/'.$theme.'/'.$path;
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
        }
        file_put_contents($file, $bytes);
    }

    private function manifest(array $themes): array
    {
        $manifest = [];
        foreach ($themes as $theme => $files) {
            foreach ($files as $path => $bytes) {
                $manifest["resources/views/theme/{$theme}/{$path}"] = hash('sha256', $bytes);
            }
        }
        return $manifest;
    }

    public function test_it_compares_a_renamed_theme_against_each_real_release_manifest(): void
    {
        // A transparent one-pixel GIF also shipped in the gradation theme of all four releases.
        $this->writeThemeFile('images/common/space.gif', base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw=='));
        $this->writeThemeFile('js/extra.js', '/* synthetic customization */');
        $this->writeThemeFile('form.blade.php', '<p>Synthetic form override</p>');
        $fingerprints = [];
        foreach (['2.00.124', '2.00.125', '2.00.133', '2.01.004'] as $version) {
            $manifest = json_decode(file_get_contents(resource_path("easymail/{$version}-sha256.json")), true, flags: JSON_THROW_ON_ERROR);
            $scan = (new CustomThemeScanner($this->root, $manifest))->scan('受付.2020');
            $this->assertSame([], $scan['errors']);
            $this->assertSame('gradation', $scan['layout'], $version);
            $this->assertSame(1, $scan['score']);
            $this->assertSame(['form.blade.php'], $scan['changes']['changed']);
            $this->assertSame(['js/extra.js'], $scan['changes']['added']);
            $this->assertContains('reply_mail_for_admin.blade.php', $scan['changes']['removed']);
            $this->assertArrayHasKey('images/common/space.gif', $scan['assets']);
            $behavior = implode("\n", $scan['behaviors']);
            foreach (['js/extra.js', 'form.blade.php', 'index.blade.php', 'thanks.blade.php', 'reply_mail_for_admin.blade.php'] as $file) {
                $this->assertStringContainsString($file, $behavior);
            }
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $scan['fingerprint']);
            $fingerprints[] = $scan['fingerprint'];
        }
        $this->assertCount(4, array_unique($fingerprints));
    }

    public function test_it_reports_ties_and_requires_positive_evidence_at_the_same_relative_path(): void
    {
        $this->writeThemeFile('form.blade.php', 'common');
        $this->writeThemeFile('stray.txt', 'one match at the wrong path');
        $base = ['form.blade.php' => 'common', 'original.txt' => 'one match at the wrong path'];
        $scanner = new CustomThemeScanner($this->root, $this->manifest(['two_column01' => $base, 'one_column' => $base]));
        $scan = $scanner->scan('受付.2020');
        $this->assertSame('one_column', $scan['layout']);
        $this->assertSame(1, $scan['score']);
        $this->assertStringContainsString('two_column01', implode("\n", $scan['warnings']));
        $this->assertSame($scan['fingerprint'], $scanner->scan('受付.2020')['fingerprint']);
        $this->writeThemeFile('stray.txt', 'changed another byte');
        $this->assertNotSame($scan['fingerprint'], $scanner->scan('受付.2020')['fingerprint']);
        $this->writeThemeFile('form.blade.php', 'different');
        $scan = $scanner->scan('受付.2020');
        $this->assertNull($scan['layout']);
        $this->assertNotEmpty($scan['errors']);
    }

    public function test_it_lists_unknown_directories_and_refuses_unsafe_or_incomplete_reads(): void
    {
        $this->writeThemeFile('base.txt', 'base');
        mkdir($this->themes.'/.hidden');
        mkdir($this->themes.'/one_column');
        symlink($this->themes.'/one_column', $this->themes.'/linked');
        $scanner = new CustomThemeScanner($this->root, $this->manifest(['one_column' => ['base.txt' => 'base']]));
        $this->assertSame(['.hidden', 'linked', '受付.2020'], $scanner->names());
        foreach (['../one_column', '.hidden', 'linked', 'missing'] as $name) {
            $this->assertNotEmpty($scanner->scan($name)['errors'], $name);
        }
        symlink($this->root, $this->themes.'/受付.2020/assets');
        $this->assertNotEmpty($scanner->scan('受付.2020')['errors']);
        unlink($this->themes.'/受付.2020/assets');
        link($this->themes.'/受付.2020/base.txt', $this->root.'/second-link');
        $this->assertNotEmpty($scanner->scan('受付.2020')['errors']);
        unlink($this->root.'/second-link');
        $file = fopen($this->themes.'/受付.2020/large.bin', 'wb');
        ftruncate($file, 40 * 1024 * 1024);
        fclose($file);
        $this->assertNotEmpty($scanner->scan('受付.2020')['errors']);
    }

    public function test_only_static_markup_verified_images_and_converted_styles_survive(): void
    {
        $stock = [
            'base.txt' => 'stock',
            'css/stock.css' => 'body {color: black}',
            'header.blade.php' => '<p>Old header</p>',
            'footer.blade.php' => '<p>Old footer</p>',
            'layout.blade.php' => '<html><body>@yield("content")</body></html>',
        ];
        foreach ($stock as $file => $bytes) {
            $this->writeThemeFile($file, $bytes, 'one_column');
            $this->writeThemeFile($file, $bytes);
        }
        $this->writeThemeFile('images/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+X2S8AAAAASUVORK5CYII='));
        $this->writeThemeFile('header.blade.php', <<<'HTML'
            <header>
            <p onclick="BAD_EVENT">日本語 <strong>案内</strong></p>
            <img src="{{ $data->theme_path }}images/logo.png" alt="会社">
            <img src="https://example.invalid/leak" alt="BAD_REMOTE">
            <?php
               echo 'BAD_PHP';
            ?>
            @if(legacy_check(
                other(')')))
              <p>BAD_CONDITION</p>
            @endif
            <script>
              badScript();
            </script>
            <p>{{ unknown(
                'BAD_BLADE') }}末尾</p>
            </header>
            HTML);
        $this->writeThemeFile('footer.blade.php', '<footer><a href="https://example.test/contact">連絡先</a><img src="{{ asset(\'theme/受付.2020/images/logo.png\') }}" alt="印"></footer>');
        $this->writeThemeFile('layout.blade.php', <<<'HTML'
            <html><head>
            <link rel="stylesheet" href="{{ $data->theme_path }}css/z.css">
            <link rel="stylesheet" href="{{ asset('css/a.css') }}">
            </head><body>
            <header><p>前</p>@include("theme.{$data->theme_name}.header")<p>後</p></header>
            @yield("content")
            <footer><p>固定</p>@include("theme.{$data->theme_name}.footer")</footer>
            </body></html>
            HTML);
        $this->writeThemeFile('css/a.css', 'p { color: blue }');
        $this->writeThemeFile('css/z.css', 'p { color: red }');
        $this->writeThemeFile('css/ignored.scss', '$source: hidden;');
        $this->writeThemeFile('css/a.css.map', '{}');
        $scan = (new CustomThemeScanner($this->root, $this->manifest(['one_column' => $stock])))->scan('受付.2020');
        $this->assertSame([], $scan['errors']);
        $this->assertSame([], $scan['behaviors']);
        $this->assertStringContainsString('<p>日本語 <strong>案内</strong></p>', $scan['header']);
        $this->assertStringContainsString('<img src="theme:images/logo.png" alt="会社">', $scan['header']);
        $this->assertStringContainsString('<p>末尾</p>', $scan['header']);
        $this->assertLessThan(strpos($scan['header'], '案内'), strpos($scan['header'], '前'));
        $this->assertGreaterThan(strpos($scan['header'], '案内'), strpos($scan['header'], '後'));
        $this->assertSame(1, substr_count($scan['header'], '案内'));
        $this->assertStringContainsString('印', $scan['footer']);
        $this->assertStringContainsString('href="https://example.test/contact"', $scan['footer']);
        $this->assertSame('images/logo.png', $scan['logo']);
        $this->assertSame(['css/z.css', 'css/a.css'], $scan['stylesheets']);
        $this->assertSame(['images/logo.png', 'css/z.css', 'css/a.css'], array_keys($scan['assets']));
        $warnings = implode("\n", $scan['warnings']);
        foreach ([2, 4, 5, 8, 12, 15] as $line) {
            $this->assertStringContainsString("header.blade.php:{$line}", $warnings);
        }
        foreach (['BAD_', 'badScript', '<?', '@if', 'unknown(', 'onclick'] as $marker) {
            $this->assertStringNotContainsString($marker, $scan['header']);
        }
        $this->assertStringNotContainsString('BAD_', $warnings);
    }

    public function test_static_extraction_omits_content_guarded_by_custom_blade_and_mixed_php_blocks(): void
    {
        $this->writeThemeFile('base.txt', 'matching');
        $this->writeThemeFile('footer.blade.php', <<<'HTML'
            <p>公開</p>
            @officeOnly()
            <p>条件付きの案内</p>
            @endOfficeOnly
            <?php if ($visible): ?>
            <p>PHPで制御される案内</p>
            <?php endif; ?>
            <p>署名</p>
            HTML);
        $scanner = new CustomThemeScanner($this->root, $this->manifest(['gray' => ['base.txt' => 'matching']]));
        $scan = $scanner->scan('受付.2020');
        $this->assertSame([], $scan['errors']);
        $this->assertStringContainsString('<p>公開</p>', $scan['footer']);
        $this->assertStringContainsString('<p>署名</p>', $scan['footer']);
        $this->assertStringNotContainsString('条件付き', $scan['footer']);
        $this->assertStringNotContainsString('PHPで制御', $scan['footer']);
        $this->assertStringContainsString('footer.blade.php:2', implode("\n", $scan['warnings']));
        $this->assertStringContainsString('footer.blade.php:5', implode("\n", $scan['warnings']));
    }

    public function test_it_reports_changed_or_removed_layout_logic_but_reuses_the_verified_stock_scaffolding(): void
    {
        $old = "<html><body>@yield('content')\n<script>stock();</script>\n</body></html>";
        $this->writeThemeFile('layout.blade.php', $old, 'gray');
        $this->writeThemeFile('base.txt', 'matching');
        $scanner = new CustomThemeScanner($this->root, $this->manifest(['gray' => ['base.txt' => 'matching', 'layout.blade.php' => $old]]));
        foreach ([
            str_replace('@yield', '<header><p>静的見出し</p></header>@yield', $old) => false,
            str_replace('stock();', 'different();', $old) => true,
            str_replace('<script>stock();</script>', '', $old) => true,
        ] as $source => $behavior) {
            $this->writeThemeFile('layout.blade.php', $source);
            $scan = $scanner->scan('受付.2020');
            $this->assertSame($behavior, $scan['behaviors'] !== [], $source);
            if ($behavior) {
                $this->assertStringContainsString('layout.blade.php', implode("\n", $scan['behaviors']));
            }
        }
    }

    public function test_allowed_static_class_selectors_can_still_style_header_text(): void
    {
        $this->writeThemeFile('base.txt', 'matching');
        $this->writeThemeFile('header.blade.php', '<header class="header company"><h1 class="header__siteTitle" data-next="value">見出し</h1></header>');
        $scanner = new CustomThemeScanner($this->root, $this->manifest(['gray' => ['base.txt' => 'matching']]));
        $scan = $scanner->scan('受付.2020');
        $this->assertStringContainsString('class="company"', $scan['header']);
        $this->assertStringNotContainsString('class="header ', $scan['header']);
        $this->assertStringContainsString('class="header__siteTitle"', $scan['header']);
        $this->assertStringNotContainsString('data-next', $scan['header']);
        $this->assertStringContainsString('header.blade.php:1', implode("\n", $scan['warnings']));
    }

    public function test_content_exceeding_the_editor_or_database_limits_is_blocked_before_persistence(): void
    {
        $this->writeThemeFile('base.txt', 'matching');
        $scanner = new CustomThemeScanner($this->root, $this->manifest(['gray' => ['base.txt' => 'matching']]));
        foreach ([str_repeat('a', 20001), str_repeat('🙂', 17000)] as $oversized) {
            $this->writeThemeFile('header.blade.php', $oversized);
            $scan = $scanner->scan('受付.2020');
            $this->assertNotEmpty($scan['errors']);
            $this->assertSame('', $scan['header']);
        }
    }
}
