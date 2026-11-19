<?php

namespace App\Services\ThemeAssets;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMText;

final class Svg
{
    private const NS = 'http://www.w3.org/2000/svg';
    private const ELEMENTS = [
        'svg', 'g', 'defs', 'title', 'desc', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'clipPath', 'linearGradient', 'radialGradient', 'stop',
    ];
    private const PRESENTATION = [
        'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linejoin',
        'stroke-linecap', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-miterlimit', 'opacity', 'color',
        'stop-color', 'stop-opacity', 'clip-path', 'clip-rule', 'font-size', 'font-weight', 'text-anchor',
    ];

    /** Rebuild a standalone static SVG. No source markup, namespace declarations, or stylesheets are copied. */
    public static function clean(string $source, ?int &$removedLine = null): ?string
    {
        if (!mb_check_encoding($source, 'UTF-8')) {
            return null;
        }
        $source = preg_replace('~\A(?:\xEF\xBB\xBF)?(?:<\?xml\s+version=(["\'])1\.0\1(?:\s+encoding=(["\'])UTF-8\2)?(?:\s+standalone=(["\'])(?:yes|no)\3)?\s*\?>)?~i', '', $source);
        if ($source === '' || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]|<!(?!--)|<\?|\{!!|\{\{/', $source)) {
            return null;
        }
        $document = new DOMDocument();
        if (!$document->loadXML($source, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)
            || $document->documentElement?->localName !== 'svg' || $document->doctype !== null) {
            return null;
        }
        // Keep the drawing when a logo also contains old scripts, event handlers or remote images.
        $nodes = $document->getElementsByTagName('*');
        if ($nodes->length > 4000) {
            return null;
        }
        foreach (iterator_to_array($nodes) as $element) {
            if (in_array(strtolower($element->localName), ['script', 'style', 'image', 'foreignobject', 'animate', 'animatemotion', 'animatetransform', 'set'], true)) {
                $removedLine ??= $element->getLineNo();
                $element->parentNode?->removeChild($element);
                continue;
            }
            foreach (iterator_to_array($element->attributes) as $attribute) {
                if (str_starts_with(strtolower($attribute->localName), 'on') || strtolower($attribute->localName) === 'href'
                    || (stripos($attribute->value, 'url(') !== false && !self::attribute($attribute->name, $attribute->value) && $attribute->name !== 'style')) {
                    $removedLine ??= $element->getLineNo();
                    $element->removeAttributeNode($attribute);
                }
            }
        }
        $output = new DOMDocument('1.0', 'UTF-8');
        $count = 0;
        $root = self::element($document->documentElement, $output, $count, 0);
        if (!$root) {
            return null;
        }
        $output->appendChild($root);
        if ($removedLine !== null) {
            $graphics = (new \DOMXPath($output))->query('//*[@d or @points or @r or @rx or @width or @x2 or local-name()="text"]');
            if (!$graphics || !array_filter(iterator_to_array($graphics), fn (DOMElement $el) => $el !== $root)) {
                return null;
            }
        }
        return $output->saveXML($root) ?: null;
    }

    private static function element(DOMElement $source, DOMDocument $output, int &$count, int $depth): ?DOMElement
    {
        $tag = $source->localName;
        if (++$count > 4000 || $depth > 48 || !in_array($source->namespaceURI, [null, self::NS], true)
            || !in_array($tag, self::ELEMENTS, true)) {
            return null;
        }
        $node = $output->createElementNS(self::NS, $tag);
        foreach ($source->attributes as $attribute) {
            if ($attribute->namespaceURI !== null) {
                return null;
            }
            $name = $attribute->name;
            $value = trim($attribute->value);
            if ($name === 'style') {
                // Accept only the same static presentation values allowed as SVG attributes.
                foreach (explode(';', $value) as $declaration) {
                    if (trim($declaration) === '') {
                        continue;
                    }
                    [$property, $setting] = array_pad(explode(':', $declaration, 2), 2, '');
                    $property = trim($property);
                    $setting = trim($setting);
                    if (!in_array($property, self::PRESENTATION, true) || !self::attribute($property, $setting)) {
                        return null;
                    }
                    $node->setAttribute($property, $setting);
                }
            } elseif (self::attribute($name, $value)) {
                $node->setAttribute($name, $value);
            } else {
                return null;
            }
        }
        foreach ($source->childNodes as $child) {
            if ($child instanceof DOMComment) {
                continue;
            }
            if ($child instanceof DOMText) {
                if (!in_array($tag, ['title', 'desc', 'text', 'tspan'], true) && trim($child->data) !== '') {
                    return null;
                }
                $node->appendChild($output->createTextNode($child->data));
                continue;
            }
            if (!$child instanceof DOMElement || !($copy = self::element($child, $output, $count, $depth + 1))) {
                return null;
            }
            $node->appendChild($copy);
        }
        return $node;
    }

    private static function attribute(string $name, string $value): bool
    {
        if ($name === 'd') {
            return preg_match('/\A[MmLlHhVvQqTtCcSsAaZzEe0-9 ., +\t\r\n-]+\z/', $value) === 1;
        }
        if (strlen($value) > 4096) {
            return false;
        }
        $pattern = match ($name) {
            'viewBox' => '/\A[-+0-9Ee.,\s]+\z/',
            'points', 'stroke-dasharray' => '/\A(?:none|[0-9 +.%Ee,\s-]+)\z/',
            'id' => '/\A[A-Za-z_][A-Za-z0-9_.-]*\z/',
            'version' => '/\A(?:1\.0|1\.1|2\.0)\z/',
            'fill', 'stroke', 'stop-color', 'color' => '/\A(?:#[a-f0-9]{3,8}|[a-z]+|rgba?\([0-9 .,%]+\)|url\(#[a-z_][a-z0-9_.-]*\))\z/i',
            'clip-path' => '/\A(?:none|url\(#[A-Za-z_][A-Za-z0-9_.-]*\))\z/',
            'clip-rule', 'fill-rule' => '/\A(?:nonzero|evenodd)\z/',
            'stroke-linejoin' => '/\A(?:round|bevel|miter)\z/',
            'stroke-linecap' => '/\A(?:round|butt|square)\z/',
            'gradientUnits', 'clipPathUnits' => '/\A(?:userSpaceOnUse|objectBoundingBox)\z/',
            'spreadMethod' => '/\A(?:pad|reflect|repeat)\z/',
            'text-anchor' => '/\A(?:start|middle|end)\z/',
            'font-weight' => '/\A(?:normal|bold|[1-9]00)\z/',
            'preserveAspectRatio' => '/\A(?:none|x(?:Min|Mid|Max)Y(?:Min|Mid|Max)(?: (?:meet|slice))?)\z/',
            'transform', 'gradientTransform' => '/\A(?:(?:matrix|translate|scale|rotate|skewX|skewY)\([0-9Ee.,\s+-]+\)[\s,]*)+\z/',
            'x', 'y', 'x1', 'y1', 'x2', 'y2', 'rx', 'ry', 'r', 'cx', 'cy', 'fx', 'fy', 'dx', 'dy',
            'width', 'height', 'offset', 'stroke-width', 'stroke-miterlimit', 'stroke-dashoffset', 'opacity',
            'fill-opacity', 'stroke-opacity', 'stop-opacity', 'font-size' => '/\A[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]{1,2})?(?:px|%)?\z/',
            default => null,
        };
        return $pattern !== null && preg_match($pattern, $value) === 1
            && (!in_array($name, ['width', 'height'], true) || ((float) $value >= 0 && (float) $value <= 8192));
    }
}
