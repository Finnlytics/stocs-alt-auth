<p>Hi {{ $user->name }},</p>

<p>You requested a password reset for your STOCS account. Click the link below to reset your password:</p>

<p><a href="{{ config('app.frontend_url') }}/reset-password?token={{ $token }}&email={{ urlencode($user->email) }}">Reset Password</a></p>

<p>This link expires in 60 minutes.</p>

<p>If you did not request a password reset, please ignore this email.</p>

<p>Thanks,<br>The STOCS Team</p>
