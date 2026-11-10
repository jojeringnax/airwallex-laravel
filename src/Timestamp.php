<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel;

use DateTimeImmutable;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;

final class Timestamp
{
    /** Compare seconds and arbitrary fractional text, without float conversion. */
    public static function compare(string $left, string $right): int
    {
        [$ls, $lf] = self::parts($left);
        [$rs, $rf] = self::parts($right);
        if ($ls !== $rs) {
            return $ls <=> $rs;
        }
        $length = max(strlen($lf), strlen($rf));

        return strcmp(str_pad($lf, $length, '0'), str_pad($rf, $length, '0')) <=> 0;
    }

    /** @return array{int,string} */
    private static function parts(string $value): array
    {
        if (! preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d+))?(Z|[+-]\d{2}:?\d{2})$/D', $value, $match)) {
            throw new EvidenceException('Provider timestamp is malformed.');
        }
        $offset = str_replace(':', '', $match[3]);
        $zone = $offset === 'Z' ? 'Z' : substr($offset, 0, 3).':'.substr($offset, 3, 2);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $match[1].$zone);
        $errors = DateTimeImmutable::getLastErrors();
        if (! $date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            throw new EvidenceException('Provider timestamp is malformed.');
        }
        // DateTime accepts out-of-range offsets; explicitly constrain them.
        if ($offset !== 'Z' && ((int) substr($offset, 1, 2) > 23 || (int) substr($offset, 3, 2) > 59)) {
            throw new EvidenceException('Provider timestamp offset is malformed.');
        }

        return [$date->getTimestamp(), $match[2]];
    }
}
