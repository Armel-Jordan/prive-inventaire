<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PieceJointe;
use App\Support\PiecesJointes;
use App\Support\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Pièces jointes des fiches et documents : liste, ajout de plusieurs fichiers, téléchargement, suppression. */
class PieceJointeController extends Controller
{
    /**
     * Pièces d'une fiche (entite + entite_id) ou de toute l'entreprise, filtrables par type de fiche,
     * catégorie et mot-clé (nom du fichier, description, catégorie).
     */
    public function index(Request $request): JsonResponse
    {
        $filtres = $request->validate([
            'entite' => ['nullable', 'required_with:entite_id', Rule::in(PiecesJointes::entites())],
            'entite_id' => ['nullable', 'integer'],
            'categorie' => ['nullable', 'string', 'max:100'],
            'recherche' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        if (isset($filtres['entite_id'])) {
            $this->fiche($request, $filtres['entite'], (int) $filtres['entite_id']);
        } elseif (isset($filtres['entite'])) {
            $this->verifierModule($request, $filtres['entite']);
        }

        $pieces = PieceJointe::with('auteur:id,nom')
            ->whereIn('entite', $this->entitesAccessibles($request))
            ->when($filtres['entite'] ?? null, fn ($q, $entite) => $q->where('entite', $entite))
            ->when($filtres['entite_id'] ?? null, fn ($q, $id) => $q->where('entite_id', $id))
            ->when($filtres['categorie'] ?? null, fn ($q, $categorie) => $q->where('categorie', $categorie))
            ->when($filtres['recherche'] ?? null, function ($q, $recherche) {
                $motif = '%'.addcslashes($recherche, '%_\\').'%';
                $q->where(fn ($q) => $q->where('nom_original', 'like', $motif)
                    ->orWhere('description', 'like', $motif)
                    ->orWhere('categorie', 'like', $motif));
            })
            ->latest('id')
            ->paginate($filtres['per_page'] ?? 50);

        $libelles = $this->libelles($pieces->getCollection());

        return response()->json($pieces->through(fn (PieceJointe $piece) => $this->presenter($piece) + [
            'libelle_entite' => $libelles[$piece->entite][$piece->entite_id] ?? null,
        ]));
    }

    /** Catégories déjà utilisées (suggestions à la saisie). */
    public function categories(Request $request): JsonResponse
    {
        $entite = $request->validate(['entite' => ['nullable', Rule::in(PiecesJointes::entites())]])['entite'] ?? null;

        return response()->json(PieceJointe::query()
            ->whereNotNull('categorie')
            ->when($entite, fn ($q) => $q->where('entite', $entite))
            ->distinct()
            ->orderBy('categorie')
            ->pluck('categorie'));
    }

    /** Ajoute un ou plusieurs fichiers à une fiche, avec une description commune ou une par fichier. */
    public function store(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'entite' => ['required', Rule::in(PiecesJointes::entites())],
            'entite_id' => ['required', 'integer'],
            'fichiers' => ['required', 'array', 'min:1', 'max:10'],
            'fichiers.*' => ['required', 'file', 'max:'.config('pieces_jointes.taille_max_ko'), $this->regleExtension()],
            'description' => ['nullable', 'string', 'max:500'],
            'descriptions' => ['nullable', 'array'],
            'descriptions.*' => ['nullable', 'string', 'max:500'],
            'categorie' => ['nullable', 'string', 'max:100'],
        ], [
            'fichiers.*.max' => 'Le fichier dépasse la taille maximale autorisée.',
        ]);

        $fiche = $this->fiche($request, $donnees['entite'], (int) $donnees['entite_id']);
        $disque = config('pieces_jointes.disque');
        $dossier = sprintf('pieces-jointes/%d/%s/%d', app(TenantContext::class)->getTenantId(), $donnees['entite'], $fiche->getKey());
        $stockes = [];

        try {
            $pieces = DB::transaction(function () use ($request, $donnees, $disque, $dossier, &$stockes) {
                $creees = [];
                foreach ($request->file('fichiers') as $index => $fichier) {
                    $chemin = $fichier->storeAs($dossier, $this->nomStockage($fichier), ['disk' => $disque]);
                    if ($chemin === false) {
                        throw new RuntimeException('Le fichier n\'a pas pu être enregistré.');
                    }
                    $stockes[] = $chemin;

                    $creees[] = PieceJointe::create([
                        'entite' => $donnees['entite'],
                        'entite_id' => $donnees['entite_id'],
                        'categorie' => $donnees['categorie'] ?? null,
                        'description' => ($donnees['descriptions'][$index] ?? null) ?: ($donnees['description'] ?? null),
                        'nom_original' => $this->nomOriginal($fichier),
                        'disque' => $disque,
                        'chemin' => $chemin,
                        'type_mime' => $fichier->getMimeType(),
                        'taille' => $fichier->getSize(),
                        'ajoute_par' => $request->user()?->id,
                    ]);
                }

                return $creees;
            });
        } catch (Throwable $erreur) {
            // Aucun fichier orphelin si l'enregistrement échoue en cours de route.
            Storage::disk($disque)->delete($stockes);
            throw $erreur;
        }

        return response()->json(array_map(fn (PieceJointe $piece) => $this->presenter($piece->load('auteur:id,nom')), $pieces), 201);
    }

