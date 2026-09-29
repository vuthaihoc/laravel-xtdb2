<?php

namespace LaravelXtdb\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use LaravelXtdb\Query\Document;
use LaravelXtdb\Query\Literal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LiteralTest extends TestCase
{
    /**
     * @return array<string, array{mixed, string}>
     */
    public static function values(): array
    {
        return [
            'null' => [null, 'NULL'],
            'true' => [true, 'TRUE'],
            'false' => [false, 'FALSE'],
            'int' => [-42, '-42'],
            'float' => [9.5, '9.5'],
            'whole float' => [3.0, '3.0'],
            'string' => ['plain', "E'plain'"],
            'quote and backslash' => ["it's a \\ path", "E'it\\'s a \\\\ path'"],
            'emoji' => ['ab🚀', "E'ab\\U0001F680'"],
            'vietnamese stays' => ['chào', "E'chào'"],
            'datetime string' => ['2026-01-02 03:04:05', "TIMESTAMP '2026-01-02T03:04:05'"],
            'datetime string with offset' => ['2026-01-02T03:04:05.123+07:00', "TIMESTAMP '2026-01-02T03:04:05.123+07:00'"],
            'date only stays text' => ['2026-01-02', "E'2026-01-02'"],
            'list' => [['a', 1, true], "[E'a', 1, TRUE]"],
            'object' => [['theme' => 'dark', 'at' => 1], "{\"theme\": E'dark', \"at\": 1}"],
            'document' => [new Document(['tags' => ['x'], 'n' => ['k' => null]]), "{\"tags\": [E'x'], \"n\": {\"k\": NULL}}"],
            'empty document' => [new Document([]), '[]'],
        ];
    }

    #[DataProvider('values')]
    public function test_literals(mixed $value, string $expected): void
    {
        $this->assertSame($expected, Literal::of($value));
    }

    public function test_date_time_objects_are_local_timestamps(): void
    {
        $this->assertSame(
            "TIMESTAMP '2026-01-02T03:04:05.000000'",
            Literal::of(new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('Asia/Ho_Chi_Minh')))
        );
    }

    public function test_dates_from_strings_can_be_turned_off(): void
    {
        $this->assertSame("E'2026-01-02 03:04:05'", Literal::of('2026-01-02 03:04:05', datesFromStrings: false));
    }

    public function test_injection_attempts_stay_inside_the_string(): void
    {
        $sql = Literal::inline('select * from "users" where "name" = ?', ["x' or 1=1 --"]);

        $this->assertSame("select * from \"users\" where \"name\" = E'x\\' or 1=1 --'", $sql);
    }

    public function test_placeholders_in_strings_identifiers_and_comments_are_kept(): void
    {
        $this->assertSame(
            "select '?' as \"a?\", E'\\'?' as b, 1 -- ?\n, /* ? */ 2 where x = 5 and y ?? z",
            Literal::inline("select '?' as \"a?\", E'\\'?' as b, 1 -- ?\n, /* ? */ 2 where x = ? and y ?? z", [5])
        );
    }

    public function test_placeholder_and_binding_counts_must_match(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Literal::inline('select ?, ?', [1]);
    }

    public function test_invalid_utf8_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Literal::of("\xC3\x28");
    }

    public function test_nul_bytes_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Literal::of("a\0b");
    }
}
