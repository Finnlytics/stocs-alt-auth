<?php

namespace App\Contracts;

interface OtpNotifier
{
    public function send(string $identifier, string $identifierType, string $code): void;
}
