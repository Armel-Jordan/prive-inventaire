<?php

namespace App\Services\Courriels;

use App\Models\CourrielParametres;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * Serveur d'envoi et enveloppe des courriels d'une entreprise : le SMTP de l'entreprise s'il
 * est configuré, sinon celui de la plateforme (au nom de l'entreprise, réponses dirigées vers
 * son adresse).
 */
class MailerTenant
{
    public function mailer(?CourrielParametres $parametres): Mailer
    {
        return $parametres?->utiliseSmtp() ? Mail::build($this->configSmtp($parametres)) : Mail::mailer();
    }

    /** Transport SMTP propre à l'entreprise (format de config/mail.php). */
    public function configSmtp(CourrielParametres $parametres): array
    {
        $chiffrement = $parametres->smtp_chiffrement ?: 'tls';

        return [
            'transport' => 'smtp',
            'scheme' => $chiffrement === 'ssl' ? 'smtps' : 'smtp',
            'host' => $parametres->smtp_hote,
            'port' => $parametres->smtp_port ?: ($chiffrement === 'ssl' ? 465 : 587),
            'username' => $parametres->smtp_utilisateur,
            'password' => $parametres->smtp_mot_de_passe,
            'timeout' => 20,
            // « tls » exige STARTTLS ; « aucun » ne le tente pas.
            'require_tls' => $chiffrement === 'tls',
            'auto_tls' => $chiffrement !== 'aucun',
        ];
    }

    /** @return array{adresse: string, nom: string} */
    public function expediteur(?CourrielParametres $parametres): array
    {
        $nom = $parametres?->expediteur_nom ?: $this->nomEntreprise();

        if ($parametres?->utiliseSmtp()) {
            return ['adresse' => $parametres->expediteur_adresse ?: (string) $parametres->smtp_utilisateur, 'nom' => $nom];
        }

        return ['adresse' => (string) config('mail.from.address'), 'nom' => $nom ?: (string) config('mail.from.name')];
    }

    /** Adresse de réponse : celle du modèle, sinon celle des paramètres d'envoi, sinon le courriel de l'entreprise. */
    public function repondreA(?CourrielParametres $parametres, ?string $duModele = null): ?string
    {
        return $duModele ?: ($parametres?->repondre_a ?: (TenantParametres::first()?->email ?: null));
    }

    private function nomEntreprise(): string
    {
        return TenantParametres::first()?->nom_entreprise
            ?: (Tenant::find(app(TenantContext::class)->getTenantId())?->nom ?? '');
    }
}
