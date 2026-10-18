<?php

namespace App\Services;

use App\Models\Configuration;
use App\Models\ContratLocation;
use App\Models\ContratLocationLigne;
use App\Models\Facture;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contrats de location (plan de parité fripv2, phase 1.8 : « Contrats de location »). Des produits sont loués à un
 * client sur une période. Chaque ligne a deux prix :
 *  - le prix de contrat, facturé une fois (« Facturer le contrat ») : contenu, caution, mise en service ;
 *  - le loyer, par unité et par période, facturé à chaque échéance pour les unités louées, moins les exemptées et
 *    les rendues.
 * Les loyers suivent le rythme du contrat (mensuel à annuel), au jour choisi, à l'avance (à échoir) ou après coup.
 * Le renouvellement crée le contrat suivant (à la main, aux mêmes prix ; automatiquement à la fin, prix augmentés des
 * taux du contrat, avec la facture de ses prix de contrat).
 */
class ContratsLocation
{
    public function __construct(
        private readonly FacturesVente $factures,
        private readonly DevisesVente $devises,
    ) {}

    /** Mois entre deux loyers. */
    public function pas(ContratLocation $contrat): int
    {
        return AbonnementsClients::FREQUENCES[$contrat->frequence] ?? 1;
    }

    /** Première facturation des loyers : le jour choisi, à la date de début ou juste après. */
    public function premiereFacturation(ContratLocation $contrat): Carbon
    {
        $debut = Carbon::parse($contrat->date_debut)->startOfDay();
        $date = Carbon::create($debut->year, $debut->month, $contrat->jour_facturation);

        return $date->lt($debut) ? $date->addMonthNoOverflow() : $date;
    }

    /**
     * Période d'un loyer facturé à cette date : la période qui commence (à échoir) ou celle qui vient de finir.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periode(ContratLocation $contrat, CarbonInterface $date): array
    {
        $pas = $this->pas($contrat);
        $jour = Carbon::parse($date)->startOfDay();
        $debut = $contrat->a_echoir ? $jour->copy() : $jour->copy()->subMonthsNoOverflow($pas);

        return [$debut, $debut->copy()->addMonthsNoOverflow($pas)->subDay()];
    }

    /** Loyer d'une période pour tout le contrat : unités facturables × loyer. */
    public function loyer(ContratLocation $contrat): float
    {
        return round($contrat->lignes->sum(fn (ContratLocationLigne $l) => $l->quantiteFacturable() * (float) $l->prix_location), 2);
    }

    /**
     * Facture brouillon des prix de contrat pas encore facturés (une fois par ligne ; les lignes ajoutées ensuite font
     * une facture complémentaire).
     */
    public function facturerContrat(ContratLocation $contrat, int $tenantId): Facture
    {
        $this->verifierNumerotation($tenantId);

        return DB::transaction(function () use ($contrat, $tenantId) {
            ContratLocation::whereKey($contrat->id)->lockForUpdate()->first();
            $lignes = $contrat->lignes()->whereNull('facture_id')->where('prix_contrat', '>', 0)->get();
            if ($lignes->isEmpty()) {
                throw ValidationException::withMessages(['contrat' => __('Tous les produits de ce contrat sont déjà facturés.')]);
            }
            $facture = $this->creerFacture($contrat, today(), $lignes->map(fn (ContratLocationLigne $l) => [
                'produit_id' => $l->produit_id,
                'quantite' => $l->quantite,
                'prix_unitaire_ht' => (float) $l->prix_contrat,
                'description' => "Contrat {$contrat->numero}",
            ])->all(), $tenantId);
            ContratLocationLigne::whereKey($lignes->pluck('id'))->update(['facture_id' => $facture->id]);
            $contrat->update(['date_facturation_contrat' => $contrat->date_facturation_contrat ?? today()]);

            return $facture;
        });
    }

