<?php

namespace App\Services;

use App\Models\Acompte;
use App\Models\AcompteRemboursement;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\DemandeAcompte;
use App\Models\Facture;
use App\Models\FacturePaiement;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Acomptes des clients (plan de parité fripv2, phase 1.5), comme la fenêtre « Acomptes » de fripv2 :
 * - recevoir un acompte, libre ou réservé à une commande du client ;
 * - l'appliquer au paiement d'une facture due du client : un paiement de la facture rattaché à l'acompte, donc le
 *   reste à payer, les échéances, l'encours et les comptes clients suivent comme pour tout paiement ;
 * - à l'émission d'une facture, les acomptes réservés à sa commande s'appliquent d'eux-mêmes ;
 * - rembourser ce qui en reste, par le mode de paiement de l'acompte par défaut.
 *
 * Aucune écriture comptable n'est passée : la comptabilité ne reprend pas encore les documents de vente.
 */
class Acomptes
{
    public const MODES = ['especes', 'cheque', 'virement', 'carte', 'autre'];

    /** Factures sur lesquelles appliquer un acompte : celles qui restent dues. */
    public const FACTURES_DUES = ['emise', 'partiellement_payee'];

    /** Commandes auxquelles un acompte ne se réserve plus. */
    private const COMMANDES_CLOSES = ['refusee', 'annulee'];

    private const TOLERANCE = 0.005;

    public function __construct(private readonly Caisses $caisses) {}

    /**
     * Reçoit un acompte ; reçu sur le poste d'une caisse (`caisse_id`), il entre dans sa session ouverte.
     *
     * @param  array{client_id: int, date_acompte: string, mode: string, reference?: ?string, montant: float|string, commande_id?: ?int, demande_id?: ?int, notes?: ?string, devise?: ?string, taux_change?: mixed, caisse_id?: ?int}  $donnees
     */
    public function recevoir(array $donnees, ?int $auteur = null): Acompte
    {
        $this->verifierCommande((int) $donnees['client_id'], $donnees['commande_id'] ?? null);
        // Devise de l'acompte : celle demandée, sinon celle du client (ou de la commande réservée).
        $commande = ! empty($donnees['commande_id']) ? ComClientEntete::find((int) $donnees['commande_id']) : null;
        $devise = array_key_exists('devise', $donnees) || ! $commande
            ? app(DevisesVente::class)->pourDocument($donnees['devise'] ?? null, $donnees['taux_change'] ?? null, Client::find((int) $donnees['client_id']))
            : ['devise' => $commande->devise, 'taux_change' => (float) ($commande->taux_change ?: 1)];

        return DB::transaction(fn () => Acompte::create($devise + [
            'client_id' => $donnees['client_id'],
            'date_acompte' => $donnees['date_acompte'],
            'mode' => $donnees['mode'],
            'reference' => $donnees['reference'] ?? null,
            'montant' => round((float) $donnees['montant'], 2),
            'commande_id' => $donnees['commande_id'] ?? null,
            'demande_id' => $donnees['demande_id'] ?? null,
            'notes' => $donnees['notes'] ?? null,
            'enregistre_par' => $auteur,
            'caisse_session_id' => $this->caisses->sessionPourEncaissement($donnees['caisse_id'] ?? null),
        ]));
    }

    /**
     * Modifie un acompte : référence, notes et réservation toujours ; date, mode et montant tant qu'il n'a servi à
     * rien (ni appliqué ni remboursé).
     */
    public function modifier(Acompte $acompte, array $donnees): Acompte
    {
        $utilise = (float) $acompte->montant_applique > 0 || (float) $acompte->montant_rembourse > 0;
        $change = fn (string $champ, mixed $valeur) => array_key_exists($champ, $donnees) && (string) $donnees[$champ] !== (string) $valeur;
        if ($utilise && ($change('date_acompte', $acompte->date_acompte->toDateString()) || $change('mode', $acompte->mode)
            || (array_key_exists('montant', $donnees) && abs((float) $donnees['montant'] - (float) $acompte->montant) > self::TOLERANCE))) {
            throw new DomainException('Un acompte déjà appliqué ou remboursé ne change plus de date, de mode ni de montant.');
        }
        if (array_key_exists('commande_id', $donnees)) {
            $this->verifierCommande((int) $acompte->client_id, $donnees['commande_id']);
        }
        $acompte->update(array_intersect_key($donnees, array_flip(['date_acompte', 'mode', 'reference', 'montant', 'commande_id', 'notes'])));

        return $acompte;
    }

