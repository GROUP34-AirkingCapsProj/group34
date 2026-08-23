<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The one-time code for a login attempt.
 *
 * Carries the user so the message can name the account it belongs to: this now
 * arrives at a personal inbox, where six unexplained digits give the reader no
 * way to tell what they are approving.
 *
 * Send synchronously. Do NOT queue it -- the code is short-lived and a queued
 * send would leave it sitting in the jobs table.
 */
class LoginOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $otp,
        public User $user,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Airking Inventory login code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
