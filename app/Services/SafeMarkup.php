<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\HtmlString;

final class SafeMarkup
{
    private const FORMATTING = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'div', 'span', 'small', 'pre', 'blockquote'];

    public static function render(string $value): HtmlString
    {
        return new HtmlString(self::convert($value)[0]);
    }

    public static function hasUnsupported(string $value): bool
    {
        return self::convert($value)[1];
    }

    private static function convert(string $value): array
    {
        $unsupported = str_contains($value, '{{') || str_contains($value, '{!!');
        if (!str_contains($value, '<')) {
            return [e($value), $unsupported];
        }
        // Rebuild inert HTML from parsed text. No source node or raw attribute is serialized.
        $doc = new DOMDocument();
        $doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>'.$value.'</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $body = $doc->getElementsByTagName('body')->item(0);
        $output = '';
        foreach ($body?->childNodes ?? [] as $child) {
            $output .= self::node($child, $unsupported);
        }
        return [$output, $unsupported];
    }

    private static function node(DOMNode $node, bool &$unsupported): string
    {
        if ($node instanceof DOMText) {
            return e($node->textContent);
        }
        if (!$node instanceof DOMElement) {
            return '';
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style', 'svg', 'math', 'iframe', 'object', 'template'], true)) {
            $unsupported = true;
            return '';
        }
        $attrs = '';
        if ($tag === 'a') {
            $href = trim($node->getAttribute('href'));
            if (preg_match('~^https?://~i', $href) && filter_var($href, FILTER_VALIDATE_URL)) {
                $attrs = ' href="'.e($href).'" rel="nofollow noreferrer"';
            } else {
                $unsupported = true;
            }
        } elseif ($tag === 'textarea' && $node->hasAttribute('readonly')) {
            // EasyMail's stock privacy notice is a scrollable read-only text area.
            return '<pre class="legacy-notice">'.e($node->textContent).'</pre>';
        } elseif ($tag === 'font') {
            $attrs = strtolower($node->getAttribute('color')) === '#ff0000' ? ' class="legacy-emphasis"' : '';
            $tag = 'span';
        } elseif (!in_array($tag, self::FORMATTING, true)) {
            $unsupported = true;
            $tag = 'span';
        }
        foreach ($node->attributes as $attr) {
            if (!in_array(strtolower($attr->name), ['href', 'color', 'class', 'style', 'target', 'rel'], true)) {
                $unsupported = true;
            }
        }
        if ($tag === 'br') {
            return '<br>';
        }
        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= self::node($child, $unsupported);
        }
        return '<'.$tag.$attrs.'>'.$inner.'</'.$tag.'>';
    }
}
