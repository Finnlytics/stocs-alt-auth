<?php

return [

    /*
    | OTP_NOTIFIER=file  → writes identifier + code to storage/otp-codes.txt (local dev)
    | OTP_NOTIFIER=mail  → queues OtpCodeEmail via the configured MAIL_MAILER (default)
    */
    'notifier' => env('OTP_NOTIFIER', 'mail'),

];
