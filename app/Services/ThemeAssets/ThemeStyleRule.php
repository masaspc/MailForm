<?php

namespace App\Services\ThemeAssets;

use App\Services\SafeMarkup;
use Wikimedia\CSS\Grammar\MatcherFactory;
use Wikimedia\CSS\Objects\ComponentValueList;
use Wikimedia\CSS\Objects\CSSObject;
use Wikimedia\CSS\Objects\Declaration;
use Wikimedia\CSS\Objects\QualifiedRule;
use Wikimedia\CSS\Objects\SimpleBlock;
use Wikimedia\CSS\Objects\Token;
use Wikimedia\CSS\Parser\Parser;
use Wikimedia\CSS\Sanitizer\StyleRuleSanitizer;
use Wikimedia\CSS\Util;

final class ThemeStyleRule extends StyleRuleSanitizer
{
    public function __construct(MatcherFactory $factory, private Stylesheet $properties, private StylesheetReport $report)
    {
        parent::__construct($factory->cssSelectorList(), $properties);
    }

    protected function doSanitize(CSSObject $object): ?CSSObject
    {
        $rule = parent::doSanitize($object);
        if (!$rule instanceof QualifiedRule) {
            return $rule;
        }
        $prelude = $rule->getPrelude();
        if ($this->report->actionAttributes) {
            // Replace a literal class selector only. Do not rewrite strings or identifiers in other grammar.
            for ($i = count($prelude) - 1; $i > 0; $i--) {
                $token = $prelude[$i];
                $dot = $prelude[$i - 1];
                if (!$token instanceof Token || $token->type() !== Token::T_IDENT || !isset($this->report->actionAttributes[$token->value()])
                    || !$dot instanceof Token || $dot->type() !== Token::T_DELIM || $dot->value() !== '.') {
                    continue;
                }
                $attribute = SimpleBlock::newFromDelimiter(Token::T_LEFT_BRACKET);
                $attribute->getValue()->add(new Token(Token::T_IDENT, $this->report->actionAttributes[$token->value()]));
                $prelude->remove($i);
                $prelude[$i - 1] = $attribute;
            }
        }
        if (!$this->report->linkedLogo || !str_contains(Util::stringify($prelude), '#logo')) {
            return $rule;
        }
        $selectors = Parser::newFromTokens($prelude->toTokenArray())->parseCommaSeparatedComponentValueList();
        $existing = $aliases = [];
        $logoLink = false;
        foreach ($selectors as $selector) {
            $existing[trim(Util::stringify($selector))] = true;
            $parts = [];
            $linked = false;
            foreach ($selector as $index => $part) {
                if ($part instanceof Token && $part->type() === Token::T_HASH && $part->value() === 'logo') {
                    $parts[] = new Token(Token::T_DELIM, '.');
                    $parts[] = new Token(Token::T_IDENT, SafeMarkup::LINKED_LOGO_CLASS);
                    $linked = true;
                    // A direct anchor rule supplies the initial image. Keep other selectors for positioning and states.
                    $tail = trim(Util::stringify(new ComponentValueList($selector->slice($index + 1))));
                    $logoLink = $logoLink || preg_match('/^(?:>\s*)?a$/iD', $tail);
                } else {
                    $parts[] = $part;
                }
            }
            if ($linked) {
                $aliases[trim(Util::stringify(new ComponentValueList($parts)))] = $parts;
            }
        }
        foreach (array_diff_key($aliases, $existing) as $parts) {
            $prelude->add(new Token(Token::T_COMMA));
            if (!$parts[0] instanceof Token || $parts[0]->type() !== Token::T_WHITESPACE) {
                $prelude->add(new Token(Token::T_WHITESPACE));
            }
            $prelude->add($parts);
        }
        if ($logoLink && $this->report->backgroundLogo === null) {
            $declarations = Parser::newFromTokens($rule->getBlock()->getValue()->toTokenArray())->parseDeclarationList();
            foreach ($declarations as $declaration) {
                if ($declaration instanceof Declaration && in_array(strtolower($declaration->getName()), ['background', 'background-image'], true)) {
                    foreach ($declaration->getValue() as $value) {
                        if ($value instanceof Token && $value->type() === Token::T_URL) {
                            $this->report->backgroundLogo = $this->properties->manifestImage($value->value());
                            break;
                        }
                    }
                }
            }
        }
        return $rule;
    }
}
