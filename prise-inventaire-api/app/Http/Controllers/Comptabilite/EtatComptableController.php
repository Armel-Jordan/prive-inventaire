<?php

namespace App\Http\Controllers\Comptabilite;

use App\Http\Controllers\Controller;
use App\Models\CompteComptable;
use App\Models\EcritureComptable;
use App\Models\ExerciceComptable;
use App\Services\Comptabilite\Ecritures;
use App\Services\Comptabilite\EtatsFinanciers;
use App\Services\Comptabilite\Exercices;
use App\Services\Comptabilite\Soldes;
use App\Support\FichierCsv;
use App\Support\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * États tirés du journal : balance des comptes, grand livre d'un compte (écran ou CSV), et saisie des soldes
 * d'ouverture du premier exercice.
 */
class EtatComptableController extends Controller
{
    public function __construct(private Soldes $soldes, private Ecritures $ecritures, private Exercices $exercices) {}

    public function balance(Request $request): JsonResponse|Response
    {
        [$du, $au] = $this->intervalle($request);
        $balance = $this->soldes->balance($du, $au);
        if ($request->query('format') !== 'csv') {
            return response()->json($balance);
        }

        $lignes = array_map(fn (array $c) => [$c['numero'], $c['libelle'], $c['ouverture_debit'], $c['ouverture_credit'],
            $c['mouvement_debit'], $c['mouvement_credit'], $c['solde_debit'], $c['solde_credit']], $balance['comptes']);
        $t = $balance['totaux'];
        $lignes[] = ['', 'Totaux', $t['ouverture_debit'], $t['ouverture_credit'], $t['mouvement_debit'], $t['mouvement_credit'], $t['solde_debit'], $t['solde_credit']];

        return FichierCsv::reponse("balance-{$du}-{$au}.csv", ['compte', 'libelle', 'ouverture_debit', 'ouverture_credit',
            'mouvement_debit', 'mouvement_credit', 'solde_debit', 'solde_credit'], $lignes);
    }

    public function grandLivre(Request $request): JsonResponse|Response
    {
        $donnees = $request->validate([
            'compte_id' => ['required', 'integer', TenantRule::exists('comptes_comptables')],
            'recherche' => ['nullable', 'string', 'max:100'],
        ]);
        [$du, $au] = $this->intervalle($request);
        $livre = $this->soldes->grandLivre(CompteComptable::findOrFail($donnees['compte_id']), $du, $au, $donnees['recherche'] ?? null);
        if ($request->query('format') !== 'csv') {
            return response()->json($livre);
        }

        $lignes = [['', '', '', '', 'Solde d\'ouverture', '', 0.0, 0.0, $livre['solde_ouverture']]];
        foreach ($livre['lignes'] as $l) {
            $lignes[] = [$l['date'], $l['journal'], $l['numero'], $l['compte'], $l['libelle'], $l['reference'], $l['debit'], $l['credit'], $l['solde']];
        }

        return FichierCsv::reponse("grand-livre-{$livre['compte']['numero']}-{$du}-{$au}.csv",
            ['date', 'journal', 'numero', 'compte', 'libelle', 'reference', 'debit', 'credit', 'solde'], $lignes);
    }

    /** Bilan à une date (aujourd'hui par défaut). */
    public function bilan(Request $request, EtatsFinanciers $etats): JsonResponse|Response
    {
        $date = Carbon::parse($request->validate(['date' => ['nullable', 'date']])['date'] ?? today())->toDateString();
        $bilan = $etats->bilan($date);
        if ($request->query('format') !== 'csv') {
            return response()->json($bilan);
        }

        $lignes = [];
        foreach (['actif' => 'Actif', 'capitaux_propres' => 'Capitaux propres', 'passif' => 'Dettes'] as $cle => $section) {
            foreach ($bilan[$cle]['groupes'] as $groupe) {
                $lignes = array_merge($lignes, $this->lignesGroupe($section, $groupe));
            }
            $lignes[] = [$section, '', 'Total', '', '', (float) $bilan[$cle]['total']];
        }
        $lignes[] = ['', '', 'Total actif', '', '', (float) $bilan['total_actif']];
        $lignes[] = ['', '', 'Total passif', '', '', (float) $bilan['total_passif']];

        return FichierCsv::reponse("bilan-{$date}.csv", ['section', 'groupe', 'libelle_groupe', 'compte', 'libelle', 'montant'], $lignes);
    }

