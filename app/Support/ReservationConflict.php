<?php

namespace App\Support;

use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class ReservationConflict extends HttpException
{
    public function __construct(public readonly string $reason)
    {
        if (! in_array($reason, ['policy', 'session_cancelled', 'session_started', 'session_unavailable', 'member_ineligible', 'capacity_conflict', 'permanent_spot_conflict', 'limit'], true)) {
            throw new InvalidArgumentException('Unknown reservation outcome.');
        }
        parent::__construct(409);
    }
}
