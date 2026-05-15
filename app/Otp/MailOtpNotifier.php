<?php

namespace App\Otp;

use App\Contracts\OtpNotifier;
use App\Mail\OtpCodeEmail;
use Illuminate\Support\Facades\Mail;

class MailOtpNotifier implements OtpNotifier
{
    public function send(string $identifier, string $identifierType, string $code): void
    {
        if ($identifierType === 'email') {
            Mail::to($identifier)->queue(new OtpCodeEmail($code));
        }
    }
}
