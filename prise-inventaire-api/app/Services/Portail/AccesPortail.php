<?php

namespace App\Services\Portail;

use App\Mail\InvitationPortail;
use App\Models\CourrielParametres;
use App\Models\PortailCompte;
use App\Models\PortailJeton;
use App\Models\PortailParametres;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Services\Courriels\MailerTenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Accès au portail client : sessions (jetons propres au portail, sans rapport avec ceux des employés),
 * invitations et liens de nouveau mot de passe, envoyés au nom de l'entreprise.
 */
class AccesPortail
{
    /** Durée d'une session du portail. */
    public const SESSION_JOURS = 30;

    /** Durée de validité d'un lien d'invitation (ou de nouveau mot de passe). */
    public const INVITATION_JOURS = 7;

    public function __construct(private MailerTenant $mailer) {}

    /** Ouvre une session : renvoie le jeton à remettre au navigateur (seule son empreinte est gardée). */
    public function ouvrirSession(PortailCompte $compte): string
    {
        $jeton = Str::random(64);
        PortailJeton::create([
            'portail_compte_id' => $compte->id,
            'jeton' => hash('sha256', $jeton),
            'expire_le' => now()->addDays(self::SESSION_JOURS),
        ]);
        $compte->forceFill(['derniere_connexion' => now()])->save();

        return $jeton;
    }

    /** Nouveau lien d'invitation (remplace le précédent) : renvoie le jeton en clair, pour le lien. */
    public function nouvelleInvitation(PortailCompte $compte): string
    {
        $jeton = Str::random(64);
        $compte->forceFill([
            'invitation_jeton' => hash('sha256', $jeton),
            'invitation_expire_le' => now()->addDays(self::INVITATION_JOURS),
        ])->save();

        return $jeton;
    }

    /** Compte d'un lien d'invitation encore valable (toutes entreprises confondues : le lien la désigne). */
    public function compteDuLien(string $jeton): ?PortailCompte
    {
        return PortailCompte::withoutGlobalScopes()
            ->where('invitation_jeton', hash('sha256', $jeton))
            ->where('invitation_expire_le', '>', now())
            ->first();
    }

    /** Lien d'invitation ou de nouveau mot de passe, sur le site de l'espace client. */
    public function lien(Tenant $tenant, string $jeton): string
    {
        return $this->urlEspaceClient().'/activation?'.http_build_query(['entreprise' => $tenant->slug, 'jeton' => $jeton]);
    }

    /**
     * Adresse du site de l'espace client : ESPACE_CLIENT_URL, sinon celle que le back-office de l'entreprise a
     * transmise (réglages, invitations), sinon FRONTEND_URL, puis APP_URL. Une adresse envoyée par un visiteur
     * anonyme (mot de passe oublié) n'est jamais reprise : elle permettrait d'envoyer un lien piégé vers un
     * autre site.
     */
    public function urlEspaceClient(): string
    {
        $adresse = config('portail.url') ?: PortailParametres::courants()->url_espace_client ?: config('comptes.url_front') ?: config('app.url');

        return rtrim((string) $adresse, '/');
    }

    /** Retient l'adresse du site de l'espace client transmise par le back-office (utilisateur connecté). */
    public function memoriserAdresse(?string $adresse): void
    {
        $adresse = self::normaliserAdresse($adresse);
        $parametres = PortailParametres::courants();
        if ($adresse !== null && $parametres->url_espace_client !== $adresse) {
            $parametres->fill(['url_espace_client' => $adresse])->save();
        }
    }

    /** Adresse http(s) sans requête ni ancre, sans / final ; null si elle n'est pas valable. */
    public static function normaliserAdresse(?string $adresse): ?string
    {
        $parties = parse_url(trim((string) $adresse));
        if (! is_array($parties) || ! in_array($parties['scheme'] ?? null, ['http', 'https'], true) || empty($parties['host'])) {
            return null;
        }
        $normalisee = $parties['scheme'].'://'.$parties['host'].(isset($parties['port']) ? ':'.$parties['port'] : '').rtrim($parties['path'] ?? '', '/');

        return strlen($normalisee) <= 255 ? $normalisee : null;
    }

    /**
     * Envoie le lien par courriel, avec le serveur d'envoi de l'entreprise. Un échec est journalisé et signalé
     * (false) : l'écran donne alors le lien à transmettre autrement.
     */
    public function envoyer(PortailCompte $compte, Tenant $tenant, string $lien, string $langue, bool $nouveauMotDePasse): bool
    {
        try {
            $parametres = CourrielParametres::first();
            $expediteur = $this->mailer->expediteur($parametres);
            $entreprise = TenantParametres::query()->value('nom_entreprise') ?: $tenant->nom;
            $courriel = (new InvitationPortail($compte->nom, $entreprise, $lien, self::INVITATION_JOURS, $langue, $nouveauMotDePasse))
                ->from($expediteur['adresse'], $expediteur['nom']);
            if ($repondre = $this->mailer->repondreA($parametres)) {
                $courriel->replyTo($repondre);
            }
            $this->mailer->mailer($parametres)->to($compte->email)->send($courriel);

            return true;
        } catch (Throwable $erreur) {
            Log::warning('Portail client : courriel d\'accès non envoyé', ['compte' => $compte->id, 'erreur' => $erreur->getMessage()]);

            return false;
        }
    }
}
