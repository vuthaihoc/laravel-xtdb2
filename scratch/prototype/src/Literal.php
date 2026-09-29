<?php

namespace Xtdb;

use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;
use Stringable;

/** Prototype: PHP values as typed XTDB SQL literals. */
final class Literal
{
    private const DATETIME = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}(:?\d{2})?)?$/';

    public static function of(mixed $value, bool $datesFromStrings = true): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value) => (string) $value,
            is_float($value) => is_finite($value) ? var_export($value, true) : throw new InvalidArgumentException('Non-finite float'),
            $value instanceof DateTimeInterface => "TIMESTAMP '".$value->format('Y-m-d\TH:i:s.uP')."'",
            $value instanceof BackedEnum => self::of($value->value, $datesFromStrings),
            is_array($value) => array_is_list($value)
                ? '['.implode(', ', array_map(fn ($v) => self::of($v, $datesFromStrings), $value)).']'
                : '{'.implode(', ', array_map(fn ($k, $v) => self::key((string) $k).': '.self::of($v, $datesFromStrings), array_keys($value), $value)).'}',
            is_string($value) && $datesFromStrings && preg_match(self::DATETIME, $value) => "TIMESTAMP '".str_replace(' ', 'T', $value)."'",
            is_string($value), $value instanceof Stringable => self::string((string) $value),
            default => throw new InvalidArgumentException('Unsupported binding type '.get_debug_type($value)),
        };
    }

    /** E'' string: backslash and quote escaped; characters beyond the BMP as \UXXXXXXXX (XTDB fails to parse them in INSERT). */
    public static function string(string $value): string
    {
        $escaped = preg_replace_callback('/[\\\\\']|[\x{10000}-\x{10FFFF}]/u', function (array $m) {
            return match ($m[0]) {
                '\\' => '\\\\',
                "'" => "\\'",
                default => sprintf('\\U%08X', mb_ord($m[0], 'UTF-8')),
            };
        }, $value);

        if ($escaped === null) {
            throw new InvalidArgumentException('Invalid UTF-8 string binding');
        }

        return "E'".$escaped."'";
    }

    private static function key(string $key): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) ? $key : throw new InvalidArgumentException("Unsupported object key [{$key}]");
    }

    /**
     * Replace the ? placeholders outside quoted strings/identifiers with literals.
     *
     * @param  array<int|string, mixed>  $bindings
     */
    public static function inline(string $sql, array $bindings, bool $datesFromStrings = true): string
    {
        $bindings = array_values($bindings);
        $out = '';
        $i = 0;
        $length = strlen($sql);

        for ($pos = 0; $pos < $length; $pos++) {
            $char = $sql[$pos];

            if ($char === "'" || $char === '"') {
                $end = $pos + 1;
                while ($end < $length) {
                    if ($sql[$end] === $char) {
                        if ($end + 1 < $length && $sql[$end + 1] === $char) { $end += 2; continue; }
                        break;
                    }
                    if ($char === "'" && $sql[$end] === '\\' && $pos > 0 && ($sql[$pos - 1] === 'E' || $sql[$pos - 1] === 'e')) { $end += 2; continue; }
                    $end++;
                }
                $out .= substr($sql, $pos, $end - $pos + 1);
                $pos = $end;
            } elseif ($char === '?' && ($sql[$pos + 1] ?? '') === '?') {
                $out .= '?';   // Laravel's escaped ?? (jsonb operators)
                $pos++;
            } elseif ($char === '?') {
                if (! array_key_exists($i, $bindings)) {
                    throw new InvalidArgumentException('Fewer bindings than placeholders');
                }
                $out .= self::of($bindings[$i++], $datesFromStrings);
            } else {
                $out .= $char;
            }
        }

        if ($i !== count($bindings)) {
            throw new InvalidArgumentException('More bindings than placeholders');
        }

        return $out;
    }
}