    /** Applique une part de l'acompte au paiement d'une facture due du même client. */
    public function appliquer(Acompte $acompte, int $factureId, float $montant, string $date, ?int $auteur = null): Acompte
    {
        return DB::transaction(function () use ($acompte, $factureId, $montant, $date, $auteur) {
            // Verrous : deux applications simultanées ne peuvent pas dépasser l'acompte ni le reste de la facture.
            $acompte = Acompte::whereKey($acompte->id)->lockForUpdate()->firstOrFail();
            $facture = Facture::whereKey($factureId)->lockForUpdate()->first();
            if (! $facture || $facture->client_id !== $acompte->client_id) {
                throw ValidationException::withMessages(['facture_id' => 'Choisissez une facture du même client.']);
            }
            if (! in_array($facture->statut, self::FACTURES_DUES, true)) {
                throw ValidationException::withMessages(['facture_id' => "La facture {$facture->numero} ne reste pas due."]);
            }
            if (! DevisesVente::memeDevise($acompte->devise, $facture->devise)) {
                throw ValidationException::withMessages(['facture_id' => "La facture {$facture->numero} n'est pas dans la devise de l'acompte."]);
            }
            if ($acompte->disponible <= 0) {
                throw new DomainException("Il ne reste rien de l'acompte du {$acompte->date_acompte->format('d/m/Y')}.");
            }
            if ($montant > $acompte->disponible + self::TOLERANCE) {
                throw ValidationException::withMessages(['montant' => "Le montant dépasse ce qui reste de l'acompte ({$this->montant($acompte->disponible)})."]);
            }
            if ($montant > (float) $facture->reste_a_payer + self::TOLERANCE) {
                throw ValidationException::withMessages(['montant' => "Le montant dépasse le reste à payer de la facture ({$this->montant($facture->reste_a_payer)})."]);
            }
            $plusTot = max($acompte->date_acompte->toDateString(), $facture->date_facture->toDateString());
            if ($date < $plusTot) {
                throw ValidationException::withMessages(['date' => 'La date ne peut pas précéder celle de l\'acompte ni celle de la facture ('.Carbon::parse($plusTot)->format('d/m/Y').').']);
            }

            // Un paiement de la facture comme un autre (reste, statut, échéances, encours), rattaché à l'acompte.
            FacturePaiement::create([
                'facture_id' => $facture->id,
                'acompte_id' => $acompte->id,
                'date_paiement' => $date,
                'montant' => round($montant, 2),
                'mode_paiement' => 'autre',
                'reference' => mb_substr('Acompte'.($acompte->reference ? ' '.$acompte->reference : ' du '.$acompte->date_acompte->format('d/m/Y')), 0, 100),
                'enregistre_par' => $auteur,
            ]);
            $this->actualiser($acompte);

            return $acompte;
        });
    }

    /**
     * Facture qui vient d'être émise : les acomptes réservés à sa commande s'y appliquent d'eux-mêmes, au plus ce
     * qui reste dû. Ce qui en reste redevient un crédit libre du client.
     */
    public function appliquerReserves(Facture $facture, ?int $auteur = null): void
    {
        if (! $facture->commande_id) {
            return;
        }
        $acomptes = Acompte::where('commande_id', $facture->commande_id)->where('client_id', $facture->client_id)
            ->orderBy('date_acompte')->orderBy('id')->get();
        foreach ($acomptes as $acompte) {
            $facture->refresh();
            if (! DevisesVente::memeDevise($acompte->devise, $facture->devise)) {
                continue;
            }
            $reste = (float) $facture->reste_a_payer;
            if ($reste <= self::TOLERANCE || ! in_array($facture->statut, self::FACTURES_DUES, true)) {
                break;
            }
            if ($acompte->disponible <= 0) {
                continue;
            }
            $date = max(Carbon::today()->toDateString(), $acompte->date_acompte->toDateString(), $facture->date_facture->toDateString());
            $this->appliquer($acompte, $facture->id, min($acompte->disponible, $reste), $date, $auteur);
        }
        // La réservation est consommée : le reste éventuel redevient libre.
        Acompte::where('commande_id', $facture->commande_id)->update(['commande_id' => null]);
    }

