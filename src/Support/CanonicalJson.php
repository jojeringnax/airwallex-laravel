<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Support;

use stdClass;

/** @internal Stable fingerprints of already-decoded values, not API wire encoding. */
final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::sort($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sort(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $members = get_object_vars($value);
            ksort($members, SORT_STRING);
            foreach ($members as &$member) {
                $member = self::sort($member);
            }

            return (object) $members;
        }
        if (is_array($value)) {
            $list = array_is_list($value);
            if (! $list) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as &$member) {
                $member = self::sort($member);
            }

            return $list ? $value : (object) $value;
        }

        return $value;
    }
}
