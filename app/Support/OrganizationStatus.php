<?php

namespace App\Support;

enum OrganizationStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Suspended = 'suspended';
}
