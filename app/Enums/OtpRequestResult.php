<?php

namespace App\Enums;

enum OtpRequestResult: string
{
    // Login flow: OTP was sent because the user exists.
    case SENT = 'sent';

    // Login flow: identifier has no matching user. We pretend success to the
    // caller (return 202 with a generic "if you have an account" message) so
    // we don't leak user existence.
    case ACCOUNT_NOT_FOUND = 'account_not_found';

    // Signup flow: identifier already has an account — refuse with a clear
    // status so the frontend can redirect them to sign in.
    case ACCOUNT_ALREADY_EXISTS = 'account_already_exists';

    // Login flow: the user exists but their Bids access is suspended. Unlike
    // ACCOUNT_NOT_FOUND we don't hide this — mirrors the explicit disclosure
    // already made at OTP-verify time (LoginResult::SUSPENDED) — so no OTP is
    // wasted sending a code to an account that would be blocked anyway.
    case ACCOUNT_SUSPENDED = 'account_suspended';

    // Identifier is currently locked out by exponential backoff. The caller
    // must respect retry_after.
    case RATE_LIMITED = 'rate_limited';
}
