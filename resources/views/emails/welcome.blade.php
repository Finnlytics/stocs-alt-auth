<p>Hi {{ $user->name }},</p>

@if($platform->value === 'b2b')
<p>Welcome to Bulk STOCS! Your account is currently awaiting approval. We will review your application and notify you once approved.</p>
@else
<p>Welcome to Stocs Bids! Your account is ready to go. Start bidding on exclusive products today.</p>
@endif

<p>Thanks,<br>The STOCS Team</p>
