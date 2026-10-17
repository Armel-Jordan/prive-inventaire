<?php

namespace App\Services;

use App\Models\AbonnementClient;
use App\Models\AbonnementEcheance;
use App\Models\Configuration;
use App\Models\Facture;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Abonnements clients (plan de parité fripv2, phase 1.8 : « Facturation des abonnements »). Un abonnement facture un
 * produit à un client à intervalle régulier (chaque mois, trimestre, semestre ou année), au jour choisi : de 1 à 28,
 * pour tomber le même jour tous les mois. Ses échéances sont calculées à l'enregistrement, de la date de début à la
 * date de fin. La facturation transforme les échéances dues en vraies factures : une par client et par date
 * d'échéance, datée de l'échéance, une ligne par abonnement. Une échéance facturée n'est jamais reprise ; changer le
 * calendrier ne touche qu'aux échéances pas encore facturées.
 */
class AbonnementsClients
{
    /** Fréquence => nombre de mois entre deux échéances. */
    public const FREQUENCES = ['mensuelle' => 1, 'trimestrielle' => 3, 'semestrielle' => 6, 'annuelle' => 12];

    /** Variables de la description, remplacées par la période facturée. */
    public const VARIABLES = ['{debut_periode}', '{fin_periode}', '{mois}', '{annee}'];

    /** Fin dans ce nombre de jours : l'abonnement est « bientôt échu ». */
    public const PREAVIS_JOURS = 30;

    /** Durée maximale d'un abonnement (les échéances sont calculées d'avance). */
    public const DUREE_MAX_ANNEES = 10;

    public function __construct(
        private readonly FacturesVente $factures,
        private readonly CalculPrix $prix,
        private readonly DevisesVente $devises,
    ) {}

    /**
     * Dates de facturation de la date de début à la date de fin : le jour choisi chaque mois, ou tous les 3, 6 ou 12
     * mois à partir du mois de facturation. La première tombe à la date de début ou juste après.
     *
     * @return list<Carbon>
     */
    public function dates(AbonnementClient $abonnement): array
    {
        $pas = self::FREQUENCES[$abonnement->frequence];
        $debut = Carbon::parse($abonnement->date_debut)->startOfDay();
        $fin = Carbon::parse($abonnement->date_fin)->startOfDay();
        $mois = $pas === 1 ? $debut->month : ($abonnement->mois_facturation ?? $debut->month);
        $date = Carbon::create($debut->year, $mois, $abonnement->jour_facturation);
        // Premier cycle avant le début, puis première date au début ou après.
        while ($date->gt($debut)) {
            $date->subMonthsNoOverflow($pas);
        }
        while ($date->lt($debut)) {
            $date->addMonthsNoOverflow($pas);
        }
        $dates = [];
        for (; $date->lte($fin); $date->addMonthsNoOverflow($pas)) {
            $dates[] = $date->copy();
        }

        return $dates;
    }

    /**
     * Période couverte par une échéance : celle qui court à sa date, du jour de début de période jusqu'à la veille du
     * suivant ; « période précédente » la recule d'un cycle (facturer après coup, l'usage du mois écoulé).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periode(AbonnementClient $abonnement, CarbonInterface $date): array
    {
        $pas = self::FREQUENCES[$abonnement->frequence];
        $jour = Carbon::parse($date)->startOfDay();
        $mois = $pas === 1 ? $jour->month : ($abonnement->mois_debut_periode ?? $abonnement->mois_facturation ?? $jour->month);
        $debut = Carbon::create($jour->year, $mois, $abonnement->jour_debut_periode);
        while ($debut->gt($jour)) {
            $debut->subMonthsNoOverflow($pas);
        }
        while ($debut->copy()->addMonthsNoOverflow($pas)->lte($jour)) {
            $debut->addMonthsNoOverflow($pas);
        }
        if ($abonnement->periode_precedente) {
            $debut->subMonthsNoOverflow($pas);
        }

        return [$debut, $debut->copy()->addMonthsNoOverflow($pas)->subDay()];
    }

    /**
     * (Re)calcule les échéances à facturer : les échéances facturées restent ; les autres sont remplacées par le
     * calendrier, qui reprend après la dernière échéance facturée.
     */
    public function planifier(AbonnementClient $abonnement): void
    {
        DB::transaction(function () use ($abonnement) {
            $abonnement->echeances()->whereNull('facture_id')->delete();
            $derniere = $abonnement->echeances()->max('date_prevue');
            foreach ($this->dates($abonnement) as $date) {
                if ($derniere && $date->lte(Carbon::parse($derniere))) {
                    continue;
                }
                [$debut, $fin] = $this->periode($abonnement, $date);
                $abonnement->echeances()->create([
                    'tenant_id' => $abonnement->tenant_id,
                    'date_prevue' => $date->toDateString(),
                    'periode_debut' => $debut->toDateString(),
                    'periode_fin' => $fin->toDateString(),
                ]);
            }
        });
    }

