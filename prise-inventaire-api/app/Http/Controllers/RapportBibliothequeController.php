<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Rapports\Catalogue;
use App\Rapports\Rapport;
use App\Support\ClasseurXlsx;
use App\Support\EntreprisePdf;
use App\Support\FichierCsv;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Bibliothèque des rapports (plan de parité fripv2, phases 5.1 et 5.2) : le catalogue des rapports que le compte peut
 * ouvrir, et chaque rapport à l'écran (lignes et totaux) ou en fichier (CSV, Excel, PDF), dans la langue demandée.
 */
class RapportBibliothequeController extends Controller
{
    public const FORMATS = ['json', 'csv', 'xlsx', 'pdf'];

    public function catalogue(Request $request): JsonResponse
    {
        $langue = $this->langue($request);
        $rapports = Catalogue::pour($request->user(), $request->attributes->get('tenant'));
        $typesParametres = $rapports->flatMap(fn (Rapport $r) => array_column($r->parametres(), 'type'));

        return response()->json([
            'categories' => collect(Catalogue::CATEGORIES)
                ->map(fn (string $categorie) => ['code' => $categorie, 'rapports' => $rapports->filter(fn (Rapport $r) => $r->categorie() === $categorie)
                    ->map(fn (Rapport $r) => $this->definition($r, $langue))->values()->all()])
                ->filter(fn (array $c) => $c['rapports'] !== [])->values()->all(),
            // Catégories de produits proposées aux rapports filtrés par catégorie.
            'categories_produits' => $typesParametres->contains('categorie')
                ? ProduitTenant::query()->whereNotNull('categorie')->where('categorie', '!=', '')->distinct()->orderBy('categorie')->pluck('categorie')->values()->all()
                : [],
        ]);
    }

    public function executer(Request $request, string $code): JsonResponse|Response
    {
        $rapport = Catalogue::pour($request->user(), $request->attributes->get('tenant'))->get($code);
        abort_unless($rapport, 404, 'Rapport introuvable, ou réservé à un autre droit.');
        $rapport->langue = $this->langue($request);
        $saisies = $request->validate($rapport->regles() + ['format' => ['nullable', Rule::in(self::FORMATS)], 'langue' => ['nullable', Rule::in(['fr', 'en'])]]);
        $valeurs = $rapport->valeurs($saisies);
        $colonnes = $rapport->colonnes($valeurs);
        $lignes = $rapport->lignes($valeurs);
        $totaux = $this->totaux($colonnes, $lignes);
        $format = $saisies['format'] ?? 'json';
        $libelles = array_map(fn (array $c) => $c['libelle'][$rapport->langue] ?? $c['libelle']['fr'], $colonnes);
        $nom = $code.'-'.today()->toDateString();

        if ($format === 'json') {
            return response()->json([
                'rapport' => $this->definition($rapport, $rapport->langue),
                'valeurs' => $valeurs,
                'colonnes' => collect($colonnes)->map(fn (array $c, string $cle) => ['cle' => $cle, 'libelle' => $libelles[$cle], 'type' => $c['type'], 'total' => $c['total'] ?? false])->values()->all(),
                'lignes' => $lignes,
                'totaux' => $totaux,
                'genere_le' => now()->toIso8601String(),
            ]);
        }

        $cles = array_keys($colonnes);
        $valeursLignes = array_map(fn (array $l) => array_map(fn (string $cle) => $l[$cle] ?? null, $cles), $lignes);
        if ($totaux) {
            $valeursLignes[] = array_map(fn (string $cle, int $index) => $totaux[$cle] ?? ($index === 0 ? 'Total' : null), $cles, array_keys($cles));
        }
        if ($format === 'csv') {
            return FichierCsv::reponse("{$nom}.csv", array_values($libelles), array_map(fn (array $l) => array_map(fn ($v) => is_int($v) ? (string) $v : $v, $l), $valeursLignes));
        }
        if ($format === 'xlsx') {
            $montants = array_map(fn (string $cle) => $colonnes[$cle]['type'] === 'montant', $cles);

            return response(ClasseurXlsx::contenu($rapport->texte($rapport->titre()), array_values($libelles), $valeursLignes, $montants), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$nom.'.xlsx"',
            ]);
        }

