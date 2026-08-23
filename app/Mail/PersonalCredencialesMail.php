<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class PersonalCredencialesMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $nombre,
        public string $email,
        public string $password,
        public string $rol,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu cuenta en Mis Vales',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.personal-credenciales',
        );
    }

    /**
     * @return array<int, mixed>
     */
    public function attachments(): array
    {
        return [];
    }
}
