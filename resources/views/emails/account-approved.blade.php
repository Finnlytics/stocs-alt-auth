<p>Hi {{ $user->name }},</p>

@if($platform === 'b2b')
<p>Great news! Your Bulk STOCS account has been approved. You can now log in and start purchasing.</p>
@else
<p>Great news! Your Stocs Bids account has been approved. You can now log in and start bidding.</p>
@endif

<p>Thanks,<br>The STOCS Team</p>
