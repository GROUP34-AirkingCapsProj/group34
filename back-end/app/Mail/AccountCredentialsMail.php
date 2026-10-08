<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Carries a live plaintext credential -- the temporary password protecting a
 * brand-new account.
 *
 * Send this synchronously with Mail::to()->send(). Do NOT queue it: the
 * Queueable trait would serialize the plaintext password into the jobs table,
 * leaving the credential at rest somewhere far more accessible than the
 * recipient's inbox.
 */
class AccountCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $plainPassword,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Airking Inventory account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.account_credentials',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
