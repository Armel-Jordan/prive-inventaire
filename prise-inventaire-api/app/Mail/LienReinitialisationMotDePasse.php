<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Lien de réinitialisation du mot de passe, en français ou en anglais selon la langue de l'écran. */
class LienReinitialisationMotDePasse extends Mailable
{
    use Queueable;

    public function __construct(
        public string $nom,
        public string $entreprise,
        public string $lien,
        public int $minutes,
        public string $langue = 'fr',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->langue === 'en'
            ? "Reset your password — {$this->entreprise}"
            : "Réinitialisation de votre mot de passe — {$this->entreprise}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reinitialisation-mot-de-passe',
            text: 'emails.reinitialisation-mot-de-passe-texte',
        );
    }
}
