<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfigurationTacheIgnoree;
use App\Support\ConfigurationACompleter;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuration à compléter : étapes de mise en route vérifiées dans les données de l'entreprise,
 * réglages recommandés écartés avec une raison, compteurs de qualité des données.
 */
class ConfigurationACompleterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $actif = fn (?string $module) => ! $module || ! $tenant || $tenant->hasModule($module);
        $ignorees = ConfigurationTacheIgnoree::with('auteur:id,nom')->get()->keyBy('cle');
        $restantes = ['obligatoires' => 0, 'recommandees' => 0];

        $etapes = [];
        foreach (ConfigurationACompleter::etapes((int) app(TenantContext::class)->getTenantId()) as $cleEtape => $taches) {
            $lignes = [];
            foreach ($taches as $cle => $tache) {
                if (! $actif($tache['module'])) {
                    continue;
                }
                $faite = (bool) ($tache['faite'])();
                $ignoree = $tache['obligatoire'] ? null : $ignorees->get($cle);
                if (! $faite && ! $ignoree) {
                    $restantes[$tache['obligatoire'] ? 'obligatoires' : 'recommandees']++;
                }
                $lignes[] = [
                    'cle' => $cle,
                    'obligatoire' => $tache['obligatoire'],
                    'faite' => $faite,
                    'lien' => $tache['lien'],
                    'ignoree' => $ignoree ? [
                        'raison' => $ignoree->raison,
                        'par' => $ignoree->auteur?->nom,
                        'le' => $ignoree->updated_at?->toIso8601String(),
                    ] : null,
                ];
            }
            if ($lignes) {
                $etapes[] = ['cle' => $cleEtape, 'taches' => $lignes];
            }
        }

        $qualite = [];
        foreach (ConfigurationACompleter::qualite() as $cle => $compteur) {
            $nombre = $actif($compteur['module']) ? (int) ($compteur['nombre'])() : 0;
            if ($nombre > 0) {
                $qualite[] = ['cle' => $cle, 'nombre' => $nombre, 'lien' => $compteur['lien']];
            }
        }

        return response()->json(['etapes' => $etapes, 'qualite' => $qualite, 'restantes' => $restantes]);
    }

    /** Écarte un réglage recommandé qui ne concerne pas l'entreprise ; la raison est obligatoire. */
    public function ignorer(Request $request, string $cle): JsonResponse
    {
        $tache = $this->tache($cle);
        if ($tache['obligatoire']) {
            return response()->json(['message' => 'Un réglage obligatoire ne peut pas être ignoré.'], 422);
        }

        $raison = $request->validate(
            ['raison' => ['required', 'string', 'max:255']],
            ['raison.required' => 'Indiquez pourquoi ce réglage ne vous concerne pas.'],
        )['raison'];
        ConfigurationTacheIgnoree::updateOrCreate(['cle' => $cle], ['raison' => trim($raison), 'ignore_par' => $request->user()?->id]);

        return $this->index($request);
    }

    public function reprendre(Request $request, string $cle): JsonResponse
    {
        $this->tache($cle);
        ConfigurationTacheIgnoree::where('cle', $cle)->delete();

        return $this->index($request);
    }

    private function tache(string $cle): array
    {
        foreach (ConfigurationACompleter::etapes((int) app(TenantContext::class)->getTenantId()) as $taches) {
            if (isset($taches[$cle])) {
                return $taches[$cle];
            }
        }
        abort(404, 'Réglage inconnu.');
    }
}
