<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\BonLivraisonLigne;
use App\Models\ComFourLigne;
use App\Models\Facture;
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

    /** Solde d'un lot (somme de son journal), pour les filtres et les tris. */
    public const SOLDE = '(SELECT COALESCE(SUM(m.quantite), 0) FROM lot_mouvements m WHERE m.lot_id = lots.id)';

    /**
     * Lots d'un produit qui ont du stock, dans l'ordre de sortie : le premier à périmer d'abord (FEFO), puis le plus
     * anciennement reçu (FIFO).
     *
     * @return Collection<int, Lot>
     */
    public function lotsDisponibles(int $produitId): Collection
    {
        return Lot::query()->where('produit_id', $produitId)
            ->withSum('journal as solde', 'quantite')
            ->withMin(['journal as premiere_reception' => fn ($q) => $q->where('type', LotMouvement::RECEPTION)], 'date_mouvement')
            ->whereRaw(self::SOLDE.' > 0')
            ->orderByRaw('CASE WHEN date_peremption IS NULL THEN 1 ELSE 0 END')->orderBy('date_peremption')
            ->orderBy('premiere_reception')->orderBy('id')
            ->get();
    }

    /**
     * Répartition automatique d'une sortie sur les lots disponibles, dans l'ordre de sortie, sans dépasser leur stock :
     * ce que les lots ne couvrent pas (stock reçu avant le suivi) sort sans lot.
     *
     * @param  Collection<int, Lot>  $lots
     * @return list<array{0: int, 1: float}> [lot, quantité]
     */
    public function premiersSortis(Collection $lots, float $unites): array
    {
        $allocations = [];
        foreach ($lots as $lot) {
            if ($unites <= 1e-9) {
                break;
            }
            $quantite = min($unites, (float) $lot->solde);
            if ($quantite > 0) {
                $allocations[] = [$lot->id, $quantite];
                $unites -= $quantite;
            }
        }

        return $allocations;
    }

    /**
     * Sortie d'une ligne de bon de livraison : les lots indiqués (sinon les premiers à sortir, dans la limite de leur
     * stock) et les numéros de série indiqués, au client du bon. Un lot ne sort pas plus que ce qu'il en reste ; un
     * numéro de série doit être en stock ; rien ne dépasse la quantité livrée (en unités de stock).
     *
     * @param  list<array{lot_id: int|string, quantite: float|int|string}>|null  $lots
     * @param  list<string>|null  $numeros
     */
    public function livrer(BonLivraisonLigne $ligne, ProduitTenant $produit, float $unites, ?array $lots, ?array $numeros, ?int $clientId, string $champ, ?int $auteurId): void
    {
        if ($unites <= 0) {
            return;
        }
        if ($produit->suivi === 'lot') {
            $disponibles = Lot::query()->where('produit_id', $produit->id)->lockForUpdate()->pluck('id');
            $parId = $this->lotsDisponibles($produit->id)->whereIn('id', $disponibles)->keyBy('id');
            $allocations = $lots === null ? $this->premiersSortis($parId->values(), $unites) : $this->allocationsSaisies($lots, $parId, $unites, $produit, $champ);
            foreach ($allocations as [$lotId, $quantite]) {
                LotMouvement::create(['lot_id' => $lotId, 'type' => LotMouvement::LIVRAISON, 'quantite' => -$quantite, 'bon_livraison_ligne_id' => $ligne->id,
                    'client_id' => $clientId, 'date_mouvement' => today(), 'cree_par' => $auteurId]);
            }
        } elseif ($produit->suivi === 'serie' && $numeros) {
            $series = $this->seriesEnStock($produit, $numeros, $unites, "{$champ}.numeros_serie");
            NumeroSerie::query()->whereIn('id', $series->pluck('id'))->update([
                'statut' => NumeroSerie::LIVRE, 'bon_livraison_ligne_id' => $ligne->id, 'client_id' => $clientId, 'livre_le' => today()->toDateString(),
            ]);
        }
    }

    /**
     * Lots indiqués pour une sortie : des lots du produit qui ont du stock, chacun sans dépasser ce qu'il en reste, et
     * ensemble sans dépasser la quantité sortie.
     *
     * @param  Collection<int, Lot>  $disponibles  par identifiant
     * @return list<array{0: int, 1: float}>
     */
    private function allocationsSaisies(array $lots, Collection $disponibles, float $unites, ProduitTenant $produit, string $champ): array
    {
        $allocations = [];
        $total = 0.0;
        foreach (array_values($lots) as $index => $saisie) {
            $lot = $disponibles->get((int) $saisie['lot_id']);
            $quantite = round((float) $saisie['quantite'], 4);
            if (! $lot) {
                throw ValidationException::withMessages(["{$champ}.lots.{$index}.lot_id" => "Ce lot n'a plus de stock ou n'est pas un lot de {$produit->numero}."]);
            }
            if ($quantite > (float) $lot->solde + 1e-6) {
                throw ValidationException::withMessages(["{$champ}.lots.{$index}.quantite" => "Il ne reste que {$lot->solde} du lot {$lot->numero}."]);
            }
            $allocations[] = [$lot->id, $quantite];
            $total += $quantite;
        }
        if ($total > $unites + 1e-6) {
            throw ValidationException::withMessages(["{$champ}.lots" => "Les lots de {$produit->numero} dépassent la quantité livrée ({$total} pour {$unites})."]);
        }

        return $allocations;
    }

    /**
     * Numéros de série indiqués pour une sortie : connus pour le produit, en stock, sans doublon, pas plus que la
     * quantité sortie.
     *
     * @return Collection<int, NumeroSerie>
     */
    private function seriesEnStock(ProduitTenant $produit, array $numeros, float $unites, string $champ): Collection
    {
        $numeros = array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n), $numeros), fn (string $n) => $n !== '')));
        if (count($numeros) > $unites) {
            throw ValidationException::withMessages([$champ => "{$produit->numero} : ".count($numeros)." numéro(s) de série pour {$unites} unité(s) livrée(s)."]);
        }
        $series = NumeroSerie::query()->where('produit_id', $produit->id)->whereIn('numero', $numeros)->lockForUpdate()->get();
        $parNumero = $series->keyBy(fn (NumeroSerie $s) => mb_strtolower($s->numero));
        foreach ($numeros as $numero) {
            $serie = $parNumero->get(mb_strtolower($numero));
            if (! $serie) {
                throw ValidationException::withMessages([$champ => "Numéro de série inconnu pour {$produit->numero} : {$numero}."]);
            }
            if ($serie->statut !== NumeroSerie::EN_STOCK) {
                throw ValidationException::withMessages([$champ => "Le numéro de série {$numero} n'est pas en stock."]);
            }
        }

        return $series;
    }

    /** Vente au comptoir : ses produits suivis par lot sortent des premiers lots à sortir, au client de la vente. */
    public function venteComptoir(Facture $facture, ?int $auteurId): void
    {
        foreach ($facture->lignes()->with('produit')->get() as $ligne) {
            if ($ligne->produit?->suivi !== 'lot') {
                continue;
            }
            $lots = Lot::query()->where('produit_id', $ligne->produit->id)->lockForUpdate()->pluck('id');
            $unites = round((float) $ligne->quantite * $ligne->facteurStock(), 4);
            foreach ($this->premiersSortis($this->lotsDisponibles($ligne->produit->id)->whereIn('id', $lots)->values(), $unites) as [$lotId, $quantite]) {
                LotMouvement::create(['lot_id' => $lotId, 'type' => LotMouvement::VENTE_COMPTOIR, 'quantite' => -$quantite, 'facture_ligne_id' => $ligne->id,
                    'client_id' => $facture->client_id, 'date_mouvement' => $facture->date_facture ?? today(), 'cree_par' => $auteurId]);
            }
        }
    }

    /**
     * Rappel d'un lot : les clients qui l'ont reçu (livraisons, ventes au comptoir), avec le document, la date, la
     * quantité et leurs coordonnées.
     *
     * @return list<array<string, mixed>>
     */
    public function rappel(Lot $lot): array
    {
        return LotMouvement::query()->where('lot_id', $lot->id)->whereIn('type', LotMouvement::SORTIES_CLIENT)
            ->with(['client:id,code,raison_sociale,telephone,email', 'bonLivraisonLigne.bonLivraison:id,numero,date_livraison', 'factureLigne.facture:id,numero,date_facture'])
            ->orderBy('date_mouvement')->orderBy('id')->get()
            ->map(fn (LotMouvement $m) => [
                'date' => $m->date_mouvement?->toDateString(),
                'document' => $m->bonLivraisonLigne?->bonLivraison?->numero ?? $m->factureLigne?->facture?->numero,
                'type' => $m->type,
                'quantite' => round(-$m->quantite, 4),
                'client' => $m->client?->only(['id', 'code', 'raison_sociale', 'telephone', 'email']),
            ])->values()->all();
    }

    /** Le suivi d'un produit ne change plus dès que des lots ou des numéros de série lui sont enregistrés. */
    public function suiviModifiable(ProduitTenant $produit): bool
    {
        return ! Lot::query()->where('produit_id', $produit->id)->exists()
            && ! NumeroSerie::query()->where('produit_id', $produit->id)->exists();
    }
}
