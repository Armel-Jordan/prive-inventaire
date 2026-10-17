<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Configuration;
use App\Models\ContratLocation;
use App\Models\ContratLocationLigne;
use App\Models\Facture;
use App\Services\AbonnementsClients;
use App\Services\ContratLocationPdf;
use App\Services\ContratsLocation;
use App\Support\Droits;
use App\Support\TenantRule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Contrats de location : liste, saisie (lignes facturées figées), facture des prix de contrat, facturation des loyers
 * dus, retours, renouvellement, impression.
 */
class ContratLocationController extends Controller
{
    public function __construct(private readonly ContratsLocation $contrats) {}

    public function index(Request $request): JsonResponse
    {
        $statut = $request->input('statut', 'actifs');
        $requete = ContratLocation::query()
            ->with(['client:id,raison_sociale,devise', 'type:id,code,nom', 'lignes'])
            ->when($statut === 'actifs', fn ($q) => $q->where('actif', true))
            ->when($statut === 'inactifs', fn ($q) => $q->where('actif', false))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            // Contrats qui finissent d'ici une date (renouvellements à préparer).
            ->when($request->filled('fin_avant'), fn ($q) => $q->whereDate('date_fin', '>=', today()->toDateString())
                ->whereDate('date_fin', '<=', Carbon::parse($request->input('fin_avant'))->toDateString()));
        if ($request->filled('recherche')) {
            $texte = '%'.addcslashes(trim((string) $request->input('recherche')), '%_\\').'%';
            $requete->where(fn ($q) => $q->where('numero', 'like', $texte)->orWhereHas('client', fn ($c) => $c->where('raison_sociale', 'like', $texte)));
        }
        $page = $requete->orderByDesc('date_debut')->orderByDesc('id')->paginate(min(100, max(1, $request->integer('per_page', 20))));
        $page->getCollection()->transform(fn (ContratLocation $c) => $this->resume($c));

        return response()->json($page);
    }

    public function show(int $id): JsonResponse
    {
        $contrat = ContratLocation::with([
            'client:id,raison_sociale,devise', 'type:id,code,nom', 'renouvelePar:id,numero', 'precedent:id,numero',
            'lignes.produit' => fn ($q) => $q->select('id', 'numero', 'description'), 'lignes.facture:id,numero,statut',
        ])->findOrFail($id);
        $factures = Facture::query()->where('contrat_location_id', $contrat->id)
            ->orderByDesc('date_facture')->orderByDesc('id')
            ->get(['id', 'numero', 'date_facture', 'statut', 'montant_ttc', 'devise']);

        return response()->json($this->resume($contrat) + ['factures' => $factures]);
    }