    /** Fichier d'origine, toujours en téléchargement ; seuls les types sûrs gardent leur type réel. */
    public function fichier(Request $request, int $id): StreamedResponse
    {
        $piece = PieceJointe::findOrFail($id);
        $this->verifierModule($request, $piece->entite);

        $stockage = Storage::disk($piece->disque);
        abort_unless($stockage->exists($piece->chemin), 404, 'Le fichier n\'existe plus sur le serveur.');

        return $stockage->download($piece->chemin, $piece->nom_original, [
            'Content-Type' => in_array($piece->type_mime, PiecesJointes::TYPES_AFFICHABLES, true) ? $piece->type_mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $piece = PieceJointe::findOrFail($id);
        $this->verifierModule($request, $piece->entite);

        $piece->update($request->validate([
            'description' => ['nullable', 'string', 'max:500'],
            'categorie' => ['nullable', 'string', 'max:100'],
        ]));

        return response()->json($this->presenter($piece->load('auteur:id,nom')));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $piece = PieceJointe::findOrFail($id);
        $this->verifierModule($request, $piece->entite);

        Storage::disk($piece->disque)->delete($piece->chemin);
        $piece->delete();

        return response()->json(['message' => 'Pièce jointe supprimée.']);
    }

    private function presenter(PieceJointe $piece): array
    {
        return $piece->only(['id', 'entite', 'entite_id', 'categorie', 'description', 'nom_original', 'type_mime', 'taille']) + [
            'affichable' => in_array($piece->type_mime, PiecesJointes::TYPES_AFFICHABLES, true),
            'auteur' => $piece->auteur?->nom,
            'created_at' => $piece->created_at?->toIso8601String(),
        ];
    }

    /** Fiche ou document de l'entreprise courante (404 sinon), dont le module est actif (403 sinon). */
    private function fiche(Request $request, string $entite, int $id): Model
    {
        $this->verifierModule($request, $entite);

        return PiecesJointes::modele($entite)::query()->findOrFail($id);
    }

    private function verifierModule(Request $request, string $entite): void
    {
        $module = PiecesJointes::module($entite);
        $tenant = $request->attributes->get('tenant');

        abort_if($module && $tenant && ! $tenant->hasModule($module), 403, "Le module « {$module} » n'est pas activé pour cette entreprise.");
    }

    /** Types de fiches dont le module est actif : les pièces des autres restent masquées. */
    private function entitesAccessibles(Request $request): array
    {
        $tenant = $request->attributes->get('tenant');

        return array_values(array_filter(
            PiecesJointes::entites(),
            fn (string $entite) => ! $tenant || ! PiecesJointes::module($entite) || $tenant->hasModule(PiecesJointes::module($entite)),
        ));
    }

    /** Libellés des fiches de la page, en une requête par type ; une fiche supprimée reste nommée. */
    private function libelles(iterable $pieces): array
    {
        $libelles = [];
        foreach (collect($pieces)->groupBy('entite') as $entite => $groupe) {
            $classe = PiecesJointes::modele($entite);
            $requete = $classe::query();
            if (in_array(SoftDeletes::class, class_uses_recursive($classe), true)) {
                $requete->withTrashed();
            }
            $libelles[$entite] = $requete->whereKey($groupe->pluck('entite_id')->unique()->all())->get()
                ->mapWithKeys(fn (Model $fiche) => [$fiche->getKey() => PiecesJointes::libelle($fiche)])
                ->all();
        }

        return $libelles;
    }

    /** Refuse les exécutables et scripts, d'après l'extension du nom d'origine. */
    private function regleExtension(): Closure
    {
        $refusees = config('pieces_jointes.extensions_refusees', []);

        return function (string $attribut, mixed $fichier, Closure $echec) use ($refusees) {
            if ($fichier instanceof UploadedFile && in_array(mb_strtolower($fichier->getClientOriginalExtension()), $refusees, true)) {
                $echec('Type de fichier refusé pour des raisons de sécurité : '.$fichier->getClientOriginalName());
            }
        };
    }

    /** Nom de stockage aléatoire : rien de ce que l'utilisateur saisit n'entre dans le chemin. */
    private function nomStockage(UploadedFile $fichier): string
    {
        $extension = substr(preg_replace('/[^a-z0-9]/', '', mb_strtolower($fichier->getClientOriginalExtension())), 0, 10);

        return Str::uuid().($extension !== '' ? '.'.$extension : '');
    }

    private function nomOriginal(UploadedFile $fichier): string
    {
        $nom = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $fichier->getClientOriginalName()));

        return Str::limit($nom !== '' ? $nom : 'fichier', 250, '');
    }
}
