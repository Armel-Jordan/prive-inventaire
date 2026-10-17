<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\ComFourLigne;
use App\Models\Fournisseur;
use App\Models\Lot;
use App\Models\LotMouvement;
use App\Models\NumeroSerie;
use App\Models\ProduitTenant;
use App\Models\ReceptionArrivagesLigne;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Lots et numéros de série (plan de parité fripv2, phase 2.8a), pour les produits qui le demandent :
 *  - suivi par lot : chaque réception indique le numéro de lot (et sa date de péremption si le produit l'exige) ; le
 *    lot est créé à sa première réception et tient un journal de quantités en unités de stock ;
 *  - suivi par numéro de série : chaque unité de stock reçue a son numéro, unique pour le produit.
 * Une réception annulée contre-passe ses lots et retire ses numéros de série, sauf si un exemplaire est déjà sorti.
 * Un produit sans suivi se reçoit comme avant (le lot saisi reste une simple mention).
 */
class LotsSeries
{
    /** Quantité reçue en unités de stock (une caisse de 12 : 12). */
    public function unitesStock(ComFourLigne $ligne, int $quantite): int
    {
        return $quantite * max(1, (int) ($ligne->qte_par_unite_achat ?? 1));
    }

    /** Numéros de série saisis, sans blancs ni lignes vides. */
    public function numeros(array $saisie): array
    {
        return array_values(array_filter(array_map(fn ($n) => trim((string) $n), (array) ($saisie['numeros_serie'] ?? [])), fn (string $n) => $n !== ''));
    }

    /**
     * Contrôle, avant tout enregistrement, les saisies d'une réception pour les produits suivis.
     *
     * @param  list<array<string, mixed>>  $saisies
     * @param  Collection<int, ComFourLigne>  $lignes  lignes de commande (avec leur produit), par identifiant
     */
    public function verifierReception(array $saisies, Collection $lignes): void
    {
        $erreurs = [];
        $vus = [];
        foreach ($saisies as $index => $saisie) {
            $ligne = $lignes[(int) $saisie['com_four_ligne_id']];
            $produit = $ligne->produit;
            if ($produit?->suivi === 'lot') {
                $erreur = $this->erreurLot($produit, $saisie);
                if ($erreur) {
                    $erreurs["receptions.{$index}.".$erreur[0]] = $erreur[1];
                }
            } elseif ($produit?->suivi === 'serie') {
                $attendus = $this->unitesStock($ligne, (int) $saisie['quantite_recue']);
                $numeros = $this->numeros($saisie);
                $cle = "receptions.{$index}.numeros_serie";
                if (count($numeros) !== $attendus) {
                    $erreurs[$cle] = "{$produit->numero} se suit par numéro de série : saisissez {$attendus} numéro(s), un par unité ("
                        .count($numeros).' saisi(s)).';

                    continue;
                }
                foreach ($numeros as $numero) {
                    $doublon = $produit->id.'|'.mb_strtolower($numero);
                    if (mb_strlen($numero) > 100) {
                        $erreurs[$cle] = "Le numéro de série « {$numero} » dépasse 100 caractères.";
                    } elseif (isset($vus[$doublon])) {
                        $erreurs[$cle] = "Le numéro de série {$numero} de {$produit->numero} est saisi deux fois.";
                    }
                    $vus[$doublon] = true;
                }
                $existants = NumeroSerie::query()->where('produit_id', $produit->id)->whereIn('numero', $numeros)->pluck('numero');
                if ($existants->isNotEmpty() && ! isset($erreurs[$cle])) {
                    $erreurs[$cle] = "Déjà enregistré(s) pour {$produit->numero} : {$existants->take(5)->implode(', ')}.";
                }
            }
        }
        if ($erreurs) {
            throw ValidationException::withMessages($erreurs);
        }
    }

    /** Lot exigé (et sa péremption si le produit l'exige), cohérent avec le lot déjà enregistré : [champ, message] ou null. */
    private function erreurLot(ProduitTenant $produit, array $saisie): ?array
    {
        $numero = trim((string) ($saisie['lot_numero'] ?? ''));
        if ($numero === '') {
            return ['lot_numero', "{$produit->numero} se suit par lot : indiquez le numéro de lot."];
        }
        $peremption = $saisie['date_peremption'] ?? null;
        if ($produit->peremption && blank($peremption)) {
            return ['date_peremption', "{$produit->numero} : indiquez la date de péremption du lot {$numero}."];
        }
        $lot = Lot::query()->where('produit_id', $produit->id)->where('numero', $numero)->first();
        if ($lot?->date_peremption && filled($peremption) && ! $lot->date_peremption->isSameDay(Carbon::parse($peremption))) {
            return ['date_peremption', "Le lot {$numero} de {$produit->numero} est enregistré avec la péremption du {$lot->date_peremption->format('d/m/Y')}."];
        }

        return null;
    }

    /** Après la création d'une ligne de réception : son lot (créé à sa première réception) et son entrée, ou ses numéros de série. */
    public function enregistrerReception(ReceptionArrivagesLigne $ligne, ComFourLigne $ligneCommande, array $saisie, Fournisseur $fournisseur, ?AdminUser $auteur): void
    {
        $produit = $ligneCommande->produit;
        if ($produit?->suivi === 'lot') {
            $numero = trim((string) $saisie['lot_numero']);
            $peremption = filled($saisie['date_peremption'] ?? null) ? $saisie['date_peremption'] : null;
            $lot = Lot::query()->firstOrCreate(['produit_id' => $produit->id, 'numero' => $numero],
                ['date_peremption' => $peremption, 'fournisseur_id' => $fournisseur->id]);
            if (! $lot->date_peremption && $peremption) {
                $lot->update(['date_peremption' => $peremption]);
            }
            $ligne->update(['lot_id' => $lot->id, 'lot_numero' => $numero]);
            LotMouvement::create(['lot_id' => $lot->id, 'type' => LotMouvement::RECEPTION, 'quantite' => $this->unitesStock($ligneCommande, $ligne->quantite_recue),
                'reception_ligne_id' => $ligne->id, 'date_mouvement' => $ligne->date_reception, 'cree_par' => $auteur?->id]);
        } elseif ($produit?->suivi === 'serie') {
            foreach ($this->numeros($saisie) as $numero) {
                NumeroSerie::create(['produit_id' => $produit->id, 'numero' => $numero, 'statut' => NumeroSerie::EN_STOCK,
                    'reception_ligne_id' => $ligne->id, 'fournisseur_id' => $fournisseur->id, 'recu_le' => $ligne->date_reception]);
            }
        }
    }

    /**
     * Réception annulée : ses lots sont contre-passés et ses numéros de série retirés. Refusé si un exemplaire en est
     * déjà sorti (numéro de série livré ou retourné, lot déjà entamé).
     *
     * @param  Collection<int, ReceptionArrivagesLigne>  $lignes
     */
    public function receptionAnnulee(Collection $lignes, ?AdminUser $auteur): void
    {
        $ids = $lignes->pluck('id');
        $sortis = NumeroSerie::query()->whereIn('reception_ligne_id', $ids)->where('statut', '!=', NumeroSerie::EN_STOCK)->pluck('numero');
        if ($sortis->isNotEmpty()) {
            throw new DomainException("Numéro(s) de série déjà sorti(s) : {$sortis->take(5)->implode(', ')}. La réception ne peut plus être annulée.");
        }
        NumeroSerie::query()->whereIn('reception_ligne_id', $ids)->delete();

        foreach ($lignes->whereNotNull('lot_id') as $ligne) {
            $entree = (float) LotMouvement::query()->where('reception_ligne_id', $ligne->id)->sum('quantite');
            if ($entree <= 0) {
                continue;
            }
            $lot = Lot::query()->lockForUpdate()->findOrFail($ligne->lot_id);
            $solde = (float) LotMouvement::query()->where('lot_id', $lot->id)->sum('quantite');
            if ($solde + 1e-6 < $entree) {
                throw new DomainException("Le lot {$lot->numero} a déjà été entamé ({$solde} sur {$entree} reçus) : la réception ne peut plus être annulée.");
            }
            LotMouvement::create(['lot_id' => $lot->id, 'type' => LotMouvement::ANNULATION_RECEPTION, 'quantite' => -$entree,
                'reception_ligne_id' => $ligne->id, 'date_mouvement' => today(), 'cree_par' => $auteur?->id]);
        }
    }

    /** Le suivi d'un produit ne change plus dès que des lots ou des numéros de série lui sont enregistrés. */
    public function suiviModifiable(ProduitTenant $produit): bool
    {
        return ! Lot::query()->where('produit_id', $produit->id)->exists()
            && ! NumeroSerie::query()->where('produit_id', $produit->id)->exists();
    }
}
