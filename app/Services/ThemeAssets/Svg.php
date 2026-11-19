<?php

namespace App\Services\ThemeAssets;

use DOMAttr;
use DOMComment;
use DOMDocument;
use DOMElement;
use DOMText;
use InvalidArgumentException;
use UnexpectedValueException;
use Wikimedia\CSS\Objects\ComponentValueList;
use Wikimedia\CSS\Objects\CSSFunction;
use Wikimedia\CSS\Objects\Declaration;
use Wikimedia\CSS\Objects\Token;
use Wikimedia\CSS\Parser\Parser;
use Wikimedia\CSS\Util;

final class Svg
{
    private const NS = 'http://www.w3.org/2000/svg';
    private const XLINK = 'http://www.w3.org/1999/xlink';
    private const MAX_ELEMENTS = 4000;
    private const MAX_DEPTH = 48;
    private const ELEMENTS = [
        'svg', 'g', 'defs', 'title', 'desc', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'clipPath', 'linearGradient', 'radialGradient', 'stop', 'use', 'symbol',
    ];
    private const URL_ATTRIBUTES = ['fill', 'stroke', 'stop-color', 'color', 'clip-path'];
    private const PRESENTATION = [
        'fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-opacity', 'stroke-linejoin',
        'stroke-linecap', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-miterlimit', 'opacity', 'color',
        'stop-color', 'stop-opacity', 'clip-path', 'clip-rule', 'font-size', 'font-weight', 'text-anchor',
    ];

    /** Rebuild a standalone static SVG. No source markup, namespace declarations, or stylesheets are copied. */
    public static function clean(string $source, ?int &$rejectedLine = null): ?string
    {
        $rejectedLine = null;
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
        $output = new DOMDocument('1.0', 'UTF-8');
        $count = 0;
        try {
            $root = self::element($document->documentElement, $output, $count, 0, $rejectedLine);
        } catch (InvalidArgumentException|UnexpectedValueException) {
            // CSS tokens can contain numbers outside the PHP runtime's range.
            return null;
        }
        if (!$root) {
            return null;
        }
        $output->appendChild($root);
        if (!self::resolveReferences($output)) {
            return null;
        }
        return $output->saveXML($root) ?: null;
    }

