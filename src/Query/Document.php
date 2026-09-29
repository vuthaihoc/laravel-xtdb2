<?php

namespace LaravelXtdb\Query;

use JsonSerializable;

/**
 * A nested XTDB value (an object or a list) bound to a query.
 *
 * Plain PHP arrays cannot be bindings: the query builder flattens them, and
 * insert() reads a leading array value as a list of rows. Wrap nested data in
 * a Document (or use the AsDocument cast) and it is written as an XTDB
 * object or list literal, so its fields can be queried: where('meta->source', 'x').
 */
final class Document implements JsonSerializable
{
    /**
     * @param  array<array-key, mixed>  $value
     */
    public function __construct(public readonly array $value) {}

    /**
     * @return array<array-key, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->value;
    }
}
