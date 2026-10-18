<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AbonnementClient;
use App\Models\AbonnementEcheance;
use App\Models\AbonnementReleve;
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
            ->withCount(['echeances', 'releves', 'echeances as echeances_en_retard' => fn ($q) => $q->aFacturer()->whereDate('date_prevue', '<', today()->toDateString())])
            ->withMin(['echeances as prochaine_echeance' => fn ($q) => $q->aFacturer()], 'date_prevue')
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
        // Une fois une échéance facturée (ou close), le début et le client ne changent plus (le reste du calendrier, si).
        if ($abonnement->echeances()->traitee()->exists()) {
            if (! Carbon::parse($donnees['date_debut'])->isSameDay($abonnement->date_debut)) {
                throw ValidationException::withMessages(['date_debut' => __('La date de début ne peut pas être modifiée : des factures existent déjà pour cet abonnement.')]);
            }
            if ((int) $donnees['client_id'] !== (int) $abonnement->client_id) {
                throw ValidationException::withMessages(['client_id' => __('Le client ne peut pas changer : des factures existent déjà pour cet abonnement.')]);
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
            $abonnement->echeances()->aFacturer()->delete();
            $abonnement->delete();
        });

        return response()->json(['message' => __('Abonnement supprimé.')]);
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
        ], ['jusquau.before_or_equal' => __('La date limite ne peut pas dépasser un an.')]);
        $emettre = (bool) ($donnees['emettre'] ?? false);
        // Facturer crée des factures (et les émet si demandé) : ces droits-là aussi.
        foreach (array_filter(['creer' => true, 'emettre' => $emettre]) as $action => $requis) {
            if (! $droits->permet($request->user(), 'factures', $action)) {
                return response()->json(['message' => $droits->messageRefus('factures', $action), 'module' => 'factures', 'action' => $action], 403);
            }
        }
        $resultat = $this->abonnements->facturer(Carbon::parse($donnees['jusquau']), $donnees['client_id'] ?? null, $emettre, $request->user()?->id, $request->user()->tenant_id);

        return response()->json([
            'message' => $resultat['factures'] === [] && $resultat['erreurs'] === [] && $resultat['sans_facture'] === 0
                ? __('Aucune échéance à facturer pour cette date.')
                : __(':nombre facture(s) créée(s) pour :echeances échéance(s)', ['nombre' => count($resultat['factures']), 'echeances' => $resultat['echeances']])
                    .($resultat['sans_facture'] ? ', '.__(':nombre sans rien à facturer', ['nombre' => $resultat['sans_facture']]) : '')
                    .($resultat['erreurs'] ? ', '.__(':nombre erreur(s)', ['nombre' => count($resultat['erreurs'])]) : '').'.',
            'factures' => array_map(fn (Facture $f) => $f->fresh()->only(['id', 'numero', 'client_id', 'date_facture', 'statut', 'montant_ttc', 'devise']), $resultat['factures']),
            'echeances' => $resultat['echeances'],
            'sans_facture' => $resultat['sans_facture'],
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

    /** Relevés de consommation d'un abonnement à l'usage, les plus récents d'abord, avec leur état (facturé ou non). */
    public function releves(int $id): JsonResponse
    {
        $abonnement = AbonnementClient::findOrFail($id);

        return response()->json($abonnement->releves()->orderByDesc('date_releve')->orderByDesc('id')->get()
            ->map(fn (AbonnementReleve $r) => $r->toArray() + ['facture' => $this->abonnements->releveFacture($r)]));
    }

    public function ajouterReleve(Request $request, int $id): JsonResponse
    {
        $abonnement = AbonnementClient::findOrFail($id);
        if (! $abonnement->aLUsage()) {
            throw ValidationException::withMessages(['abonnement' => __("Cet abonnement n'est pas facturé à l'usage.")]);
        }
        $donnees = $request->validate([
            'date_releve' => ['required', 'date', 'before_or_equal:tomorrow'],
            'quantite' => ['required', 'integer', 'min:0', 'max:999999999'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $releve = new AbonnementReleve($donnees + ['abonnement_id' => $abonnement->id, 'cree_par' => $request->user()?->id]);
        if ($this->abonnements->releveFacture($releve)) {
            throw ValidationException::withMessages(['date_releve' => __('Cette période est déjà facturée : le relevé ne peut plus y entrer.')]);
        }
        $releve->save();

        return $this->releves($id)->setStatusCode(201);
    }

    public function supprimerReleve(int $id, int $releveId): JsonResponse
    {
        $releve = AbonnementReleve::where('abonnement_id', $id)->findOrFail($releveId);
        if ($this->abonnements->releveFacture($releve)) {
            throw ValidationException::withMessages(['releve' => __('Ce relevé est déjà facturé.')]);
        }
        $releve->delete();

        return $this->releves($id);
    }

    /** Import d'un fichier CSV de relevés (client, produit, date, quantité, note) : tout ou rien. */
    public function importerReleves(Request $request): JsonResponse
    {
        $request->validate(['fichier' => ['required', 'file', 'max:5120', 'mimes:csv,txt']], ['fichier.mimes' => __('Le fichier doit être un CSV.')]);
        $resultat = $this->abonnements->importerReleves((string) file_get_contents($request->file('fichier')->getRealPath()), $request->user()?->id);

        return response()->json($resultat + ['message' => $resultat['erreurs'] ? __('Aucun relevé importé.') : __(':nombre relevé(s) importé(s).', ['nombre' => $resultat['importes']])], $resultat['erreurs'] ? 422 : 200);
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
        // À l'usage, le montant dépend de la consommation : pas de total d'avance.
        $montant = $prix !== null && ! $abonnement->aLUsage() ? $prix * $abonnement->quantite : null;
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
     * Échéances : en retard d'abord (à facturer), puis à venir (la prochaine en tête), puis les traitées (facturées ou
     * sans rien à facturer), de la plus récente à la plus ancienne.
     */
    private function echeances(AbonnementClient $abonnement): array
    {
        $echeances = $abonnement->echeances()->with('facture:id,numero,statut,montant_ttc,devise,date_facture')->get();
        $aFacturer = $echeances->filter(fn (AbonnementEcheance $e) => ! $e->facture_id && ! $e->rien_a_facturer)->sortBy('date_prevue');
        $enRetard = $aFacturer->filter(fn ($e) => $e->date_prevue->lt(today()));
        $facturees = $echeances->diff($aFacturer)->sortByDesc('date_prevue');

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
            // fixe : la quantité de l'abonnement ; releve : la consommation relevée sur la période (au-delà de l'incluse).
            'mode_quantite' => ['nullable', Rule::in(['fixe', 'releve'])],
            'quantite' => ['required_unless:mode_quantite,releve', 'nullable', 'integer', 'min:1', 'max:999999'],
            'quantite_incluse' => ['nullable', 'integer', 'min:0', 'max:999999'],
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
            'jour_facturation.between' => __('Le jour de facturation va de 1 à 28 (le même jour tous les mois).'),
            'jour_debut_periode.between' => __('Le jour de début de période va de 1 à 28.'),
            'date_fin.after' => __('La date de fin doit être postérieure à la date de début.'),
            'prix_unitaire.required_if' => __('Indiquez le prix fixe.'),
        ]);
        if (Carbon::parse($donnees['date_fin'])->gt(Carbon::parse($donnees['date_debut'])->addYears(AbonnementsClients::DUREE_MAX_ANNEES))) {
            throw ValidationException::withMessages(['date_fin' => __('Un abonnement dure au plus :annees ans.', ['annees' => AbonnementsClients::DUREE_MAX_ANNEES])]);
        }
        $mensuel = $donnees['frequence'] === 'mensuelle';
        // Au-delà du mois : mois de facturation (par défaut celui du début) et mois de début de période.
        $moisFacturation = $mensuel ? null : ($donnees['mois_facturation'] ?? Carbon::parse($donnees['date_debut'])->month);

        $usage = ($donnees['mode_quantite'] ?? 'fixe') === 'releve';

        return [
            'client_id' => $donnees['client_id'],
            'produit_id' => $donnees['produit_id'],
            'description' => $donnees['description'] ?? null,
            'mode_quantite' => $usage ? 'releve' : 'fixe',
            'quantite' => $usage ? 1 : $donnees['quantite'],
            'quantite_incluse' => $usage ? ($donnees['quantite_incluse'] ?? 0) : 0,
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