    /**
     * Facture les loyers dus jusqu'à une date incluse, pour tous les clients ou un seul : une facture par contrat et
     * par période, datée de la facturation prévue. La prochaine facturation avance d'une période à chaque fois, jusqu'à
     * la fin du contrat. Un contrat inactif n'est pas facturé ; une erreur (taux de change) n'arrête pas les autres.
     *
     * @return array{factures: list<Facture>, periodes: int, erreurs: list<array{contrat: string, client: ?string, message: string}>}
     */
    public function facturerLoyers(CarbonInterface $jusquau, ?int $clientId, bool $emettre, ?int $auteur, int $tenantId): array
    {
        $this->verifierNumerotation($tenantId);
        $contrats = ContratLocation::query()->where('actif', true)->whereNotNull('prochaine_facturation')
            ->whereDate('prochaine_facturation', '<=', $jusquau->toDateString())
            ->whereColumn('prochaine_facturation', '<=', 'date_fin')
            ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
            ->orderBy('prochaine_facturation')->orderBy('id')->get();

        $resultat = ['factures' => [], 'periodes' => 0, 'erreurs' => []];
        foreach ($contrats as $contrat) {
            try {
                DB::transaction(function () use ($contrat, $jusquau, $emettre, $auteur, $tenantId, &$resultat) {
                    $contrat = ContratLocation::with(['lignes', 'client'])->lockForUpdate()->findOrFail($contrat->id);
                    while ($contrat->prochaine_facturation && $contrat->prochaine_facturation->lte($jusquau) && $contrat->prochaine_facturation->lte($contrat->date_fin)) {
                        $date = $contrat->prochaine_facturation->copy();
                        [$debut, $fin] = $this->periode($contrat, $date);
                        $lignes = $contrat->lignes->filter(fn (ContratLocationLigne $l) => $l->quantiteFacturable() > 0 && (float) $l->prix_location > 0)
                            ->map(fn (ContratLocationLigne $l) => [
                                'produit_id' => $l->produit_id,
                                'quantite' => $l->quantiteFacturable(),
                                'prix_unitaire_ht' => (float) $l->prix_location,
                                'description' => "Location du {$debut->format('d/m/Y')} au {$fin->format('d/m/Y')} — contrat {$contrat->numero}",
                            ])->values()->all();
                        // Rien à louer (tout est rendu ou exempté) : la période passe sans facture.
                        if ($lignes !== []) {
                            $facture = $this->creerFacture($contrat, $date, $lignes, $tenantId);
                            if ($emettre) {
                                $this->factures->emettre($facture, $auteur);
                            }
                            $resultat['factures'][] = $facture;
                            $resultat['periodes']++;
                        }
                        $contrat->update(['derniere_facturation' => $date->toDateString(), 'prochaine_facturation' => $date->copy()->addMonthsNoOverflow($this->pas($contrat))->toDateString()]);
                        $contrat->refresh();
                    }
                });
            } catch (ValidationException $e) {
                $resultat['erreurs'][] = ['contrat' => $contrat->numero, 'client' => $contrat->client?->raison_sociale, 'message' => collect($e->errors())->flatten()->first() ?? $e->getMessage()];
            }
        }

        return $resultat;
    }

    /** Retour d'unités louées : plus de loyer pour elles à partir de la facturation suivante. */
    public function retour(ContratLocationLigne $ligne, int $quantite, string $date): void
    {
        $reste = $ligne->quantite - $ligne->quantite_retournee;
        if ($quantite < 1 || $quantite > $reste) {
            throw ValidationException::withMessages(['quantite' => __('Quantité rendue trop grande : il reste :reste unité(s) chez le client.', ['reste' => $reste])]);
        }
        $ligne->update(['quantite_retournee' => $ligne->quantite_retournee + $quantite, 'date_retour' => $date]);
    }

