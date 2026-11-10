<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Jojeringnax\AirwallexLaravel\Support\Quantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuantityProofTest extends TestCase
{
    #[DataProvider('observations')]
    public function test_native_quantity_proof_is_exact(mixed $observation, int $requested, bool $equal): void
    {
        self::assertSame($equal, Quantity::equalsInteger($observation, $requested));
    }

    public static function observations(): array
    {
        return [
            ['5', 5, true], ['5.000', 5, true], ['5.00000000000000001', 5, false],
            ['50e-1', 5, true], ['0.5e1', 5, true], ['5e+0', 5, true], ['500000000000000000000000000e-26', 5, true],
            ['5.00000000000000001e0', 5, false], ['4.999999999999999999999', 5, false],
            ['2147483647.000000000', 2147483647, true], ['2147483647.000000001', 2147483647, false],
            ['1e1000000000', 1, false], ['1e-1000000000', 1, false], ['0', 1, false],
            ['0.000', 1, false], ['-5', 5, false], ['05', 5, false], ['+5', 5, false],
            [5.0, 5, false], [5, 5, true], [null, 5, false], [false, 5, false],
        ];
    }
}
