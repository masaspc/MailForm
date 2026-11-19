<?php

namespace App\Services\ThemeAssets;

use Wikimedia\CSS\Objects\AtRule;
use Wikimedia\CSS\Objects\CSSObject;
use Wikimedia\CSS\Objects\Token;
use Wikimedia\CSS\Parser\Parser;
use Wikimedia\CSS\Sanitizer\MediaAtRuleSanitizer;
use Wikimedia\CSS\Util;

final class MediaRule extends MediaAtRuleSanitizer
{
    protected function doSanitize(CSSObject $object): ?CSSObject
    {
        if (!$object instanceof AtRule || !$object->getBlock()) {
            return parent::doSanitize($object);
        }
        // A top-level comma separates alternatives. Keep conjunctions, negation and nested commas intact.
        $queries = Parser::newFromTokens($object->getPrelude()->toTokenArray())->parseCommaSeparatedComponentValueList();
        if (count($queries) === 1) {
            return parent::doSanitize($object);
        }
        $accepted = [];
        foreach ($queries as $query) {
            $first = Util::findFirstNonWhitespace($query);
            if (!$first || !$this->mediaQueryListMatcher->matchAgainst($query)) {
                $this->sanitizationError('invalid-media-query', $first ?? $object);
                continue;
            }
            if ($accepted) {
                $accepted[] = new Token(Token::T_COMMA);
            }
            array_push($accepted, ...$query);
        }
        if (!$accepted) {
            return null;
        }
        $filtered = clone $object;
        $filtered->getPrelude()->clear();
        $filtered->getPrelude()->add($accepted);
        return parent::doSanitize($filtered);
    }
}
