<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Las seis cifras que abren una cuenta, o la crean. El código va también en el asunto:
 * mucha gente lo lee en la notificación sin llegar a abrir el email.
 */
class LoginCode extends Mailable
{
    public function __construct(
        public readonly string $code,
        public readonly bool $isNew = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->code} es tu código para entrar en ".config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.login-code');
    }
}