    /** Compte de résultat d'une période (l'exercice en cours par défaut). */
    public function compteResultat(Request $request, EtatsFinanciers $etats): JsonResponse|Response
    {
        [$du, $au] = $this->intervalle($request);
        $resultat = $etats->resultat($du, $au);
        if ($request->query('format') !== 'csv') {
            return response()->json($resultat);
        }

        $lignes = [];
        foreach (['produits' => 'Produits', 'charges' => 'Charges'] as $cle => $section) {
            foreach ($resultat[$cle]['groupes'] as $groupe) {
                $lignes = array_merge($lignes, $this->lignesGroupe($section, $groupe));
            }
            $lignes[] = [$section, '', 'Total', '', '', (float) $resultat[$cle]['total']];
        }
        $lignes[] = ['', '', 'Résultat', '', '', (float) $resultat['resultat']];

        return FichierCsv::reponse("compte-de-resultat-{$du}-{$au}.csv", ['section', 'groupe', 'libelle_groupe', 'compte', 'libelle', 'montant'], $lignes);
    }

    /** Lignes CSV d'un groupe : ses comptes, ou le groupe lui-même s'il n'en a pas (résultat non clôturé). */
    private function lignesGroupe(string $section, array $groupe): array
    {
        if (! $groupe['comptes']) {
            return [[$section, $groupe['numero'] ?? '', $groupe['libelle'], '', '', (float) $groupe['montant']]];
        }

        return array_map(fn (array $c) => [$section, $groupe['numero'] ?? '', $groupe['libelle'], $c['numero'], $c['libelle'], (float) $c['montant']], $groupe['comptes']);
    }

    /** Comptes de bilan du plan avec leur solde d'ouverture sur le premier exercice. */
    public function soldesOuverture(): JsonResponse
    {
        $exercice = $this->ecritures->exerciceOuverture()?->load('periodes');
        $ecriture = $exercice
            ? EcritureComptable::with('lignes')->where('source', EcritureComptable::OUVERTURE)->where('exercice_id', $exercice->id)->first()
            : null;
        $montants = $ecriture?->lignes->groupBy('compte_id') ?? collect();
        $comptes = CompteComptable::whereIn('nature', ['actif', 'passif', 'capitaux_propres'])
            ->where('type', '!=', CompteComptable::EN_TETE)
            ->where(fn ($q) => $q->where('actif', true)->orWhereIn('id', $montants->keys()))
            ->orderBy('numero')->get(['id', 'numero', 'libelle', 'nature', 'type']);

        return response()->json([
            'exercice' => $exercice ? [
                'id' => $exercice->id,
                'libelle' => $exercice->libelle,
                'date_debut' => $exercice->date_debut->toDateString(),
                'verrouille' => $exercice->estCloture() || (bool) $exercice->periodes->first()?->fermee,
            ] : null,
            'ecriture' => $ecriture?->only(['id', 'numero']),
            'comptes' => $comptes->map(fn (CompteComptable $c) => $c->only(['id', 'numero', 'libelle', 'nature', 'type']) + [
                'debit' => round((float) ($montants[$c->id] ?? collect())->sum('debit'), 2),
                'credit' => round((float) ($montants[$c->id] ?? collect())->sum('credit'), 2),
            ]),
        ]);
    }

    public function enregistrerSoldesOuverture(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'lignes' => ['present', 'array', 'max:5000'],
            'lignes.*.compte_id' => ['required', 'integer', TenantRule::exists('comptes_comptables')],
            'lignes.*.debit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'lignes.*.credit' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
        ]);
        $ecriture = $this->ecritures->enregistrerOuverture($donnees['lignes'], $request->user()?->id);

        return response()->json([
            'message' => $ecriture
                ? __("Soldes d'ouverture enregistrés (:numero).", ['numero' => $ecriture->numero])
                : __("Soldes d'ouverture effacés."),
            'ecriture' => $ecriture?->only(['id', 'numero']),
        ]);
    }

    /** Dates demandées ; sinon celles de l'exercice en cours (ou du dernier), sinon l'année civile. */
    private function intervalle(Request $request): array
    {
        $request->validate(['du' => ['nullable', 'date'], 'au' => ['nullable', 'date', 'after_or_equal:du']]);
        $exercice = $this->exercices->periodeDu()?->exercice ?? ExerciceComptable::orderByDesc('date_debut')->first();
        $du = $request->input('du') ?: ($exercice?->date_debut->toDateString() ?? now()->startOfYear()->toDateString());
        $au = $request->input('au') ?: ($exercice?->date_fin->toDateString() ?? now()->endOfYear()->toDateString());

        return [Carbon::parse($du)->toDateString(), Carbon::parse($au)->toDateString()];
    }
}
