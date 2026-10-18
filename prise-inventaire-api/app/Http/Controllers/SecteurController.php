<?php

namespace App\Http\Controllers;

use App\Models\Configuration;
use App\Models\Secteur;
use App\Support\TenantRule;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecteurController extends Controller
{
    /**
     * Secteurs actifs (listes de choix, application mobile) ; ?statut=inactifs ou ?statut=tous pour retrouver les
     * secteurs désactivés, qui disparaissaient de l'écran sans moyen de les réactiver.
     */
    public function index(Request $request): JsonResponse
    {
        $statut = $request->query('statut', 'actifs');
        $secteurs = Secteur::query()->with('entrepot:id,code,nom')
            ->when($statut !== 'tous', fn ($q) => $q->where('actif', $statut !== 'inactifs'))
            // Multi-entrepôts : les secteurs d'un entrepôt.
            ->when($request->integer('entrepot_id'), fn ($q, $entrepot) => $q->where('entrepot_id', $entrepot))
            ->where('tenant_id', auth()->user()->tenant_id)
            ->orderBy('code')
            ->get();

        return response()->json($secteurs);
    }

    /** Code déjà pris par un secteur désactivé : l'utilisateur est invité à le réactiver. */
    private function codeNonDesactive(?int $ignorer = null): Closure
    {
        return function (string $attribut, mixed $valeur, Closure $echec) use ($ignorer) {
            $desactive = Secteur::where('code', $valeur)->where('actif', false)
                ->when($ignorer, fn ($q) => $q->whereKeyNot($ignorer))->first();
            if ($desactive) {
                $echec(__('Le code « :code » appartient au secteur désactivé « :nom » : réactivez-le (filtre « Désactivés » de la liste des secteurs).', ['code' => $valeur, 'nom' => $desactive->nom]));
            }
        };
    }

    /** Entrepôt du référentiel ; celui du secteur reste accepté s'il a été retiré depuis (le secteur se réenregistre). */
    private function regleEntrepot(?Secteur $secteur = null): array
    {
        return ['nullable', 'integer', TenantRule::exists('entrepots')->where(fn ($q) => $q->whereNull('deleted_at')
            ->when($secteur?->entrepot_id, fn ($q, $actuel) => $q->orWhere('id', $actuel)))];
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['nullable', 'string', 'max:10', $this->codeNonDesactive(), TenantRule::unique('secteurs', 'code')],
            'nom' => 'required|string|max:100',
            'description' => 'nullable|string',
            'entrepot_id' => $this->regleEntrepot(),
        ]);

        // Générer le code automatiquement si non fourni
        $code = $request->code;
        if (empty($code)) {
            $config = Configuration::pourEntite('secteur', $request->attributes->get('tenant')->id);
            if ($config && $config->auto_increment) {
                $code = $config->genererNumero();
                $config->incrementer();
            } else {
                return response()->json([
                    'success' => false,
                    'message' => __('Le code est requis (pas de configuration auto-increment)'),
                ], 422);
            }
        }

        $secteur = Secteur::create([
            'tenant_id' => auth()->user()->tenant_id,
            'code' => strtoupper($code),
            'nom' => $request->nom,
            'description' => $request->description,
            'entrepot_id' => $request->input('entrepot_id'),
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Secteur créé avec succès'),
            'secteur' => $secteur->load('entrepot:id,code,nom'),
        ], 201);
    }

    public function show($id): JsonResponse
    {
        $secteur = Secteur::findOrFail($id);

        return response()->json($secteur);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $secteur = Secteur::findOrFail($id);

        $request->validate([
            // Mêmes règles qu'à la création : un code « ST1 » ou « SEC-001 » (numérotation automatique) était
            // accepté à la création puis refusé à chaque modification du secteur.
            'code' => ['sometimes', 'string', 'max:10', TenantRule::unique('secteurs', 'code', $id)],
            'nom' => 'sometimes|string|max:100',
            'description' => 'nullable|string',
            'actif' => 'sometimes|boolean',
            'entrepot_id' => $this->regleEntrepot($secteur),
        ]);

        if ($request->has('code')) {
            $secteur->code = strtoupper($request->code);
        }
        if ($request->has('nom')) {
            $secteur->nom = $request->nom;
        }
        if ($request->has('description')) {
            $secteur->description = $request->description;
        }
        if ($request->has('actif')) {
            $secteur->actif = $request->actif;
        }
        if ($request->has('entrepot_id')) {
            $secteur->entrepot_id = $request->input('entrepot_id');
        }

        $secteur->save();

        return response()->json([
            'success' => true,
            'message' => __('Secteur modifié avec succès'),
            'secteur' => $secteur->load('entrepot:id,code,nom'),
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $secteur = Secteur::findOrFail($id);
        $secteur->actif = false;
        $secteur->save();

        return response()->json([
            'success' => true,
            'message' => __('Secteur désactivé avec succès'),
        ]);
    }

    /**
     * Valider un QR code secteur
     */
    public function validateQrCode(Request $request): JsonResponse
    {
        $request->validate([
            'qr_code' => 'required|string|max:500',
        ]);

        $secteur = $this->secteurDuQr(trim($request->qr_code));

        if (! $secteur) {
            return response()->json([
                'valide' => false,
                'message' => __('QR code secteur non reconnu'),
            ], 404);
        }

        return response()->json([
            'valide' => true,
            'secteur' => $secteur,
        ]);
    }

    /**
     * Secteur actif désigné par le contenu d'un QR code, quelle qu'en soit la forme : code du secteur (étiquettes
     * imprimées par l'écran Secteurs), JSON {"code", "nom", "id"} des anciennes étiquettes, QR attribué
     * (« SECT-A1-… »), ou nom du secteur.
     */
    private function secteurDuQr(string $contenu): ?Secteur
    {
        $actifs = fn () => Secteur::query()->where('actif', true);

        $json = json_decode($contenu, true);
        if (is_array($json) && filled($json['code'] ?? null)) {
            $contenu = (string) $json['code'];
        }

        return $actifs()->where('qr_code', $contenu)->first()
            ?? $actifs()->whereRaw('LOWER(code) = ?', [mb_strtolower($contenu)])->first()
            ?? $actifs()->whereRaw('LOWER(nom) = ?', [mb_strtolower($contenu)])->first();
    }

    /**
     * Générer un QR code pour un secteur
     */
    public function generateQrCode($id): JsonResponse
    {
        $secteur = Secteur::findOrFail($id);

        // Générer un code unique basé sur le code secteur
        $qrCode = 'SECT-'.strtoupper($secteur->code).'-'.substr(md5($secteur->id.time()), 0, 8);

        $secteur->qr_code = $qrCode;
        $secteur->save();

        return response()->json([
            'success' => true,
            'qr_code' => $qrCode,
            'secteur' => $secteur,
        ]);
    }

    /**
     * Mettre à jour le QR code d'un secteur
     */
    public function updateQrCode(Request $request, $id): JsonResponse
    {
        $secteur = Secteur::findOrFail($id);

        $request->validate([
            'qr_code' => ['required', 'string', 'max:100', TenantRule::unique('secteurs', 'qr_code', $id)],
        ]);

        $secteur->qr_code = $request->qr_code;
        $secteur->save();

        return response()->json([
            'success' => true,
            'message' => __('QR code mis à jour'),
            'secteur' => $secteur,
        ]);
    }
}
