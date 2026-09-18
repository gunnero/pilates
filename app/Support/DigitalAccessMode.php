<?php

namespace App\Support;

enum DigitalAccessMode: string
{
    case App = 'app';
    case Assisted = 'assisted';
    case Offline = 'offline';
}