    /** actif, bientot_echu (fin dans les 30 jours) ou echu (fin passée). */
    public function statut(AbonnementClient $abonnement): string
    {
        $fin = Carbon::parse($abonnement->date_fin)->startOfDay();

        return match (true) {
            $fin->lt(today()) => 'echu',
            $fin->lte(today()->addDays(self::PREAVIS_JOURS)) => 'bientot_echu',
            default => 'actif',
        };
    }

    /**
     * Prix unitaire à une date, dans la devise du client : le prix figé, sinon le tarif du client à cette date (listes
     * de prix, remise, règles), converti dans sa devise. Null quand le produit n'a aucun prix.
     *
     * @param  array{devise: ?string, taux_change: float}|null  $devise  devise du client, si déjà connue
     */
    public function prixA(AbonnementClient $abonnement, CarbonInterface $date, ?array $devise = null): ?float
    {
        if ($abonnement->prix_fixe) {
            return $abonnement->prix_unitaire !== null ? (float) $abonnement->prix_unitaire : null;
        }
        $calcul = $this->prix->calculer($abonnement->client, [['produit_id' => $abonnement->produit_id, 'quantite' => $abonnement->quantite]], $date)[0] ?? [];
        if (($calcul['prix'] ?? null) === null) {
            return null;
        }
        $devise ??= $this->devises->pourDocument(null, null, $abonnement->client);

        return $devise['devise'] && $devise['taux_change'] > 0 ? round($calcul['prix'] / $devise['taux_change'], 2) : round((float) $calcul['prix'], 2);
    }

    /** Texte de la ligne de facture : la description de l'abonnement, ses variables remplacées par la période. */
    public function description(AbonnementClient $abonnement, CarbonInterface $debut, CarbonInterface $fin): ?string
    {
        if (! filled($abonnement->description)) {
            return null;
        }

        return strtr($abonnement->description, [
            '{debut_periode}' => $debut->format('d/m/Y'),
            '{fin_periode}' => $fin->format('d/m/Y'),
            '{mois}' => Carbon::parse($debut)->locale('fr')->translatedFormat('F'),
            '{annee}' => $debut->format('Y'),
        ]);
    }

