<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * PROGRAM PHASE 2 (password reset, SECURITY.md §5). Delivers the single-use
 * reset token to the account owner. In this codebase the default mailer is
 * the 'log' driver (MAIL_MAILER=log), so the token is written to the mail
 * log in local/testing environments — the SAME channel the reset link uses
 * in production once a real mailer is configured. No token value is stored
 * in the database (only its SHA-256 hash), and the token is single-use and
 * short-lived.
 */
final class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $token,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Swasthya — Reset your password');
    }

    public function content(): Content
    {
        return new Content(
            text: 'swasthya::mail.reset-password',
            with: ['token' => $this->token],
        );
    }
}
