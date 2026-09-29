<?php

namespace LaravelXtdb\Eloquent\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LaravelXtdb\Query\Document;

/**
 * Stores an array as a native XTDB object or list, so its fields can be
 * queried (where('settings->theme', 'dark')). Laravel's `array`/`json`
 * casts store JSON text, whose fields XTDB cannot see.
 *
 * @implements CastsAttributes<array<array-key, mixed>|null, mixed>
 */
class AsDocument implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<array-key, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return match (true) {
            $value === null => null,
            $value instanceof Document => $value->value,
            is_array($value) => $value,
            is_string($value) => (array) json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            default => throw new InvalidArgumentException("The [{$key}] attribute is not a document."),
        };
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, Document|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$key => match (true) {
            $value === null => null,
            $value instanceof Document => $value,
            is_array($value) => new Document($value),
            is_string($value) => new Document((array) json_decode($value, true, flags: JSON_THROW_ON_ERROR)),
            default => throw new InvalidArgumentException("The [{$key}] attribute must be an array."),
        }];
    }
}
