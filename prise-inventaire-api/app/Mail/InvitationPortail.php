<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Lien d'accès au portail client (invitation ou nouveau mot de passe), en français ou en anglais. */
class InvitationPortail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $nom,
        public string $entreprise,
        public string $lien,
        public int $jours,
        public string $langue = 'fr',
        public bool $nouveauMotDePasse = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: match (true) {
            $this->langue === 'en' && $this->nouveauMotDePasse => "Choose a new password — {$this->entreprise} customer portal",
            $this->langue === 'en' => "Your access to the {$this->entreprise} customer portal",
            $this->nouveauMotDePasse => "Nouveau mot de passe — portail client {$this->entreprise}",
            default => "Votre accès au portail client {$this->entreprise}",
        });
    }

    public function content(): Content
    {
        return new Content(view: 'emails.invitation-portail', text: 'emails.invitation-portail-texte');
    }
}
