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

    /**
     * Define el asunto del correo del código de tercer factor.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu Código de Acceso - Tercer Factor MisVales',
        );
    }

    /**
     * Renderiza la vista del correo con el código y el rol del destinatario.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.third-factor',
            with: ['rol' => $this->rol],
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
