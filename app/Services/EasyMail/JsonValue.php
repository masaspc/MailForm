<?php

namespace App\Services\EasyMail;

/** MySQL may reorder JSON object keys. Array order and scalar types are significant. */
final class JsonValue
{
    public static function same(mixed $left, mixed $right): bool
    {
        if ($left === $right) {
            return true;
        }
        if (!is_array($left) || !is_array($right) || count($left) !== count($right) || array_is_list($left) !== array_is_list($right)) {
            return false;
        }
        foreach ($left as $key => $value) {
            if (!array_key_exists($key, $right) || !self::same($value, $right[$key])) {
                return false;
            }
        }
        return true;
    }
}
