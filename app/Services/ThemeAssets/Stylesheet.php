<?php

namespace App\Services\ThemeAssets;

use App\Services\ThemeAssets;
use InvalidArgumentException;
use UnexpectedValueException;
use Wikimedia\CSS\Grammar\Alternative;
use Wikimedia\CSS\Grammar\KeywordMatcher;
use Wikimedia\CSS\Grammar\MatcherFactory;
use Wikimedia\CSS\Grammar\TokenMatcher;
use Wikimedia\CSS\Objects\ComponentValueList;
use Wikimedia\CSS\Objects\CSSFunction;
use Wikimedia\CSS\Objects\CSSObject;
use Wikimedia\CSS\Objects\Declaration;
use Wikimedia\CSS\Objects\SimpleBlock;
use Wikimedia\CSS\Objects\Token;
use Wikimedia\CSS\Parser\DataSourceTokenizer;
use Wikimedia\CSS\Parser\Parser;
use Wikimedia\CSS\Parser\StringDataSource;
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
        $known = $this->getKnownProperties();
        $align = ['start', 'end', 'center', 'baseline', 'stretch'];
        $pack = ['start', 'end', 'center', 'justify'];
        // The 2009 box and 2012 flexbox drafts used different names and keyword sets.
        $properties = [
            '-ms-flex-align' => new KeywordMatcher($align),
            '-ms-flex-item-align' => new KeywordMatcher([...$align, 'auto']),
            '-ms-flex-pack' => new KeywordMatcher([...$pack, 'distribute']),
            '-ms-flex-line-pack' => new KeywordMatcher([...$pack, 'distribute', 'stretch']),
            '-ms-flex-positive' => $known['flex-grow'],
            '-ms-flex-negative' => $known['flex-shrink'],
            '-ms-flex-preferred-size' => $known['flex-basis'],
            '-ms-flex-order' => $known['order'],
            'text-size-adjust' => new Alternative([
                new KeywordMatcher(['auto', 'none']),
                new TokenMatcher(Token::T_PERCENTAGE, static fn (Token $token) => $token->value() >= 0),
            ]),
        ];
        $box = [
            'box-orient' => new KeywordMatcher(['horizontal', 'vertical', 'inline-axis', 'block-axis']),
            'box-direction' => new KeywordMatcher(['normal', 'reverse']),
            'box-align' => new KeywordMatcher($align),
            'box-pack' => new KeywordMatcher($pack),
            'box-flex' => $known['flex-grow'],
            'box-ordinal-group' => $factory->integer(),
        ];
        foreach ($box as $property => $matcher) {
            $properties['-webkit-'.$property] = $matcher;
            $properties['-moz-'.$property] = $matcher;
        }
        $this->addKnownProperties($properties);
    }

    public static function clean(string $path, string $source, array $images, array &$warnings, ?StylesheetReport $themeReport = null): ?string
    {
        if (!mb_check_encoding($source, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0e-\x1f\x7f]|<\?|\{!!|\{\{/', $source)) {
            return null;
        }
        $source = preg_replace('/\A(?:\xEF\xBB\xBF)?(?:@charset "UTF-8";)?/i', '', $source);
        $tokens = [];
        $segmentLength = 0;
        $diagnostics = $themeReport ?? new StylesheetReport();
        try {
            $tokenizer = new DataSourceTokenizer(new StringDataSource($source));
            while (($token = $tokenizer->consumeToken())->type() !== Token::T_EOF) {
                $tokens[] = $token;
                $segmentLength = in_array($token->type(), [Token::T_SEMICOLON, Token::T_LEFT_BRACE, Token::T_RIGHT_BRACE], true)
                    ? 0 : $segmentLength + 1;
                if (count($tokens) > 20000 || $segmentLength > 512) {
                    $diagnostics->record($path, [['stylesheet-too-complex', ...$token->getPosition()]]);
                    return null;
                }
            }
            $parser = Parser::newFromTokens($tokens, $token);
            $sheet = $parser->parseStylesheet();
            if ($errors = array_merge($tokenizer->getParseErrors(), $parser->getParseErrors())) {
                $diagnostics->record($path, $errors);
                return null;
            }
            $factory = MatcherFactory::singleton();
            $properties = new self($factory, $path, $images);
            $style = $diagnostics->linkedLogo ? new LinkedLogoRule($factory, $properties, $diagnostics)
                : new StyleRuleSanitizer($factory->cssSelectorList(), $properties);
            $media = new MediaRule($factory->cssMediaQueryList());
            $media->setRuleSanitizers([$style]); // One responsive block is sufficient; do not process nested at-rules.
            $sanitizer = new StylesheetSanitizer([$style, $media]);
            $clean = $sanitizer->sanitize($sheet);
            $diagnostics->record($path, $sanitizer->getSanitizationErrors());
            // Separate full rules to leave stylesheet text editable in the administration screen.
            $rules = [];
            foreach ($clean->getRuleList() as $rule) {
                $rules[] = Util::stringify($rule);
            }
            return implode("\n", $rules);
        } catch (InvalidArgumentException|UnexpectedValueException) {
            // Includes numeric tokens that cannot be represented by the installed PHP runtime.
            return null;
        } finally {
            if ($themeReport === null) {
                array_push($warnings, ...$diagnostics->warnings());
            }
        }
    }

    protected function doSanitize(CSSObject $object): ?CSSObject
    {
        if (!$object instanceof Declaration) {
            return parent::doSanitize($object);
        }
        if (!$this->rewriteImages($object->getValue())) {
            $this->sanitizationError('unsupported-function-or-image', $object);
            return null;
        }
        // Return the original spelling and priority only after checking the complete property grammar.
        return parent::doSanitize($this->validationDeclaration($object)) ? $object : null;
    }

    private static function unprefixed(string $name): string
    {
        return preg_replace('/^-(?:webkit|moz|ms)-(?=[a-z])/i', '', $name);
    }

    private function validationDeclaration(Declaration $declaration): Declaration
    {
        $name = strtolower($declaration->getName());
        $property = isset($this->getKnownProperties()[$name]) ? $name : self::unprefixed($name);
        $tokens = $declaration->getValue()->toTokenArray();
        $changed = $property !== $name;
        foreach ($tokens as $index => $token) {
            if (!in_array($token->type(), [Token::T_IDENT, Token::T_FUNCTION], true) || !str_starts_with($token->value(), '-')) {
                continue;
            }
            $original = $token->value();
            $standard = self::unprefixed($original);
            if ($property === 'display' && $token->type() === Token::T_IDENT) {
                $standard = match (strtolower($original)) {
                    '-webkit-box', '-moz-box', '-ms-flexbox' => 'flex',
                    '-webkit-inline-box', '-moz-inline-box', '-ms-inline-flexbox' => 'inline-flex',
                    default => $standard,
                };
            }
            if (strcasecmp($standard, $original) !== 0) {
                $tokens[$index] = new Token($token->type(), ['value' => $standard, 'position' => $token->getPosition()]);
                $changed = true;
            }
        }
        if (!$changed) {
            return $declaration;
        }
        $probe = new Declaration(new Token(Token::T_IDENT, ['value' => $property, 'position' => $declaration->getPosition()]));
        $values = Parser::newFromTokens($tokens)->parseComponentValueList();
        $probe->getValue()->add($values->toComponentValueArray());
        return $probe;
    }

    private function rewriteImages(ComponentValueList $values): bool
    {
        foreach ($values as $index => $value) {
            if ($value instanceof CSSFunction) {
                $name = strtolower($value->getName());
                if ($name !== 'url') {
                    if (!in_array(self::unprefixed($name), self::FUNCTIONS, true) || !$this->rewriteImages($value->getValue())) {
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
            $values[$index] = new Token(Token::T_URL, ['value' => $relative, 'position' => $value->getPosition()]);
        }
        return true;
    }

    private function imagePath(string $source): ?string
    {
        $path = $this->manifestImage($source);
        if ($path === null) {
            return null;
        }
        $base = explode('/', $this->path);
        array_pop($base);
        $resolved = explode('/', $path);
        while ($base && $resolved && $base[0] === $resolved[0]) {
            array_shift($base);
            array_shift($resolved);
        }
        $fragment = strstr($source, '#') ?: '';
        return str_repeat('../', count($base)).implode('/', array_map('rawurlencode', $resolved)).$fragment;
    }

    public function manifestImage(string $source): ?string
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
        return $path;
    }
}
