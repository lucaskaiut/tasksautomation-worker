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

    /**
     * @param  array<string, mixed>  $data
     */
    public static function optionalStringOrUserSummary(array $data, string $key): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        $value = $data[$key];

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            if (isset($value['name']) && is_scalar($value['name'])) {
                return (string) $value['name'];
            }

            if (isset($value['id']) && is_scalar($value['id'])) {
                return (string) $value['id'];
            }

            return null;
        }

        return is_scalar($value) ? (string) $value : null;
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
