<?php

namespace App\Services\ThemeAssets;

use App\Services\ThemeAssets;
use InvalidArgumentException;
use UnexpectedValueException;
use Wikimedia\CSS\Grammar\MatcherFactory;
use Wikimedia\CSS\Objects\ComponentValueList;
use Wikimedia\CSS\Objects\CSSFunction;
use Wikimedia\CSS\Objects\CSSObject;
use Wikimedia\CSS\Objects\Declaration;
use Wikimedia\CSS\Objects\SimpleBlock;
use Wikimedia\CSS\Objects\Token;
use Wikimedia\CSS\Parser\DataSourceTokenizer;
use Wikimedia\CSS\Parser\Parser;
use Wikimedia\CSS\Parser\StringDataSource;
use Wikimedia\CSS\Sanitizer\MediaAtRuleSanitizer;
use Wikimedia\CSS\Sanitizer\StylePropertySanitizer;
use Wikimedia\CSS\Sanitizer\StyleRuleSanitizer;
use Wikimedia\CSS\Sanitizer\StylesheetSanitizer;
use Wikimedia\CSS\Util;

final class Stylesheet extends StylePropertySanitizer
{
    // No functions with indirect resource/substitution semantics (image-set, attr, var, src, etc.).
    private const FUNCTIONS = [
        'rgb', 'rgba', 'hsl', 'hsla', 'hwb', 'calc', 'min', 'max', 'clamp',
        'linear-gradient', 'radial-gradient', 'repeating-linear-gradient', 'repeating-radial-gradient',
        'repeat', 'minmax', 'fit-content', 'matrix', 'matrix3d', 'translate', 'translate3d',
        'translatex', 'translatey', 'translatez', 'scale', 'scalex', 'scaley', 'scalez', 'scale3d',
        'rotate', 'rotatex', 'rotatey', 'rotatez', 'rotate3d', 'skew', 'skewx', 'skewy',
        'perspective', 'cubic-bezier', 'steps', 'circle', 'ellipse', 'inset', 'polygon',
    ];

    private function __construct(MatcherFactory $factory, private readonly string $path, private readonly array $images)
    {
        parent::__construct($factory);
    }

