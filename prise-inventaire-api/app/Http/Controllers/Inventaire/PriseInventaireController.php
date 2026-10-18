<?php

namespace App\Http\Controllers\Inventaire;

use App\Http\Controllers\Controller;
use App\Models\PriseInventaire;
use App\Models\PriseInventaireCorrection;
use App\Services\PrisesInventaire;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Prises d'inventaire : sessions de comptage par-dessus les scans, écarts avec le dernier inventaire
 * validé, corrections, validation (inventaire officiel). Consultation pour tous ; création, corrections,
 * validation et annulation par les administrateurs et responsables (routes).
 */
class PriseInventaireController extends Controller
{
    public function __construct(private PrisesInventaire $prises) {}

    public function index(): JsonResponse
    {
        $prises = PriseInventaire::with(['secteurs:secteurs.id,code,nom', 'createur:id,nom', 'cloturePar:id,nom'])
            ->withCount('lignes')
            ->orderByDesc('date_debut')->orderByDesc('id')
            ->get();

        return response()->json($prises);
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'libelle' => ['required', 'string', 'max:150'],
            'tous_secteurs' => ['sometimes', 'boolean'],
            'secteur_ids' => ['required_unless:tous_secteurs,true', 'array'],
            'secteur_ids.*' => ['integer', TenantRule::exists('secteurs')],
            'date_debut' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['secteur_ids.required_unless' => __('Choisissez au moins un secteur, ou tous les secteurs.')]);
        $tous = (bool) ($donnees['tous_secteurs'] ?? false);
        $secteurIds = $tous ? [] : array_values(array_unique($donnees['secteur_ids'] ?? []));
        if (! $tous && ! $secteurIds) {
            return response()->json(['message' => __('Choisissez au moins un secteur, ou tous les secteurs.')], 422);
        }

        // Deux sessions en cours ne peuvent pas compter le même secteur : les scans seraient comptés deux fois.
        $enCours = PriseInventaire::where('statut', PriseInventaire::EN_COURS)->with('secteurs:secteurs.id')->get();
        $conflit = $enCours->first(fn (PriseInventaire $p) => $tous || $p->tous_secteurs || $p->secteurs->pluck('id')->intersect($secteurIds)->isNotEmpty());
        if ($conflit) {
            return response()->json(['message' => __("La prise :numero est déjà en cours sur ces secteurs : validez-la ou annulez-la d'abord.", ['numero' => $conflit->numero])], 422);
        }

        $prise = DB::transaction(function () use ($request, $donnees, $tous, $secteurIds) {
            $annee = now()->year;
            $rang = PriseInventaire::where('numero', 'like', "INV-{$annee}-%")->lockForUpdate()->count() + 1;
            $prise = PriseInventaire::create([
                'numero' => sprintf('INV-%d-%04d', $annee, $rang),
                'libelle' => trim($donnees['libelle']),
                'statut' => PriseInventaire::EN_COURS,
                'tous_secteurs' => $tous,
                'date_debut' => $donnees['date_debut'] ?? now(),
                'notes' => $donnees['notes'] ?? null,
                'creee_par' => $request->user()->id,
            ]);
            $prise->secteurs()->sync($secteurIds);

            return $prise;
        });

