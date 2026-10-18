<?php

namespace App\Http\Controllers;

use App\Jobs\EnvoyerCourriel;
use App\Models\ProduitTenant;
use App\Rapports\Catalogue;
use App\Rapports\Production;
use App\Rapports\Rapport;
use App\Services\Courriels\EnvoiCourriel;
use App\Support\Courriels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Bibliothèque des rapports (plan de parité fripv2, phases 5.1 et 5.2) : le catalogue des rapports que le compte peut
 * ouvrir, chaque rapport à l'écran (lignes et totaux) ou en fichier (CSV, Excel, PDF), dans la langue demandée, et
 * son envoi par courriel.
 */
class RapportBibliothequeController extends Controller
{
    public const FORMATS = ['json', 'csv', 'xlsx', 'pdf'];

    /** Messages des adresses de courriel refusées (destinataires, copies), dans la langue de la requête. */
    public static function messagesAdresses(): array
    {
        return [
            'destinataires.required' => __('Indiquez au moins un destinataire.'),
            'destinataires.max' => __('Vingt destinataires au plus.'),
            'destinataires.*.email' => __('Adresse de courriel invalide : « :input ».'),
            'copies.*.email' => __('Adresse de courriel invalide : « :input ».'),
            'copies_cachees.*.email' => __('Adresse de courriel invalide : « :input ».'),
        ];
    }

    public function __construct(private readonly Production $production) {}

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
        $rapport = $this->rapport($request, $code);
        $saisies = $request->validate($rapport->regles() + ['format' => ['nullable', Rule::in(self::FORMATS)], 'langue' => ['nullable', Rule::in(['fr', 'en'])]]);
        $valeurs = $rapport->valeurs($saisies);
        $format = $saisies['format'] ?? 'json';

        if ($format === 'json') {
            ['colonnes' => $colonnes, 'lignes' => $lignes, 'totaux' => $totaux] = $this->production->donnees($rapport, $valeurs);

            return response()->json([
                'rapport' => $this->definition($rapport, $rapport->langue),
                'valeurs' => $valeurs,
                'colonnes' => collect($colonnes)->map(fn (array $c, string $cle) => ['cle' => $cle] + $c)->values()->all(),
                'lignes' => $lignes,
                'totaux' => $totaux,
                'genere_le' => now()->toIso8601String(),
            ]);
        }

        $fichier = $this->production->fichier($rapport, $valeurs, $format, $this->fuseau($request));

        return response($fichier['contenu'], 200, [
            'Content-Type' => $fichier['mime'].($format === 'csv' ? '; charset=UTF-8' : ''),
            'Content-Disposition' => 'attachment; filename="'.$fichier['nom'].'"',
        ]);
    }

    /** Pré-remplissage de la fenêtre d'envoi d'un rapport par courriel (modèle « rapport » de l'entreprise). */
    public function preparerCourriel(Request $request, EnvoiCourriel $envois, string $code): JsonResponse
    {
        $rapport = $this->rapport($request, $code);
        $saisies = $request->validate($this->reglesParametres($rapport) + ['langue' => ['nullable', Rule::in(Courriels::LANGUES)]]);

        return response()->json($envois->preparerRapport($rapport, $rapport->valeurs($saisies['parametres'] ?? []), $rapport->langue, $request->user()?->email));
    }

    /** Envoie le rapport par courriel (mis en file, puis envoyé tout de suite avec la file « sync »). */
    public function envoyerCourriel(Request $request, EnvoiCourriel $envois, string $code): JsonResponse
    {
        $rapport = $this->rapport($request, $code);
        $donnees = $request->validate($this->reglesParametres($rapport) + [
            'format' => ['required', Rule::in(Production::FORMATS)],
            'langue' => ['required', Rule::in(Courriels::LANGUES)],
            'destinataires' => ['required', 'array', 'min:1', 'max:20'],
            'destinataires.*' => ['required', 'email', 'max:255'],
            'copies' => ['nullable', 'array', 'max:20'],
            'copies.*' => ['required', 'email', 'max:255'],
            'copies_cachees' => ['nullable', 'array', 'max:20'],
            'copies_cachees.*' => ['required', 'email', 'max:255'],
            'objet' => ['required', 'string', 'max:255'],
            'contenu' => ['required', 'string', 'max:20000'],
        ], self::messagesAdresses());

        $envoi = $envois->creerRapport($rapport, [
            'valeurs' => $rapport->valeurs($donnees['parametres'] ?? []),
            'format' => $donnees['format'],
            'fuseau' => $this->fuseau($request),
        ], $donnees, $request->user()?->id);
        EnvoyerCourriel::dispatch($envoi->id);

        return response()->json($envoi->refresh()->load('auteur:id,nom')->resume(), 201);
    }

    /** Un rapport que le compte peut ouvrir, dans la langue demandée ; sinon « introuvable ». */
    private function rapport(Request $request, string $code): Rapport
    {
        $rapport = Catalogue::pour($request->user(), $request->attributes->get('tenant'))->get($code);
        abort_unless($rapport, 404, __('Rapport introuvable, ou réservé à un autre droit.'));
        $rapport->langue = $this->langue($request);

        return $rapport;
    }

    /** Règles des paramètres d'un rapport donnés sous « parametres » (envoi par courriel). */
    private function reglesParametres(Rapport $rapport): array
    {
        return ['parametres' => ['nullable', 'array']]
            + collect($rapport->regles())->mapWithKeys(fn (array $regles, string $cle) => ["parametres.{$cle}" => $regles])->all();
    }

    private function langue(Request $request): string
    {
        $langue = (string) $request->input('langue', 'fr');

        return in_array($langue, ['fr', 'en'], true) ? $langue : 'fr';
    }

    /** Fuseau de l'utilisateur transmis par l'écran (« Europe/Paris »), sinon celui de l'application. */
    private function fuseau(Request $request): string
    {
        $fuseau = (string) $request->input('fuseau', '');

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
}
