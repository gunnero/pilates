<?php

namespace App\Support;

final class MemberContact
{
    public static function phone(string $value): string
    {
        $value = str_replace([' ', '-', '.', '(', ')'], '', trim($value));

        return str_starts_with($value, '00') ? '+'.substr($value, 2) : $value;
    }
}
