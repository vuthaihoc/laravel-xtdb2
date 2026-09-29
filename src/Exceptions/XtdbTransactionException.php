<?php

namespace LaravelXtdb\Exceptions;

use RuntimeException;
use Throwable;

/**
 * An XTDB transaction is read-only or read-write, decided by its first
 * statement, and a read-write transaction runs no queries (only ASSERT).
 */
class XtdbTransactionException extends RuntimeException
{
    public static function readInWriteTransaction(Throwable $previous): self
    {
        return new self(
            'XTDB does not run queries inside a transaction that writes. Read before DB::transaction() and '
            .'write inside it, or check conditions with an ASSERT statement.',
            0,
            $previous,
        );
    }

    public static function writeInReadTransaction(Throwable $previous): self
    {
        return new self(
            'XTDB made this transaction read-only because its first statement was a query, so it cannot write. '
            .'Read before DB::transaction() and write inside it.',
            0,
            $previous,
        );
    }
}
