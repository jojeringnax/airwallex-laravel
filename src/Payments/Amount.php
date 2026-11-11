<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Payments;

use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;

/** Exact application-unit conversion and reservation arithmetic; no FX or rounding. */
final class Amount
{
    public static function fromMinor(mixed $minor, string $currency): string
    {
        if (! is_string($minor) || ! preg_match('/^(0|[1-9][0-9]{0,99})$/D', $minor)) {
            throw new EvidenceException('Minor units must be an exact unsigned integer string.');
        }
        $exponent = self::exponent($currency);
        if ($exponent === 0) {
            return $minor;
        }
        $padded = str_pad($minor, $exponent + 1, '0', STR_PAD_LEFT);

        return substr($padded, 0, -$exponent).'.'.substr($padded, -$exponent);
    }

    public static function canonical(mixed $amount, string $currency): string
    {
        return self::fromMinor(self::units($amount, $currency), $currency);
    }

    public static function units(mixed $amount, string $currency): string
    {
        if (! is_string($amount) || ! preg_match('/^([0-9]{1,100})(?:\.([0-9]{1,100}))?$/D', $amount, $parts)) {
            throw new EvidenceException('Amounts must be exact unsigned decimal strings.');
        }
        $exponent = self::exponent($currency);
        $fraction = rtrim($parts[2] ?? '', '0');
        if (strlen($fraction) > $exponent) {
            throw new EvidenceException('Amount exceeds the currency minor-unit precision.');
        }

        return ltrim($parts[1].str_pad($fraction, $exponent, '0'), '0') ?: '0';
    }

    public static function compare(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    public static function add(string $left, string $right): string
    {
        $sum = '';
        $carry = 0;
        for ($a = strlen($left) - 1, $b = strlen($right) - 1; $a >= 0 || $b >= 0 || $carry !== 0; $a--, $b--) {
            $digit = ($a >= 0 ? ord($left[$a]) - 48 : 0) + ($b >= 0 ? ord($right[$b]) - 48 : 0) + $carry;
            $sum = (string) ($digit % 10).$sum;
            $carry = intdiv($digit, 10);
        }

        return $sum;
    }

    public static function subtract(string $left, string $right): string
    {
        if (self::compare($left, $right) < 0) {
            throw new EvidenceException('Exact unsigned subtraction cannot produce a negative reservation balance.');
        }
        $result = '';
        $borrow = 0;
        for ($a = strlen($left) - 1, $b = strlen($right) - 1; $a >= 0; $a--, $b--) {
            $digit = ord($left[$a]) - 48 - $borrow - ($b >= 0 ? ord($right[$b]) - 48 : 0);
            $borrow = $digit < 0 ? 1 : 0;
            $result = (string) ($digit + ($borrow ? 10 : 0)).$result;
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function exponent(string $currency): int
    {
        $values = json_decode(file_get_contents(__DIR__.'/../../resources/currency-exponents.json'), true, flags: JSON_THROW_ON_ERROR);
        if (! isset($values[$currency]) || ! is_int($values[$currency])) {
            throw new EvidenceException('Currency precision is not in the verified conversion reference.');
        }

        return $values[$currency];
    }
}
