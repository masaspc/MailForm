<?php

namespace App\Services;

use App\Models\Theme;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\HtmlString;

final class SafeMarkup
{
    private const FORMATTING = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'div', 'span', 'small', 'pre', 'blockquote'];
    public const THEME_FORMATTING = [...self::FORMATTING, 'section', 'nav', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
    public const LINKED_LOGO_CLASS = 'mailform-header-logo';

    public static function themeClasses(string $value): ?string
    {
        $value = trim($value, " \t\r\n");
        if ($value === '' || strlen($value) > 256) {
            return null;
        }
        $classes = preg_split('/[ \t\r\n]+/', $value);
        foreach ($classes as $class) {
            if (!preg_match('/^[a-z_][a-z_0-9-]{0,63}$/iD', $class) || preg_match('/^js[-_]/i', $class)) {
                return null;
            }
        }
        return implode(' ', array_unique($classes));
    }

    public static function render(string $value, ?Theme $theme = null): HtmlString
    {
        return new HtmlString(self::convert($value, $theme)[0]);
    }

    public static function hasUnsupported(string $value, ?Theme $theme = null): bool
    {
        return self::convert($value, $theme)[1];
    }

    public static function themeHeader(?Theme $theme, string $fallback): HtmlString
    {
        $logo = $theme?->logo;
        $header = self::render($theme?->header ?: '', $theme)->toHtml();
        if ($logo && isset(ThemeCatalog::files($theme, 'image/')[$logo])) {
            $src = e(ThemeAssets::url($theme->slug, $logo));
            $image = '<img src="'.$src.'"';
            if (str_contains($header, $image)) {
                $header = str_replace($image, $image.' data-theme-logo', $header);
            } elseif (empty($theme->import_report['background_logo']) || !self::hasLinkedLogo($header)) {
                $header = $image.' data-theme-logo alt="'.e($header === '' ? $theme->name : '').'">'.$header;
            }
        }
        return new HtmlString($header !== '' ? $header : e($fallback));
    }

    public static function hasLinkedLogo(?string $header): bool
    {
        if (!$header || !str_contains($header, self::LINKED_LOGO_CLASS) || !($body = self::body($header))) {
            return false;
        }
        foreach ($body->getElementsByTagName('a') as $link) {
            if (!$link->hasAttribute('href') || $link->getElementsByTagName('img')->length) {
                continue;
            }
            for ($parent = $link->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
                if (in_array(self::LINKED_LOGO_CLASS, explode(' ', $parent->getAttribute('class')), true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Keep the selected logo in its original position in imported header markup. */
    public static function replaceHeaderLogo(?string $header, ?string $previous, ?string $selected): ?string
    {
        if (!$header || !$previous || $previous === $selected || !str_contains($header, '<')) {
            return $header;
        }
        $body = self::body($header);
        if (!$body) {
            return $header;
        }
        $changed = false;
        foreach (iterator_to_array($body->getElementsByTagName('img')) as $image) {
            if ($image->getAttribute('src') !== 'theme:'.$previous) {
                continue;
            }
            if ($selected === null) {
                $image->parentNode->removeChild($image);
            } else {
                $image->setAttribute('src', 'theme:'.$selected);
            }
            $changed = true;
        }
        if (!$changed) {
            return $header;
        }
        $markup = '';
        foreach ($body->childNodes as $child) {
            $markup .= $body->ownerDocument->saveHTML($child);
        }
        return $markup;
    }

    private static function body(string $value): ?DOMElement
    {
        $doc = new DOMDocument();
        $doc->loadHTML('<!doctype html><html><head><meta charset="UTF-8"></head><body>'.$value.'</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        return $doc->getElementsByTagName('body')->item(0);
    }

    private static function convert(string $value, ?Theme $theme): array
    {
        $unsupported = str_contains($value, '{{') || str_contains($value, '{!!');
        if (!str_contains($value, '<')) {
            return [e($value), $unsupported];
        }
        // Rebuild inert HTML from parsed text. No source node or raw attribute is serialized.
        $body = self::body($value);
        $output = '';
        $images = ThemeCatalog::files($theme, 'image/');
        foreach ($body?->childNodes ?? [] as $child) {
            $output .= self::node($child, $unsupported, $theme?->slug, $images);
        }
        return [$output, $unsupported];
    }

    private static function node(DOMNode $node, bool &$unsupported, ?string $slug, array $images): string
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
        $classAttr = '';
        if ($slug !== null && $node->hasAttribute('class') && in_array($tag, [...self::THEME_FORMATTING, 'img', 'a'], true)) {
            $classes = self::themeClasses($node->getAttribute('class'));
            if ($classes !== null) {
                $classAttr = ' class="'.e($classes).'"';
            } else {
                $unsupported = true;
            }
        }
        if ($tag === 'img') {
            $src = $node->getAttribute('src');
            $path = substr($src, 6);
            if ($slug === null || !str_starts_with($src, 'theme:') || !isset($images[$path])) {
                $unsupported = true;
                return '';
            }
            foreach ($node->attributes as $attr) {
                if (!in_array($attr->name, ['src', 'alt', 'class'], true)) {
                    $unsupported = true;
                }
            }
            return '<img src="'.e(ThemeAssets::url($slug, $path)).'" alt="'.e($node->getAttribute('alt')).'"'.$classAttr.'>';
        } elseif ($tag === 'a') {
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
        } elseif (!in_array($tag, $slug === null ? self::FORMATTING : self::THEME_FORMATTING, true)) {
            $unsupported = true;
            $tag = 'span';
        }
        foreach ($node->attributes as $attr) {
            if (!in_array(strtolower($attr->name), ['href', 'color', 'class', 'style', 'target', 'rel'], true)) {
                $unsupported = true;
            }
        }
        if ($tag === 'br') {
            return '<br'.$classAttr.'>';
        }
        $inner = '';
        foreach ($node->childNodes as $child) {
            $inner .= self::node($child, $unsupported, $slug, $images);
        }
        return '<'.$tag.$attrs.$classAttr.'>'.$inner.'</'.$tag.'>';
    }
}
