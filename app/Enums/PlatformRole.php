<?php

namespace App\Enums;

enum PlatformRole: string
{
    case ADMIN = 'admin';
    case WHOLESALER = 'wholesaler';
    case CONSUMER = 'consumer';
}
