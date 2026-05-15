<?php

namespace App\Otp;

use App\Contracts\OtpNotifier;

class FileOtpNotifier implements OtpNotifier
{
    private string $path;

    public function __construct()
    {
        $this->path = storage_path('otp-codes.txt');
    }

    public function send(string $identifier, string $identifierType, string $code): void
    {
        $line = sprintf(
            "[%s] %s (%s)  →  %s\n",
            now()->format('Y-m-d H:i:s'),
            $identifier,
            $identifierType,
            $code,
        );

        file_put_contents($this->path, $line, FILE_APPEND | LOCK_EX);
    }
}
