<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\User;
use App\Services\ThemeAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ImportedThemeAssetsTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/aS8AAAAASUVORK5CYII=';
    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 32"><title>社章</title><g fill="#235698"><path d="M0 0 L20 20 Z"/></g></svg>';

    public function test_css_retains_safe_rules_and_rewrites_only_manifest_images_relative_to_its_file(): void
    {
        $warnings = [];
        $image = ThemeAssets::prepare('images/青 ロゴ.png', base64_decode(self::PNG), [], $warnings);
        $css = <<<'CSS'
            @import "unapproved.css";
            @font-face { font-family: hidden; src: url(other.woff2); }
            .banner { color: #263748; background: white url("../../images/./青 ロゴ.png") no-repeat center; }
            @media (max-width: 640px) { .banner { padding: 12px; } }
            .outside { background-image: url("https://example.invalid/not-in-manifest.png"); }
            .missing { background-image: url(../../images/missing.png); }
            .indirect { background-image: image-set("unapproved.png" 1x); }
            .unknown { width: expression(1); }
            CSS;
        $asset = ThemeAssets::prepare('css/nested/theme.css', $css, ['images/青 ロゴ.png' => $image], $warnings);
        $this->assertSame('text/css', $asset['mime']);
        $this->assertSame(hash('sha256', $asset['bytes']), $asset['hash']);
        foreach (['.banner', '#263748', '../../images/%E9%9D%92%20%E3%83%AD%E3%82%B4.png', '@media', '640px', '12px'] as $retained) {
            $this->assertStringContainsString($retained, $asset['bytes']);
        }
        foreach (['@import', '@font-face', 'example.invalid', 'missing.png', 'unapproved', 'expression', 'image-set'] as $dropped) {
            $this->assertStringNotContainsString($dropped, $asset['bytes']);
        }
        $this->assertNotEmpty($warnings);
        foreach ($warnings as $warning) {
            $this->assertStringContainsString('css/nested/theme.css', $warning);
        }
        $editWarnings = [];
        $edited = ThemeAssets::prepare('css/nested/theme.css', $asset['bytes'], ['images/青 ロゴ.png' => $image], $editWarnings);
        $this->assertSame($asset, $edited);
        $this->assertSame([], $editWarnings);
    }

    public function test_an_unusable_path_format_or_file_is_rejected_with_a_review_warning(): void
    {
        $png = base64_decode(self::PNG);
        $cases = [
            '../logo.png' => $png, '/logo.png' => $png, 'x/.private/logo.png' => $png,
            'logo.php' => $png, 'not-a-png.png' => 'A text document', 'wrong-extension.gif' => $png,
            'truncated.png' => substr($png, 0, 25), 'too-big.css' => str_repeat(' ', 262145),
            'processing.svg' => '<?ignored sample?>'.self::SVG,
            'external.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><image href="https://example.invalid/p.png"/></svg>',
            'unknown-element.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject/></svg>',
            'unknown-attribute.svg' => '<svg xmlns="http://www.w3.org/2000/svg" onload="unused"/>',
            'document-type.svg' => '<!DOCTYPE svg>'.self::SVG,
            'external-fill.svg' => '<svg xmlns="http://www.w3.org/2000/svg"><path fill="url(https://example.invalid/color)"/></svg>',
            'huge-canvas.svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="100000" height="100000"/>',
        ];
        foreach ($cases as $path => $bytes) {
            $warnings = [];
            $this->assertNull(ThemeAssets::prepare($path, $bytes, [], $warnings), $path);
            $this->assertNotEmpty($warnings, $path);
        }
    }

    public function test_local_css_image_cache_query_maps_to_the_stored_asset(): void
    {
        $warnings = [];
        $image = ThemeAssets::prepare('images/mark.png', base64_decode(self::PNG), [], $warnings);
        $asset = ThemeAssets::prepare('css/print.css', '.brand{background-image:url(../images/mark.png?v=42)}', ['images/mark.png' => $image], $warnings);
        $this->assertSame([], $warnings);
        $this->assertStringContainsString('../images/mark.png', $asset['bytes']);
        $this->assertStringNotContainsString('?v=', $asset['bytes']);
    }

    public function test_svg_logo_keeps_its_local_artwork_when_active_parts_are_removed(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="unused"><script>legacy sample</script>'
            .'<image href="https://example.invalid/old.png"/><path d="M0 0 L2 2 Z" fill="blue"/></svg>';
        $warnings = [];
        $asset = ThemeAssets::prepare('images/logo.svg', $svg, [], $warnings);
        $this->assertNotNull($asset);
        $this->assertStringContainsString('fill="blue"', $asset['bytes']);
        foreach (['script', 'onload', 'example.invalid', 'legacy sample'] as $removed) {
            $this->assertStringNotContainsString($removed, $asset['bytes']);
        }
        $this->assertStringContainsString('images/logo.svg:1', implode(' ', $warnings));
    }

    public function test_static_svg_gradients_and_presentation_attributes_are_preserved_without_source_metadata(): void
    {
        $warnings = [];
        $source = <<<'SVG'
            <?xml version="1.0" encoding="UTF-8"?>
            <svg xmlns="http://www.w3.org/2000/svg" width="80" height="32">
            <!-- Illustrator source comment -->
            <defs><linearGradient id="tint"><stop offset="0%" stop-color="#234567"/></linearGradient></defs>
            <path d="M0,0 L80,32 Z" style="fill:url(#tint);stroke-width:2"/>
            </svg>
            SVG;
        $asset = ThemeAssets::prepare('company.svg', $source, [], $warnings);
        $this->assertSame([], $warnings);
        $this->assertSame('image/svg+xml', $asset['mime']);
        $this->assertStringContainsString('fill="url(#tint)"', $asset['bytes']);
        $this->assertStringContainsString('stroke-width="2"', $asset['bytes']);
        $this->assertStringNotContainsString('source comment', $asset['bytes']);
        $this->assertStringNotContainsString('<?', $asset['bytes']);
    }

    public function test_content_addressed_storage_deduplicates_across_themes_and_excludes_damaged_blobs(): void
    {
        $disk = Storage::fake('local');
        $warnings = [];
        $png = base64_decode(self::PNG);
        $stock = ThemeAssets::prepare('images/logo.png', $png, [], $warnings);
        $copied = ThemeAssets::prepare('custom/社章.PNG', $png, [], $warnings);
        $first = ThemeAssets::store($stock);
        $this->assertSame(['hash' => hash('sha256', $png), 'mime' => 'image/png'], $first);
        $this->assertSame($first, ThemeAssets::store($copied));
        $files = $disk->allFiles();
        $this->assertCount(1, $files);
        $this->assertSame($files[0], ThemeAssets::storagePath($first['hash']));
        $this->assertSame($png, ThemeAssets::read($first));
        $this->assertTrue(ThemeAssets::intact($first));
        $disk->put($files[0], 'damaged');
        $this->assertFalse(ThemeAssets::intact($first));
        $this->assertNull(ThemeAssets::read($first));
        $this->expectException(RuntimeException::class);
        ThemeAssets::store($stock);
    }

    public function test_asset_responses_require_a_referenced_reviewed_or_manual_theme_and_confine_direct_svg_views(): void
    {
        Storage::fake('local');
        $warnings = [];
        $svg = ThemeAssets::store(ThemeAssets::prepare('images/紋章.svg', self::SVG, [], $warnings));
        $this->assertSame([], $warnings);
        $theme = new Theme(['slug' => 'vintage', 'name' => '旧テーマ', 'layout' => 'one_column',
            'assets' => ['images/紋章.svg' => $svg, 'wrong.css' => $svg]]);
        $theme->forceFill(['import_report' => ['source' => 'vintage']])->save();
        $url = '/_theme/vintage/file/images/%E7%B4%8B%E7%AB%A0.svg';
        $this->get($url)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => 'viewer']))->get($url)->assertNotFound();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get($url)->assertOk()->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertSee('社章');
        $theme->forceFill(['reviewed_at' => now()])->save();
        auth()->logout();
        $response = $this->get($url)->assertOk();
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->get('/_theme/vintage/file/wrong.css')->assertNotFound();
        $this->get('/_theme/vintage/file/images/missing.svg')->assertNotFound();
        $this->get('/_theme/other/file/images/%E7%B4%8B%E7%AB%A0.svg')->assertNotFound();
        $this->get('/_theme/vintage/file/'.$svg['hash'])->assertNotFound();
        $theme->forceFill(['import_report' => null, 'reviewed_at' => null])->save();
        $this->get($url)->assertOk();
    }

    public function test_asset_links_keep_the_front_controller_and_encode_each_logical_path_segment(): void
    {
        config(['app.url' => 'https://forms.example.test/申込/index.php']);
        $this->assertSame('/%E7%94%B3%E8%BE%BC/index.php/_theme/brand/file/images/blue%20brand%2B1.png',
            ThemeAssets::url('brand', 'images/blue brand+1.png'));
    }
}
