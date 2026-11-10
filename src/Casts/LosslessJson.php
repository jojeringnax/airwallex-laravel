<?php

declare(strict_types=1);

namespace Jojeringnax\AirwallexLaravel\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Jojeringnax\AirwallexLaravel\Exception\EvidenceException;
use stdClass;

/** Persistence codec for SDK-decoded values; this does not decode API responses. */
class LosslessJson implements CastsAttributes
{
    public function __construct(private readonly string $mode = 'plain') {}

    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($this->mode === 'encrypted') {
            $value = Model::currentEncrypter()->decrypt($value, false);
        }
        $decoded = json_decode($value, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if ($decoded instanceof stdClass) {
            return array_map(self::restore(...), self::members($decoded));
        }
        if (is_array($decoded)) {
            return array_map(self::restore(...), $decoded);
        }
        throw new EvidenceException('Stored billing JSON must have an object or array root.');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        return $this->mode === 'encrypted' ? Model::currentEncrypter()->encrypt($json, false) : $json;
    }

    /** Numeric object property names become integer array keys in PHP.
     * @return array<array-key,mixed>
     */
    private static function members(stdClass $value): array
    {
        return get_object_vars($value);
    }

    private static function restore(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $values = array_map(self::restore(...), self::members($value));

            // Empty and list-shaped objects must keep object identity. Other maps
            // become SDK-shaped associative arrays without changing JSON meaning.
            return $values === [] || array_is_list($values) ? (object) $values : $values;
        }

        return is_array($value) ? array_map(self::restore(...), $value) : $value;
    }
}
