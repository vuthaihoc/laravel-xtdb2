<?php

namespace LaravelXtdb\Query;

use Illuminate\Database\Query\Processors\PostgresProcessor;

class Processor extends PostgresProcessor
{
    /**
     * XTDB reports column types in its own notation: ":utf8", "[:? :i64]"
     * (nullable), "[:timestamp-local :micro]"...
     *
     * @param  list<array<string, mixed>|object>  $results
     * @return list<array<string, mixed>>
     */
    public function processColumns($results)
    {
        return array_map(function ($result) {
            $result = (array) $result;
            $type = (string) $result['type'];

            return [
                'name' => $result['name'],
                'type_name' => static::typeName($type),
                'type' => $type,
                'collation' => null,
                'nullable' => $result['nullable'] === 'YES' || str_starts_with($type, '[:? '),
                'default' => null,
                'auto_increment' => false,
                'comment' => null,
                'generation' => null,
            ];
        }, $results);
    }

    /**
     * The closest PostgreSQL type name of an XTDB type.
     */
    public static function typeName(string $type): string
    {
        $type = preg_replace('/^\[:\? (.*)\]$/', '$1', $type) ?? $type;
        $base = preg_match('/^\[?:([a-z0-9-]+)/', $type, $match) === 1 ? $match[1] : $type;

        return match ($base) {
            'utf8' => 'text',
            'i8', 'i16' => 'smallint',
            'i32' => 'integer',
            'i64' => 'bigint',
            'f32' => 'real',
            'f64' => 'double precision',
            'decimal' => 'numeric',
            'bool' => 'boolean',
            'instant', 'timestamp-tz' => 'timestamptz',
            'timestamp-local' => 'timestamp',
            'date' => 'date',
            'time-local' => 'time',
            'duration', 'interval' => 'interval',
            'uuid' => 'uuid',
            'varbinary' => 'bytea',
            'struct' => 'document',
            'list', 'set' => 'array',
            'union' => 'mixed',
            'null', 'nothing' => 'unknown',
            default => $base,
        };
    }
}