    public function store(Request $request): JsonResponse
    {
        $donnees = $this->valider($request);
        $tenantId = $request->user()->tenant_id;
        $config = Configuration::pourEntite('contrat_location', $tenantId);
        if (! $config || ! $config->auto_increment) {
            throw ValidationException::withMessages(['numero' => 'La numérotation automatique des contrats de location doit être active.']);
        }
        $contrat = DB::transaction(function () use ($donnees, $request, $tenantId) {
            $contrat = ContratLocation::create(array_diff_key($donnees, ['lignes' => 0]) + [
                'numero' => Configuration::consommerNumero('contrat_location', $tenantId),
                'cree_par' => $request->user()?->id,
            ]);
            foreach ($donnees['lignes'] as $ligne) {
                $contrat->lignes()->create(array_diff_key($ligne, ['id' => 0]));
            }
            if (! $contrat->prochaine_facturation) {
                $contrat->update(['prochaine_facturation' => $this->contrats->premiereFacturation($contrat)->toDateString()]);
            }

            return $contrat;
        });

        return $this->show($contrat->id)->setStatusCode(201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $contrat = ContratLocation::with('lignes')->findOrFail($id);
        $donnees = $this->valider($request);
        $facturees = $contrat->lignes->whereNotNull('facture_id')->keyBy('id');
        if (($facturees->isNotEmpty() || $contrat->factures()->exists()) && (int) $donnees['client_id'] !== (int) $contrat->client_id) {
            throw ValidationException::withMessages(['client_id' => 'Le client ne peut pas changer : ce contrat est déjà facturé.']);
        }
        // Les lignes facturées restent telles quelles ; on ajoute des lignes pour louer davantage.
        $recues = collect($donnees['lignes'])->keyBy(fn ($l, $i) => $l['id'] ?? "nouvelle-{$i}");
        foreach ($facturees as $ligneId => $ligne) {
            $saisie = $recues->get($ligneId);
            if (! $saisie) {
                throw ValidationException::withMessages(['lignes' => 'Une ligne facturée ne se retire pas.']);
            }
            foreach (['produit_id', 'quantite', 'quantite_exemptee', 'prix_contrat', 'prix_location'] as $champ) {
                if ((float) $saisie[$champ] !== (float) $ligne->{$champ}) {
                    throw ValidationException::withMessages(['lignes' => 'Une ligne facturée ne change plus : ajoutez une ligne pour louer davantage.']);
                }
            }
        }
        DB::transaction(function () use ($contrat, $donnees, $facturees, $recues) {
            $calendrier = fn (ContratLocation $c) => Carbon::parse($c->date_debut)->toDateString()."|{$c->frequence}|{$c->jour_facturation}";
            $avant = $calendrier($contrat);
            $contrat->update(array_diff_key($donnees, ['lignes' => 0, 'prochaine_facturation' => 0]));
            // Lignes pas encore facturées : remplacées par la saisie (une ligne inconnue devient une nouvelle ligne).
            $existantes = $contrat->lignes->whereNull('facture_id')->keyBy('id');
            $contrat->lignes()->whereNull('facture_id')->whereNotIn('id', $recues->keys()->filter(fn ($cle) => is_int($cle))->all())->delete();
            foreach ($recues as $cle => $saisie) {
                if ($facturees->has($cle)) {
                    continue;
                }
                $valeurs = array_diff_key($saisie, ['id' => 0]);
                $existantes->has($cle) ? $existantes->get($cle)->update($valeurs) : $contrat->lignes()->create($valeurs);
            }
            // Prochaine facturation : celle saisie ; sinon recalculée si le calendrier change avant le premier loyer.
            $contrat->refresh();
            if ($donnees['prochaine_facturation']) {
                $contrat->update(['prochaine_facturation' => $donnees['prochaine_facturation']]);
            } elseif (! $contrat->derniere_facturation && $avant !== $calendrier($contrat)) {
                $contrat->update(['prochaine_facturation' => $this->contrats->premiereFacturation($contrat)->toDateString()]);
            }
        });

        return $this->show($contrat->id);
    }

    /** Un contrat jamais facturé se supprime ; sinon on le désactive. */
    public function destroy(int $id): JsonResponse
    {
        $contrat = ContratLocation::findOrFail($id);
        if ($contrat->lignes()->whereNotNull('facture_id')->exists() || $contrat->factures()->exists()) {
            throw ValidationException::withMessages(['contrat' => 'Ce contrat a des factures : désactivez-le plutôt.']);
        }
        DB::transaction(function () use ($contrat) {
            $contrat->lignes()->delete();
            $contrat->delete();
        });

        return response()->json(['message' => 'Contrat supprimé.']);
    }

    /** Facture brouillon des prix de contrat pas encore facturés. */
    public function facturer(Request $request, int $id, Droits $droits): JsonResponse
    {
        if ($refus = $this->refusFactures($request, $droits, false)) {
            return $refus;
        }
        $facture = $this->contrats->facturerContrat(ContratLocation::findOrFail($id), $request->user()->tenant_id);

        return response()->json(['message' => "Facture {$facture->numero} créée.", 'facture' => $facture->only(['id', 'numero', 'statut', 'montant_ttc', 'devise'])], 201);
    }

    /** Factures des loyers dus jusqu'à une date, pour tous les clients ou un seul ; bilan. */
    public function facturerLoyers(Request $request, Droits $droits): JsonResponse
    {
        $donnees = $request->validate([
            'jusquau' => ['required', 'date', 'before_or_equal:'.today()->addYear()->toDateString()],
            'client_id' => ['nullable', 'integer', TenantRule::exists('clients')],
            'emettre' => ['boolean'],
        ], ['jusquau.before_or_equal' => 'La date limite ne peut pas dépasser un an.']);
        $emettre = (bool) ($donnees['emettre'] ?? false);
        if ($refus = $this->refusFactures($request, $droits, $emettre)) {
            return $refus;
        }
        $resultat = $this->contrats->facturerLoyers(Carbon::parse($donnees['jusquau']), $donnees['client_id'] ?? null, $emettre, $request->user()?->id, $request->user()->tenant_id);

        return response()->json([
            'message' => $resultat['factures'] === [] && $resultat['erreurs'] === []
                ? 'Aucun loyer à facturer pour cette date.'
                : count($resultat['factures']).' facture(s) de loyer créée(s)'.($resultat['erreurs'] ? ', '.count($resultat['erreurs']).' erreur(s)' : '').'.',
            'factures' => array_map(fn (Facture $f) => $f->fresh()->only(['id', 'numero', 'client_id', 'contrat_location_id', 'date_facture', 'statut', 'montant_ttc', 'devise']), $resultat['factures']),
            'erreurs' => $resultat['erreurs'],
        ]);
    }

    /** Retour d'unités louées (plus de loyer pour elles ensuite). */
    public function retour(Request $request, int $id, int $ligneId): JsonResponse
    {
        $ligne = ContratLocationLigne::where('contrat_id', $id)->findOrFail($ligneId);
        $donnees = $request->validate([
            'quantite' => ['required', 'integer', 'min:1'],
            'date_retour' => ['required', 'date', 'before_or_equal:tomorrow'],
        ]);
        $this->contrats->retour($ligne, $donnees['quantite'], $donnees['date_retour']);

        return $this->show($id);
    }

    /** Contrat suivant, aux mêmes prix (à revoir avant de le facturer). */
    public function renouveler(Request $request, int $id): JsonResponse
    {
        $suivant = $this->contrats->renouveler(ContratLocation::findOrFail($id), false, $request->user()?->id, $request->user()->tenant_id);

        return $this->show($suivant->id)->setStatusCode(201);
    }

    public function pdf(int $id, ContratLocationPdf $documents): Response
    {
        $contrat = ContratLocation::findOrFail($id);

        return $documents->pdf($contrat)->stream("contrat-location-{$contrat->numero}.pdf");
    }

    /** Créer des factures demande ce droit, et celui de les émettre si on les émet. */
    private function refusFactures(Request $request, Droits $droits, bool $emettre): ?JsonResponse
    {
        foreach (array_filter(['creer' => true, 'emettre' => $emettre]) as $action => $requis) {
            if (! $droits->permet($request->user(), 'factures', $action)) {
                return response()->json(['message' => $droits->messageRefus('factures', $action), 'module' => 'factures', 'action' => $action], 403);
            }
        }

        return null;
    }

    /** Contrat, statut et chiffres de la liste : loyer par période, produits à facturer, unités louées. */
    private function resume(ContratLocation $contrat): array
    {
        $contrat->loadMissing('lignes');

        return $contrat->toArray() + [
            'loyer_periode' => $this->contrats->loyer($contrat),
            'a_facturer' => $contrat->lignes->whereNull('facture_id')->filter(fn ($l) => (float) $l->prix_contrat > 0)->count(),
            'unites_louees' => $contrat->lignes->sum(fn ($l) => $l->quantite - $l->quantite_retournee),
            'echu' => Carbon::parse($contrat->date_fin)->lt(today()),
        ];
    }

    private function valider(Request $request): array
    {
        $donnees = $request->validate([
            'client_id' => ['required', 'integer', TenantRule::exists('clients')->whereNull('deleted_at')],
            'type_location_id' => ['nullable', 'integer', TenantRule::exists('types_location')->whereNull('deleted_at')],
            'date_debut' => ['required', 'date'],
            'date_fin' => ['required', 'date', 'after:date_debut'],
            'frequence' => ['required', Rule::in(array_keys(AbonnementsClients::FREQUENCES))],
            'jour_facturation' => ['required', 'integer', 'between:1,28'],
            'a_echoir' => ['boolean'],
            'prochaine_facturation' => ['nullable', 'date'],
            'actif' => ['boolean'],
            'renouvellement_auto' => ['boolean'],
            'taux_contrat' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'taux_location' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lignes' => ['required', 'array', 'min:1', 'max:200'],
            'lignes.*.id' => ['nullable', 'integer'],
            'lignes.*.produit_id' => ['required', 'integer', TenantRule::exists('produits')],
            'lignes.*.quantite' => ['required', 'integer', 'min:1', 'max:999999'],
            'lignes.*.quantite_exemptee' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'lignes.*.prix_contrat' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'lignes.*.prix_location' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ], [
            'date_fin.after' => 'La date de fin doit être postérieure à la date de début.',
            'jour_facturation.between' => 'Le jour de facturation va de 1 à 28 (le même jour tous les mois).',
            'lignes.required' => 'Ajoutez au moins un produit loué.',
        ]);
        foreach ($donnees['lignes'] as $i => $ligne) {
            if (($ligne['quantite_exemptee'] ?? 0) > $ligne['quantite']) {
                throw ValidationException::withMessages(["lignes.{$i}.quantite_exemptee" => 'Les unités exemptées ne dépassent pas la quantité louée.']);
            }
        }

        return [
            'client_id' => $donnees['client_id'],
            'type_location_id' => $donnees['type_location_id'] ?? null,
            'date_debut' => $donnees['date_debut'],
            'date_fin' => $donnees['date_fin'],
            'frequence' => $donnees['frequence'],
            'jour_facturation' => $donnees['jour_facturation'],
            'a_echoir' => (bool) ($donnees['a_echoir'] ?? true),
            'prochaine_facturation' => $donnees['prochaine_facturation'] ?? null,
            'actif' => (bool) ($donnees['actif'] ?? true),
            'renouvellement_auto' => (bool) ($donnees['renouvellement_auto'] ?? false),
            'taux_contrat' => $donnees['taux_contrat'] ?? 0,
            'taux_location' => $donnees['taux_location'] ?? 0,
            'notes' => $donnees['notes'] ?? null,
            'lignes' => array_map(fn (array $l) => [
                'id' => $l['id'] ?? null,
                'produit_id' => $l['produit_id'],
                'quantite' => $l['quantite'],
                'quantite_exemptee' => $l['quantite_exemptee'] ?? 0,
                'prix_contrat' => $l['prix_contrat'] ?? 0,
                'prix_location' => $l['prix_location'] ?? 0,
            ], $donnees['lignes']),
        ];
    }
}