    /**
     * Facture les échéances dues jusqu'à une date incluse, pour tous les clients ou un seul : une facture par client et
     * par date d'échéance, datée de l'échéance, une ligne par abonnement (prix à la date de l'échéance). Les factures
     * naissent en brouillon, ou émises si demandé, sauf quand un de leurs abonnements est marqué « brouillon ». Un
     * prix ou un taux de change manquant n'arrête pas les autres factures : l'erreur est rapportée.
     *
     * @return array{factures: list<Facture>, echeances: int, erreurs: list<array{client: ?string, date: string, message: string}>}
     */
    public function facturer(CarbonInterface $jusquau, ?int $clientId, bool $emettre, ?int $auteur, int $tenantId): array
    {
        $config = Configuration::pourEntite('facture', $tenantId);
        if (! $config || ! $config->auto_increment) {
            throw ValidationException::withMessages(['facture' => 'La numérotation automatique des factures doit être active pour facturer les abonnements.']);
        }
        $dues = AbonnementEcheance::query()
            ->whereNull('facture_id')->whereDate('date_prevue', '<=', $jusquau->toDateString())
            ->whereHas('abonnement', fn ($q) => $q->when($clientId, fn ($q) => $q->where('client_id', $clientId)))
            ->with(['abonnement.client', 'abonnement.produit'])
            ->orderBy('date_prevue')->orderBy('id')->get();

        $resultat = ['factures' => [], 'echeances' => 0, 'erreurs' => []];
        $groupes = $dues->groupBy(fn (AbonnementEcheance $e) => $e->abonnement->client_id.'|'.$e->date_prevue->toDateString());
        foreach ($groupes as $echeances) {
            $client = $echeances->first()->abonnement->client;
            $date = $echeances->first()->date_prevue;
            try {
                $facture = DB::transaction(function () use ($echeances, $client, $date, $emettre, $auteur, $tenantId) {
                    // Reprises sous verrou : une facturation lancée en même temps ne les facture pas deux fois.
                    $libres = AbonnementEcheance::whereKey($echeances->pluck('id'))->whereNull('facture_id')->lockForUpdate()->pluck('id')->all();
                    $echeances = $echeances->whereIn('id', $libres)->values();
                    if ($echeances->isEmpty()) {
                        return null;
                    }
                    $devise = $this->devises->pourDocument(null, null, $client);
                    $lignes = $echeances->map(function (AbonnementEcheance $echeance) use ($date, $devise) {
                        $abonnement = $echeance->abonnement;
                        $prix = $this->prixA($abonnement, $date, $devise);
                        if ($prix === null) {
                            throw ValidationException::withMessages(['prix' => "Aucun prix pour le produit {$abonnement->produit?->numero} : fixez le prix de l'abonnement."]);
                        }

                        return [
                            'produit_id' => $abonnement->produit_id,
                            'quantite' => $abonnement->quantite,
                            'prix_unitaire_ht' => $prix,
                            'description' => $this->description($abonnement, $echeance->periode_debut, $echeance->periode_fin),
                        ];
                    })->all();
                    $facture = $this->factures->creer($devise + [
                        'client_id' => $client->id,
                        'date_facture' => $date->toDateString(),
                        'lignes' => $lignes,
                    ], $tenantId);
                    AbonnementEcheance::whereKey($echeances->pluck('id'))->update(['facture_id' => $facture->id]);
                    if ($emettre && ! $echeances->contains(fn (AbonnementEcheance $e) => $e->abonnement->brouillon)) {
                        $this->factures->emettre($facture, $auteur);
                    }

                    return [$facture, $echeances->count()];
                });
                if ($facture) {
                    $resultat['factures'][] = $facture[0];
                    $resultat['echeances'] += $facture[1];
                }
            } catch (ValidationException $e) {
                $resultat['erreurs'][] = ['client' => $client?->raison_sociale, 'date' => $date->toDateString(), 'message' => collect($e->errors())->flatten()->first() ?? $e->getMessage()];
            }
        }

        return $resultat;
    }

    /** Relie une échéance à une facture faite à la main pour elle : elle ne sera plus facturée. */
    public function relier(AbonnementEcheance $echeance, Facture $facture): void
    {
        if ($echeance->facture_id) {
            throw ValidationException::withMessages(['echeance' => 'Cette échéance est déjà reliée à une facture.']);
        }
        if ((int) $facture->client_id !== (int) $echeance->abonnement->client_id) {
            throw ValidationException::withMessages(['facture_id' => "Cette facture n'est pas celle du client de l'abonnement."]);
        }
        $echeance->update(['facture_id' => $facture->id]);
    }

    /** Statut d'une échéance : celui de sa facture, sinon en_retard (date passée) ou a_venir. */
    public function statutEcheance(AbonnementEcheance $echeance): string
    {
        if ($echeance->facture) {
            return $echeance->facture->statut;
        }

        return $echeance->date_prevue->lt(today()) ? 'en_retard' : 'a_venir';
    }
}
