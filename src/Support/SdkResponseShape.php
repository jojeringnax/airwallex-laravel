<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Support;

use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Webhook\WebhookEvent;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use ReflectionClass;
use SensitiveParameter;
use stdClass;

/** @internal Projects SDK-decoded values; never parses provider JSON or reconstructs missing wire proof. */
final class SdkResponseShape
{
    /** @param array<string,mixed> $arguments */
    public static function client(#[SensitiveParameter] array $arguments): Client
    {
        if (self::enabled()) {
            $arguments['preserveResponseObjects'] = true;
        }

        return (new ReflectionClass(Client::class))->newInstanceArgs($arguments);
    }

    /** @return array<array-key,mixed> */
    public static function webhook(#[SensitiveParameter] WebhookEvent $event): array
    {
        return self::normalize($event->toArray(preserveObjects: self::enabled()));
    }

    /** @param array<array-key,mixed> $value @return array<array-key,mixed> */
    public static function normalize(#[SensitiveParameter] array $value): array
    {
        return array_map(self::restore(...), $value);
    }

    private static function restore(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $members = array_map(self::restore(...), self::members($value));

            return $members === [] || array_is_list($members) ? (object) $members : $members;
        }

        return is_array($value) ? array_map(self::restore(...), $value) : $value;
    }

    /** Numeric object property names become integer array keys in PHP.
     * @return array<array-key,mixed>
     */
    private static function members(stdClass $value): array
    {
        return get_object_vars($value);
    }

    private static function enabled(): bool
    {
        $enabled = config('airwallex.preserve_response_objects', false);
        if (! is_bool($enabled)) {
            throw new ConfigurationException('SDK response object preservation must be a boolean.');
        }

        return $enabled;
    }
}