    public static function clean(string $path, string $source, array $images, array &$warnings): ?string
    {
        if (!mb_check_encoding($source, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0e-\x1f\x7f]|<\?|\{!!|\{\{/', $source)) {
            return null;
        }
        $source = preg_replace('/\A(?:\xEF\xBB\xBF)?(?:@charset "UTF-8";)?/i', '', $source);
        $tokens = [];
        $segmentLength = 0;
        try {
            $tokenizer = new DataSourceTokenizer(new StringDataSource($source));
            while (($token = $tokenizer->consumeToken())->type() !== Token::T_EOF) {
                $tokens[] = $token;
                $segmentLength = in_array($token->type(), [Token::T_SEMICOLON, Token::T_LEFT_BRACE, Token::T_RIGHT_BRACE], true)
                    ? 0 : $segmentLength + 1;
                if (count($tokens) > 20000 || $segmentLength > 512) {
                    $warnings[] = "{$path}:1 CSS の規則数または記述の複雑さが上限を超えました。";
                    return null;
                }
            }
            $parser = Parser::newFromTokens($tokens, $token);
            $sheet = $parser->parseStylesheet();
            if ($errors = array_merge($tokenizer->getParseErrors(), $parser->getParseErrors())) {
                self::report($path, $errors, $warnings);
                return null;
            }
            $factory = MatcherFactory::singleton();
            $style = new StyleRuleSanitizer($factory->cssSelectorList(), new self($factory, $path, $images));
            $media = new MediaAtRuleSanitizer($factory->cssMediaQueryList());
            $media->setRuleSanitizers([$style]); // One responsive block is sufficient; do not process nested at-rules.
            $sanitizer = new StylesheetSanitizer([$style, $media]);
            $clean = $sanitizer->sanitize($sheet);
            self::report($path, $sanitizer->getSanitizationErrors(), $warnings);
            // Separate full rules to leave stylesheet text editable in the administration screen.
            $rules = [];
            foreach ($clean->getRuleList() as $rule) {
                $rules[] = Util::stringify($rule);
            }
            return implode("\n", $rules);
        } catch (InvalidArgumentException|UnexpectedValueException) {
            // Includes numeric tokens that cannot be represented by the installed PHP runtime.
            return null;
        }
    }

    private static function report(string $path, array $errors, array &$warnings): void
    {
        foreach (array_slice($errors, 0, 30) as [$code, $line, $column]) {
            $warnings[] = "{$path}:{$line}:{$column} 未対応の CSS を除外しました（{$code}）。";
        }
        if (count($errors) > 30) {
            $warnings[] = "{$path}：ほか ".(count($errors) - 30).' 件の CSS 警告があります。';
        }
    }

    protected function doSanitize(CSSObject $object): ?CSSObject
    {
        if ($object instanceof Declaration && !$this->rewriteImages($object->getValue())) {
            $this->sanitizationError('unsupported-function-or-image', $object);
            return null;
        }
        // Validate the complete property grammar after replacing every accepted URL token.
        return parent::doSanitize($object);
    }

    private function rewriteImages(ComponentValueList $values): bool
    {
        foreach ($values as $index => $value) {
            if ($value instanceof CSSFunction) {
                $name = strtolower($value->getName());
                if ($name !== 'url') {
                    if (!in_array($name, self::FUNCTIONS, true) || !$this->rewriteImages($value->getValue())) {
                        return false;
                    }
                    continue;
                }
                $args = array_values(array_filter($value->getValue()->slice(0),
                    static fn ($arg) => !($arg instanceof Token && $arg->type() === Token::T_WHITESPACE)));
                if (count($args) !== 1 || !$args[0] instanceof Token || $args[0]->type() !== Token::T_STRING) {
                    return false;
                }
                $source = $args[0]->value();
            } elseif ($value instanceof Token && $value->type() === Token::T_URL) {
                $source = $value->value();
            } else {
                if ($value instanceof SimpleBlock && !$this->rewriteImages($value->getValue())) {
                    return false;
                }
                continue;
            }
            $relative = $this->imagePath($source);
            if ($relative === null) {
                return false;
            }
            $values[$index] = new Token(Token::T_URL, $relative);
        }
        return true;
    }

    private function imagePath(string $source): ?string
    {
        if ($source === '' || strlen($source) > 1536 || str_starts_with($source, '/') || strpbrk($source, ":\\") !== false) {
            return null;
        }
        [$file, $fragment] = array_pad(explode('#', $source, 2), 2, null);
        $source = explode('?', $file, 2)[0];
        if ($source === '' || ($fragment !== null && !preg_match('/^[-A-Za-z0-9_]+$/D', $fragment))) {
            return null;
        }
        $base = explode('/', $this->path);
        array_pop($base);
        $resolved = $base;
        foreach (explode('/', $source) as $part) {
            if ($part === '.') {
                continue;
            }
            if ($part === '..') {
                if (!$resolved) {
                    return null;
                }
                array_pop($resolved);
                continue;
            }
            $part = rawurldecode($part);
            if ($part === '' || $part === '.' || $part === '..' || str_contains($part, '/')) {
                return null;
            }
            $resolved[] = $part;
        }
        $path = implode('/', $resolved);
        $image = $this->images[$path] ?? null;
        if (!ThemeAssets::matchesPath($path, $image) || !str_starts_with($image['mime'], 'image/')) {
            return null;
        }
        while ($base && $resolved && $base[0] === $resolved[0]) {
            array_shift($base);
            array_shift($resolved);
        }
        return str_repeat('../', count($base)).implode('/', array_map('rawurlencode', $resolved)).($fragment === null ? '' : '#'.$fragment);
    }
}
