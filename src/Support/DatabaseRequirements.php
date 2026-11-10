<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Support;

/** @internal Qualified MySQL line/floor; VERSION() may include build suffixes. */
final class DatabaseRequirements
{
    public static function supportedMySqlVersion(string $version): bool
    {
        return stripos($version, 'mariadb') === false
            && preg_match('/^(8\\.4\\.[0-9]+)(?:[-+].*)?$/D', $version, $matches) === 1
            && version_compare($matches[1], '8.4.11', '>=');
    }
}
