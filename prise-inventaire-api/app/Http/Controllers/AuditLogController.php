<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BonLivraison;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ComClientEntete;
use App\Models\ComFourEntete;
use App\Models\ConditionPaiement;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\Fournisseur;
use App\Models\GroupeTaxe;
use App\Models\ProduitTenant;
use App\Models\Representant;
use App\Models\StatutPersonnalise;
use App\Models\Transporteur;
use App\Observers\JournalModifications;
use App\Support\Droits;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /** Fiches et documents qui ont un bouton « Historique » (socle S4.6) : clé => [modèle journalisé, module à voir]. */
    private const ENTITES = [
        'client' => [Client::class, 'clients'],
        'fournisseur' => [Fournisseur::class, 'fournisseurs'],
        'produit' => [ProduitTenant::class, 'produits'],
        'devis' => [Devis::class, 'devis'],
        'commande_client' => [ComClientEntete::class, 'commandes_client'],
        'facture' => [Facture::class, 'factures'],
        'bon_livraison' => [BonLivraison::class, 'bons_livraison'],
        'commande_fournisseur' => [ComFourEntete::class, 'commandes_fournisseur'],
    ];

    /** Champs qui désignent une autre fiche : leur valeur s'affiche par son nom. */
    private const REFERENCES = [
        'client_id' => [Client::class, 'raison_sociale'],
        'fournisseur_id' => [Fournisseur::class, 'raison_sociale'],
        'representant_id' => [Representant::class, 'nom'],
        'transporteur_id' => [Transporteur::class, 'nom'],
        'condition_paiement_id' => [ConditionPaiement::class, 'libelle'],
        'groupe_taxe_id' => [GroupeTaxe::class, 'nom'],
        'contact_id' => [ClientContact::class, 'nom'],
        'statut_personnalise_id' => [StatutPersonnalise::class, 'libelle'],
    ];

    /**
     * Liste des logs d'audit avec filtres — isolés par tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $query = AuditLog::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('model_type')) {
            $query->where('model_type', $request->model_type);
        }

        if ($request->filled('model_id')) {
            $query->where('model_id', $request->model_id);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('date_debut')) {
            $query->whereDate('created_at', '>=', $request->date_debut);
        }

        if ($request->filled('date_fin')) {
            $query->whereDate('created_at', '<=', $request->date_fin);
        }

        $limit = min($request->integer('limit', 100), 500);
        $logs = $query->limit($limit)->get();

        return response()->json($logs);
    }

    /**
     * Historique d'un élément spécifique — isolé par tenant.
     */
    public function history(string $modelType, string $modelId): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;

        $logs = AuditLog::where('tenant_id', $tenantId)
            ->where('model_type', $modelType)
            ->where('model_id', $modelId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json($logs);
    }

    /**
     * Historique d'une fiche ou d'un document (socle S4.6, bouton « Historique ») : ses créations, modifications et
     * suppressions, les plus récentes d'abord, avec pour chaque modification les champs changés (libellé, avant,
     * après). Il faut le droit de voir l'historique (route) et celui de voir la fiche ou le document.
     */
    public function entite(Request $request, Droits $droits, string $entite, int $id): JsonResponse
    {
        [$modele, $module] = self::ENTITES[$entite] ?? abort(404);
        $droits->exiger($request->user(), $module, 'voir');
        $libelle = JournalModifications::MODELES[$modele][0];

        $logs = AuditLog::where('tenant_id', $request->user()->tenant_id)
            ->where('model_type', $libelle)
            ->where('model_id', (string) $id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(200)
            ->get();
        $noms = $this->noms($logs);

        return response()->json($logs->map(fn (AuditLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'date' => $log->created_at?->toIso8601String(),
            'auteur' => $log->user_name,
            'champs' => $log->action === 'update' ? $this->champs($log, $noms) : [],
        ])->values());
    }

    /**
     * Champs changés d'une modification : libellé lisible (celui des messages de validation, sinon le nom du champ),
     * valeurs avant et après, et leur nom quand le champ désigne une autre fiche.
     *
     * @param  array<string, array<int, string>>  $noms
     * @return list<array{champ: string, libelle: string, avant: mixed, apres: mixed, avant_texte: ?string, apres_texte: ?string}>
     */
    private function champs(AuditLog $log, array $noms): array
    {
        $avant = (array) ($log->old_values ?? []);
        $apres = (array) ($log->new_values ?? []);
        $champs = [];
        foreach (array_unique([...array_keys($apres), ...array_keys($avant)]) as $champ) {
            $cle = "validation.attributes.{$champ}";
            $traduit = __($cle);
            $champs[] = [
                'champ' => $champ,
                'libelle' => $traduit === $cle ? str_replace('_', ' ', $champ) : $traduit,
                'avant' => $avant[$champ] ?? null,
                'apres' => $apres[$champ] ?? null,
                'avant_texte' => isset($avant[$champ]) ? ($noms[$champ][(int) $avant[$champ]] ?? null) : null,
                'apres_texte' => isset($apres[$champ]) ? ($noms[$champ][(int) $apres[$champ]] ?? null) : null,
            ];
        }

        return $champs;
    }

    /**
     * Noms des fiches désignées par les champs de référence de ces journaux, en une requête par champ.
     *
     * @return array<string, array<int, string>>
     */
    private function noms($logs): array
    {
        $ids = [];
        foreach ($logs as $log) {
            foreach ([(array) ($log->old_values ?? []), (array) ($log->new_values ?? [])] as $valeurs) {
                foreach (array_intersect_key($valeurs, self::REFERENCES) as $champ => $valeur) {
                    if (is_numeric($valeur)) {
                        $ids[$champ][] = (int) $valeur;
                    }
                }
            }
        }
        $noms = [];
        foreach ($ids as $champ => $liste) {
            [$modele, $colonne] = self::REFERENCES[$champ];
            $requete = $modele::query();
            if (in_array(SoftDeletes::class, class_uses_recursive($modele), true)) {
                $requete->withTrashed();
            }
            $noms[$champ] = $requete->whereIn('id', array_unique($liste))->pluck($colonne, 'id')->map(fn ($nom) => (string) $nom)->all();
        }

        return $noms;
    }

    /**
     * Statistiques des audits — isolées par tenant.
     */
    public function stats(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $today = now()->startOfDay();
        $thisWeek = now()->startOfWeek();
        $thisMonth = now()->startOfMonth();

        $base = fn () => AuditLog::where('tenant_id', $tenantId);

        return response()->json([
            'today' => $base()->where('created_at', '>=', $today)->count(),
            'this_week' => $base()->where('created_at', '>=', $thisWeek)->count(),
            'this_month' => $base()->where('created_at', '>=', $thisMonth)->count(),
            'by_action' => [
                'create' => $base()->where('action', 'create')->where('created_at', '>=', $thisMonth)->count(),
                'update' => $base()->where('action', 'update')->where('created_at', '>=', $thisMonth)->count(),
                'delete' => $base()->where('action', 'delete')->where('created_at', '>=', $thisMonth)->count(),
            ],
            'by_model' => $base()->where('created_at', '>=', $thisMonth)
                ->selectRaw('model_type, count(*) as count')
                ->groupBy('model_type')
                ->pluck('count', 'model_type'),
        ]);
    }
}