        return response()->json($prise->load('secteurs:secteurs.id,code,nom'), 201);
    }

    /** Session, avancement par secteur et résumé des lignes. */
    public function show(int $id): JsonResponse
    {
        $prise = PriseInventaire::with(['secteurs:secteurs.id,code,nom', 'createur:id,nom', 'cloturePar:id,nom'])->findOrFail($id);
        $lignes = $this->prises->lignes($prise);

        return response()->json([
            'prise' => $prise,
            'avancement' => $this->prises->avancement($prise),
            'resume' => [
                'lignes' => $lignes->count(),
                ...collect(PrisesInventaire::STATUTS_LIGNE)->mapWithKeys(fn ($s) => [$s => $lignes->where('statut', $s)->count()])->all(),
                'corrigees' => $lignes->where('corrigee', true)->count(),
                'quantite_comptee' => round($lignes->sum('quantite_comptee'), 4),
                'quantite_retenue' => round($lignes->sum('quantite_retenue'), 4),
            ],
        ]);
    }

    /** Lignes filtrées et paginées (statut, secteur, recherche sur le produit). */
    public function lignes(Request $request, int $id): JsonResponse
    {
        $prise = PriseInventaire::findOrFail($id);
        $filtres = $request->validate([
            'statut' => ['nullable', Rule::in([...PrisesInventaire::STATUTS_LIGNE, 'corrigee'])],
            'secteur' => ['nullable', 'string', 'max:255'],
            'recherche' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        $lignes = $this->filtrer($this->prises->lignes($prise), $filtres);
        $parPage = $filtres['per_page'] ?? 50;
        $page = $filtres['page'] ?? 1;

        return response()->json([
            'data' => $lignes->forPage($page, $parPage)->values(),
            'total' => $lignes->count(),
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($lignes->count() / $parPage)),
        ]);
    }

    /** Quantité retenue saisie par un responsable (vide : retour au comptage des scans). */
    public function corriger(Request $request, int $id): JsonResponse
    {
        $prise = PriseInventaire::findOrFail($id);
        if ($prise->statut !== PriseInventaire::EN_COURS) {
            return response()->json(['message' => __('Seule une prise en cours peut être corrigée.')], 422);
        }
        $donnees = $request->validate([
            'secteur' => ['required', 'string', 'max:255'],
            'produit_numero' => ['required', 'string', 'max:50'],
            'quantite_retenue' => ['present', 'nullable', 'numeric', 'min:0', 'max:99999999999'],
            'raison' => ['nullable', 'string', 'max:255'],
        ]);

        $cle = ['prise_inventaire_id' => $prise->id, 'secteur' => $donnees['secteur'], 'produit_numero' => $donnees['produit_numero']];
        if ($donnees['quantite_retenue'] === null) {
            PriseInventaireCorrection::where($cle)->delete();
        } else {
            PriseInventaireCorrection::updateOrCreate($cle, [
                'quantite_retenue' => $donnees['quantite_retenue'],
                'raison' => filled($donnees['raison'] ?? null) ? trim($donnees['raison']) : null,
                'auteur_id' => $request->user()->id,
            ]);
        }

        $ligne = $this->prises->lignes($prise)
            ->first(fn ($l) => mb_strtolower($l['secteur']) === mb_strtolower($donnees['secteur']) && $l['produit_numero'] === $donnees['produit_numero']);

        return response()->json($ligne);
    }

    public function valider(Request $request, int $id): JsonResponse
    {
        $prise = $this->prises->valider(PriseInventaire::findOrFail($id), $request->user());
        if (! $prise) {
            return response()->json(['message' => __('Seule une prise en cours peut être validée.')], 422);
        }

        return response()->json($prise->load(['secteurs:secteurs.id,code,nom', 'cloturePar:id,nom'])->loadCount('lignes'));
    }

    public function annuler(Request $request, int $id): JsonResponse
    {
        $prise = PriseInventaire::findOrFail($id);
        if ($prise->statut !== PriseInventaire::EN_COURS) {
            return response()->json(['message' => __('Seule une prise en cours peut être annulée.')], 422);
        }
        $prise->update([
            'statut' => PriseInventaire::ANNULEE,
            'date_fin' => $prise->date_fin ?? now(),
            'cloturee_par' => $request->user()->id,
            'cloturee_le' => now(),
        ]);

        return response()->json($prise);
    }

    /** Rapport d'écarts en CSV (séparateur « ; », UTF-8 avec BOM pour Excel). */
    public function exporter(Request $request, int $id): StreamedResponse
    {
        $prise = PriseInventaire::findOrFail($id);
        $lignes = $this->filtrer($this->prises->lignes($prise), $request->only(['statut', 'secteur', 'recherche']));

        return response()->streamDownload(function () use ($lignes) {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['Secteur', 'Produit', 'Description', 'Compté', 'Scans', 'Référence', 'Écart', 'Entrées depuis', 'Sorties depuis', 'Retenu', 'Raison', 'Statut'], ';');
            foreach ($lignes as $l) {
                fputcsv($sortie, [
                    $l['secteur'], $l['produit_numero'], $l['description'], $l['quantite_comptee'], $l['nb_scans'], $l['quantite_reference'],
                    $l['ecart'], $l['entrees_depuis'], $l['sorties_depuis'], $l['quantite_retenue'], $l['raison'], $l['statut'],
                ], ';');
            }
            fclose($sortie);
        }, "{$prise->numero}-ecarts.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Inventaire officiel : quantités de la dernière prise validée de chaque secteur. */
    public function officiel(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'secteur_id' => ['nullable', 'integer'],
            'recherche' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $lignes = $this->prises->inventaireOfficiel($filtres['secteur_id'] ?? null)
            ->when($filtres['recherche'] ?? null, fn (Collection $c, $texte) => $c->filter(fn ($l) => $this->correspond($l, $texte))->values());
        $page = $filtres['page'] ?? 1;

        return response()->json([
            'data' => $lignes->forPage($page, 50)->values(),
            'total' => $lignes->count(),
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($lignes->count() / 50)),
        ]);
    }

    private function filtrer(Collection $lignes, array $filtres): Collection
    {
        $statut = $filtres['statut'] ?? null;

        return $lignes
            ->when($statut === 'corrigee', fn ($c) => $c->where('corrigee', true))
            ->when($statut && $statut !== 'corrigee', fn ($c) => $c->where('statut', $statut))
            ->when($filtres['secteur'] ?? null, fn ($c, $secteur) => $c->filter(fn ($l) => mb_strtolower($l['secteur']) === mb_strtolower($secteur)))
            ->when($filtres['recherche'] ?? null, fn ($c, $texte) => $c->filter(fn ($l) => $this->correspond($l, $texte)))
            ->values();
    }

    private function correspond(array $ligne, string $texte): bool
    {
        $texte = mb_strtolower($texte);

        return str_contains(mb_strtolower($ligne['produit_numero']), $texte) || str_contains(mb_strtolower((string) $ligne['description']), $texte);
    }
}
