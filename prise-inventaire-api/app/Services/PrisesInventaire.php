<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\MouvementTenant;
use App\Models\PriseInventaire;
use App\Models\PriseInventaireCorrection;
use App\Models\PriseInventaireLigne;
use App\Models\ProduitTenant;
use App\Models\ScanTenant;
use App\Models\Secteur;
use App\Support\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Prise d'inventaire formelle (plan de parité fripv2, phase 2.7), par-dessus les scans.
 *
 * - Comptage : somme des scans de la période de la session, dans ses secteurs. Un scan désigne son secteur
 *   par le code (saisie web) ou par le nom (application Android) : les deux sont reconnus.
 * - Référence : la dernière prise validée couvrant le secteur (inventaire officiel). Un produit absent de
 *   cette prise y vaut 0 ; un secteur jamais inventorié n'a pas de référence (lignes « nouveau »).
 * - Mouvements connus depuis la référence (réceptions rangées dans le secteur, relocalisations), affichés
 *   pour expliquer les écarts, sans entrer dans leur calcul.
 * - Validation : les quantités retenues (comptage ou correction d'un responsable) sont figées.
 */
final class PrisesInventaire
{
    public const STATUTS_LIGNE = ['nouveau', 'non_compte', 'ecart', 'conforme'];

    /** Secteurs de la session (tous les secteurs de l'entreprise si elle les couvre tous). */
    public function secteurs(PriseInventaire $prise): Collection
    {
        return ($prise->tous_secteurs ? Secteur::query() : $prise->secteurs())->orderBy('code')->get();
    }

    /** Lignes de la session : figées si elle est validée, sinon calculées à l'instant. */
    public function lignes(PriseInventaire $prise): Collection
    {
        if ($prise->statut !== PriseInventaire::VALIDEE) {
            return $this->calculer($prise);
        }

        $descriptions = $this->descriptions($prise->lignes()->pluck('produit_numero'));

        return $prise->lignes()->orderBy('secteur')->orderBy('produit_numero')->get()->map(fn (PriseInventaireLigne $l) => $this->finaliser([
            'secteur_id' => $l->secteur_id,
            'secteur' => $l->secteur,
            'produit_numero' => $l->produit_numero,
            'description' => $descriptions[$l->produit_numero] ?? null,
            'quantite_comptee' => $l->quantite_comptee,
            'nb_scans' => $l->nb_scans,
            'dernier_scan' => null,
            'quantite_reference' => $l->quantite_reference,
            'reference_prise_id' => $l->reference_prise_id,
            'entrees_depuis' => $l->entrees_depuis,
            'sorties_depuis' => $l->sorties_depuis,
            'quantite_retenue' => $l->quantite_retenue,
            'raison' => $l->raison,
            'corrigee' => $l->raison !== null || abs($l->quantite_retenue - $l->quantite_comptee) > 1e-9,
        ]));
    }

    /** Avancement par secteur : scans, produits, employés, dernier scan, référence. */
    public function avancement(PriseInventaire $prise): array
    {
        $secteurs = $this->secteurs($prise);
        $index = $this->index($secteurs);
        // Ensembles de produits et d'employés par secteur : un secteur saisi sous son code et sous son nom
        // ne compte ses produits qu'une fois.
        $groupes = [];
        foreach ($this->scansDeLaPeriode($prise, $secteurs)
            ->selectRaw('secteur, numero, employe, COUNT(*) AS nb, MAX(date_saisie) AS dernier')
            ->groupBy('secteur', 'numero', 'employe')->get() as $groupe) {
            $secteur = $index[mb_strtolower((string) $groupe->secteur)] ?? null;
            $cle = $secteur ? 'id:'.$secteur->id : 'brut:'.$groupe->secteur;
            $groupes[$cle] ??= ['nb_scans' => 0, 'produits' => [], 'employes' => [], 'dernier_scan' => null];
            $groupes[$cle]['nb_scans'] += (int) $groupe->nb;
            $groupes[$cle]['produits'][$groupe->numero] = true;
            $groupes[$cle]['employes'][$groupe->employe] = true;
            $groupes[$cle]['dernier_scan'] = max($groupes[$cle]['dernier_scan'], $this->date($groupe->dernier));
        }
        $parSecteur = array_map(fn ($g) => [
            'nb_scans' => $g['nb_scans'],
            'nb_produits' => count($g['produits']),
            'nb_employes' => count($g['employes']),
            'dernier_scan' => $g['dernier_scan'],
        ], $groupes);

        [$references] = $this->references($prise, $secteurs);
        $resultat = [];
        foreach ($secteurs as $secteur) {
            $stats = $parSecteur['id:'.$secteur->id] ?? null;
            // En mode « tous les secteurs », un secteur inactif sans scan est omis.
            if ($prise->tous_secteurs && ! $secteur->actif && ! $stats) {
                continue;
            }
            $reference = $references[$secteur->id] ?? null;
            $resultat[] = [
                'secteur_id' => $secteur->id,
                'code' => $secteur->code,
                'nom' => $secteur->nom,
                ...($stats ?? ['nb_scans' => 0, 'nb_produits' => 0, 'nb_employes' => 0, 'dernier_scan' => null]),
                'reference' => $reference ? ['id' => $reference->id, 'numero' => $reference->numero, 'date' => $reference->date_fin?->toIso8601String()] : null,
            ];
        }
        foreach ($parSecteur as $cle => $stats) {
            if (str_starts_with($cle, 'brut:')) {
                $resultat[] = ['secteur_id' => null, 'code' => substr($cle, 5), 'nom' => null, ...$stats, 'reference' => null];
            }
        }

        return $resultat;
    }

    /** Fige les lignes et valide la session ; renvoie null si elle n'est plus en cours. */
    public function valider(PriseInventaire $prise, AdminUser $par): ?PriseInventaire
    {
        return DB::transaction(function () use ($prise, $par) {
            $prise = PriseInventaire::whereKey($prise->id)->lockForUpdate()->first();
            if (! $prise || $prise->statut !== PriseInventaire::EN_COURS) {
                return null;
            }

            $prise->date_fin ??= now();
            $maintenant = now();
            // Une ligne à 0 sans référence ni écart n'apporte rien à l'inventaire officiel.
            $lignes = $this->calculer($prise)
                ->filter(fn (array $l) => abs($l['quantite_retenue']) > 1e-9 || abs((float) $l['quantite_reference']) > 1e-9)
                ->map(fn (array $l) => [
                    'tenant_id' => $prise->tenant_id,
                    'prise_inventaire_id' => $prise->id,
                    'secteur_id' => $l['secteur_id'],
                    'secteur' => $l['secteur'],
                    'produit_numero' => $l['produit_numero'],
                    'quantite_comptee' => $l['quantite_comptee'],
                    'nb_scans' => $l['nb_scans'],
                    'quantite_reference' => $l['quantite_reference'],
                    'reference_prise_id' => $l['reference_prise_id'],
                    'quantite_retenue' => $l['quantite_retenue'],
                    'ecart' => $l['ecart'],
                    'entrees_depuis' => $l['entrees_depuis'],
                    'sorties_depuis' => $l['sorties_depuis'],
                    'raison' => $l['raison'],
                    'created_at' => $maintenant,
                    'updated_at' => $maintenant,
                ]);
            foreach ($lignes->chunk(500) as $paquet) {
                PriseInventaireLigne::insert($paquet->values()->all());
            }

            $prise->fill(['statut' => PriseInventaire::VALIDEE, 'cloturee_par' => $par->id, 'cloturee_le' => $maintenant])->save();

            return $prise;
        });
    }

    /**
     * Inventaire officiel : pour chaque secteur, les quantités de la dernière prise validée qui le couvre.
     *
     * @return Collection<int, array>
     */
    public function inventaireOfficiel(?int $secteurId = null, ?int $entrepotId = null): Collection
    {
        // Un secteur, ou les secteurs d'un entrepôt (multi-entrepôts).
        $secteurs = Secteur::query()->when($secteurId, fn ($q) => $q->whereKey($secteurId))
            ->when($entrepotId, fn ($q) => $q->where('entrepot_id', $entrepotId))->orderBy('code')->get();
        [$references] = $this->references(null, $secteurs);
        $lignes = $this->lignesDeReference($references, null)->sortBy(['secteur', 'produit_numero'])->values();
        $descriptions = $this->descriptions($lignes->pluck('produit_numero'));
        $prises = collect($references)->filter()->keyBy('id');

        return $lignes->filter(fn ($l) => $l->quantite_retenue != 0)->values()->map(fn (PriseInventaireLigne $l) => [
            'secteur_id' => $l->secteur_id,
            'secteur' => $l->secteur,
            'produit_numero' => $l->produit_numero,
            'description' => $descriptions[$l->produit_numero] ?? null,
            'quantite' => $l->quantite_retenue,
            'prise' => ($p = $prises->get($l->prise_inventaire_id)) ? ['id' => $p->id, 'numero' => $p->numero, 'date' => $p->date_fin?->toIso8601String()] : null,
        ]);
    }

    /** Calcul des lignes à partir des scans, de la référence, des mouvements et des corrections. */
    private function calculer(PriseInventaire $prise): Collection
    {
        $secteurs = $this->secteurs($prise);
        $parId = $secteurs->keyBy('id');
        $index = $this->index($secteurs);
        $lignes = [];
        $ligne = function (?Secteur $secteur, string $libelle, string $numero) use (&$lignes): string {
            $cle = ($secteur ? 'id:'.$secteur->id : 'brut:'.$libelle).'|'.$numero;
            $lignes[$cle] ??= [
                'secteur_id' => $secteur?->id,
                'secteur' => $secteur?->code ?? $libelle,
                'produit_numero' => $numero,
                'quantite_comptee' => 0.0,
                'nb_scans' => 0,
                'dernier_scan' => null,
                'quantite_reference' => null,
                'reference_prise_id' => null,
                'entrees_depuis' => null,
                'sorties_depuis' => null,
            ];

            return $cle;
        };

        // 1. Comptage : scans de la période (le même secteur peut apparaître sous son code et son nom).
        foreach ($this->scansDeLaPeriode($prise, $secteurs)
            ->selectRaw('secteur, numero, SUM(quantite) AS total, COUNT(*) AS nb, MAX(date_saisie) AS dernier')
            ->groupBy('secteur', 'numero')->get() as $groupe) {
            $secteur = $index[mb_strtolower((string) $groupe->secteur)] ?? null;
            if (! $secteur && ! $prise->tous_secteurs) {
                continue;
            }
            $cle = $ligne($secteur, (string) $groupe->secteur, (string) $groupe->numero);
            $lignes[$cle]['quantite_comptee'] += (float) $groupe->total;
            $lignes[$cle]['nb_scans'] += (int) $groupe->nb;
            $lignes[$cle]['dernier_scan'] = max($lignes[$cle]['dernier_scan'], $this->date($groupe->dernier));
        }

        // 2. Référence : dernière prise validée par secteur ; un produit absent y vaut 0.
        [$references, $referenceBrute] = $this->references($prise, $secteurs);
        foreach ($this->lignesDeReference($references, $prise->tous_secteurs ? $referenceBrute : null) as $reference) {
            $secteur = $reference->secteur_id ? $parId->get($reference->secteur_id) : null;
            if ($reference->secteur_id && ! $secteur) {
                continue;
            }
            $cle = $ligne($secteur, $reference->secteur, $reference->produit_numero);
            $lignes[$cle]['quantite_reference'] = $reference->quantite_retenue;
        }

        // 3. Mouvements connus depuis la référence, secteur par secteur.
        foreach ($secteurs as $secteur) {
            $reference = $references[$secteur->id] ?? null;
            if (! $reference?->date_fin) {
                continue;
            }
            foreach ($this->mouvementsDepuis($secteur, $reference->date_fin) as $numero => $mouvement) {
                $cle = $ligne($secteur, $secteur->code, (string) $numero);
                $lignes[$cle]['entrees_depuis'] = $mouvement['entrees'];
                $lignes[$cle]['sorties_depuis'] = $mouvement['sorties'];
            }
        }

        // Toute ligne d'un secteur déjà inventorié a une référence (0 si le produit n'y figurait pas).
        foreach ($lignes as $cle => $l) {
            $reference = $l['secteur_id'] ? ($references[$l['secteur_id']] ?? null) : ($prise->tous_secteurs ? $referenceBrute : null);
            if ($reference) {
                $lignes[$cle]['reference_prise_id'] = $reference->id;
                $lignes[$cle]['quantite_reference'] ??= 0.0;
                $lignes[$cle]['entrees_depuis'] ??= 0.0;
                $lignes[$cle]['sorties_depuis'] ??= 0.0;
            }
        }

        // 4. Corrections des responsables.
        $corrections = PriseInventaireCorrection::where('prise_inventaire_id', $prise->id)->get()
            ->keyBy(fn ($c) => mb_strtolower($c->secteur).'|'.$c->produit_numero);
        foreach ($corrections as $correction) {
            $secteur = $index[mb_strtolower($correction->secteur)] ?? null;
            $ligne($secteur, $correction->secteur, $correction->produit_numero);
        }

        $descriptions = $this->descriptions(collect($lignes)->pluck('produit_numero'));

        return collect($lignes)->map(function (array $l) use ($corrections, $descriptions) {
            $correction = $corrections->get(mb_strtolower($l['secteur']).'|'.$l['produit_numero']);

            return $this->finaliser([
                ...$l,
                'description' => $descriptions[$l['produit_numero']] ?? null,
                'quantite_retenue' => $correction ? $correction->quantite_retenue : $l['quantite_comptee'],
                'raison' => $correction?->raison,
                'corrigee' => $correction !== null,
            ]);
        })->sortBy([['secteur', 'asc'], ['produit_numero', 'asc']])->values();
    }

    /** Arrondis, écart et statut de la ligne. */
    private function finaliser(array $l): array
    {
        foreach (['quantite_comptee', 'quantite_reference', 'quantite_retenue', 'entrees_depuis', 'sorties_depuis'] as $champ) {
            $l[$champ] = $l[$champ] === null ? null : round((float) $l[$champ], 4);
        }
        $l['ecart'] = $l['quantite_reference'] === null ? null : round($l['quantite_retenue'] - $l['quantite_reference'], 4);
        $l['statut'] = match (true) {
            $l['quantite_reference'] === null => 'nouveau',
            $l['nb_scans'] === 0 && ! $l['corrigee'] => 'non_compte',
            abs($l['ecart']) > 1e-9 => 'ecart',
            default => 'conforme',
        };

        return $l;
    }

    /** Scans de la période et des secteurs de la session (les scans supprimés sont exclus). */
    private function scansDeLaPeriode(PriseInventaire $prise, Collection $secteurs)
    {
        return ScanTenant::query()
            ->where('date_saisie', '>=', $prise->date_debut)
            ->where('date_saisie', '<=', $prise->date_fin ?? now())
            ->when(! $prise->tous_secteurs, fn ($q) => $q->whereIn('secteur', $secteurs->flatMap(fn (Secteur $s) => [$s->code, $s->nom])->unique()->values()));
    }

    /**
     * Dernière prise validée couvrant chaque secteur, hors la session elle-même, et dernière prise validée
     * « tous secteurs » (pour les scans d'un secteur inconnu).
     *
     * @return array{0: array<int, ?PriseInventaire>, 1: ?PriseInventaire}
     */
    private function references(?PriseInventaire $prise, Collection $secteurs): array
    {
        $validees = PriseInventaire::validees()
            ->when($prise, fn ($q) => $q->whereKeyNot($prise->id))
            ->with('secteurs')
            ->orderByDesc('cloturee_le')->orderByDesc('id')
            ->get();

        $references = [];
        foreach ($secteurs as $secteur) {
            $references[$secteur->id] = $validees->first(fn (PriseInventaire $v) => $v->couvre($secteur->id));
        }

        return [$references, $validees->first(fn (PriseInventaire $v) => $v->tous_secteurs)];
    }

    /** Lignes figées des prises de référence pour leurs secteurs (et lignes sans secteur connu). */
    private function lignesDeReference(array $references, ?PriseInventaire $referenceBrute): Collection
    {
        $groupes = [];
        foreach ($references as $secteurId => $reference) {
            if ($reference) {
                $groupes[$reference->id][] = $secteurId;
            }
        }
        if (! $groupes && ! $referenceBrute) {
            return collect();
        }

        return PriseInventaireLigne::query()->where(function ($q) use ($groupes, $referenceBrute) {
            foreach ($groupes as $priseId => $secteurIds) {
                $q->orWhere(fn ($q) => $q->where('prise_inventaire_id', $priseId)->whereIn('secteur_id', $secteurIds));
            }
            if ($referenceBrute) {
                $q->orWhere(fn ($q) => $q->where('prise_inventaire_id', $referenceBrute->id)->whereNull('secteur_id'));
            }
        })->get();
    }

    /**
     * Entrées (réceptions rangées dans le secteur, relocalisations vers lui) et sorties (relocalisations
     * depuis lui) enregistrées après la date donnée, par numéro de produit.
     *
     * @return array<string, array{entrees: float, sorties: float}>
     */
    private function mouvementsDepuis(Secteur $secteur, CarbonInterface $depuis): array
    {
        $resultat = [];
        $ajouter = function (iterable $groupes, string $sens) use (&$resultat) {
            foreach ($groupes as $groupe) {
                $resultat[$groupe->numero] ??= ['entrees' => 0.0, 'sorties' => 0.0];
                $resultat[$groupe->numero][$sens] += (float) $groupe->total;
            }
        };
        $libelles = array_values(array_unique([$secteur->code, $secteur->nom]));

        $ajouter(DB::table('reception_arrivages_ligne as r')
            ->join('com_four_ligne as l', 'l.id', '=', 'r.com_four_ligne_id')
            ->join('com_four_entete as e', 'e.id', '=', 'l.com_four_entete_id')
            ->join('produits as p', 'p.id', '=', 'l.produit_id')
            ->where('e.tenant_id', app(TenantContext::class)->getTenantId())
            ->where('r.secteur_id', $secteur->id)
            ->where('r.created_at', '>', $depuis)
            ->where('r.annulee', false)
            ->groupBy('p.numero')
            // Quantité reçue en unités d'achat : ramenée à l'unité de stock (un carton de 12 compte 12 unités).
            ->selectRaw('p.numero AS numero, SUM(r.quantite_recue * COALESCE(l.qte_par_unite_achat, 1)) AS total')
            ->get(), 'entrees');

        foreach (['secteur_destination' => 'entrees', 'secteur_source' => 'sorties'] as $colonne => $sens) {
            $ajouter(MouvementTenant::query()
                ->where('date_mouvement', '>', $depuis)
                ->whereIn($colonne, $libelles)
                ->groupBy('produit_numero')
                ->selectRaw('produit_numero AS numero, SUM(quantite) AS total')
                ->get(), $sens);
        }

        return $resultat;
    }

    /** Secteurs indexés par code et par nom, sans tenir compte de la casse. */
    private function index(Collection $secteurs): array
    {
        $index = [];
        foreach ($secteurs as $secteur) {
            $index[mb_strtolower($secteur->nom)] = $secteur;
            $index[mb_strtolower($secteur->code)] = $secteur;
        }

        return $index;
    }

    /** @return array<string, string> description par numéro de produit */
    private function descriptions(Collection $numeros): array
    {
        return $numeros->isEmpty() ? [] : ProduitTenant::withTrashed()->whereIn('numero', $numeros->unique()->values())->pluck('description', 'numero')->all();
    }

    private function date(mixed $valeur): ?string
    {
        return $valeur ? Carbon::parse($valeur)->toIso8601String() : null;
    }
}
