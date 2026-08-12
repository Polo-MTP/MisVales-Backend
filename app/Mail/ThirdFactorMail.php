<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class ThirdFactorMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $code,
        public string $rol = 'tu cuenta',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu Código de Acceso - Tercer Factor MisVales',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.third-factor',
            with: ['rol' => $this->rol],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
