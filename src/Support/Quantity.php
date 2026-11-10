<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Support;

/** @internal Exact observation equality, never amount/proration arithmetic. */
final class Quantity
{
    public static function equalsInteger(mixed $observed, int $requested): bool
    {
        if ($requested < 1) {
            return false;
        }
        if (is_int($observed)) {
            return $observed === $requested;
        }
        if (! is_string($observed) || ! preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?$/D', $observed, $match)) {
            return false;
        }
        $fraction = $match[2] ?? '';
        $digits = ltrim($match[1].$fraction, '0');
        if ($digits === '') {
            return false;
        }
        $exponentText = $match[3] ?? '0';
        $exponentDigits = ltrim(ltrim($exponentText, '+-'), '0');
        // Excessively large exponents cannot be expanded for proof. Retain an
        // unconfirmed outcome rather than allocate memory or approximate it.
        if (strlen($exponentDigits) > 6) {
            return false;
        }
        $exponent = (int) $exponentText;
        $scale = $exponent - strlen($fraction);
        $target = (string) $requested;
        if ($scale >= 0) {
            if (strlen($digits) + $scale !== strlen($target)) {
                return false;
            }

            return $digits.str_repeat('0', $scale) === $target;
        }
        $remove = -$scale;
        if ($remove >= strlen($digits) || strspn($digits, '0', strlen($digits) - $remove) !== $remove) {
            return false;
        }

        return substr($digits, 0, strlen($digits) - $remove) === $target;
    }
}
