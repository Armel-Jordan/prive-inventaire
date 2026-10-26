<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\EnvoyerCourriel;
use App\Models\ComFourEntete;
use App\Models\CourrielEnvoi;
use App\Services\Courriels\EnvoiCourriel;
use App\Services\Portail\AccesPortail;
use App\Support\Courriels;
use App\Support\Droits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Envoi des documents par courriel, file d'envoi et historique. Préparer, envoyer, relancer ou consulter l'envoi
 * d'un document demande le droit de voir ce document (Courriels::DROITS) ; l'historique de toute l'entreprise
 * demande le droit de voir la configuration (écran Courriels envoyés).
 */
class CourrielEnvoiController extends Controller
{
    public function __construct(private readonly EnvoiCourriel $envois) {}

    /** Pré-remplissage de la fenêtre de rédaction pour un document. */
    public function preparer(Request $request, Droits $droits): JsonResponse
    {
        $donnees = $request->validate([
            'type' => ['required', Rule::in(array_keys(Courriels::DOCUMENTS))],
            'document_id' => ['required', 'integer'],
            'langue' => ['nullable', Rule::in(Courriels::LANGUES)],
            // Adresse du site de l'espace client connue du back-office : celle du lien de réponse d'un devis.
            'origine' => ['nullable', 'string', 'max:255'],
        ]);
        $this->verifierModule($request, $donnees['type']);
        $this->exigerVoir($request, $droits, $donnees['type']);
        $document = $this->envois->document($donnees['type'], (int) $donnees['document_id']);
        if ($donnees['type'] === 'devis' && filled($donnees['origine'] ?? null)) {
            app(AccesPortail::class)->memoriserAdresse($donnees['origine']);
        }

        return response()->json($this->envois->preparer($donnees['type'], $document, $donnees['langue'] ?? 'fr'));
    }

    /** Met le courriel en file puis l'envoie (tout de suite avec la file « sync »). */
    public function store(Request $request, Droits $droits): JsonResponse
    {
        $donnees = $request->validate([
            'type' => ['required', Rule::in(array_keys(Courriels::DOCUMENTS))],
            'document_id' => ['required', 'integer'],
            'langue' => ['required', Rule::in(Courriels::LANGUES)],
            'destinataires' => ['required', 'array', 'min:1', 'max:20'],
            'destinataires.*' => ['required', 'email', 'max:255'],
            'copies' => ['nullable', 'array', 'max:20'],
            'copies.*' => ['required', 'email', 'max:255'],
            'copies_cachees' => ['nullable', 'array', 'max:20'],
            'copies_cachees.*' => ['required', 'email', 'max:255'],
            'objet' => ['required', 'string', 'max:255'],
            'contenu' => ['required', 'string', 'max:20000'],
            'joindre_pdf' => ['boolean'],
        ]);
        $this->verifierModule($request, $donnees['type']);
        $this->exigerVoir($request, $droits, $donnees['type']);
        $document = $this->envois->document($donnees['type'], (int) $donnees['document_id']);
        // Le bon de commande au brouillon est validé par son envoi : il y faut le droit de valider la commande.
        if ($donnees['type'] === 'commande_fournisseur' && $document->getAttribute('statut') === ComFourEntete::STATUT_BROUILLON
            && ! $droits->permet($request->user(), 'commandes_fournisseur', 'valider')) {
            return response()->json([
                'message' => $droits->messageRefus('commandes_fournisseur', 'valider'), 'module' => 'commandes_fournisseur', 'action' => 'valider',
            ], 403);
        }

        $envoi = $this->envois->creer($donnees['type'], $document, $donnees, $request->user()?->id);
        EnvoyerCourriel::dispatch($envoi->id);

        return response()->json($this->presenter($envoi->refresh()->load('auteur:id,nom')), 201);
    }

    /**
     * Historique, du plus récent au plus ancien ; filtrable par document, par rapport joint et par statut. Les envois
     * d'un document : le droit de le voir ; ceux d'un rapport : le droit de voir les rapports ; sinon, l'historique
     * de toute l'entreprise : le droit de voir la configuration.
     */
    public function index(Request $request, Droits $droits): JsonResponse
    {
        $filtres = $request->validate([
            'type' => ['nullable', Rule::in(Courriels::types())],
            'document_id' => ['nullable', 'integer'],
            // Code d'un rapport de la bibliothèque : ses envois.
            'rapport' => ['nullable', 'string', 'max:60'],
            'statut' => ['nullable', Rule::in(CourrielEnvoi::STATUTS)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        if (isset($filtres['type'], $filtres['document_id']) && isset(Courriels::DROITS[$filtres['type']])) {
            $this->exigerVoir($request, $droits, $filtres['type']);
        } elseif (isset($filtres['rapport'])) {
            $droits->exiger($request->user(), 'rapports', 'voir');
        } else {
            $droits->exiger($request->user(), 'configuration', 'voir');
        }

        $envois = CourrielEnvoi::with('auteur:id,nom')
            ->when($filtres['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filtres['document_id'] ?? null, fn ($q, $id) => $q->where('document_id', $id))
            ->when($filtres['rapport'] ?? null, fn ($q, $code) => $q->where('rapport->code', $code))
            ->when($filtres['statut'] ?? null, fn ($q, $statut) => $q->where('statut', $statut))
            ->latest('id')
            ->paginate($filtres['per_page'] ?? 20);

        return response()->json($envois->through(fn (CourrielEnvoi $envoi) => $this->presenter($envoi)));
    }

    /** Un envoi : le droit de voir la configuration (tout l'historique), sinon celui de voir son document. */
    public function show(Request $request, Droits $droits, int $id): JsonResponse
    {
        $envoi = CourrielEnvoi::with('auteur:id,nom')->findOrFail($id);
        if (! $droits->permet($request->user(), 'configuration', 'voir')) {
            $this->exigerVoir($request, $droits, $envoi->type);
        }

        return response()->json($this->presenter($envoi) + [
            'corps_html' => $envoi->corps_html,
            'corps_texte' => $envoi->corps_texte,
        ]);
    }

    /** Relance manuelle d'un courriel en échec. */
    public function reessayer(Request $request, Droits $droits, int $id): JsonResponse
    {
        $envoi = CourrielEnvoi::findOrFail($id);
        if ($envoi->statut !== CourrielEnvoi::ECHEC) {
            return response()->json(['message' => __('Seul un courriel en échec peut être renvoyé.')], 422);
        }
        $this->verifierModule($request, $envoi->type);
        $this->exigerVoir($request, $droits, $envoi->type);

        EnvoyerCourriel::dispatch($envoi->id);

        return response()->json($this->presenter($envoi->refresh()->load('auteur:id,nom')));
    }

    private function presenter(CourrielEnvoi $envoi): array
    {
        return $envoi->resume();
    }

    /** Droit de voir le document de ce type (ou les rapports) ; un type sans document : celui de voir la configuration. */
    private function exigerVoir(Request $request, Droits $droits, string $type): void
    {
        $droits->exiger($request->user(), Courriels::DROITS[$type] ?? 'configuration', 'voir');
    }

    /** Le document dépend d'un module (ventes, achats) que l'entreprise doit avoir activé. */
    private function verifierModule(Request $request, string $type): void
    {
        $module = Courriels::MODULES[$type] ?? null;
        $tenant = $request->attributes->get('tenant');

        abort_if($module && $tenant && ! $tenant->hasModule($module), 403, __("Le module « :module » n'est pas activé pour cette entreprise.", ['module' => $module]));
    }
}