    /**
     * Rembourse au client une part de ce qui reste de l'acompte (par défaut, par son mode de paiement) ; fait sur le
     * poste d'une caisse (`caisse_id`), l'argent sort de sa session ouverte.
     *
     * @param  array{montant: float|string, date_remboursement: string, mode?: ?string, reference?: ?string, notes?: ?string, caisse_id?: ?int}  $donnees
     */
    public function rembourser(Acompte $acompte, array $donnees, ?int $auteur = null): Acompte
    {
        return DB::transaction(function () use ($acompte, $donnees, $auteur) {
            $acompte = Acompte::whereKey($acompte->id)->lockForUpdate()->firstOrFail();
            $montant = (float) $donnees['montant'];
            if ($acompte->disponible <= 0) {
                throw new DomainException("Il ne reste rien à rembourser de l'acompte du {$acompte->date_acompte->format('d/m/Y')}.");
            }
            if ($montant > $acompte->disponible + self::TOLERANCE) {
                throw ValidationException::withMessages(['montant' => "Le montant dépasse ce qui reste de l'acompte ({$this->montant($acompte->disponible)})."]);
            }
            if ($donnees['date_remboursement'] < $acompte->date_acompte->toDateString()) {
                throw ValidationException::withMessages(['date_remboursement' => 'Le remboursement ne peut pas précéder l\'acompte ('.$acompte->date_acompte->format('d/m/Y').').']);
            }
            AcompteRemboursement::create([
                'acompte_id' => $acompte->id,
                'date_remboursement' => $donnees['date_remboursement'],
                'montant' => round($montant, 2),
                // L'argent ressort par où il est entré, sauf choix contraire.
                'mode' => $donnees['mode'] ?? $acompte->mode,
                'reference' => $donnees['reference'] ?? null,
                'notes' => $donnees['notes'] ?? null,
                'enregistre_par' => $auteur,
                'caisse_session_id' => $this->caisses->sessionPourEncaissement($donnees['caisse_id'] ?? null),
            ]);
            $this->actualiser($acompte);

            return $acompte;
        });
    }

    /**
     * Supprime un acompte saisi par erreur, qui n'a servi à rien. Celui d'une demande d'acompte remet la demande
     * dans son état d'avant le paiement.
     */
    public function supprimer(Acompte $acompte): void
    {
        if ((float) $acompte->montant_applique > 0 || (float) $acompte->montant_rembourse > 0) {
            throw new DomainException('Un acompte déjà appliqué ou remboursé ne se supprime plus.');
        }
        DB::transaction(function () use ($acompte) {
            if ($acompte->demande_id) {
                DemandeAcompte::whereKey($acompte->demande_id)->where('acompte_id', $acompte->id)->get()
                    ->each(fn (DemandeAcompte $demande) => $demande->update([
                        'statut' => $demande->envoyee_le ? DemandeAcompte::ENVOYEE : DemandeAcompte::BROUILLON,
                        'payee_le' => null,
                        'acompte_id' => null,
                    ]));
            }
            $acompte->delete();
        });
    }

    /** Totaux appliqués et remboursés de l'acompte. */
    private function actualiser(Acompte $acompte): void
    {
        $acompte->montant_applique = $acompte->applications()->sum('montant');
        $acompte->montant_rembourse = $acompte->remboursements()->sum('montant');
        $acompte->save();
    }

    /** Une commande à laquelle réserver un acompte : du même client, ni refusée ni annulée. */
    private function verifierCommande(int $clientId, mixed $commandeId): void
    {
        if (empty($commandeId)) {
            return;
        }
        $commande = ComClientEntete::find((int) $commandeId);
        if (! $commande || $commande->client_id !== $clientId || in_array($commande->statut, self::COMMANDES_CLOSES, true)) {
            throw ValidationException::withMessages(['commande_id' => 'Choisissez une commande en cours du même client.']);
        }
    }

    private function montant(mixed $valeur): string
    {
        return number_format((float) $valeur, 2, ',', ' ');
    }
}