        return Pdf::loadView('pdf.rapport', [
            'langue' => $rapport->langue,
            'titre' => $rapport->texte($rapport->titre()),
            'description' => $rapport->texte($rapport->description()),
            'parametres' => $this->parametresLisibles($rapport, $valeurs),
            'colonnes' => collect($colonnes)->map(fn (array $c, string $cle) => ['libelle' => $libelles[$cle], 'type' => $c['type']])->all(),
            'lignes' => $lignes,
            'totaux' => $totaux,
            'genere' => now()->setTimezone($this->fuseau($request))->format($rapport->langue === 'en' ? 'Y-m-d H:i' : 'd/m/Y H:i'),
        ] + EntreprisePdf::entete())
            ->setPaper('A4', count($colonnes) > 6 ? 'landscape' : 'portrait')->setOption('isFontSubsettingEnabled', true)
            ->download("{$nom}.pdf");
    }

    private function langue(Request $request): string
    {
        $langue = (string) $request->query('langue', 'fr');

        return in_array($langue, ['fr', 'en'], true) ? $langue : 'fr';
    }

    /** Fuseau de l'utilisateur transmis par l'écran (« Europe/Paris »), sinon celui de l'application. */
    private function fuseau(Request $request): string
    {
        $fuseau = (string) $request->query('fuseau', '');

        return in_array($fuseau, timezone_identifiers_list(), true) ? $fuseau : config('app.timezone');
    }

    /** Définition d'un rapport pour l'écran : code, catégorie, titre, description, paramètres (avec leur valeur par défaut). */
    private function definition(Rapport $rapport, string $langue): array
    {
        return [
            'code' => $rapport->code(),
            'categorie' => $rapport->categorie(),
            'titre' => $rapport->titre()[$langue] ?? $rapport->titre()['fr'],
            'description' => $rapport->description()[$langue] ?? $rapport->description()['fr'],
            'parametres' => collect($rapport->parametres())->map(fn (array $p, string $cle) => array_filter([
                'cle' => $cle,
                'type' => $p['type'],
                'libelle' => $p['libelle'][$langue] ?? $p['libelle']['fr'],
                'defaut' => $p['defaut'] ?? null,
                'min' => $p['min'] ?? null,
                'max' => $p['max'] ?? null,
                'options' => isset($p['options']) ? collect($p['options'])->map(fn (array $l, string $valeur) => ['valeur' => $valeur, 'libelle' => $l[$langue] ?? $l['fr']])->values()->all() : null,
            ], fn ($v) => $v !== null))->values()->all(),
        ];
    }

    /** Totaux des colonnes qui en ont (montants arrondis au centime). */
    private function totaux(array $colonnes, array $lignes): array
    {
        $totaux = [];
        foreach ($colonnes as $cle => $colonne) {
            if ($colonne['total'] ?? false) {
                $somme = array_sum(array_map(fn (array $l) => (float) ($l[$cle] ?? 0), $lignes));
                $totaux[$cle] = match ($colonne['type']) {
                    'entier' => (int) round($somme),
                    'pourcentage' => round($somme, 1),
                    'quantite' => round($somme, 4),
                    default => round($somme, 2),
                };
            }
        }

        return $totaux;
    }

    /** Paramètres renseignés, lisibles, pour l'en-tête du PDF : libellé => valeur (date, option, client, fournisseur). */
    private function parametresLisibles(Rapport $rapport, array $valeurs): array
    {
        $lisibles = [];
        foreach ($rapport->parametres() as $cle => $parametre) {
            $valeur = $valeurs[$cle] ?? null;
            if ($valeur === null || $valeur === '') {
                continue;
            }
            $lisibles[$rapport->texte($parametre['libelle'])] = match ($parametre['type']) {
                'date' => Carbon::parse($valeur)->format($rapport->langue === 'en' ? 'Y-m-d' : 'd/m/Y'),
                'choix' => $rapport->texte($parametre['options'][$valeur] ?? ['fr' => (string) $valeur]),
                'client' => Client::withTrashed()->find($valeur)?->raison_sociale ?? (string) $valeur,
                'fournisseur' => Fournisseur::withTrashed()->find($valeur)?->raison_sociale ?? (string) $valeur,
                default => (string) $valeur,
            };
        }

        return $lisibles;
    }
}
