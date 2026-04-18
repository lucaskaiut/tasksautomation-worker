<?php

namespace App\DTOs\Mapping;

final class ApiValue
{
    public static function optionalString(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return (string) $data[$key];
    }

    public static function optionalInt(array $data, string $key): ?int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return (int) $data[$key];
    }

    public static function optionalMixed(array $data, string $key): mixed
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return $data[$key];
    }
}
