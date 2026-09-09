<?php

namespace App\Mail;

use App\Domain\Admin\Models\AdminInvitation;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class AdminInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly AdminInvitation $invitation,
        public readonly string $invitationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You are invited to administer '.$this->tenant->name);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.admin-invitation');
    }
}
