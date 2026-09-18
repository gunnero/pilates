<?php

namespace App\Support;

enum MembershipStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
}
