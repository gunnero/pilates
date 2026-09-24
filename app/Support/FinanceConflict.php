<?php

namespace App\Support;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class FinanceConflict extends HttpException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(409);
    }
}
