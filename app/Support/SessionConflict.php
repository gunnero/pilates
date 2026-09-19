<?php

namespace App\Support;

use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class SessionConflict extends HttpException
{
    public function __construct(public readonly string $reason)
    {
        if (! in_array($reason, ['readonly', 'invalid', 'conflict', 'protected', 'reconciliation_failed'], true)) {
            throw new InvalidArgumentException('Unknown session conflict code.');
        }
        parent::__construct(409);
    }
}
