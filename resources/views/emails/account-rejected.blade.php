<p>Hi {{ $user->name }},</p>

<p>We have reviewed your account application and unfortunately we are unable to approve it at this time.</p>

@if($reason)
<p><strong>Reason:</strong> {{ $reason }}</p>
@endif

<p>If you believe this is an error, please contact us.</p>

<p>Thanks,<br>The STOCS Team</p>
