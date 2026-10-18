<?php

namespace App\Http\Controllers;

use App\Models\ProduitTenant;
use App\Models\ScanTenant;
use App\Models\Secteur;
use App\Services\CodesBarresProduits;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ScanTenantController extends Controller
{
    /**
     * Historique des scans, filtré par l'API (employé, secteur, produit, période). Avec « page », la réponse est
     * paginée et couvre tout l'historique ; sans, elle reste la liste des 100 derniers scans attendue par
     * l'application mobile.
     */
    public function historique(Request $request): JsonResponse
    {
        $query = $this->requeteFiltree($request)->orderByDesc('date_saisie')->orderByDesc('id');

        if ($request->has('page')) {
            return response()->json($query->paginate((int) $request->input('per_page', 100)));
        }

        return response()->json($query->limit(100)->get());
    }

    /**
     * Scans filtrés. La période est donnée en instants (début inclus, fin exclue) calculés par l'écran dans le
     * fuseau de l'utilisateur : un filtre « du 15 au 15 » comparait des dates UTC à des heures locales.
     * Un secteur se retrouve par son code comme par son nom (le web enregistre le code, Android le nom).
     */
    private function requeteFiltree(Request $request): Builder
    {
        $request->validate([
            'depuis' => 'nullable|date',
            'jusqu_a' => 'nullable|date',
            'produit' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:500',
            'entrepot_id' => 'nullable|integer',
        ]);

        return ScanTenant::query()->whereNull('deleted_at')
            ->when($request->filled('employe'), fn ($q) => $q->where('employe', $request->employe))
            ->when($request->filled('secteur'), fn ($q) => $q->whereIn('secteur', $this->appellationsSecteur((string) $request->secteur)))
            // Multi-entrepôts : les scans des secteurs de l'entrepôt.
            ->when($request->integer('entrepot_id'), fn ($q, $entrepot) => $q->whereIn('secteur', $this->appellationsEntrepot($entrepot)))
            ->when($request->filled('produit'), fn ($q) => $q->where('numero', 'like', '%'.addcslashes((string) $request->produit, '%_\\').'%'))
            ->when($request->filled('depuis'), fn ($q) => $q->where('date_saisie', '>=', Carbon::parse($request->depuis)->utc()))
            ->when($request->filled('jusqu_a'), fn ($q) => $q->where('date_saisie', '<', Carbon::parse($request->jusqu_a)->utc()));
    }

    /** Code et nom d'un secteur, sous lesquels ses scans ont pu être enregistrés. */
    private function appellationsSecteur(string $secteur): array
    {
        $fiche = Secteur::where('code', $secteur)->orWhere('nom', $secteur)->first();

        return $fiche ? array_values(array_unique([$fiche->code, $fiche->nom, $secteur])) : [$secteur];
    }

    /** Codes et noms des secteurs d'un entrepôt (même désactivés : leurs scans restent). */
    private function appellationsEntrepot(int $entrepot): array
    {
        return Secteur::withTrashed()->where('entrepot_id', $entrepot)->get(['code', 'nom'])
            ->flatMap(fn (Secteur $s) => [$s->code, $s->nom])->unique()->values()->all();
    }

    /** Secteur ramené à son code (un scan Android porte le nom), pour compter un secteur une seule fois. */
    private function codesParAppellation(): Collection
    {
        return Secteur::query()->get(['code', 'nom'])
            ->flatMap(fn (Secteur $s) => [mb_strtolower($s->code) => $s->code, mb_strtolower($s->nom) => $s->code]);
    }

    /** Export CSV de tous les scans filtrés (l'écran n'exportait que les 100 lignes chargées). */
    public function exporter(Request $request): StreamedResponse
    {
        $query = $this->requeteFiltree($request)->orderByDesc('date_saisie')->orderByDesc('id');
        $fuseau = $this->fuseau($request);

        return response()->streamDownload(function () use ($query, $fuseau) {
            $sortie = fopen('php://output', 'w');
            // BOM : Excel lit alors le fichier en UTF-8 (accents des secteurs et des employés).
            fwrite($sortie, "\u{FEFF}");
            fputcsv($sortie, ['Numéro', 'Type', 'Quantité', 'Unité', 'Employé', 'Secteur', 'Date'], ';');
            $query->chunk(1000, function ($scans) use ($sortie, $fuseau) {
                foreach ($scans as $scan) {
                    fputcsv($sortie, [
                        $scan->numero, $scan->type, $this->quantiteCsv($scan->quantite), $scan->unite_mesure, $scan->employe, $scan->secteur,
                        $scan->date_saisie?->copy()->setTimezone($fuseau)->format('Y-m-d H:i'),
                    ], ';');
                }
            });
            fclose($sortie);
        }, 'inventaire_'.now($fuseau)->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Quantité du CSV sans zéros inutiles et avec une virgule décimale (« 4,5 »), comme l'attend Excel en français. */
    private function quantiteCsv(mixed $quantite): string
    {
        $texte = rtrim(rtrim(number_format((float) $quantite, 4, '.', ''), '0'), '.');

        return str_replace('.', ',', $texte);
    }

    /**
     * Statistiques d'une période calculées sur tous ses scans (l'écran les calculait sur les 100 derniers) :
     * total, jours d'activité (dans le fuseau de l'utilisateur), secteurs, employés et types.
     */
    public function statistiques(Request $request): JsonResponse
    {
        $request->validate(['depuis' => 'required|date', 'jusqu_a' => 'required|date']);
        $base = $this->requeteFiltree($request);
        $fuseau = $this->fuseau($request);
        $codes = $this->codesParAppellation();

        $parJour = (clone $base)->pluck('date_saisie')
            ->countBy(fn ($date) => Carbon::parse($date)->setTimezone($fuseau)->toDateString())
            ->sortKeys();
        $parSecteur = (clone $base)->selectRaw('secteur, COUNT(*) as total')->groupBy('secteur')->get()
            ->groupBy(fn ($ligne) => $codes[mb_strtolower((string) $ligne->secteur)] ?? $ligne->secteur)
            ->map(fn ($lignes) => $lignes->sum('total'));
        $compter = fn (string $colonne) => (clone $base)->selectRaw("{$colonne} as nom, COUNT(*) as total")
            ->groupBy($colonne)->orderByDesc('total')->get()
            ->map(fn ($ligne) => ['nom' => $ligne->nom ?: __('Non défini'), 'total' => (int) $ligne->total]);

        $total = $parJour->sum();

        return response()->json([
            'total' => $total,
            'jours_actifs' => $parJour->count(),
            'moyenne_par_jour' => $parJour->count() ? (int) round($total / $parJour->count()) : 0,
            'secteurs_distincts' => $parSecteur->count(),
            'employes_distincts' => (clone $base)->distinct()->count('employe'),
            'par_jour' => $parJour->map(fn ($nombre, $jour) => ['date' => $jour, 'total' => $nombre])->values(),
            'par_secteur' => $parSecteur->sortDesc()->map(fn ($nombre, $code) => ['nom' => $code, 'total' => $nombre])->values()->take(8),
            'par_employe' => $compter('employe')->take(10)->values(),
            'par_type' => $compter('type')->values(),
        ]);
    }

    /**
     * Quantités comptées par produit sur deux périodes (A, puis B) et leur écart. La page additionnait tous
     * les scans jusqu'à chaque date : un cumul ne fait que croître, une baisse n'apparaissait jamais.
     */
    public function comparaison(Request $request): JsonResponse
    {
        $request->validate([
            'a_depuis' => 'required|date', 'a_jusqu_a' => 'required|date',
            'b_depuis' => 'required|date', 'b_jusqu_a' => 'required|date',
        ]);

        $quantites = fn (string $depuis, string $jusqua) => ScanTenant::query()->whereNull('deleted_at')
            ->where('date_saisie', '>=', Carbon::parse($depuis)->utc())
            ->where('date_saisie', '<', Carbon::parse($jusqua)->utc())
            ->selectRaw('numero, MAX(type) as type, MAX(secteur) as secteur, SUM(quantite) as quantite')
            ->groupBy('numero')->get()->keyBy('numero');
        $a = $quantites($request->a_depuis, $request->a_jusqu_a);
        $b = $quantites($request->b_depuis, $request->b_jusqu_a);

        $lignes = $a->keys()->merge($b->keys())->unique()->map(function ($numero) use ($a, $b) {
            $avant = (float) ($a[$numero]->quantite ?? 0);
            $apres = (float) ($b[$numero]->quantite ?? 0);

            return [
                'numero' => $numero,
                'type' => $b[$numero]->type ?? $a[$numero]->type ?? '',
                'secteur' => $b[$numero]->secteur ?? $a[$numero]->secteur ?? '',
                'quantite_a' => $avant,
                'quantite_b' => $apres,
                'difference' => $apres - $avant,
                'pourcentage' => $avant > 0 ? round(($apres - $avant) / $avant * 100, 1) : ($apres > 0 ? 100.0 : 0.0),
            ];
        })->sortByDesc(fn ($ligne) => abs($ligne['difference']))->values();

        return response()->json(['lignes' => $lignes]);
    }

    /** Fuseau de l'utilisateur transmis par l'écran (« Europe/Paris »), sinon celui de l'application. */
    private function fuseau(Request $request): string
    {
        $fuseau = (string) $request->input('fuseau', '');

        return in_array($fuseau, timezone_identifiers_list(), true) ? $fuseau : config('app.timezone');
    }

    public function enregistrer(Request $request): JsonResponse
    {
        $request->validate([
            'numero' => 'required|string|max:50',
            'quantite' => 'required|numeric|min:0',
            'employe' => 'required|string|max:50',
            'secteur' => 'required|string|max:100',
            'type' => 'nullable|string|max:50',
            'unite_mesure' => 'sometimes|string|max:20',
            'scanneur' => 'nullable|string|max:50',
        ]);

        // Un code-barres plutôt qu'un numéro : le scan compte pour son produit, à la quantité du code (un carton).
        $numero = $request->numero;
        $quantite = $request->quantite;
        $codeLu = null;
        if (! ProduitTenant::query()->where('numero', $numero)->exists()) {
            $lecture = app(CodesBarresProduits::class)->resoudre($numero);
            if ($lecture && $lecture['code'] !== null) {
                $codeLu = $lecture['code'];
                $numero = $lecture['produit']->numero;
                $quantite = round((float) $quantite * $lecture['quantite'], 3);
            }
        }

        $scan = ScanTenant::create([
            'numero' => $numero,
            'code_lu' => $codeLu,
            'type' => $request->type,
            'quantite' => $quantite,
            'unite_mesure' => $request->unite_mesure ?? 'UN',
            'employe' => $request->employe,
            'secteur' => $request->secteur,
            'date_saisie' => now(),
            'scanneur' => $request->scanneur,
        ]);

        return response()->json([
            'success' => true,
            'message' => __('Scan enregistré avec succès'),
            'scan' => $scan,
        ], 201);
    }

    public function modifier(Request $request, $id): JsonResponse
    {
        $scan = ScanTenant::findOrFail($id);

        $request->validate([
            'quantite' => 'required|numeric|min:0',
        ]);

        $scan->quantite = $request->quantite;
        $scan->save();

        return response()->json([
            'success' => true,
            'message' => __('Scan modifié avec succès'),
            'scan' => $scan,
        ]);
    }

    public function supprimer($id): JsonResponse
    {
        $scan = ScanTenant::findOrFail($id);
        $scan->deleted_at = now();
        $scan->save();

        return response()->json([
            'success' => true,
            'message' => __('Scan supprimé avec succès'),
        ]);
    }

    /** Total, scans du jour, et dates du premier et du dernier scan (bornes proposées par la comparaison). */
    public function stats(): JsonResponse
    {
        $total = ScanTenant::whereNull('deleted_at')->count();
        $today = ScanTenant::whereNull('deleted_at')
            ->whereDate('date_saisie', today())
            ->count();

        // Instants ISO (la base stocke en UTC sans fuseau) : l'écran les ramène à des dates locales.
        $iso = fn ($valeur) => $valeur ? Carbon::parse($valeur, 'UTC')->toIso8601String() : null;

        return response()->json([
            'total' => $total,
            'today' => $today,
            'premier' => $iso(ScanTenant::whereNull('deleted_at')->min('date_saisie')),
            'dernier' => $iso(ScanTenant::whereNull('deleted_at')->max('date_saisie')),
        ]);
    }
}
