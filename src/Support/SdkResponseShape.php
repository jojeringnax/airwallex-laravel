<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Support;

use Jojeringnax\Airwallex\Client;
use Jojeringnax\Airwallex\Webhook\WebhookEvent;
use Jojeringnax\AirwallexLaravel\Exception\ConfigurationException;
use ReflectionClass;
use ReflectionMethod;
use SensitiveParameter;
use stdClass;

/** @internal Projects SDK-decoded values; never parses provider JSON or reconstructs missing wire proof. */
final class SdkResponseShape
{
    /** @param array<string,mixed> $arguments */
    public static function client(#[SensitiveParameter] array $arguments): Client
    {
        if (self::enabled()) {
            self::requireSupported();
            $arguments['preserveResponseObjects'] = true;
        }

        // Named reflection arguments keep the optional SDK v0.1.2 addition out of the v0.1.1 call surface.
        return (new ReflectionClass(Client::class))->newInstanceArgs($arguments);
    }

    /** @return array<array-key,mixed> */
    public static function webhook(#[SensitiveParameter] WebhookEvent $event): array
    {
        if (self::enabled()) {
            self::requireSupported();
            $value = (new ReflectionMethod($event, 'toArray'))->invokeArgs($event, ['preserveObjects' => true]);
        } else {
            $value = $event->toArray();
        }
        if (! is_array($value)) {
            throw new ConfigurationException('SDK webhook view must return an array envelope.');
        }

        return self::normalize($value);
    }

    /** @param array<array-key,mixed> $value @return array<array-key,mixed> */
    public static function normalize(#[SensitiveParameter] array $value): array
    {
        return array_map(self::restore(...), $value);
    }

    private static function restore(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $members = array_map(self::restore(...), get_object_vars($value));

            return $members === [] || array_is_list($members) ? (object) $members : $members;
        }

        return is_array($value) ? array_map(self::restore(...), $value) : $value;
    }

    private static function enabled(): bool
    {
        $enabled = config('airwallex.preserve_response_objects', false);
        if (! is_bool($enabled)) {
            throw new ConfigurationException('SDK response object preservation must be a boolean.');
        }

        return $enabled;
    }

    private static function requireSupported(): void
    {
        foreach ([[Client::class, '__construct', 'preserveResponseObjects'], [WebhookEvent::class, 'toArray', 'preserveObjects']] as [$class, $method, $name]) {
            $parameters = (new ReflectionMethod($class, $method))->getParameters();
            if (! in_array($name, array_map(static fn ($parameter) => $parameter->getName(), $parameters), true)) {
                throw new ConfigurationException('SDK response object preservation requires airwallex-php-sdk v0.1.2 or later; select and qualify that released dependency first.');
            }
        }
    }
}
