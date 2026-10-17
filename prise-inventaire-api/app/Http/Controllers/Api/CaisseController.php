<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caisse;
use App\Models\CaisseSession;
use App\Services\Caisses;
use App\Services\SommaireCaissePdf;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gestion de caisse : caisses et leur session en cours, ouverture avec le fond, entrées et sorties d'argent,
 * fermeture (tiroir compté), historique des sessions et sommaire imprimable. Les règles sont dans App\Services\Caisses.
 */
class CaisseController extends Controller
{
    private const DEVISE = ['nullable', 'string', 'regex:/^[A-Za-z]{3}$/'];

    public function __construct(private readonly Caisses $caisses) {}

    /** Caisses actives (et celles encore ouvertes), avec leur session en cours et son attendu. */
    public function index(): JsonResponse
    {
        $caisses = Caisse::with('sessionOuverte.ouvreur:id,nom')->orderBy('code')->get()
            ->filter(fn (Caisse $caisse) => $caisse->actif || $caisse->sessionOuverte)
            ->map(function (Caisse $caisse) {
                $session = $caisse->sessionOuverte;

                return $caisse->only(['id', 'code', 'nom', 'actif']) + ['session' => $session ? [
                    'id' => $session->id, 'ouverte_le' => $session->ouverte_le, 'ouvreur' => $session->ouvreur?->only(['id', 'nom']),
                    'totaux' => $this->caisses->totaux($this->caisses->chiffres($session)),
                ] : null];
            });

        return response()->json($caisses->values());
    }

    public function ouvrir(Request $request, int $id): JsonResponse
    {
        $caisse = Caisse::findOrFail($id);
        $donnees = $request->validate([
            'fond' => 'nullable|numeric|min:0|max:9999999999',
            'mode' => ['nullable', Rule::in(Caisses::MODES)],
            'devise' => self::DEVISE,
            'note' => 'nullable|string|max:255',
        ]);

        return $this->executer(fn () => response()->json($this->caisses->sommaire($this->caisses->ouvrir(
            $caisse, (float) ($donnees['fond'] ?? 0), $donnees['mode'] ?? 'especes', $donnees['devise'] ?? null, $donnees['note'] ?? null, $request->user()?->id,
        )), 201));
    }

    /** Sessions d'une période (7 derniers jours par défaut) et celles encore ouvertes. */
    public function sessions(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'caisse_id' => 'nullable|integer',
            'du' => 'nullable|date_format:Y-m-d',
            'au' => 'nullable|date_format:Y-m-d|after_or_equal:du',
            'fuseau' => 'nullable|string|max:64',
        ], ['au.after_or_equal' => 'La fin de la période précède son début.']);
        $fuseau = $this->fuseau($request);
        $au = $donnees['au'] ?? now($fuseau)->toDateString();
        $du = $donnees['du'] ?? now($fuseau)->subDays(6)->toDateString();

        return response()->json(['du' => $du, 'au' => $au, 'sessions' => $this->caisses->liste($donnees['caisse_id'] ?? null, $du, $au, $fuseau)]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json($this->caisses->sommaire(CaisseSession::findOrFail($id)));
    }

    /** Entrée ou sortie d'argent (la raison d'une sortie est obligatoire). */
    public function mouvement(Request $request, int $id): JsonResponse
    {
        $session = CaisseSession::findOrFail($id);
        $donnees = $request->validate([
            'type' => ['required', Rule::in(['entree', 'sortie'])],
            'mode' => ['required', Rule::in(Caisses::MODES)],
            'devise' => self::DEVISE,
            'montant' => 'required|numeric|min:0.01|max:9999999999',
            'note' => 'nullable|required_if:type,sortie|string|max:255',
        ], ['note.required_if' => "Indiquez la raison de la sortie d'argent."]);

        return $this->executer(function () use ($session, $donnees, $request) {
            $this->caisses->mouvement($session, $donnees['type'], $donnees['mode'], $donnees['devise'] ?? null, (float) $donnees['montant'],
                $donnees['note'] ?? null, $request->user()?->id);

            return response()->json($this->caisses->sommaire($session->fresh()), 201);
        });
    }

    /** Ferme la caisse : montants comptés par mode (et devise), note de fermeture. */
    public function fermer(Request $request, int $id): JsonResponse
    {
        $session = CaisseSession::findOrFail($id);
        $donnees = $request->validate([
            'comptes' => 'present|array|max:100',
            'comptes.*.mode' => ['required', Rule::in(Caisses::MODES)],
            'comptes.*.devise' => self::DEVISE,
            'comptes.*.montant' => 'required|numeric|min:0|max:9999999999',
            'note' => 'nullable|string|max:1000',
        ]);

        return $this->executer(fn () => response()->json($this->caisses->sommaire(
            $this->caisses->fermer($session, $donnees['comptes'], $donnees['note'] ?? null, $request->user()?->id),
        )));
    }

    /** Sommaire de caisse d'une session fermée (chiffres figés à la fermeture). */
    public function pdf(Request $request, int $id, SommaireCaissePdf $documents): Response
    {
        $session = CaisseSession::findOrFail($id);
        if ($session->estOuverte()) {
            return response()->json(['message' => "Fermez la caisse avant d'imprimer son sommaire."], 422);
        }

        return $documents->sommaire($session, $this->fuseau($request))->stream('sommaire-caisse-'.($session->caisse?->code ?? $session->caisse_id).'-'.$session->ouverte_le->format('Y-m-d').'.pdf');
    }

    /** Fuseau de l'utilisateur transmis par l'écran (« Europe/Paris »), sinon celui de l'application. */
    private function fuseau(Request $request): string
    {
        $fuseau = (string) $request->input('fuseau', '');

        return in_array($fuseau, timezone_identifiers_list(), true) ? $fuseau : config('app.timezone');
    }

    /** Erreurs de règle (état de la caisse ou de la session) : 422 avec leur message. */
    private function executer(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
