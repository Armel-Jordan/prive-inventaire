<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProfilImport;
use App\Services\ImportPrixFournisseurs;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Import d'une grille de prix fournisseur (simulation, puis import) et profils d'import mémorisés. Les règles sont dans
 * App\Services\ImportPrixFournisseurs.
 */
class ImportPrixFournisseurController extends Controller
{
    public function __construct(private readonly ImportPrixFournisseurs $imports) {}

    /** Simule ou applique l'import : rapport des prix mis à jour, inchangés et des lignes ignorées avec leur motif. */
    public function importer(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'fichier' => 'required|file|max:5120|mimes:csv,txt',
            'fournisseur_id' => ['nullable', 'integer', TenantRule::exists('fournisseurs')],
            'colonne_fournisseur' => 'nullable|integer|min:1|max:200',
            'colonne_code' => 'required|integer|min:1|max:200',
            'colonne_cout' => 'required|integer|min:1|max:200',
            'ligne_debut' => 'nullable|integer|min:1|max:1000',
            'simuler' => 'boolean',
        ], ['fichier.mimes' => 'Choisissez un fichier CSV (enregistrez un classeur Excel au format CSV).']);

        $rapport = $this->imports->importer((string) file_get_contents($request->file('fichier')->getRealPath()), $donnees, $request->boolean('simuler'));
        $simulation = $request->boolean('simuler');

        return response()->json($rapport + [
            'simulation' => $simulation,
            'message' => ($simulation ? 'Simulation : ' : '')."{$rapport['mises_a_jour']} prix ".($simulation ? 'à mettre à jour' : 'mis à jour')
                .", {$rapport['inchangees']} inchangé(s), ".count($rapport['ignorees']).' ligne(s) ignorée(s).',
        ]);
    }

    public function profils(Request $request): JsonResponse
    {
        $type = $request->validate(['type' => ['required', Rule::in(ProfilImport::TYPES)]])['type'];

        return response()->json(ProfilImport::where('type', $type)->orderBy('nom')->get(['id', 'type', 'nom', 'reglages']));
    }

    /** Enregistre (ou remplace, à nom égal) un profil d'import. */
    public function enregistrerProfil(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'type' => ['required', Rule::in(ProfilImport::TYPES)],
            'nom' => 'required|string|max:100',
            'reglages' => 'required|array',
            'reglages.fournisseur_id' => 'nullable|integer',
            'reglages.colonne_fournisseur' => 'nullable|integer|min:1|max:200',
            'reglages.colonne_code' => 'required|integer|min:1|max:200',
            'reglages.colonne_cout' => 'required|integer|min:1|max:200',
            'reglages.ligne_debut' => 'nullable|integer|min:1|max:1000',
        ]);
        $profil = ProfilImport::updateOrCreate(
            ['type' => $donnees['type'], 'nom' => trim($donnees['nom'])],
            ['reglages' => $donnees['reglages'], 'cree_par' => $request->user()?->id],
        );

        return response()->json($profil->only(['id', 'type', 'nom', 'reglages']), $profil->wasRecentlyCreated ? 201 : 200);
    }

    public function supprimerProfil(int $id): JsonResponse
    {
        ProfilImport::findOrFail($id)->delete();

        return response()->json(['message' => "Profil d'import supprimé."]);
    }
}
