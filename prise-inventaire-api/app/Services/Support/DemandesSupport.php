<?php

namespace App\Services\Support;

use App\Mail\CourrielSimple;
use App\Models\AdminUser;
use App\Models\DemandeSupport;
use App\Models\DemandeSupportMessage;
use App\Models\Notification;
use App\Models\SuperAdmin;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Demandes de support et suggestions (plan de parité fripv2, phase 6.7) : création par un compte, réponses de l'usager
 * et de l'éditeur, statut. L'éditeur est prévenu par courriel (adresse du support) ; l'usager, par une notification
 * de son entreprise.
 */
class DemandesSupport
{
    public function __construct(private readonly TenantContext $contexte) {}

    public function creer(AdminUser $compte, array $donnees): DemandeSupport
    {
        $demande = DemandeSupport::create([
            'admin_user_id' => $compte->id,
            'type' => $donnees['type'],
            'sujet' => trim($donnees['sujet']),
            'description' => trim($donnees['description']),
            'priorite' => $donnees['priorite'] ?? 'normale',
            'statut' => 'ouverte',
            'ecran' => $donnees['ecran'] ?? null,
            'dernier_message_le' => now(),
            'dernier_auteur' => 'usager',
        ]);
        $this->prevenirEditeur($demande, $compte, $demande->type === 'suggestion' ? 'Nouvelle suggestion' : 'Nouvelle demande de support', $demande->description);

        return $demande;
    }

    /** Réponse de l'usager : une demande résolue ou fermée se rouvre ; une demande en attente de lui repasse en cours. */
    public function repondreUsager(DemandeSupport $demande, AdminUser $compte, string $message): DemandeSupportMessage
    {
        $reponse = DemandeSupportMessage::create([
            'tenant_id' => $demande->tenant_id, 'demande_support_id' => $demande->id, 'auteur' => 'usager', 'admin_user_id' => $compte->id, 'message' => trim($message),
        ]);
        $statut = match ($demande->statut) {
            'resolue', 'fermee' => 'ouverte',
            'en_attente' => 'en_cours',
            default => $demande->statut,
        };
        $this->changerStatut($demande, $statut, ['dernier_message_le' => now(), 'dernier_auteur' => 'usager']);
        $this->prevenirEditeur($demande, $compte, "Réponse de l'usager", $reponse->message);

        return $reponse;
    }

    /** Réponse de l'éditeur : la demande passe au statut choisi (en attente de l'usager par défaut) ; l'usager est prévenu. */
    public function repondreEditeur(DemandeSupport $demande, SuperAdmin $editeur, string $message, ?string $statut): DemandeSupportMessage
    {
        $reponse = DemandeSupportMessage::create([
            'tenant_id' => $demande->tenant_id, 'demande_support_id' => $demande->id, 'auteur' => 'editeur', 'super_admin_id' => $editeur->id, 'message' => trim($message),
        ]);
        $this->changerStatut($demande, $statut ?? 'en_attente', ['dernier_message_le' => now(), 'dernier_auteur' => 'editeur']);
        $this->prevenirUsager($demande, "Réponse à votre demande n° {$demande->id}", Str::limit($reponse->message, 200));

        return $reponse;
    }

    /** Statut (et date de résolution) ; l'usager est prévenu quand l'éditeur résout sa demande. */
    public function changerStatut(DemandeSupport $demande, string $statut, array $autres = [], bool $prevenir = false): void
    {
        $resolue = match ($statut) {
            'resolue' => $demande->resolue_le ?? now(),
            'fermee' => $demande->resolue_le,
            default => null,
        };
        $demande->update($autres + ['statut' => $statut, 'resolue_le' => $resolue]);
        if ($prevenir && $statut === 'resolue') {
            $this->prevenirUsager($demande, "Demande n° {$demande->id} résolue", $demande->sujet);
        }
    }

    /** Notification à l'auteur de la demande (lui seul la voit), avec le lien vers la demande. */
    private function prevenirUsager(DemandeSupport $demande, string $titre, string $message): void
    {
        $this->contexte->runAsTenant($demande->tenant_id, fn () => Notification::create([
            'type' => 'support', 'titre' => $titre, 'message' => $message, 'lien' => "/support?demande={$demande->id}",
            'destinataire' => $demande->auteur?->nom, 'data' => ['demande_id' => $demande->id], 'admin_user_id' => $demande->admin_user_id,
        ]));
    }

    /** Courriel à l'éditeur (adresse du support), s'il y en a une ; un échec d'envoi n'empêche rien. */
    private function prevenirEditeur(DemandeSupport $demande, AdminUser $compte, string $evenement, string $texte): void
    {
        $adresse = config('support.adresse');
        if (blank($adresse)) {
            return;
        }
        $entreprise = Tenant::find($demande->tenant_id)?->nom ?? '';
        $objet = "{$evenement} n° {$demande->id} — {$entreprise} : {$demande->sujet}";
        $corps = implode("\n", [
            "{$evenement} n° {$demande->id}",
            "Entreprise : {$entreprise}",
            "Compte : {$compte->nom} <{$compte->email}>",
            'Priorité : '.$demande->priorite.' · Statut : '.$demande->statut,
            $demande->ecran ? "Écran : {$demande->ecran}" : '',
            '',
            $texte,
        ]);
        try {
            Mail::to($adresse)->send(new CourrielSimple(
                objetCourriel: Str::limit($objet, 200),
                corpsHtml: nl2br(e($corps), false),
                corpsTexte: $corps,
                expediteur: ['adresse' => (string) config('mail.from.address'), 'nom' => (string) config('mail.from.name')],
                reponseA: $compte->email,
            ));
        } catch (Throwable $erreur) {
            Log::warning("Courriel du support pour la demande {$demande->id} non envoyé : {$erreur->getMessage()}");
        }
    }
}
