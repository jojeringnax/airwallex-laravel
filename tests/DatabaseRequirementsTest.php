<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Tests;

use Jojeringnax\AirwallexLaravel\Support\DatabaseRequirements;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DatabaseRequirementsTest extends TestCase
{
    #[DataProvider('serverVersions')]
    public function test_mysql_server_version_uses_numeric_release_and_retains_the_qualified_line(string $version, bool $supported): void
    {
        self::assertSame($supported, DatabaseRequirements::supportedMySqlVersion($version));
    }

    public static function serverVersions(): array
    {
        return [['8.4.11', true], ['8.4.11-commercial', true], ['8.4.11-debug', true], ['8.4.11-log', true], ['8.4.12', true], ['8.4.10-commercial', false], ['8.0.44', false], ['9.0.0', false], ['10.11.0-MariaDB', false], ['8.4.11-MariaDB', false], ['unknown', false]];
    }
}
