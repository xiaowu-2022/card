<?php

namespace App\Mail;

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class UserVerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Tenant $tenant, public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your verification code');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.user-verification-code');
    }
}
