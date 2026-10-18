<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChampPersonnalise;
use App\Models\ChampPersonnaliseValeur;
use App\Services\ValeursChampsPersonnalises;
use App\Support\ChampsPersonnalises;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Champs personnalisés : définitions par type de fiche (libellé, type, options, obligatoire,
 * sans doublon, valeur par défaut, aide, ordre), valeurs fiche par fiche, recherche par valeur.
 */
class ChampPersonnaliseController extends Controller
{
    public function __construct(private readonly ValeursChampsPersonnalises $valeurs) {}

    // ── Définitions ──────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $entite = $request->validate(['entite' => ['nullable', Rule::in(ChampsPersonnalises::entites())]])['entite'] ?? null;
        if ($entite) {
            $this->verifierModule($request, $entite);
        }

        $champs = ChampPersonnalise::query()
            ->whereIn('entite', $entite ? [$entite] : $this->entitesAccessibles($request))
            ->when($request->boolean('actifs'), fn ($q) => $q->where('actif', true))
            ->orderBy('entite')->orderBy('ordre')->orderBy('id')
            ->get();

        return response()->json($champs->map(fn (ChampPersonnalise $champ) => $this->presenter($champ)));
    }

    public function store(Request $request): JsonResponse
    {
        $base = $request->validate([
            'entite' => ['required', Rule::in(ChampsPersonnalises::entites())],
            'type' => ['required', Rule::in(ChampsPersonnalises::TYPES)],
        ]);
        $this->verifierModule($request, $base['entite']);

        $champ = new ChampPersonnalise($base);
        $donnees = $this->validerDefinition($request, $champ);
        $champ->fill($donnees + [
            'code' => $this->codeLibre($base['entite'], $donnees['libelle']),
            'ordre' => (int) ChampPersonnalise::where('entite', $base['entite'])->max('ordre') + 1,
        ])->save();

        return response()->json($this->presenter($champ), 201);
    }

    /** Le type et la fiche visée ne changent pas : les valeurs déjà saisies restent cohérentes. */
    public function update(Request $request, int $id): JsonResponse
    {
        $champ = ChampPersonnalise::findOrFail($id);
        $this->verifierModule($request, $champ->entite);

        $champ->update($this->validerDefinition($request, $champ));

        return response()->json($this->presenter($champ));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $champ = ChampPersonnalise::findOrFail($id);
        $this->verifierModule($request, $champ->entite);

        DB::transaction(function () use ($champ) {
            $champ->valeurs()->delete();
            $champ->delete();
        });

        return response()->json(['message' => __('Champ personnalisé supprimé, avec ses valeurs.')]);
    }

    /** Nouvel ordre d'affichage des champs d'un type de fiche. */
    public function ordonner(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'entite' => ['required', Rule::in(ChampsPersonnalises::entites())],
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'distinct'],
        ]);
        $this->verifierModule($request, $donnees['entite']);

        $champs = ChampPersonnalise::where('entite', $donnees['entite'])->whereIn('id', $donnees['ids'])->get()->keyBy('id');
        DB::transaction(function () use ($donnees, $champs) {
            foreach (array_values($donnees['ids']) as $position => $id) {
                $champs->get($id)?->update(['ordre' => $position + 1]);
            }
        });

        return response()->json(ChampPersonnalise::where('entite', $donnees['entite'])->orderBy('ordre')->orderBy('id')->get()
            ->map(fn (ChampPersonnalise $champ) => $this->presenter($champ)));
    }

    // ── Valeurs d'une fiche ──────────────────────────────────────

    /** Champs actifs de la fiche avec leur valeur (ou la valeur par défaut si rien n'est encore saisi). */
    public function valeurs(Request $request): JsonResponse
    {
        [$entite, $fiche] = $this->fiche($request);

        return response()->json($this->champsDeLaFiche($entite, (int) $fiche->getKey()));
    }

    public function enregistrerValeurs(Request $request): JsonResponse
    {
        [$entite, $fiche] = $this->fiche($request);
        $entiteId = (int) $fiche->getKey();
        $champs = ChampPersonnalise::where('entite', $entite)->where('actif', true)->get();

        $regles = ['valeurs' => ['present', 'array']];
        $noms = [];
        foreach ($champs as $champ) {
            $regles += $this->valeurs->regles($champ, "valeurs.{$champ->id}");
            $noms["valeurs.{$champ->id}"] = $champ->libelle;
            $noms["valeurs.{$champ->id}.*"] = $champ->libelle;
        }
        $saisies = Validator::make($request->all(), $regles, $this->messagesValeurs(), $noms)->validate()['valeurs'] ?? [];

        $aEnregistrer = [];
        $doublons = [];
        foreach ($champs as $champ) {
            $stockee = $this->valeurs->versStockage($champ, $saisies[$champ->id] ?? null);
            if ($stockee !== null && $champ->sans_doublon && $this->valeurs->estUnDoublon($champ, $stockee, $entiteId)) {
                $doublons["valeurs.{$champ->id}"] = __('« :champ » : cette valeur est déjà utilisée par une autre fiche.', ['champ' => $champ->libelle]);
            }
            $aEnregistrer[$champ->id] = $stockee;
        }
        if ($doublons) {
            throw ValidationException::withMessages($doublons);
        }

        DB::transaction(function () use ($aEnregistrer, $entiteId) {
            foreach ($aEnregistrer as $champId => $stockee) {
                if ($stockee === null) {
                    ChampPersonnaliseValeur::where('champ_personnalise_id', $champId)->where('entite_id', $entiteId)->delete();
                } else {
                    ChampPersonnaliseValeur::updateOrCreate(['champ_personnalise_id' => $champId, 'entite_id' => $entiteId], ['valeur' => $stockee]);
                }
            }
        });

        return response()->json($this->champsDeLaFiche($entite, $entiteId));
    }

    /** Fiches dont un champ porte une valeur : contient (textes), égale (nombres, dates, listes, cases). */
    public function recherche(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'champ_id' => ['required', 'integer'],
            'valeur' => ['required', 'string', 'max:255'],
        ]);
        $champ = ChampPersonnalise::findOrFail($donnees['champ_id']);
        $this->verifierModule($request, $champ->entite);
        $cherche = trim($donnees['valeur']);

        $requete = ChampPersonnaliseValeur::where('champ_personnalise_id', $champ->id);
        match ($champ->type) {
            'texte', 'texte_long', 'courriel', 'url', 'telephone' => $requete->whereRaw('LOWER(valeur) LIKE ?', ['%'.addcslashes(mb_strtolower($cherche), '%_\\').'%']),
            'liste_multiple' => $requete->where('valeur', 'like', '%'.addcslashes(json_encode($cherche, JSON_UNESCAPED_UNICODE), '%_\\').'%'),
            'case' => $requete->where('valeur', filter_var($cherche, FILTER_VALIDATE_BOOL) ? '1' : '0'),
            'nombre' => is_numeric($cherche) ? $requete->where('valeur', $this->valeurs->versStockage($champ, $cherche)) : $requete->whereRaw('1 = 0'),
            default => $requete->where('valeur', $cherche),
        };
        $trouvees = $requete->limit(200)->get();

        $classe = ChampsPersonnalises::modele($champ->entite);
        $fiches = $classe::query()
            ->when(in_array(SoftDeletes::class, class_uses_recursive($classe), true), fn ($q) => $q->withTrashed())
            ->whereKey($trouvees->pluck('entite_id')->all())
            ->get()->keyBy(fn (Model $fiche) => $fiche->getKey());

        return response()->json($trouvees->map(fn (ChampPersonnaliseValeur $v) => [
            'entite_id' => $v->entite_id,
            'libelle_entite' => $fiches->has($v->entite_id) ? ChampsPersonnalises::libelle($fiches[$v->entite_id]) : null,
            'valeur' => $this->valeurs->versAffichage($champ, $v->valeur),
        ])->values());
    }

    // ── Outils ───────────────────────────────────────────────────

    private function validerDefinition(Request $request, ChampPersonnalise $champ): array
    {
        $donnees = $request->validate([
            'libelle' => ['required', 'string', 'max:100'],
            'options' => [Rule::requiredIf(in_array($champ->type, ChampsPersonnalises::TYPES_A_CHOIX, true)), 'nullable', 'array', 'max:50'],
            'options.*' => ['required', 'string', 'max:100', 'distinct'],
            'format' => ['nullable', Rule::in(ChampsPersonnalises::FORMATS_NOMBRE)],
            'decimales' => ['nullable', 'integer', 'between:0,6'],
            'obligatoire' => ['boolean'],
            'sans_doublon' => ['boolean'],
            'aide' => ['nullable', 'string', 'max:240'],
            'actif' => ['boolean'],
        ], ['options.required' => __('Ajoutez au moins un choix à la liste.')]);

        $estAChoix = in_array($champ->type, ChampsPersonnalises::TYPES_A_CHOIX, true);
        $donnees['options'] = $estAChoix ? array_values(array_map('trim', $donnees['options'] ?? [])) : null;
        $donnees['format'] = $champ->type === 'nombre' ? ($donnees['format'] ?? 'nombre') : null;
        $donnees['decimales'] = $champ->type === 'nombre' ? ($donnees['decimales'] ?? null) : null;
        $donnees['sans_doublon'] = in_array($champ->type, ChampsPersonnalises::TYPES_SANS_DOUBLON, true) && ($donnees['sans_doublon'] ?? false);

        // La valeur par défaut suit les règles du champ, avec les options qui viennent d'être saisies.
        $essai = (clone $champ)->forceFill(['options' => $donnees['options'], 'decimales' => $donnees['decimales']]);
        $defaut = Validator::make(
            ['valeur_defaut' => $request->input('valeur_defaut')],
            $this->valeurs->regles($essai, 'valeur_defaut', obligatoireApplique: false),
            $this->messagesValeurs(),
            ['valeur_defaut' => __('valeur par défaut'), 'valeur_defaut.*' => __('valeur par défaut')],
        )->validate()['valeur_defaut'] ?? null;
        $donnees['valeur_defaut'] = $this->valeurs->versStockage($essai, $defaut);

        return $donnees;
    }

    private function presenter(ChampPersonnalise $champ): array
    {
        return $champ->only(['id', 'entite', 'code', 'libelle', 'type', 'options', 'format', 'decimales', 'obligatoire', 'sans_doublon', 'aide', 'ordre', 'actif']) + [
            'valeur_defaut' => $this->valeurs->versAffichage($champ, $champ->valeur_defaut),
        ];
    }

    private function champsDeLaFiche(string $entite, int $entiteId): array
    {
        $champs = ChampPersonnalise::where('entite', $entite)->where('actif', true)->orderBy('ordre')->orderBy('id')->get();
        $saisies = ChampPersonnaliseValeur::whereIn('champ_personnalise_id', $champs->pluck('id'))->where('entite_id', $entiteId)
            ->pluck('valeur', 'champ_personnalise_id');

        return $champs->map(fn (ChampPersonnalise $champ) => $this->presenter($champ) + [
            'valeur' => $this->valeurs->versAffichage($champ, $saisies->has($champ->id) ? $saisies[$champ->id] : $champ->valeur_defaut),
            'saisie' => $saisies->has($champ->id),
        ])->all();
    }

    /** @return array{0: string, 1: Model} */
    private function fiche(Request $request): array
    {
        $donnees = $request->validate([
            'entite' => ['required', Rule::in(ChampsPersonnalises::entites())],
            'entite_id' => ['required', 'integer'],
        ]);
        $this->verifierModule($request, $donnees['entite']);

        return [$donnees['entite'], ChampsPersonnalises::modele($donnees['entite'])::query()->findOrFail($donnees['entite_id'])];
    }

    /** Code technique unique par type de fiche, tiré du libellé (« No projet » → no_projet). */
    private function codeLibre(string $entite, string $libelle): string
    {
        $base = Str::limit(Str::slug($libelle, '_') ?: 'champ', 50, '');
        $code = $base;
        for ($i = 2; ChampPersonnalise::where('entite', $entite)->where('code', $code)->exists(); $i++) {
            $code = "{$base}_{$i}";
        }

        return $code;
    }

    private function verifierModule(Request $request, string $entite): void
    {
        $module = ChampsPersonnalises::module($entite);
        $tenant = $request->attributes->get('tenant');

        abort_if($module && $tenant && ! $tenant->hasModule($module), 403, __("Le module « :module » n'est pas activé pour cette entreprise.", ['module' => $module]));
    }

    /** Messages de validation des valeurs (textes français de ValeursChampsPersonnalises::MESSAGES), dans la langue de la requête. */
    private function messagesValeurs(): array
    {
        return array_map(fn (string $message) => __($message), ValeursChampsPersonnalises::MESSAGES);
    }

    private function entitesAccessibles(Request $request): array
    {
        $tenant = $request->attributes->get('tenant');

        return array_values(array_filter(
            ChampsPersonnalises::entites(),
            fn (string $entite) => ! $tenant || ! ChampsPersonnalises::module($entite) || $tenant->hasModule(ChampsPersonnalises::module($entite)),
        ));
    }
}
