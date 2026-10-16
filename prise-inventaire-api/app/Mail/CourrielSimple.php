<?php

namespace App\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Symfony\Component\Mime\Email;

/**
 * Courriel déjà rendu (objet, HTML, texte) avec l'enveloppe de l'entreprise : expéditeur,
 * adresse de réponse, accusé de lecture facultatif, pièces jointes générées en mémoire.
 */
class CourrielSimple extends Mailable
{
    /**
     * @param  array{adresse: string, nom: string}  $expediteur
     * @param  list<array{nom: string, contenu: string, mime?: string}>  $fichiers
     */
    public function __construct(
        public string $objetCourriel,
        public string $corpsHtml,
        public string $corpsTexte,
        public array $expediteur,
        public ?string $reponseA = null,
        public bool $accuseLecture = false,
        public array $fichiers = [],
    ) {}

    public function envelope(): Envelope
    {
        $accuse = $this->reponseA ?: $this->expediteur['adresse'];

        return new Envelope(
            from: new Address($this->expediteur['adresse'], $this->expediteur['nom'] ?: null),
            replyTo: $this->reponseA ? [new Address($this->reponseA)] : [],
            subject: $this->objetCourriel,
            using: $this->accuseLecture
                ? [fn (Email $message) => $message->getHeaders()->addMailboxHeader('Disposition-Notification-To', $accuse)]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->corpsHtml, text: 'emails.courriel-texte', with: ['texte' => $this->corpsTexte]);
    }

    public function attachments(): array
    {
        return array_map(
            fn (array $fichier) => Attachment::fromData(fn () => $fichier['contenu'], $fichier['nom'])
                ->withMime($fichier['mime'] ?? 'application/pdf'),
            $this->fichiers,
        );
    }
}
