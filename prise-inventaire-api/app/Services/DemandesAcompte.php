<?php

namespace App\Services;

use App\Models\ComClientEntete;
use App\Models\Configuration;
use App\Models\DemandeAcompte;
use App\Models\Devis;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Demandes d'acompte (plan de parité fripv2, phase 1.5) : un dépôt demandé au client, avec une description et un
 * montant sans taxe, rattaché au besoin à un devis ou à une commande du client.
 *
 * Brouillon (modifiable), envoyée (par courriel, ou marquée à la main ; elle ne se modifie plus), puis payée : la
 * marquer payée enregistre l'acompte au dossier du client, réservé à la commande de la demande s'il y en a une.
 * Ensuite, l'usage de cet acompte donne l'état « partiellement appliquée » ou « appliquée ».
 */
class DemandesAcompte
{
    public function __construct(private readonly Acomptes $acomptes) {}

    /** @param array{client_id: int, date_demande: string, description: string, montant: float|string, devis_id?: ?int, commande_id?: ?int} $donnees */
    public function creer(array $donnees, int $tenantId, ?int $auteur = null): DemandeAcompte
    {
        $this->verifierDocuments((int) $donnees['client_id'], $donnees);
        $config = Configuration::pourEntite('demande_acompte', $tenantId);
        if (! $config || ! $config->auto_increment) {
            throw new DomainException("Numéro requis : activez la numérotation automatique des demandes d'acompte (Configuration → Numérotation).");
        }

        return DB::transaction(fn () => DemandeAcompte::create($this->champs($donnees) + [
            'client_id' => $donnees['client_id'],
            'numero' => Configuration::consommerNumero('demande_acompte', $tenantId),
            'statut' => DemandeAcompte::BROUILLON,
            'cree_par' => $auteur,
        ]));
    }

    public function modifier(DemandeAcompte $demande, array $donnees): DemandeAcompte
    {
        if ($demande->statut !== DemandeAcompte::BROUILLON) {
            throw new DomainException("Une demande d'acompte envoyée ou payée ne se modifie plus.");
        }
        $client = (int) ($donnees['client_id'] ?? $demande->client_id);
        $this->verifierDocuments($client, $donnees + $demande->only(['devis_id', 'commande_id']));
        $demande->update($this->champs($donnees) + ['client_id' => $client]);

        return $demande;
    }

    /** Marque la demande envoyée (aussi fait à son envoi par courriel). Sans effet si elle l'est déjà. */
    public function envoyer(DemandeAcompte $demande): DemandeAcompte
    {
        if ($demande->statut === DemandeAcompte::BROUILLON) {
            $demande->update(['statut' => DemandeAcompte::ENVOYEE, 'envoyee_le' => now()]);
        }

        return $demande;
    }

    /** Après l'envoi de la demande par courriel. */
    public function apresEnvoiCourriel(int $demandeId): void
    {
        $demande = DemandeAcompte::find($demandeId);
        if ($demande) {
            $this->envoyer($demande);
        }
    }

    /**
     * Dépôt reçu : la demande passe payée et l'acompte est enregistré au dossier du client (réservé à la commande de
     * la demande, si elle est toujours en cours).
     *
     * @param  array{date: string, mode: string, reference?: ?string}  $paiement
     */
    public function encaisser(DemandeAcompte $demande, array $paiement, ?int $auteur = null): DemandeAcompte
    {
        return DB::transaction(function () use ($demande, $paiement, $auteur) {
            $demande = DemandeAcompte::whereKey($demande->id)->lockForUpdate()->firstOrFail();
            if ($demande->statut === DemandeAcompte::PAYEE) {
                throw new DomainException("La demande d'acompte {$demande->numero} est déjà payée.");
            }
            if ($paiement['date'] < $demande->date_demande->toDateString()) {
                throw ValidationException::withMessages(['date' => 'Le paiement ne peut pas précéder la demande ('.$demande->date_demande->format('d/m/Y').').']);
            }
            $commande = $demande->commande_id ? ComClientEntete::find($demande->commande_id) : null;
            $acompte = $this->acomptes->recevoir([
                'client_id' => $demande->client_id,
                'date_acompte' => $paiement['date'],
                'mode' => $paiement['mode'],
                'reference' => $paiement['reference'] ?? null,
                'montant' => $demande->montant,
                'commande_id' => $commande && ! in_array($commande->statut, ['refusee', 'annulee'], true) ? $commande->id : null,
                'demande_id' => $demande->id,
                'notes' => "Demande d'acompte {$demande->numero}",
            ], $auteur);
            $demande->update(['statut' => DemandeAcompte::PAYEE, 'payee_le' => now(), 'acompte_id' => $acompte->id]);

            return $demande;
        });
    }

    /** Supprime une demande qui n'est pas payée. */
    public function supprimer(DemandeAcompte $demande): void
    {
        if ($demande->statut === DemandeAcompte::PAYEE) {
            throw new DomainException("Une demande d'acompte payée ne se supprime plus.");
        }
        $demande->delete();
    }

    private function champs(array $donnees): array
    {
        return array_intersect_key($donnees, array_flip(['date_demande', 'description', 'montant', 'devis_id', 'commande_id']));
    }

    /** Devis et commande rattachés : du même client (une commande refusée ou annulée ne compte plus). */
    private function verifierDocuments(int $clientId, array $donnees): void
    {
        if (! empty($donnees['devis_id'])) {
            $devis = Devis::find((int) $donnees['devis_id']);
            if (! $devis || $devis->client_id !== $clientId) {
                throw ValidationException::withMessages(['devis_id' => 'Choisissez un devis du même client.']);
            }
        }
        if (! empty($donnees['commande_id'])) {
            $commande = ComClientEntete::find((int) $donnees['commande_id']);
            if (! $commande || $commande->client_id !== $clientId || in_array($commande->statut, ['refusee', 'annulee'], true)) {
                throw ValidationException::withMessages(['commande_id' => 'Choisissez une commande en cours du même client.']);
            }
        }
    }
}
