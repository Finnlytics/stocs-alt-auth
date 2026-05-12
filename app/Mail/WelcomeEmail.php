<?php

namespace App\Mail;

use App\Enums\Platform;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly Platform $platform
    ) {}

    public function envelope(): Envelope
    {
        $name = $this->platform === Platform::B2B ? 'Bulk STOCS' : 'Stocs Bids';

        return new Envelope(
            subject: "Welcome to {$name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome',
        );
    }
}
