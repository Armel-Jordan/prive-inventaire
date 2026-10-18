<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Balise;
use App\Models\BaliseAffectation;
use App\Models\BaliseItem;
use App\Support\Fiches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Balises de classement : définitions (balise à un choix ou à plusieurs, et ses items), pose sur
 * les fiches et documents, recherche des fiches par items.
 */
class BaliseController extends Controller
{
    // ── Définitions ──────────────────────────────────────────────

    /** Balises (avec leurs items), éventuellement limitées à celles d'un type de fiche. */
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate(['entite' => ['nullable', Rule::in(Fiches::entites())]]);
        $actifs = $request->boolean('actifs');

        $balises = Balise::with(['items' => fn ($q) => $q->when($actifs, fn ($q) => $q->where('actif', true))])
            ->when($actifs, fn ($q) => $q->where('actif', true))
            ->orderBy('ordre')->orderBy('id')
            ->get()
            ->filter(fn (Balise $b) => ! isset($filtres['entite']) || in_array($filtres['entite'], $b->entites ?? [], true))
            ->values();

        return response()->json($balises);
    }

    public function store(Request $request): JsonResponse
    {
        $balise = new Balise($this->validerBalise($request) + ['ordre' => (int) Balise::max('ordre') + 1]);
        $balise->save();

        return response()->json($balise->load('items'), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $balise = Balise::findOrFail($id);
        $donnees = $this->validerBalise($request);

        // Passer à un seul choix est refusé si des fiches portent déjà plusieurs items de la balise.
        if ($donnees['type'] === 'choix' && $balise->type !== 'choix' && $this->ficheAvecPlusieursItems($balise)) {
            throw ValidationException::withMessages([
                'type' => __('Des fiches portent déjà plusieurs items de cette balise : retirez-les avant de passer à un seul choix.'),
            ]);
        }

        $balise->update($donnees);

        return response()->json($balise->load('items'));
    }

    public function destroy(int $id): JsonResponse
    {
        $balise = Balise::findOrFail($id);
        DB::transaction(function () use ($balise) {
            BaliseAffectation::whereIn('balise_item_id', $balise->items()->pluck('id'))->delete();
            $balise->items()->delete();
            $balise->delete();
        });

        return response()->json(['message' => __('Balise supprimée, avec ses items et leurs poses.')]);
    }

    public function ajouterItem(Request $request, int $id): JsonResponse
    {
        $balise = Balise::findOrFail($id);
        $item = $balise->items()->create($this->validerItem($request, $balise) + [
            'ordre' => (int) BaliseItem::where('balise_id', $balise->id)->max('ordre') + 1,
        ]);

        return response()->json($item, 201);
    }

    public function modifierItem(Request $request, int $itemId): JsonResponse
    {
        $item = BaliseItem::findOrFail($itemId);
        $item->update($this->validerItem($request, $item->balise, $item));

        return response()->json($item);
    }

    public function supprimerItem(int $itemId): JsonResponse
    {
        $item = BaliseItem::findOrFail($itemId);
        DB::transaction(function () use ($item) {
            BaliseAffectation::where('balise_item_id', $item->id)->delete();
            $item->delete();
        });

        return response()->json(['message' => __('Item supprimé.')]);
    }

    // ── Pose sur les fiches ──────────────────────────────────────

    /** Items posés sur plusieurs fiches d'un coup (une liste entière) : { id de la fiche: [ids d'items] }. */
    public function affectations(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'entite' => ['required', Rule::in(Fiches::entites())],
            'ids' => ['required', 'string', 'max:2000'],
        ]);
        Fiches::verifierModule($request, $donnees['entite']);
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', $donnees['ids']))))), 0, 200);

        $poses = BaliseAffectation::where('entite', $donnees['entite'])
            ->whereIn('entite_id', $ids)
            ->whereIn('balise_item_id', $this->itemsApplicables($donnees['entite'])->pluck('id'))
            ->get(['entite_id', 'balise_item_id']);

        return response()->json((object) $poses->groupBy('entite_id')->map(fn ($groupe) => $groupe->pluck('balise_item_id')->values())->all());
    }

    /** Remplace les items posés sur une fiche (un seul par balise à choix unique). */
    public function enregistrerAffectations(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'entite' => ['required', Rule::in(Fiches::entites())],
            'entite_id' => ['required', 'integer'],
            'items' => ['present', 'array', 'max:100'],
            'items.*' => ['integer', 'distinct'],
        ]);
        Fiches::verifierModule($request, $donnees['entite']);
        $fiche = Fiches::modele($donnees['entite'])::query()->findOrFail($donnees['entite_id']);

        $applicables = $this->itemsApplicables($donnees['entite']);
        $choisis = $applicables->whereIn('id', $donnees['items']);
        if ($choisis->count() !== count($donnees['items'])) {
            throw ValidationException::withMessages(['items' => __("Certains items n'existent pas ou ne s'appliquent pas à ce type de fiche.")]);
        }
        foreach ($choisis->groupBy('balise_id') as $groupe) {
            $balise = $groupe->first()->balise;
            if ($balise->type === 'choix' && $groupe->count() > 1) {
                throw ValidationException::withMessages(['items' => __("« :balise » n'accepte qu'un seul choix.", ['balise' => $balise->nom])]);
            }
        }

        DB::transaction(function () use ($donnees, $fiche, $applicables, $choisis) {
            BaliseAffectation::where('entite', $donnees['entite'])->where('entite_id', $fiche->getKey())
                ->whereIn('balise_item_id', $applicables->pluck('id'))->delete();
            foreach ($choisis as $item) {
                BaliseAffectation::create(['balise_item_id' => $item->id, 'entite' => $donnees['entite'], 'entite_id' => $fiche->getKey()]);
            }
        });

        return response()->json(['items' => $choisis->pluck('id')->values()]);
    }

    /** Fiches qui portent un des items (mode « un ») ou tous les items (mode « tous »). */
    public function recherche(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'entite' => ['required', Rule::in(Fiches::entites())],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*' => ['integer'],
            'mode' => ['nullable', Rule::in(['un', 'tous'])],
        ]);
        Fiches::verifierModule($request, $donnees['entite']);
        $items = array_values(array_unique($donnees['items']));

        $ids = BaliseAffectation::where('entite', $donnees['entite'])
            ->whereIn('balise_item_id', $items)
            ->select('entite_id')
            ->groupBy('entite_id')
            ->when(($donnees['mode'] ?? 'un') === 'tous', fn ($q) => $q->havingRaw('COUNT(DISTINCT balise_item_id) = ?', [count($items)]))
            ->limit(200)
            ->pluck('entite_id')
            ->all();

        $fiches = Fiches::charger($donnees['entite'], $ids);

        return response()->json(array_map(fn (int $id) => [
            'entite_id' => $id,
            'libelle_entite' => $fiches->has($id) ? Fiches::libelle($fiches[$id]) : null,
        ], $ids));
    }

    // ── Outils ───────────────────────────────────────────────────

    private function validerBalise(Request $request): array
    {
        return $request->validate([
            'nom' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::in(Balise::TYPES)],
            'entites' => ['required', 'array', 'min:1'],
            'entites.*' => ['distinct', Rule::in(Fiches::entites())],
            'actif' => ['boolean'],
        ], ['entites.required' => __('Choisissez au moins un type de fiche.')]);
    }

    private function validerItem(Request $request, Balise $balise, ?BaliseItem $item = null): array
    {
        $donnees = $request->validate([
            'libelle' => ['required', 'string', 'max:60'],
            'couleur' => ['nullable', Rule::in(BaliseItem::COULEURS)],
            'actif' => ['boolean'],
        ]);

        $existe = BaliseItem::where('balise_id', $balise->id)
            ->when($item, fn ($q) => $q->whereKeyNot($item->id))
            ->whereRaw('LOWER(libelle) = ?', [mb_strtolower(trim($donnees['libelle']))])
            ->exists();
        if ($existe) {
            throw ValidationException::withMessages(['libelle' => __('Cet item existe déjà dans la balise.')]);
        }

        return ['libelle' => trim($donnees['libelle']), 'couleur' => $donnees['couleur'] ?? ($item?->couleur ?? 'gris')]
            + (array_key_exists('actif', $donnees) ? ['actif' => $donnees['actif']] : []);
    }

    /** Items actifs des balises actives qui s'appliquent au type de fiche. */
    private function itemsApplicables(string $entite): \Illuminate\Support\Collection
    {
        $balises = Balise::where('actif', true)->get()->filter(fn (Balise $b) => in_array($entite, $b->entites ?? [], true));

        return BaliseItem::with('balise')->where('actif', true)->whereIn('balise_id', $balises->pluck('id'))->get();
    }

    private function ficheAvecPlusieursItems(Balise $balise): bool
    {
        return BaliseAffectation::whereIn('balise_item_id', $balise->items()->pluck('id'))
            ->select('entite', 'entite_id')
            ->groupBy('entite', 'entite_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
    }
}
