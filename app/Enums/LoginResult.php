<?php

namespace App\Enums;

enum LoginResult: string
{
    case SUCCESS = 'success';
    case INVALID_CREDENTIALS = 'invalid_credentials';
    case PENDING = 'pending';
    case REJECTED = 'rejected';
    case SUSPENDED = 'suspended';
    case NO_ACCESS = 'no_access';
}
