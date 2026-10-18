<?php

namespace App\Http\Controllers;

use App\Models\FiltreMemorise;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Filtres mémorisés (plan de parité fripv2, phase 5.3) : les filtres d'un écran enregistrés sous un nom par le compte
 * connecté, dont un « par défaut » appliqué à l'ouverture de l'écran. Données personnelles : chacun ne voit et ne règle
 * que les siens.
 */
class FiltreMemoriseController extends Controller
{
    /** Filtres au plus par écran et par compte. */
    public const MAXIMUM = 30;

    /** Écran : « scans », « clients », « rapport:ventes-par-periode »… */
    private const REGLE_ECRAN = ['required', 'string', 'max:80', 'regex:/^[a-z0-9][a-z0-9:_-]*$/'];

    public function index(Request $request): JsonResponse
    {
        $ecran = $request->validate(['ecran' => self::REGLE_ECRAN])['ecran'];

        return response()->json($this->filtres($request, $ecran)->orderByDesc('par_defaut')->orderBy('nom')->get()
            ->map(fn (FiltreMemorise $filtre) => $this->presenter($filtre))->values());
    }

    /** Enregistre les filtres sous ce nom ; ceux qui le portent déjà sont remplacés. */
    public function store(Request $request): JsonResponse
    {
        $request->merge(['nom' => trim((string) $request->input('nom'))]);
        $donnees = $request->validate([
            'ecran' => self::REGLE_ECRAN,
            'nom' => ['required', 'string', 'max:100'],
            'valeurs' => ['present', 'array', 'max:40'],
            'par_defaut' => ['sometimes', 'boolean'],
        ]);
        $this->verifierValeurs($donnees['valeurs']);
        $existant = $this->filtres($request, $donnees['ecran'])->where('nom', $donnees['nom'])->first();
        if (! $existant && $this->filtres($request, $donnees['ecran'])->count() >= self::MAXIMUM) {
            throw ValidationException::withMessages(['nom' => [__(':maximum filtres au plus par écran : supprimez-en un pour enregistrer celui-ci.', ['maximum' => self::MAXIMUM])]]);
        }

        $filtre = DB::transaction(function () use ($request, $donnees, $existant) {
            // Un seul filtre par défaut par écran.
            if ($donnees['par_defaut'] ?? false) {
                $this->filtres($request, $donnees['ecran'])->update(['par_defaut' => false]);
            }
            $filtre = $existant ?? new FiltreMemorise(['admin_user_id' => $request->user()->id, 'ecran' => $donnees['ecran'], 'nom' => $donnees['nom'], 'par_defaut' => false]);
            $filtre->valeurs = $donnees['valeurs'];
            if (array_key_exists('par_defaut', $donnees)) {
                $filtre->par_defaut = $donnees['par_defaut'];
            }
            $filtre->save();

            return $filtre;
        });

        return response()->json($this->presenter($filtre), $existant ? 200 : 201);
    }

    /** Renomme le filtre, ou le marque (ou plus) comme celui appliqué à l'ouverture de l'écran. */
    public function update(Request $request, int $id): JsonResponse
    {
        $filtre = $this->filtre($request, $id);
        if ($request->has('nom')) {
            $request->merge(['nom' => trim((string) $request->input('nom'))]);
        }
        $donnees = $request->validate([
            'nom' => ['sometimes', 'required', 'string', 'max:100',
                Rule::unique('filtres_memorises', 'nom')->where('admin_user_id', $request->user()->id)->where('ecran', $filtre->ecran)->ignore($filtre->id)],
            'par_defaut' => ['sometimes', 'boolean'],
        ], ['nom.unique' => __('Vous avez déjà un filtre de ce nom pour cet écran.')]);

        DB::transaction(function () use ($request, $filtre, $donnees) {
            if ($donnees['par_defaut'] ?? false) {
                $this->filtres($request, $filtre->ecran)->whereKeyNot($filtre->id)->update(['par_defaut' => false]);
            }
            $filtre->fill($donnees)->save();
        });

        return response()->json($this->presenter($filtre));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->filtre($request, $id)->delete();

        return response()->json(null, 204);
    }

    /** Filtres du compte connecté pour un écran. */
    private function filtres(Request $request, string $ecran): Builder
    {
        return FiltreMemorise::query()->where('admin_user_id', $request->user()->id)->where('ecran', $ecran);
    }

    /** Un filtre du compte connecté ; celui d'un autre compte est « introuvable ». */
    private function filtre(Request $request, int $id): FiltreMemorise
    {
        return FiltreMemorise::query()->where('admin_user_id', $request->user()->id)->findOrFail($id);
    }

    /** Valeurs admises : texte (500 caractères au plus), nombre, booléen ou vide, ou une liste de ces valeurs. */
    private function verifierValeurs(array $valeurs): void
    {
        $simple = fn ($v) => $v === null || is_bool($v) || is_int($v) || is_float($v) || (is_string($v) && mb_strlen($v) <= 500);
        foreach ($valeurs as $cle => $valeur) {
            $admise = is_string($cle) && strlen($cle) <= 60
                && ($simple($valeur) || (is_array($valeur) && array_is_list($valeur) && count($valeur) <= 50 && collect($valeur)->every($simple)));
            if (! $admise) {
                throw ValidationException::withMessages(['valeurs' => [__('Valeur de filtre non prise en charge : « :cle ».', ['cle' => $cle])]]);
            }
        }
    }

    private function presenter(FiltreMemorise $filtre): array
    {
        return [
            'id' => $filtre->id,
            'ecran' => $filtre->ecran,
            'nom' => $filtre->nom,
            // Toujours un objet, même vide.
            'valeurs' => (object) ($filtre->valeurs ?? []),
            'par_defaut' => $filtre->par_defaut,
            'updated_at' => $filtre->updated_at?->toIso8601String(),
        ];
    }
}
