<?php

namespace App\Services;

use App\Models\CourrielEnvoi;
use App\Models\Facture;
use App\Models\FactureRelance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Relances de paiement (plan de parité fripv2, phase 1.5) : les factures en retard (échéance dépassée, ou date de
 * facture sans échéance), avec leurs relances ; chaque relance est notée avec son niveau (1re, 2e…) :
 * - par courriel : envoyée depuis l'écran (modèle « relance », facture jointe) ou par la tâche planifiée ;
 * - faite autrement (téléphone, courrier), notée à la main.
 */
class Relances
{
    /** Factures qui restent dues. */
    public const FACTURES_DUES = ['emise', 'partiellement_payee'];

    /** Nombre de relances automatiques au plus, par facture. */
    public const MAXIMUM_AUTOMATIQUES = 3;

    /**
     * Factures en retard à une date, avec leur retard en jours et leurs relances (nombre, dernière).
     *
     * @param  array{client_id?: ?int, retard_min?: ?int, jamais?: bool, recherche?: ?string}  $filtres
     * @return Collection<int, array>
     */
    public function enRetard(string $date, array $filtres = []): Collection
    {
        $jour = Carbon::parse($date)->startOfDay();
        $recherche = trim((string) ($filtres['recherche'] ?? ''));
        $factures = Facture::with('client:id,code,raison_sociale,email')->withCount('relances')
            ->whereIn('statut', self::FACTURES_DUES)->where('reste_a_payer', '>', 0)
            ->where(fn ($q) => $q->whereDate('date_echeance', '<', $jour->toDateString())
                ->orWhere(fn ($sans) => $sans->whereNull('date_echeance')->whereDate('date_facture', '<', $jour->toDateString())))
            ->when($filtres['client_id'] ?? null, fn ($q, $client) => $q->where('client_id', $client))
            ->when($recherche !== '', function ($q) use ($recherche) {
                $motif = '%'.addcslashes($recherche, '%_\\').'%';
                $q->where(fn ($r) => $r->where('numero', 'like', $motif)
                    ->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $motif)->orWhere('code', 'like', $motif)));
            })
            ->get();
        $dernieres = FactureRelance::whereIn('facture_id', $factures->pluck('id'))->orderBy('date_relance')->orderBy('id')->get()
            ->groupBy('facture_id')->map(fn (Collection $relances) => $relances->last());

        return $factures->map(function (Facture $f) use ($jour, $dernieres) {
            $echeance = ($f->date_echeance ?? $f->date_facture)->copy()->startOfDay();
            $derniere = $dernieres[$f->id] ?? null;

            return [
                'facture_id' => $f->id,
                'numero' => $f->numero,
                'client' => $f->client?->only(['id', 'code', 'raison_sociale', 'email']),
                'date_facture' => $f->date_facture->toDateString(),
                'date_echeance' => $f->date_echeance?->toDateString(),
                'montant_ttc' => round((float) $f->montant_ttc, 2),
                'reste_a_payer' => round((float) $f->reste_a_payer, 2),
                'retard' => (int) $echeance->diffInDays($jour),
                'nb_relances' => (int) $f->relances_count,
                'derniere_relance' => $derniere ? [
                    'date' => $derniere->date_relance->toDateString(), 'mode' => $derniere->mode,
                    'niveau' => $derniere->niveau, 'automatique' => $derniere->automatique,
                ] : null,
            ];
        })
            ->filter(fn (array $f) => $f['retard'] >= (int) ($filtres['retard_min'] ?? 0))
            ->filter(fn (array $f) => empty($filtres['jamais']) || $f['nb_relances'] === 0)
            ->sortByDesc('retard')->values();
    }

    /** Note une relance de la facture : son niveau suit les relances déjà faites. */
    public function noter(Facture $facture, string $mode, string $date, ?string $notes = null, ?int $auteur = null, ?int $envoiId = null, bool $automatique = false): FactureRelance
    {
        return FactureRelance::create([
            'facture_id' => $facture->id,
            'client_id' => $facture->client_id,
            'niveau' => FactureRelance::where('facture_id', $facture->id)->count() + 1,
            'date_relance' => $date,
            'mode' => $mode,
            'envoi_id' => $envoiId,
            'automatique' => $automatique,
            'notes' => $notes,
            'cree_par' => $auteur,
        ]);
    }

    /** Courriel de relance envoyé : la relance est notée (une seule fois par courriel). */
    public function apresEnvoiCourriel(CourrielEnvoi $envoi): void
    {
        if ($envoi->type !== 'relance' || FactureRelance::where('envoi_id', $envoi->id)->exists()) {
            return;
        }
        $facture = Facture::find($envoi->document_id);
        if ($facture) {
            $this->noter($facture, 'courriel', Carbon::today()->toDateString(), null, $envoi->envoye_par, $envoi->id, $envoi->envoye_par === null);
        }
    }

    /**
     * Factures à relancer d'elles-mêmes : en retard d'au moins $jours, dont la dernière relance date d'au moins
     * $jours, relancées moins de MAXIMUM_AUTOMATIQUES fois, d'un client qui a une adresse de courriel.
     *
     * @return Collection<int, Facture>
     */
    public function aRelancerAutomatiquement(int $jours): Collection
    {
        $limite = Carbon::today()->subDays(max(0, $jours))->toDateString();
        $factures = Facture::with('client:id,email')->withCount('relances')->withMax('relances', 'date_relance')
            ->whereIn('statut', self::FACTURES_DUES)->where('reste_a_payer', '>', 0)
            ->where(fn ($q) => $q->whereDate('date_echeance', '<=', $limite)
                ->orWhere(fn ($sans) => $sans->whereNull('date_echeance')->whereDate('date_facture', '<=', $limite)))
            ->orderBy('date_echeance')->orderBy('id')
            ->get();

        return $factures->filter(fn (Facture $f) => $f->relances_count < self::MAXIMUM_AUTOMATIQUES
            && filled($f->client?->email)
            && ($f->relances_max_date_relance === null || Carbon::parse($f->relances_max_date_relance)->toDateString() <= $limite))->values();
    }
}