    /**
     * Contrat suivant : même client, type, rythme et produits (unités encore chez le client, un produit par ligne),
     * du lendemain de la fin pour la même durée. À la main : mêmes prix, à revoir avant de facturer. Automatique :
     * prix augmentés des taux du contrat, et facture brouillon des prix de contrat.
     */
    public function renouveler(ContratLocation $contrat, bool $automatique, ?int $auteur, int $tenantId): ContratLocation
    {
        return DB::transaction(function () use ($contrat, $automatique, $auteur, $tenantId) {
            $contrat = ContratLocation::with(['lignes', 'type'])->lockForUpdate()->findOrFail($contrat->id);
            if ($contrat->renouvele_par_id) {
                throw ValidationException::withMessages(['contrat' => __('Ce contrat est déjà renouvelé.')]);
            }
            $config = Configuration::pourEntite('contrat_location', $tenantId);
            if (! $config || ! $config->auto_increment) {
                throw ValidationException::withMessages(['contrat' => __('La numérotation automatique des contrats de location doit être active.')]);
            }
            // Un produit par ligne : unités encore chez le client, prix de la ligne la plus récente.
            $lignes = $contrat->lignes->sortBy('id')->groupBy('produit_id')->map(function (Collection $groupe) use ($contrat, $automatique) {
                $derniere = $groupe->last();
                $hausse = fn (float $prix, $taux) => $automatique ? round($prix * (1 + (float) $taux / 100), 2) : $prix;
                $quantite = $groupe->sum(fn (ContratLocationLigne $l) => $l->quantite - $l->quantite_retournee);

                return [
                    'produit_id' => $derniere->produit_id,
                    'quantite' => $quantite,
                    'quantite_exemptee' => min($quantite, $groupe->sum('quantite_exemptee')),
                    'prix_contrat' => $hausse((float) $derniere->prix_contrat, $contrat->taux_contrat),
                    'prix_location' => $hausse((float) $derniere->prix_location, $contrat->taux_location),
                ];
            })->filter(fn (array $l) => $l['quantite'] > 0)->values();
            if ($lignes->isEmpty()) {
                throw ValidationException::withMessages(['contrat' => __('Plus aucune unité louée : rien à renouveler.')]);
            }
            $mois = max(1, $contrat->type?->duree_mois ?? (int) round(Carbon::parse($contrat->date_debut)->floatDiffInMonths(Carbon::parse($contrat->date_fin)->addDay())));
            $debut = Carbon::parse($contrat->date_fin)->addDay();
            $suivant = ContratLocation::create([
                'numero' => Configuration::consommerNumero('contrat_location', $tenantId),
                'client_id' => $contrat->client_id,
                'type_location_id' => $contrat->type_location_id,
                'date_debut' => $debut->toDateString(),
                'date_fin' => $debut->copy()->addMonthsNoOverflow($mois)->subDay()->toDateString(),
                'frequence' => $contrat->frequence,
                'jour_facturation' => $contrat->jour_facturation,
                'a_echoir' => $contrat->a_echoir,
                'actif' => true,
                'renouvellement_auto' => $contrat->renouvellement_auto,
                'taux_contrat' => $contrat->taux_contrat,
                'taux_location' => $contrat->taux_location,
                'contrat_precedent_id' => $contrat->id,
                'notes' => $contrat->notes,
                'cree_par' => $auteur,
            ]);
            foreach ($lignes as $ligne) {
                $suivant->lignes()->create($ligne + ['tenant_id' => $tenantId]);
            }
            $suivant->update(['prochaine_facturation' => $this->premiereFacturation($suivant)->toDateString()]);
            $contrat->update(['renouvele_par_id' => $suivant->id]);
            if ($automatique && $suivant->lignes()->where('prix_contrat', '>', 0)->exists()) {
                $this->facturerContrat($suivant, $tenantId);
            }

            return $suivant->fresh(['lignes']);
        });
    }

    /** Contrats à renouveler automatiquement : actifs, renouvellement demandé, pas encore renouvelés, fin atteinte. */
    public function aRenouveler(CarbonInterface $date): Collection
    {
        return ContratLocation::query()->where('actif', true)->where('renouvellement_auto', true)->whereNull('renouvele_par_id')
            ->whereDate('date_fin', '<=', $date->toDateString())->orderBy('date_fin')->get();
    }

    /** Facture brouillon d'un contrat, dans la devise du client, reliée au contrat. */
    private function creerFacture(ContratLocation $contrat, CarbonInterface $date, array $lignes, int $tenantId): Facture
    {
        $facture = $this->factures->creer($this->devises->pourDocument(null, null, $contrat->client) + [
            'client_id' => $contrat->client_id,
            'date_facture' => $date->toDateString(),
            'lignes' => $lignes,
        ], $tenantId);
        $facture->update(['contrat_location_id' => $contrat->id]);

        return $facture;
    }

    private function verifierNumerotation(int $tenantId): void
    {
        $config = Configuration::pourEntite('facture', $tenantId);
        if (! $config || ! $config->auto_increment) {
            throw ValidationException::withMessages(['facture' => __('La numérotation automatique des factures doit être active.')]);
        }
    }
}