    private static function element(DOMElement $source, DOMDocument $output, int &$count, int $depth, ?int &$rejectedLine): ?DOMElement
    {
        $tag = $source->localName;
        if (++$count > self::MAX_ELEMENTS || $depth > self::MAX_DEPTH || !in_array($source->namespaceURI, [null, self::NS], true)
            || !in_array($tag, self::ELEMENTS, true)) {
            $rejectedLine = $source->getLineNo();
            return null;
        }
        $node = $output->createElementNS(self::NS, $tag);
        foreach ($source->attributes as $attribute) {
            if (!self::copyAttribute($node, $attribute)) {
                $rejectedLine = $source->getLineNo();
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
            if (!$child instanceof DOMElement || !($copy = self::element($child, $output, $count, $depth + 1, $rejectedLine))) {
                return null;
            }
            $node->appendChild($copy);
        }
        return $node;
    }

    private static function copyAttribute(DOMElement $node, DOMAttr $attribute): bool
    {
        $name = $attribute->localName;
        $value = trim($attribute->value);
        if (str_starts_with(strtolower($name), 'on') || str_contains($name, ':')) {
            return false;
        }
        if (in_array(strtolower($name), ['href', 'src'], true) || $attribute->nodeName === 'xml:base') {
            if ($value !== '' && !str_starts_with($value, '#')) {
                return false;
            }
            if ($name === 'href' && strlen($value) > 1 && strlen($value) <= 4096
                && in_array($node->localName, ['use', 'linearGradient', 'radialGradient'], true)) {
                if ($attribute->namespaceURI === null) {
                    $node->setAttribute('href', $value);
                } elseif ($attribute->namespaceURI === self::XLINK) {
                    $node->setAttributeNS(self::XLINK, 'xlink:href', $value);
                }
            }
            return true;
        }
        if ($attribute->namespaceURI === null && $name === 'id') {
            // Keep the original spelling internally until all forward references are known.
            if ($value !== '' && strlen($attribute->value) <= 4096) {
                $node->setAttribute('id', $attribute->value);
            }
            return true;
        }
        if (str_starts_with($name, 'data-')) {
            return true;
        }
        if ($attribute->namespaceURI === null && $name === 'style') {
            if (strlen($value) > 4096) {
                return false;
            }
            foreach (Parser::newFromString($value)->parseDeclarationOrAtRuleList() as $rule) {
                if (!$rule instanceof Declaration) {
                    return false;
                }
                $property = strtolower($rule->getName());
                if (!self::copyCssValue($node, $property, $rule->getValue(), in_array($property, self::PRESENTATION, true))) {
                    return false;
                }
            }
            return true;
        }
        return $attribute->namespaceURI !== null || self::copyValue($node, $name, $value);
    }

    private static function copyValue(DOMElement $node, string $name, string $value): bool
    {
        if (in_array($name, ['width', 'height'], true) && ((float) $value < 0 || (float) $value > 8192)) {
            return false;
        }
        if (self::attribute($name, $value)) {
            $node->setAttribute($name, $value);
            return true;
        }
        if (!str_contains($value, '(') && !str_contains($value, '\\')) {
            return true;
        }
        if (strlen($value) > 4096) {
            return false;
        }
        $parts = Parser::newFromString($value)->parseComponentValueList();
        return self::copyCssValue($node, $name, $parts, in_array($name, self::URL_ATTRIBUTES, true));
    }

    private static function copyCssValue(DOMElement $node, string $name, ComponentValueList $parts, bool $supported): bool
    {
        if (!self::localResourcesOnly($parts)) {
            return false;
        }
        if (!$supported) {
            return true;
        }
        $value = trim(Util::stringify($parts));
        if (self::attribute($name, $value)) {
            $node->setAttribute($name, $value);
        } elseif (in_array($name, self::URL_ATTRIBUTES, true)) {
            self::copyLocalPaint($node, $name, $parts);
        }
        return true;
    }

    private static function copyLocalPaint(DOMElement $node, string $name, ComponentValueList $parts): void
    {
        $values = self::nonWhitespace($parts);
        if (!$values || count($values) > 2) {
            return;
        }
        $url = $values[0];
        $type = Token::T_URL;
        // CSS tokenizes url(#id) as a URL and url("#id") as a function containing a string.
        if ($url instanceof CSSFunction && strtolower($url->getName()) === 'url') {
            $arguments = self::nonWhitespace($url->getValue());
            $url = count($arguments) === 1 ? $arguments[0] : null;
            $type = Token::T_STRING;
        }
        if (!$url instanceof Token || $url->type() !== $type || !str_starts_with($url->value(), '#') || $url->value() === '#') {
            return;
        }
        $fallback = isset($values[1]) ? Util::stringify($values[1]) : '';
        if ($fallback === '' || ($name !== 'clip-path' && self::attribute('fill', $fallback))) {
            $fragment = rawurlencode(rawurldecode(substr($url->value(), 1)));
            $node->setAttribute($name, 'url(#'.$fragment.')'.($fallback === '' ? '' : ' '.$fallback));
        }
    }

    private static function nonWhitespace(ComponentValueList $parts): array
    {
        return array_values(array_filter($parts->slice(0), static fn ($part) => !($part instanceof Token && $part->type() === Token::T_WHITESPACE)));
    }

    private static function localResourcesOnly(ComponentValueList $parts, bool $urlStrings = false): bool
    {
        foreach ($parts as $part) {
            if ($part instanceof Token) {
                $isUrl = $part->type() === Token::T_URL || ($urlStrings && $part->type() === Token::T_STRING);
                if ($part->type() === Token::T_BAD_URL || ($isUrl && !str_starts_with($part->value(), '#'))) {
                    return false;
                }
                continue;
            }
            // Several CSS image functions also accept quoted URLs without a url() wrapper.
            $urlFunction = $part instanceof CSSFunction && in_array(strtolower($part->getName()),
                ['url', 'src', 'image', 'image-set', '-webkit-image-set'], true);
            if (!self::localResourcesOnly($part->getValue(), $urlFunction)) {
                return false;
            }
        }
        return true;
    }

    /** Resolve local IRIs after every element has been rebuilt, including forward references. */
    private static function resolveReferences(DOMDocument $document): bool
    {
        $targets = $reserved = $references = [];
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element->hasAttribute('id')) {
                $id = $element->getAttribute('id');
                $targets[$id] ??= $element;
                if (self::attribute('id', $id)) {
                    $reserved[$id] = true;
                } else {
                    $element->removeAttribute('id');
                }
            }
            foreach ($element->attributes as $attribute) {
                $fallback = '';
                if ($attribute->localName === 'href') {
                    $fragment = substr($attribute->value, 1);
                } elseif (in_array($attribute->localName, self::URL_ATTRIBUTES, true) && str_starts_with($attribute->value, 'url(#')) {
                    [$fragment, $fallback] = explode(')', substr($attribute->value, 5), 2);
                } else {
                    continue;
                }
                $id = rawurldecode($fragment);
                $references[] = [$element, $attribute, $id, $fallback];
                // A generated ID must not give a previously dangling link a target.
                $reserved[$id] = true;
            }
        }
        $edges = [];
        $next = 1;
        foreach ($references as [$element, $attribute, $id, $fallback]) {
            $target = $targets[$id] ?? null;
            if (!self::attribute('id', $id)) {
                if (!$target) {
                    if ($fallback !== '') {
                        $attribute->value = ltrim($fallback);
                    } else {
                        $element->removeAttributeNode($attribute);
                    }
                    continue;
                }
                if (!$target->hasAttribute('id')) {
                    do {
                        $newId = 'svg-id-'.$next++;
                    } while (isset($reserved[$newId]));
                    $target->setAttribute('id', $newId);
                }
                $id = $target->getAttribute('id');
            }
            $attribute->value = $attribute->localName === 'href' ? '#'.$id : 'url(#'.$id.')'.$fallback;
            if ($target) {
                $edges[spl_object_id($element)][spl_object_id($target)] = $target;
            }
        }

        // Count referenced subtrees too: cycles and repeated uses must fit the same drawing budget.
        $count = 0;
        return !$edges || self::withinDrawingLimits($document->documentElement, $edges, $count, 0);
    }

    private static function withinDrawingLimits(DOMElement $node, array $edges, int &$count, int $depth): bool
    {
        if (++$count > self::MAX_ELEMENTS || $depth > self::MAX_DEPTH) {
            return false;
        }
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && !self::withinDrawingLimits($child, $edges, $count, $depth + 1)) {
                return false;
            }
        }
        foreach ($edges[spl_object_id($node)] ?? [] as $target) {
            if (!self::withinDrawingLimits($target, $edges, $count, $depth + 1)) {
                return false;
            }
        }
        return true;
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
            'fill', 'stroke', 'stop-color', 'color' => '/\A(?:#[a-f0-9]{3,8}|[a-z]+|rgba?\([0-9 .,%]+\))\z/i',
            'clip-path' => '/\Anone\z/',
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
        return $pattern !== null && preg_match($pattern, $value) === 1;
    }
}
