<?php

namespace LaravelXtdb\Query;

use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;
use Stringable;
use UnitEnum;

/**
 * PHP values as typed XTDB SQL literals.
 *
 * XTDB rejects untyped parameters in DML over the PostgreSQL wire protocol,
 * and a value sent as text stays text (a boolean becomes 't', a date a
 * string that cannot be compared with timestamps). The connection therefore
 * inlines every binding as a literal of the right type:
 *
 * - null, booleans, integers, floats: NULL, TRUE/FALSE, numbers
 * - DateTimeInterface: TIMESTAMP 'Y-m-dTH:i:s.u' (local, like Laravel's
 *   PostgreSQL `timestamp` columns); date-and-time strings as well unless
 *   $datesFromStrings is false (Eloquent formats dates to strings)
 * - Document and arrays: {"key": ...} objects and [...] lists
 * - other strings: E'...' with backslashes and quotes escaped, and characters
 *   beyond the Basic Multilingual Plane as \UXXXXXXXX
 */
final class Literal
{
    private const DATETIME = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:\d{2})?$/';

    public static function of(mixed $value, bool $datesFromStrings = true): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value) => (string) $value,
            is_float($value) => self::float($value),
            $value instanceof DateTimeInterface => "TIMESTAMP '".$value->format('Y-m-d\TH:i:s.u')."'",
            $value instanceof Document => self::nested($value->value, $datesFromStrings),
            is_array($value) => self::nested($value, $datesFromStrings),
            $value instanceof BackedEnum => self::of($value->value, $datesFromStrings),
            $value instanceof UnitEnum => self::string($value->name),
            is_string($value) && $datesFromStrings && preg_match(self::DATETIME, $value) === 1 => "TIMESTAMP '".str_replace(' ', 'T', $value)."'",
            is_string($value) => self::string($value),
            $value instanceof Stringable => self::string((string) $value),
            default => throw new InvalidArgumentException('XTDB bindings cannot be of type '.get_debug_type($value).'.'),
        };
    }

    /**
     * An E'' string literal.
     */
    public static function string(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw new InvalidArgumentException('XTDB strings cannot contain NUL bytes.');
        }

        $escaped = preg_replace_callback('/[\\\\\']|[\x{10000}-\x{10FFFF}]/u', static fn (array $match) => match ($match[0]) {
            '\\' => '\\\\',
            "'" => "\\'",
            default => sprintf('\\U%08X', mb_ord($match[0], 'UTF-8')),
        }, $value);

        if ($escaped === null) {
            throw new InvalidArgumentException('XTDB strings must be valid UTF-8; encode binary data (e.g. base64) first.');
        }

        return "E'".$escaped."'";
    }

    /**
     * Replace the ? placeholders of a query with literals. Placeholders inside
     * quoted strings, quoted identifiers and comments are left alone, and "??"
     * (Laravel's escaped question mark) is kept for PDO to unescape.
     *
     * @param  array<array-key, mixed>  $bindings
     */
    public static function inline(string $sql, array $bindings, bool $datesFromStrings = true): string
    {
        $bindings = array_values($bindings);
        $count = count($bindings);
        $length = strlen($sql);
        $out = '';
        $next = 0;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === "'" || $char === '"') {
                $end = self::closingQuote($sql, $i, $char === "'" && self::isEscapeStringPrefix($sql, $i));
                $out .= substr($sql, $i, $end - $i + 1);
                $i = $end;
            } elseif ($char === '-' && ($sql[$i + 1] ?? '') === '-') {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length - 1 : $end;
                $out .= substr($sql, $i, $end - $i + 1);
                $i = $end;
            } elseif ($char === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $length - 1 : $end + 1;
                $out .= substr($sql, $i, $end - $i + 1);
                $i = $end;
            } elseif ($char === '?' && ($sql[$i + 1] ?? '') === '?') {
                $out .= '??';   // PDO turns the escaped "??" into "?"
                $i++;
            } elseif ($char === '?') {
                if ($next >= $count) {
                    throw new InvalidArgumentException('The query has more placeholders than bindings.');
                }

                $out .= self::of($bindings[$next++], $datesFromStrings);
            } else {
                $out .= $char;
            }
        }

        if ($next !== $count) {
            throw new InvalidArgumentException("The query has {$next} placeholder(s) for {$count} binding(s).");
        }

        return $out;
    }

    /**
     * The position of the quote closing the string or identifier opened at $start.
     */
    private static function closingQuote(string $sql, int $start, bool $backslashEscapes): int
    {
        $quote = $sql[$start];
        $length = strlen($sql);

        for ($i = $start + 1; $i < $length; $i++) {
            if ($backslashEscapes && $sql[$i] === '\\') {
                $i++;
            } elseif ($sql[$i] === $quote) {
                if (($sql[$i + 1] ?? '') !== $quote) {
                    return $i;
                }

                $i++;
            }
        }

        throw new InvalidArgumentException('The query has an unterminated quoted string or identifier.');
    }

    /**
     * Whether the quote at $quote opens an E'' string: preceded by a lone E.
     */
    private static function isEscapeStringPrefix(string $sql, int $quote): bool
    {
        return $quote > 0
            && ($sql[$quote - 1] === 'E' || $sql[$quote - 1] === 'e')
            && ($quote === 1 || preg_match('/[A-Za-z0-9_$]/', $sql[$quote - 2]) !== 1);
    }

    private static function float(float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('XTDB bindings cannot be INF or NAN.');
        }

        $literal = var_export($value, true);

        return str_contains($literal, '.') || str_contains($literal, 'E') ? $literal : $literal.'.0';
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function nested(array $value, bool $datesFromStrings): string
    {
        if (array_is_list($value)) {
            return '['.implode(', ', array_map(static fn ($item) => self::of($item, $datesFromStrings), $value)).']';
        }

        $fields = [];

        foreach ($value as $key => $item) {
            $fields[] = '"'.str_replace('"', '""', (string) $key).'": '.self::of($item, $datesFromStrings);
        }

        return '{'.implode(', ', $fields).'}';
    }
}
