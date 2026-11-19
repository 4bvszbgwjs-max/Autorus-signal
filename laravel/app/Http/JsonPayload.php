<?php

namespace App\Http;

use Illuminate\Validation\ValidationException;
use stdClass;

final class JsonPayload
{
    public static function objects(string $body): array
    {
        $decoded = json_decode($body);
        $items = $decoded instanceof stdClass ? [$decoded] : $decoded;
        if (! is_array($items) || collect($items)->contains(fn ($item) => ! $item instanceof stdClass)) {
            throw ValidationException::withMessages(['body' => 'Expected a JSON object or an array of objects.']);
        }
        if (self::containsNullByte($items)) {
            throw ValidationException::withMessages(['body' => 'JSON strings and keys must not contain null bytes.']);
        }

        return $items;
    }

    public static function object(string $body): array
    {
        self::singleObject($body);

        return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }

    public static function singleObject(string $body): stdClass
    {
        $items = self::objects($body);
        if (! str_starts_with(ltrim($body), '{')) {
            throw ValidationException::withMessages(['body' => 'Expected a JSON object.']);
        }

        return $items[0];
    }

    private static function containsNullByte(mixed $value): bool
    {
        if (is_string($value)) {
            return str_contains($value, "\0");
        }
        if (is_object($value) || is_array($value)) {
            foreach ((array) $value as $key => $item) {
                if (str_contains((string) $key, "\0") || self::containsNullByte($item)) {
                    return true;
                }
            }
        }

        return false;
    }
}
