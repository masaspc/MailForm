<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\User;
use App\Services\EasyMail\CustomThemeScanner;
use App\Services\SafeMarkup;
use App\Services\ThemeAssets;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LinkedThemeLogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_linked_css_logo_keeps_its_desktop_and_mobile_slot_after_import_and_admin_replacement(): void
    {
        Storage::fake('local');
        $root = sys_get_temp_dir().'/mailform-linked-logo-'.bin2hex(random_bytes(6));
        $dir = $root.'/resources/views/theme/garden';
        mkdir($dir.'/images', 0700, true);
        mkdir($dir.'/css');
        try {
            file_put_contents($dir.'/stock.txt', 'stock');
            file_put_contents($dir.'/header.blade.php', '<p id="logo"><a href="https://example.test/">Garden</a></p><p id="name">Hours</p>');
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 2"><rect width="8" height="2" fill="red"/></svg>';
            file_put_contents($dir.'/images/brand.svg', $svg);
            file_put_contents($dir.'/images/another-logo.svg', str_replace('red', 'green', $svg));
            file_put_contents($dir.'/css/style.css', <<<'CSS'
                .unused-link, #logo a {display:block; background: url(../images/brand.svg) no-repeat center; width:160px; height:40px; text-indent:100%; overflow:hidden}
                @media (max-width: 600px) { #logo a { width:80px; height:20px } }
                CSS);
            $scan = (new CustomThemeScanner($root, ['resources/views/theme/one_column/stock.txt' => hash('sha256', 'stock')]))->scan('garden');
        } finally {
            File::deleteDirectory($root);
        }
        $this->assertSame([], $scan['errors']);
        $this->assertSame('images/brand.svg', $scan['logo']);
        $warnings = [];
        $stored = $scan['assets']['css/style.css'];
        $this->assertSame($stored, ThemeAssets::prepare('css/style.css', $stored['bytes'], $scan['assets'], $warnings));
        $this->assertSame([], $warnings);
        $theme = new Theme(['name' => 'Garden', 'slug' => 'garden', 'layout' => 'one_column', 'header' => $scan['header'],
            'logo' => $scan['logo'], 'assets' => array_map(ThemeAssets::store(...), $scan['assets'])]);
        $theme->forceFill(['import_report' => $scan, 'reviewed_at' => now()])->save();
        $theme->refresh();
        $this->assertFalse(SafeMarkup::hasUnsupported($theme->header, $theme));
        $header = SafeMarkup::themeHeader($theme, 'Fallback')->toHtml();
        $doc = new DOMDocument();
        $doc->loadHTML('<header>'.$header.'</header><main><input id="logo"><input id="name"></main>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($doc);
        $this->assertCount(1, $xpath->query('//*[@id="name"]'));
        $this->assertCount(1, $xpath->query('//*[@id="logo"]'));
        $this->assertCount(0, $xpath->query('//header//img'));
        $this->assertCount(1, $xpath->query('//header//*[contains(@class,"mailform-header-logo")]/a[@href="https://example.test/" and text()="Garden"]'));
        $url = '/_theme/garden/file/css/style.css';
        $css = $this->get($url)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~\.mailform-header-logo a\s*\{[^}]*\.\./images/brand\.svg[^}]*width:160px;[^}]*height:40px~', $css);
        $this->assertMatchesRegularExpression('~@media\s*\(max-width:\s*600px\)[^{]*\{[^}]*\.mailform-header-logo a\s*\{[^}]*width:80px;[^}]*height:20px~', $css);
        $this->get('/_theme/garden/file/images/brand.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');

        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $data = ['slug' => 'garden', 'name' => 'Garden', 'layout' => 'one_column', 'header' => $theme->header];
        $this->post('/admin/themes', $data + ['version' => $theme->editorVersion(), 'logo' => 'images/another-logo.svg'])
            ->assertRedirect('/admin/themes/garden/edit')->assertSessionHasNoErrors();
        $theme->refresh();
        $this->assertSame('images/another-logo.svg', $theme->logo);
        $this->assertSame($header, SafeMarkup::themeHeader($theme, 'Fallback')->toHtml());
        $css = $this->get($url)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~\.mailform-header-logo a\s*\{\s*background-image:\s*url\("/_theme/garden/file/images/another-logo\.svg"\)\s*!important~', $css);
        $this->get('/_theme/garden/file/images/another-logo.svg')->assertOk()->assertSee('green');
        $this->post('/admin/themes', $data + ['version' => $theme->editorVersion(), 'logo' => ''])->assertSessionHasNoErrors();
        $css = $this->get($url)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~\.mailform-header-logo a\s*\{\s*background-image:\s*none\s*!important;\s*text-indent:\s*0\s*!important~', $css);
    }

    public function test_a_stored_stylesheet_without_a_linked_logo_keeps_its_fingerprint(): void
    {
        $warnings = [];
        $css = '#logo a { color:navy; }';
        $asset = ThemeAssets::prepare('css/legacy.css', $css, [], $warnings);
        $this->assertSame(hash('sha256', $css), $asset['hash']);
        $this->assertSame([], $warnings);
    }
}
