<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AbonnementClient;
use App\Models\AbonnementEcheance;
use App\Models\Client;
use App\Models\Facture;
use App\Services\AbonnementsClients;
use App\Support\Droits;
use App\Support\TenantRule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Abonnements clients : liste et échéances, saisie (le calendrier se recalcule), aperçu, facturation des échéances
 * dues, échéance reliée à une facture faite à la main.
 */
class AbonnementClientController extends Controller
{
    public function __construct(private readonly AbonnementsClients $abonnements) {}

    public function index(Request $request): JsonResponse
    {
        $statut = $request->input('statut', 'actif');
        // Client complet : son tarif (liste de prix, remise) donne le prix du jour.
        $requete = AbonnementClient::query()
            ->with(['client', 'produit:id,numero,description'])
            ->withCount(['echeances', 'echeances as echeances_en_retard' => fn ($q) => $q->whereNull('facture_id')->whereDate('date_prevue', '<', today()->toDateString())])
            ->withMin(['echeances as prochaine_echeance' => fn ($q) => $q->whereNull('facture_id')], 'date_prevue')
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('produit_id'), fn ($q) => $q->where('produit_id', $request->integer('produit_id')))
            ->when($statut === 'actif', fn ($q) => $q->whereDate('date_fin', '>=', today()->toDateString()))
            ->when($statut === 'bientot_echu', fn ($q) => $q->whereDate('date_fin', '>=', today()->toDateString())
                ->whereDate('date_fin', '<=', today()->addDays(AbonnementsClients::PREAVIS_JOURS)->toDateString()))
            ->when($statut === 'echu', fn ($q) => $q->whereDate('date_fin', '<', today()->toDateString()));
        // Recherche : nom du client, ou numéro d'une facture produite par une échéance.
        if ($request->filled('recherche')) {
            $texte = '%'.addcslashes(trim((string) $request->input('recherche')), '%_\\').'%';
            $requete->where(fn ($q) => $q->whereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $texte))
                ->orWhereHas('echeances.facture', fn ($f) => $f->where('numero', 'like', $texte)));
        }
        // Par client, puis dans l'ordre de saisie.
        $page = $requete->orderBy(Client::withTrashed()->select('raison_sociale')->whereColumn('clients.id', 'abonnements_clients.client_id'))
            ->orderBy('abonnements_clients.id')
            ->paginate(min(100, max(1, $request->integer('per_page', 20))));
        $page->getCollection()->transform(fn (AbonnementClient $a) => $this->ligne($a));

        return response()->json($page);
    }

    public function show(int $id): JsonResponse
    {
        $abonnement = AbonnementClient::with(['client', 'produit:id,numero,description'])->findOrFail($id);

        return response()->json($this->ligne($abonnement) + ['echeances' => $this->echeances($abonnement)]);
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $this->valider($request);
        $abonnement = DB::transaction(function () use ($donnees, $request) {
            $abonnement = AbonnementClient::create($donnees + ['cree_par' => $request->user()?->id]);
            $this->abonnements->planifier($abonnement);

            return $abonnement;
        });

        return $this->show($abonnement->id)->setStatusCode(201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $abonnement = AbonnementClient::findOrFail($id);
        $donnees = $this->valider($request);
        // Une fois une échéance facturée, le début et le client ne changent plus (le reste du calendrier, si).
        if ($abonnement->echeances()->whereNotNull('facture_id')->exists()) {
            if (! Carbon::parse($donnees['date_debut'])->isSameDay($abonnement->date_debut)) {
                throw ValidationException::withMessages(['date_debut' => 'La date de début ne peut pas être modifiée : des factures existent déjà pour cet abonnement.']);
            }
            if ((int) $donnees['client_id'] !== (int) $abonnement->client_id) {
                throw ValidationException::withMessages(['client_id' => 'Le client ne peut pas changer : des factures existent déjà pour cet abonnement.']);
            }
        }
        DB::transaction(function () use ($abonnement, $donnees) {
            $abonnement->update($donnees);
            $this->abonnements->planifier($abonnement);
        });

        return $this->show($abonnement->id);
    }

    /** Fin de l'abonnement : il quitte la liste, ses échéances à facturer aussi ; les factures émises restent. */
    public function destroy(int $id): JsonResponse
    {
        $abonnement = AbonnementClient::findOrFail($id);
        DB::transaction(function () use ($abonnement) {
            $abonnement->echeances()->whereNull('facture_id')->delete();
            $abonnement->delete();
        });

        return response()->json(['message' => 'Abonnement supprimé.']);
    }

    /** Aperçu avant d'enregistrer : première échéance, période couverte, description rendue, prix et nombre d'échéances. */
    public function apercu(Request $request): JsonResponse
    {
        $abonnement = new AbonnementClient($this->valider($request));
        $dates = $this->abonnements->dates($abonnement);
        if ($dates === []) {
            return response()->json(['premiere_echeance' => null, 'echeances' => 0, 'periode' => null, 'description' => null, 'prix' => null]);
        }
        [$debut, $fin] = $this->abonnements->periode($abonnement, $dates[0]);
        try {
            $prix = $this->abonnements->prixA($abonnement, $dates[0]);
        } catch (ValidationException) {
            $prix = null;
        }

        return response()->json([
            'premiere_echeance' => $dates[0]->toDateString(),
            'echeances' => count($dates),
            'periode' => [$debut->toDateString(), $fin->toDateString()],
            'description' => $this->abonnements->description($abonnement, $debut, $fin),
            'prix' => $prix,
        ]);
    }

    /** Facture les échéances dues jusqu'à une date, pour tous les clients ou un seul ; renvoie le bilan. */
    public function facturer(Request $request, Droits $droits): JsonResponse
    {
        $donnees = $request->validate([
            'jusquau' => ['required', 'date', 'before_or_equal:'.today()->addYear()->toDateString()],
            'client_id' => ['nullable', 'integer', TenantRule::exists('clients')],
            'emettre' => ['boolean'],
        ], ['jusquau.before_or_equal' => 'La date limite ne peut pas dépasser un an.']);
        $emettre = (bool) ($donnees['emettre'] ?? false);
        // Facturer crée des factures (et les émet si demandé) : ces droits-là aussi.
        foreach (array_filter(['creer' => true, 'emettre' => $emettre]) as $action => $requis) {
            if (! $droits->permet($request->user(), 'factures', $action)) {
                return response()->json(['message' => $droits->messageRefus('factures', $action), 'module' => 'factures', 'action' => $action], 403);
            }
        }
        $resultat = $this->abonnements->facturer(Carbon::parse($donnees['jusquau']), $donnees['client_id'] ?? null, $emettre, $request->user()?->id, $request->user()->tenant_id);

        return response()->json([
            'message' => $resultat['factures'] === [] && $resultat['erreurs'] === []
                ? 'Aucune échéance à facturer pour cette date.'
                : count($resultat['factures']).' facture(s) créée(s) pour '.$resultat['echeances'].' échéance(s)'
                    .($resultat['erreurs'] ? ', '.count($resultat['erreurs']).' erreur(s)' : '').'.',
            'factures' => array_map(fn (Facture $f) => $f->fresh()->only(['id', 'numero', 'client_id', 'date_facture', 'statut', 'montant_ttc', 'devise']), $resultat['factures']),
            'echeances' => $resultat['echeances'],
            'erreurs' => $resultat['erreurs'],
        ]);
    }

    /** Relie une échéance pas encore facturée à une facture faite à la main pour elle. */
    public function relier(Request $request, int $id, int $echeanceId): JsonResponse
    {
        $echeance = AbonnementEcheance::where('abonnement_id', $id)->findOrFail($echeanceId);
        $donnees = $request->validate(['facture_id' => ['required', 'integer', TenantRule::exists('factures')]]);
        $this->abonnements->relier($echeance, Facture::findOrFail($donnees['facture_id']));

        return $this->show($id);
    }

    /** Une ligne de la liste : l'abonnement, son statut, son prix du jour et ses totaux ramenés au mois et à l'année. */
    private function ligne(AbonnementClient $abonnement): array
    {
        $pas = AbonnementsClients::FREQUENCES[$abonnement->frequence];
        try {
            $prix = $this->abonnements->prixA($abonnement, today());
        } catch (ValidationException) {
            $prix = null;
        }
        $montant = $prix !== null ? $prix * $abonnement->quantite : null;
        $ligne = $abonnement->toArray();
        $ligne['client'] = $abonnement->client?->only(['id', 'raison_sociale', 'devise']);

        return $ligne + [
            'statut' => $this->abonnements->statut($abonnement),
            'prix_courant' => $prix,
            'total_mensuel' => $montant !== null ? round($montant / $pas, 2) : null,
            'total_annuel' => $montant !== null ? round($montant * 12 / $pas, 2) : null,
        ];
    }

    /**
     * Échéances : en retard d'abord (à facturer), puis à venir (la prochaine en tête), puis les facturées, de la plus
     * récente à la plus ancienne.
     */
    private function echeances(AbonnementClient $abonnement): array
    {
        $echeances = $abonnement->echeances()->with('facture:id,numero,statut,montant_ttc,devise,date_facture')->get();
        $aFacturer = $echeances->whereNull('facture_id')->sortBy('date_prevue');
        $enRetard = $aFacturer->filter(fn ($e) => $e->date_prevue->lt(today()));
        $facturees = $echeances->whereNotNull('facture_id')->sortByDesc('date_prevue');

        return $enRetard->concat($aFacturer->diff($enRetard))->concat($facturees)->map(fn (AbonnementEcheance $e) => [
            'id' => $e->id,
            'date_prevue' => $e->date_prevue->toDateString(),
            'periode_debut' => $e->periode_debut->toDateString(),
            'periode_fin' => $e->periode_fin->toDateString(),
            'statut' => $this->abonnements->statutEcheance($e),
            'facture' => $e->facture?->only(['id', 'numero', 'statut', 'montant_ttc', 'devise']),
        ])->values()->all();
    }

    private function valider(Request $request): array
    {
        $donnees = $request->validate([
            'client_id' => ['required', 'integer', TenantRule::exists('clients')->whereNull('deleted_at')],
            'produit_id' => ['required', 'integer', TenantRule::exists('produits')->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:500'],
            'quantite' => ['required', 'integer', 'min:1', 'max:999999'],
            'prix_fixe' => ['boolean'],
            'prix_unitaire' => ['nullable', 'required_if:prix_fixe,true', 'numeric', 'min:0', 'max:99999999'],
            'frequence' => ['required', Rule::in(array_keys(AbonnementsClients::FREQUENCES))],
            'jour_facturation' => ['required', 'integer', 'between:1,28'],
            'mois_facturation' => ['nullable', 'integer', 'between:1,12'],
            'jour_debut_periode' => ['nullable', 'integer', 'between:1,28'],
            'mois_debut_periode' => ['nullable', 'integer', 'between:1,12'],
            'periode_precedente' => ['boolean'],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after:date_debut'],
            'brouillon' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'jour_facturation.between' => 'Le jour de facturation va de 1 à 28 (le même jour tous les mois).',
            'jour_debut_periode.between' => 'Le jour de début de période va de 1 à 28.',
            'date_fin.after' => 'La date de fin doit être postérieure à la date de début.',
            'prix_unitaire.required_if' => 'Indiquez le prix fixe.',
        ]);
        if (Carbon::parse($donnees['date_fin'])->gt(Carbon::parse($donnees['date_debut'])->addYears(AbonnementsClients::DUREE_MAX_ANNEES))) {
            throw ValidationException::withMessages(['date_fin' => 'Un abonnement dure au plus '.AbonnementsClients::DUREE_MAX_ANNEES.' ans.']);
        }
        $mensuel = $donnees['frequence'] === 'mensuelle';
        // Au-delà du mois : mois de facturation (par défaut celui du début) et mois de début de période.
        $moisFacturation = $mensuel ? null : ($donnees['mois_facturation'] ?? Carbon::parse($donnees['date_debut'])->month);

        return [
            'client_id' => $donnees['client_id'],
            'produit_id' => $donnees['produit_id'],
            'description' => $donnees['description'] ?? null,
            'quantite' => $donnees['quantite'],
            'prix_fixe' => (bool) ($donnees['prix_fixe'] ?? false),
            'prix_unitaire' => ($donnees['prix_fixe'] ?? false) ? $donnees['prix_unitaire'] : null,
            'frequence' => $donnees['frequence'],
            'jour_facturation' => $donnees['jour_facturation'],
            'mois_facturation' => $moisFacturation,
            'jour_debut_periode' => $donnees['jour_debut_periode'] ?? 1,
            'mois_debut_periode' => $mensuel ? null : ($donnees['mois_debut_periode'] ?? $moisFacturation),
            'periode_precedente' => (bool) ($donnees['periode_precedente'] ?? false),
            'date_debut' => $donnees['date_debut'],
            'date_fin' => $donnees['date_fin'],
            'brouillon' => (bool) ($donnees['brouillon'] ?? false),
            'notes' => $donnees['notes'] ?? null,
        ];
    }
}
