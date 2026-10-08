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
 * Send synchronously. Do NOT queue it. SerializesModels would write $otp --
 * the live plaintext code -- into the job payload, putting it at rest in a
 * store whose access controls have nothing to do with the mail transport's:
 * anyone who can read the jobs table, a failed_jobs row, or a queue backup can
 * complete a login. Hashing the column is pointless if the plaintext is queued
 * alongside it.
 */
class LoginOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * $ttlMinutes is carried rather than written into the template so the
     * lifetime the user is promised is the lifetime the issuer actually set.
     */
    public function __construct(
        public string $otp,
        public User $user,
        public int $ttlMinutes,
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
