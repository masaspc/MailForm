<?php

namespace Tests\Feature;

use App\Services\ThemeAssets;
use DOMDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImportedSvgTest extends TestCase
{
    public function test_illustrator_metadata_and_an_unreferenced_japanese_id_leave_the_drawing_intact(): void
    {
        $source = <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" xmlns:editor="urn:editor" viewBox="0 0 32 16">
              <g id="グループ_8" data-name="グループ 8" editor:layer="8" transform="translate(2 3)">
                <path d="M0 0 L12 4 Z" fill="#246" data-order="1"/>
              </g>
            </svg>
            SVG;
        $warnings = [];
        $asset = ThemeAssets::prepare('images/logo.svg', $source, [], $warnings);
        $this->assertNotNull($asset);
        $this->assertSame([], $warnings);
        $document = new DOMDocument();
        $this->assertTrue($document->loadXML($asset['bytes']));
        $group = $document->getElementsByTagName('g')->item(0);
        $path = $document->getElementsByTagName('path')->item(0);
        $this->assertSame('0 0 32 16', $document->documentElement->getAttribute('viewBox'));
        $this->assertSame('translate(2 3)', $group->getAttribute('transform'));
        $this->assertFalse($group->hasAttribute('id'));
        $this->assertFalse($group->hasAttribute('data-name'));
        $this->assertFalse($group->hasAttributeNS('urn:editor', 'layer'));
        $this->assertSame('M0 0 L12 4 Z', $path->getAttribute('d'));
        $this->assertSame('#246', $path->getAttribute('fill'));
        $this->assertFalse($path->hasAttribute('data-order'));
    }

    public function test_local_drawing_gradient_and_clip_references_resolve_to_repeatable_ascii_ids(): void
    {
        $source = <<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">
              <use href="#グループ_8"/><use xlink:href="#グループ_8" x="10"/>
              <defs>
                <linearGradient id="tone" xlink:href="#色"><stop offset="1" stop-color="white"/></linearGradient>
                <linearGradient id="色"><stop offset="0" stop-color="blue"/></linearGradient>
                <clipPath id="切り抜き"><rect width="10" height="10"/></clipPath>
                <g id="グループ_8" data-name="グループ 8" clip-path='url("#切り抜き")'>
                  <path d="M0 0 L8 8 Z" style="fill:url( '#色' );stroke:url(#tone)"/>
                </g>
              </defs>
              <g id="svg-id-1"/>
            </svg>
            SVG;
        $warnings = [];
        $asset = ThemeAssets::prepare('images/logo.svg', $source, [], $warnings);
        $this->assertNotNull($asset);
        $this->assertSame($asset, ThemeAssets::prepare('images/logo.svg', $source, [], $warnings));
        $this->assertSame($asset, ThemeAssets::prepare('images/logo.svg', $asset['bytes'], [], $warnings));
        $this->assertSame([], $warnings);

        $document = new DOMDocument();
        $this->assertTrue($document->loadXML($asset['bytes']));
        $groups = $document->getElementsByTagName('g');
        $gradients = $document->getElementsByTagName('linearGradient');
        $uses = $document->getElementsByTagName('use');
        $group = $groups->item(0);
        $groupId = $group->getAttribute('id');
        $paintId = $gradients->item(1)->getAttribute('id');
        $clipId = $document->getElementsByTagName('clipPath')->item(0)->getAttribute('id');
        foreach ([$groupId, $paintId, $clipId] as $id) {
            $this->assertMatchesRegularExpression('/\A[A-Za-z_][A-Za-z0-9_.-]*\z/', $id);
        }
        $this->assertCount(5, array_unique([$groupId, $paintId, $clipId, 'svg-id-1', 'tone']));
        $this->assertSame('svg-id-1', $groups->item(1)->getAttribute('id'));
        $this->assertSame('tone', $gradients->item(0)->getAttribute('id'));
        $this->assertSame('#'.$groupId, $uses->item(0)->getAttribute('href'));
        $this->assertSame('#'.$groupId, $uses->item(1)->getAttributeNS('http://www.w3.org/1999/xlink', 'href'));
        $this->assertSame('#'.$paintId, $gradients->item(0)->getAttributeNS('http://www.w3.org/1999/xlink', 'href'));
        $this->assertSame('url(#'.$clipId.')', $group->getAttribute('clip-path'));
        $path = $group->getElementsByTagName('path')->item(0);
        $this->assertSame('url(#'.$paintId.')', $path->getAttribute('fill'));
        $this->assertSame('url(#tone)', $path->getAttribute('stroke'));
    }

    public static function forbiddenParts(): array
    {
        return [
            'script' => ['<script/>'],
            'event' => ['<g onload="unused"/>'],
            'foreign object' => ['<foreignObject/>'],
            'image' => ['<image href="old/logo.png"/>'],
            'unsupported element' => ['<unknown/>'],
            'remote paint' => ['<path fill="url(https://example.invalid/colors)"/>'],
            'remote inline paint' => ['<path style="fill:url(../colors.svg#color)"/>'],
            'remote CSS image' => ['<g style="background-image:image-set(&quot;images/other.png&quot; 1x)"/>'],
            'external href' => ['<linearGradient href="other.svg#color"/>'],
            'external xlink href' => ['<use xlink:href="//example.invalid/logo.svg#mark"/>'],
            'external base' => ['<g xml:base="other.svg"/>'],
        ];
    }

    public function test_local_paint_with_a_fallback_color_survives_unrelated_editor_settings(): void
    {
        $source = <<<'SVG'
            <svg><defs><linearGradient id="試作'色"/></defs>
              <rect width="10" height="5" style="enable-background:new;fill:url(&quot;#試作'色&quot;) #246"
                    stroke="url(#未定義) red"/>
            </svg>
            SVG;
        $warnings = [];
        $asset = ThemeAssets::prepare('images/logo.svg', $source, [], $warnings);
        $this->assertNotNull($asset);
        $document = new DOMDocument();
        $this->assertTrue($document->loadXML($asset['bytes']));
        $id = $document->getElementsByTagName('linearGradient')->item(0)->getAttribute('id');
        $this->assertMatchesRegularExpression('/\A[A-Za-z_][A-Za-z0-9_.-]*\z/', $id);
        $rect = $document->getElementsByTagName('rect')->item(0);
        $this->assertSame('url(#'.$id.') #246', $rect->getAttribute('fill'));
        $this->assertSame('red', $rect->getAttribute('stroke'));
        $this->assertStringNotContainsString('enable-background', $asset['bytes']);
    }

    #[DataProvider('forbiddenParts')]
    public function test_forbidden_content_rejects_the_entire_svg_and_reports_its_line(string $part): void
    {
        $source = "<svg xmlns=\"http://www.w3.org/2000/svg\" xmlns:xlink=\"http://www.w3.org/1999/xlink\">\n"
            ."<path d=\"M0 0 L8 8 Z\"/>\n".$part."\n</svg>";
        $warnings = [];
        $this->assertNull(ThemeAssets::prepare('images/logo.svg', $source, [], $warnings));
        $this->assertStringContainsString('images/logo.svg:3', implode(' ', $warnings));
    }

    public function test_local_references_respect_the_existing_drawing_limits(): void
    {
        foreach ([
            '<g id="part"><use href="#part"/></g>',
            '<defs><g id="part">'.str_repeat('<circle r="1"/>', 2000).'</g></defs><use href="#part"/>',
        ] as $drawing) {
            $warnings = [];
            $this->assertNull(ThemeAssets::prepare('images/logo.svg', '<svg>'.$drawing.'</svg>', [], $warnings));
            $this->assertNotEmpty($warnings);
        }
    }
}
