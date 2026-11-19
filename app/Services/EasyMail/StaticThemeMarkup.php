<?php

namespace App\Services\EasyMail;

use App\Services\SafeMarkup;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use RuntimeException;

/** Rebuild header/footer fragments using public SafeMarkup's inert formatting vocabulary and verified images. */
final class StaticThemeMarkup
{
    public array $warnings = [];
    public array $stylesheets = [];
    public bool $layoutBehavior = false;

    private const DISCARD = ['script', 'style', 'svg', 'math', 'iframe', 'object', 'embed', 'template', 'noscript', 'form', 'input', 'button', 'select', 'textarea', 'link', 'meta', 'base', 'video', 'audio', 'canvas'];

    private int $nodes;

    public function __construct(private string $theme, private array $images) {}

    public function partial(string $path, string $source): string
    {
        $reader = new ThemeTemplateReader($this->theme, $path);
        $document = $this->document($reader->literalHtml($source), true);
        $body = $document->getElementsByTagName('body')->item(0);
        $html = $body ? trim($this->children($body, $reader, [])) : '';
        array_push($this->warnings, ...$reader->warnings);
        return $html;
    }

    public function layout(string $path, string $source, array $parts, bool $changed, string $reference): array
    {
        $stock = new ThemeTemplateReader($this->theme, $path, report: false);
        if ($reference !== '') {
            $stock->literalHtml($reference);
        }
        $reader = new ThemeTemplateReader($this->theme, $path, $reference, $changed, $stock->behaviorCounts);
        $document = $this->document($reader->literalHtml($source), false);
        foreach ($document->getElementsByTagName('link') as $link) {
            if (in_array('stylesheet', preg_split('/\s+/', strtolower(trim($link->getAttribute('rel')))), true)) {
                // Legacy layouts often add a revision to a local stylesheet. Storage uses the file path.
                $href = preg_replace('/\?[a-z0-9_.=&-]{1,128}\z/i', '', $link->getAttribute('href'));
                $css = $reader->localPath($href);
                if ($css !== null) {
                    $this->stylesheets[] = $css;
                } else {
                    $reader->warn($reader->line($link->getLineNo()), '外部・動的なスタイルシートの参照を除外しました。', $link->getAttribute('href'));
                }
            }
        }
        foreach ($stock->behaviorCounts as $hash => $count) {
            if (($reader->behaviorCounts[$hash] ?? 0) < $count) {
                $reader->warn(1, '標準レイアウトの動作を削除・変更しています。', '', true);
                break;
            }
        }
        $fromLayout = [];
        $xpath = new DOMXPath($document);
        $query = '//header | //footer | //'.$reader->slotTag;
        foreach ($xpath->query($query) as $section) {
            // Nested slots are expanded in their own header/footer, once and in document order.
            for ($parent = $section->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
                if (in_array($parent->tagName, ['header', 'footer', 'head', $reader->slotTag, ...self::DISCARD], true)) {
                    continue 2;
                }
            }
            $part = $section->tagName === $reader->slotTag ? $section->getAttribute('part') : $section->tagName;
            $fromLayout[$part] = ($fromLayout[$part] ?? '').$this->node($section, $reader, $parts);
        }
        $this->layoutBehavior = $reader->behavior;
        array_push($this->warnings, ...$reader->warnings);
        return array_replace($parts, array_map('trim', $fromLayout));
    }

    private function document(string $html, bool $fragment): DOMDocument
    {
        $this->nodes = 0;
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8"?>'.($fragment ? '<html><body>'.$html.'</body></html>' : $html),
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        return $document;
    }

    private function children(DOMNode $node, ThemeTemplateReader $reader, array $parts): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $this->node($child, $reader, $parts);
        }
        return $html;
    }

    private function node(DOMNode $node, ThemeTemplateReader $reader, array $parts): string
    {
        if (++$this->nodes > 16384) {
            throw new RuntimeException('ヘッダー・フッターの HTML 要素数が読み取り上限を超えています。');
        }
        if ($node instanceof DOMText) {
            return e(str_replace($reader->omitted, '', $node->textContent));
        }
        if (!$node instanceof DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        $line = $reader->line($node->getLineNo());
        if ($tag === $reader->slotTag) {
            return $parts[$node->getAttribute('part')] ?? '';
        }
        if (in_array($tag, self::DISCARD, true)) {
            $reader->warn($line, '表示対象外の HTML 要素を除外しました。');
            return '';
        }
        $rawClass = $node->getAttribute('class');
        $classes = str_contains($rawClass, $reader->omitted) ? null : SafeMarkup::themeClasses($rawClass);
        $logoSlot = $node->getAttribute('id') === 'logo' && in_array($tag, SafeMarkup::THEME_FORMATTING, true)
            && $node->getElementsByTagName('a')->length > 0;
        if ($logoSlot) {
            $classes = trim(($classes ?? '').' '.SafeMarkup::LINKED_LOGO_CLASS);
        }
        $class = $classes === null ? '' : ' class="'.e($classes).'"';
        foreach ($node->attributes as $attr) {
            if (($attr->name === 'class' && $classes !== null) || ($attr->name === 'id' && $logoSlot)) {
                continue;
            }
            if (!(($tag === 'img' && in_array($attr->name, ['src', 'alt'], true)) || ($tag === 'a' && $attr->name === 'href'))) {
                $reader->warn($line, '未対応の HTML 属性を除外しました。', '', str_starts_with(strtolower($attr->name), 'on'));
                break;
            }
        }
        if ($tag === 'img') {
            $path = $reader->localPath($node->getAttribute('src'));
            if ($path === null || !isset($this->images[$path])) {
                $reader->warn($line, '同じテーマの確認済み画像を参照していない img を除外しました。');
                return '';
            }
            $alt = str_replace($reader->omitted, '', $node->getAttribute('alt'));
            return '<img src="theme:'.e($path).'" alt="'.e($alt).'"'.$class.'>';
        }
        if ($tag === 'br') {
            return '<br'.$class.'>';
        }
        $inner = $this->children($node, $reader, $parts);
        if ($tag === 'header' || $tag === 'footer') {
            // The public layout already supplies .header and .footer on its outer wrappers.
            $extra = implode(' ', array_diff(explode(' ', $classes ?? ''), [$tag]));
            return $extra === '' ? $inner : '<div class="'.e($extra).'">'.$inner.'</div>';
        }
        if ($tag === 'a') {
            $url = trim($node->getAttribute('href'));
            if (!str_contains($url, $reader->omitted) && preg_match('~^https?://~i', $url) && filter_var($url, FILTER_VALIDATE_URL)) {
                return '<a href="'.e($url).'" rel="nofollow noreferrer"'.$class.'>'.$inner.'</a>';
            }
            $reader->warn($line, '外部 HTTP(S) のリンクとして確認できない href を除外しました。');
            return $inner;
        }
        if (!in_array($tag, SafeMarkup::THEME_FORMATTING, true)) {
            $reader->warn($line, '未対応の HTML タグの中身だけを残しました。');
            return $inner;
        }
        return '<'.$tag.$class.'>'.$inner.'</'.$tag.'>';
    }
}
