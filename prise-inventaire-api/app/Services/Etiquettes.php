<?php

namespace App\Services;

use App\Models\EtiquetteFormat;
use App\Models\GroupeTaxe;
use App\Models\ListePrix;
use App\Models\ListePrixProduit;
use App\Models\ProduitTenant;
use App\Support\CodesBarres;
use App\Support\EntreprisePdf;
use App\Support\MontantEnLettres;
use App\Support\SvgCodeBarre;
use App\Support\TenantContext;
use Barryvdh\DomPDF\PDF as DocumentPdf;
use Dompdf\FontMetrics;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Étiquettes des produits (plan de parité fripv2, phase 2.1) :
 * - formats : rouleau (une étiquette par page, de sa taille, pour une imprimante d'étiquettes) ou planche (une grille
 *   sur une page A4 ou Letter, avec ses marges et ses espaces) ; ceux d'origine (les trois de fripv2 en 38 × 22 mm :
 *   complet, sans prix, code-barres seul, et une planche A4 de 21) sont créés au premier affichage ; un format par
 *   défaut ; il reste toujours un format actif ;
 * - contenu : nom de l'entreprise, description, code-barres et ses chiffres, numéro, prix, date d'impression ; la
 *   disposition se calcule d'après la taille de l'étiquette et du texte, et un format trop petit pour son contenu est
 *   refusé ;
 * - impression en PDF : chaque produit en autant d'exemplaires que demandé, avec le code-barres choisi (sinon son
 *   principal, sinon le numéro du produit, en Code 128 s'il n'est pas un EAN) ; le prix est celui d'une liste de prix,
 *   sinon le prix de base, taxes comprises si le format le demande (groupe de taxes choisi, sinon celui par défaut) ;
 *   sur une planche entamée, l'impression commence à la position indiquée.
 */
class Etiquettes
{
    public const SUPPORTS = ['rouleau', 'planche'];

    /** Pages des planches : largeur et hauteur en millimètres. */
    public const PAGES = ['A4' => [210.0, 297.0], 'LETTER' => [215.9, 279.4]];

    /** Ce qu'une étiquette peut porter, dans l'ordre de la disposition. */
    public const CONTENUS = ['entreprise', 'description', 'code_barres', 'chiffres', 'numero', 'prix', 'prix_taxes', 'date'];

    /** Étiquettes au plus par impression. */
    public const MAXIMUM = 2000;

    /** Hauteur minimale d'un code-barres lisible, en millimètres. */
    public const HAUTEUR_CODE_MINIMALE = 5.0;

    /** Blanc au-dessus et au-dessous des barres, en millimètres. */
    public const BLANC_CODE = 0.3;

    /** Police des étiquettes (livrée avec DomPDF : accents compris). */
    private const POLICE = 'DejaVu Sans';

    /** Espacement des chiffres sous le code-barres, en points. */
    public const ESPACEMENT_CHIFFRES = 0.4;

    /** Métriques des polices de DomPDF, pour ajuster les textes à leur place. */
    private ?FontMetrics $metriques = null;

    /** Formats d'origine, créés au premier affichage. */
    private const ORIGINE = [
        ['nom' => 'Complet', 'support' => 'rouleau', 'largeur_mm' => 38, 'hauteur_mm' => 22, 'par_defaut' => true,
            'contenu' => ['description', 'code_barres', 'chiffres', 'numero', 'prix']],
        ['nom' => 'Sans prix', 'support' => 'rouleau', 'largeur_mm' => 38, 'hauteur_mm' => 22,
            'contenu' => ['description', 'code_barres', 'chiffres', 'numero']],
        ['nom' => 'Code-barres seul', 'support' => 'rouleau', 'largeur_mm' => 38, 'hauteur_mm' => 22,
            'contenu' => ['code_barres', 'chiffres']],
        // Planche courante de 21 étiquettes de 63,5 × 38,1 mm.
        ['nom' => 'Planche A4 (3 × 7)', 'support' => 'planche', 'largeur_mm' => 63.5, 'hauteur_mm' => 38.1, 'page' => 'A4',
            'colonnes' => 3, 'rangees' => 7, 'marge_haut_mm' => 15.15, 'marge_gauche_mm' => 7.25, 'espace_colonnes_mm' => 2.54,
            'contenu' => ['entreprise', 'description', 'code_barres', 'chiffres', 'numero', 'prix']],
    ];

    /** Points par millimètre (taille des pages du PDF). */
    private const POINTS_PAR_MM = 72 / 25.4;

    public function __construct(
        private readonly DevisesVente $devises,
        private readonly CalculTaxes $taxes,
        private readonly TenantContext $contexte,
    ) {}

    /** Formats de l'entreprise, dans leur ordre ; ceux d'origine sont créés au premier appel. */
    public function formats(): Collection
    {
        if (! EtiquetteFormat::query()->exists()) {
            $this->creerFormatsOrigine();
        }

        return EtiquetteFormat::query()->orderBy('ordre')->orderBy('nom')->get();
    }

    /** Formats d'origine, sans passer par le journal des modifications (personne ne les a saisis). */
    private function creerFormatsOrigine(): void
    {
        $lignes = [];
        foreach (self::ORIGINE as $rang => $format) {
            $valeurs = $this->normaliser($format);
            $lignes[] = ['contenu' => json_encode($valeurs['contenu'])] + $valeurs + [
                'tenant_id' => $this->contexte->getTenantId(), 'nom' => $format['nom'], 'par_defaut' => $format['par_defaut'] ?? false,
                'actif' => true, 'ordre' => $rang + 1, 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        // Deux premiers affichages simultanés : le second ne recrée rien (nom unique par entreprise).
        EtiquetteFormat::query()->insertOrIgnore($lignes);
    }

    /** Crée ou modifie un format : géométrie contrôlée (la grille tient dans la page, le contenu dans l'étiquette). */
    public function enregistrer(?EtiquetteFormat $format, array $donnees): EtiquetteFormat
    {
        $valeurs = $this->normaliser($donnees + ($format ? $format->only(['support', 'largeur_mm', 'hauteur_mm', 'page', 'colonnes',
            'rangees', 'marge_haut_mm', 'marge_gauche_mm', 'espace_colonnes_mm', 'espace_rangees_mm', 'contenu', 'taille_police']) : []));
        $valeurs['nom'] = trim((string) ($donnees['nom'] ?? $format?->nom));
        if (EtiquetteFormat::query()->where('nom', $valeurs['nom'])->when($format, fn ($q) => $q->whereKeyNot($format->id))->exists()) {
            throw ValidationException::withMessages(['nom' => __("Un format s'appelle déjà « :nom ».", ['nom' => $valeurs['nom']])]);
        }
        $this->controlerGrille($valeurs);
        $this->controlerContenu(new EtiquetteFormat($valeurs));

        $actif = (bool) ($donnees['actif'] ?? $format?->actif ?? true);
        if (! $actif && ! EtiquetteFormat::query()->where('actif', true)->when($format, fn ($q) => $q->whereKeyNot($format->id))->exists()) {
            throw ValidationException::withMessages(['actif' => __('Gardez au moins un format actif.')]);
        }

        return DB::transaction(function () use ($format, $donnees, $valeurs, $actif) {
            $format ??= new EtiquetteFormat(['ordre' => (int) EtiquetteFormat::query()->max('ordre') + 1]);
            $format->fill($valeurs + [
                'actif' => $actif,
                // Un format désactivé n'est plus celui par défaut.
                'par_defaut' => $actif && (bool) ($donnees['par_defaut'] ?? $format->par_defaut ?? false),
            ]);
            if (array_key_exists('ordre', $donnees)) {
                $format->ordre = (int) $donnees['ordre'];
            }
            $format->save();
            if ($format->par_defaut) {
                EtiquetteFormat::query()->whereKeyNot($format->id)->where('par_defaut', true)->update(['par_defaut' => false]);
            }
            $this->garderUnDefaut();

            return $format->refresh();
        });
    }

    /** Retire un format ; il en reste toujours un actif, et un par défaut. */
    public function supprimer(EtiquetteFormat $format): void
    {
        if (! EtiquetteFormat::query()->where('actif', true)->whereKeyNot($format->id)->exists()) {
            throw ValidationException::withMessages(['format' => __('Gardez au moins un format actif.')]);
        }
        DB::transaction(function () use ($format) {
            $format->delete();
            $this->garderUnDefaut();
        });
    }

    /** Sans format par défaut actif, le premier actif le devient. */
    private function garderUnDefaut(): void
    {
        if (! EtiquetteFormat::query()->where('par_defaut', true)->where('actif', true)->exists()) {
            EtiquetteFormat::query()->where('par_defaut', true)->update(['par_defaut' => false]);
            EtiquetteFormat::query()->where('actif', true)->orderBy('ordre')->orderBy('nom')->first()?->update(['par_defaut' => true]);
        }
    }

    /** Valeurs d'un format : celles d'un rouleau sont ramenées à une étiquette par page ; le contenu dans son ordre. */
    private function normaliser(array $donnees): array
    {
        $planche = ($donnees['support'] ?? 'rouleau') === 'planche';
        $contenu = array_values(array_intersect(self::CONTENUS, (array) ($donnees['contenu'] ?? [])));
        // Les taxes et les chiffres n'ont de sens qu'avec le prix et le code-barres.
        if (! in_array('prix', $contenu, true)) {
            $contenu = array_values(array_diff($contenu, ['prix_taxes']));
        }
        if (! in_array('code_barres', $contenu, true)) {
            $contenu = array_values(array_diff($contenu, ['chiffres']));
        }

        return [
            'support' => $planche ? 'planche' : 'rouleau',
            'largeur_mm' => round((float) ($donnees['largeur_mm'] ?? 0), 2),
            'hauteur_mm' => round((float) ($donnees['hauteur_mm'] ?? 0), 2),
            'page' => $planche ? strtoupper((string) ($donnees['page'] ?? 'A4')) : null,
            'colonnes' => $planche ? (int) ($donnees['colonnes'] ?? 1) : 1,
            'rangees' => $planche ? (int) ($donnees['rangees'] ?? 1) : 1,
            'marge_haut_mm' => $planche ? round((float) ($donnees['marge_haut_mm'] ?? 0), 2) : 0,
            'marge_gauche_mm' => $planche ? round((float) ($donnees['marge_gauche_mm'] ?? 0), 2) : 0,
            'espace_colonnes_mm' => $planche ? round((float) ($donnees['espace_colonnes_mm'] ?? 0), 2) : 0,
            'espace_rangees_mm' => $planche ? round((float) ($donnees['espace_rangees_mm'] ?? 0), 2) : 0,
            'contenu' => $contenu,
            'taille_police' => (int) ($donnees['taille_police'] ?? 7),
        ];
    }

    /** La grille d'une planche tient dans sa page. */
    private function controlerGrille(array $valeurs): void
    {
        if ($valeurs['support'] !== 'planche') {
            return;
        }
        if (! isset(self::PAGES[$valeurs['page']])) {
            throw ValidationException::withMessages(['page' => __('Choisissez une page A4 ou Letter.')]);
        }
        [$largeurPage, $hauteurPage] = self::PAGES[$valeurs['page']];
        $largeur = $valeurs['marge_gauche_mm'] + $valeurs['colonnes'] * $valeurs['largeur_mm'] + ($valeurs['colonnes'] - 1) * $valeurs['espace_colonnes_mm'];
        $hauteur = $valeurs['marge_haut_mm'] + $valeurs['rangees'] * $valeurs['hauteur_mm'] + ($valeurs['rangees'] - 1) * $valeurs['espace_rangees_mm'];
        $nombre = fn (float $valeur) => number_format($valeur, 2, ',', ' ');
        if ($largeur > $largeurPage + 0.01) {
            throw ValidationException::withMessages(['colonnes' => __('La grille est trop large : :largeur mm, marge comprise, pour une page de :page mm.', [
                'largeur' => $nombre($largeur),
                'page' => $nombre($largeurPage),
            ])]);
        }
        if ($hauteur > $hauteurPage + 0.01) {
            throw ValidationException::withMessages(['rangees' => __('La grille est trop haute : :hauteur mm, marge comprise, pour une page de :page mm.', [
                'hauteur' => $nombre($hauteur),
                'page' => $nombre($hauteurPage),
            ])]);
        }
    }

    /** Le contenu tient dans l'étiquette : il reste de la place pour un code-barres lisible. */
    private function controlerContenu(EtiquetteFormat $format): void
    {
        if ($format->contenu === []) {
            throw ValidationException::withMessages(['contenu' => __("Choisissez ce que porte l'étiquette.")]);
        }
        $disposition = $this->disposition($format);
        if (in_array('code_barres', $format->contenu, true) && $disposition['code_barres'] < self::HAUTEUR_CODE_MINIMALE) {
            $reste = $disposition['code_barres'] < 0.05
                ? __('il ne resterait rien')
                : __('il resterait :hauteur mm', ['hauteur' => number_format($disposition['code_barres'], 1, ',', ' ')]);
            throw ValidationException::withMessages(['contenu' => __("L'étiquette est trop petite pour tout ce qu'elle porte : :reste pour le code-barres (au moins :minimum mm). Réduisez le texte ou retirez un élément.", [
                'reste' => $reste,
                'minimum' => number_format(self::HAUTEUR_CODE_MINIMALE, 0),
            ])]);
        }
        if ($disposition['libre'] < 0) {
            throw ValidationException::withMessages(['contenu' => __("L'étiquette est trop petite pour tout ce qu'elle porte. Réduisez le texte ou retirez un élément.")]);
        }
    }

    /**
     * Disposition d'une étiquette du format, de haut en bas : marge intérieure, hauteur de chaque zone (mm), taille du
     * texte (pt) et lignes de description ; le code-barres prend la place qui reste.
     *
     * @return array{marge: float, interieur: float, police: int, ligne: float, entreprise: float, description: float, lignes_description: int, code_barres: float, chiffres: float, bas: float, date: float, libre: float}
     */
    public function disposition(EtiquetteFormat $format): array
    {
        $contenu = $format->contenu;
        $a = fn (string $element) => in_array($element, $contenu, true);
        $marge = round(min(2.0, min($format->largeur_mm, $format->hauteur_mm) * 0.06), 2);
        $ligne = fn (float $points) => self::hauteurLigne($points);
        $police = $format->taille_police;
        $zones = [
            'entreprise' => $a('entreprise') ? $ligne($police - 1) : 0.0,
            'chiffres' => $a('code_barres') && $a('chiffres') ? $ligne($police - 1) : 0.0,
            // Numéro à gauche, prix à droite (plus grand).
            'bas' => $a('numero') || $a('prix') ? $ligne($a('prix') ? $police + 3 : $police - 1) : 0.0,
            'date' => $a('date') ? $ligne($police - 2) : 0.0,
        ];
        $disponible = $format->hauteur_mm - 2 * $marge - array_sum($zones);
        // Description : deux lignes sur une étiquette assez haute, une sinon ; sans code-barres, la place qui reste.
        $lignesDescription = 0;
        if ($a('description')) {
            $lignesDescription = $a('code_barres')
                ? ($format->hauteur_mm >= 30 ? 2 : 1)
                : max(1, min(4, (int) floor($disponible / $ligne($police))));
        }
        $zones['description'] = $lignesDescription * $ligne($police);
        $reste = round($disponible - $zones['description'], 2);

        return [
            'marge' => $marge,
            'interieur' => round($format->largeur_mm - 2 * $marge, 2),
            'police' => $police,
            'ligne' => $ligne($police),
            'lignes_description' => $lignesDescription,
            // Un blanc au-dessus et au-dessous des barres, pour que le texte ne les touche pas.
            'code_barres' => $a('code_barres') ? max(0.0, round($reste - 2 * self::BLANC_CODE, 2)) : 0.0,
            'libre' => $a('code_barres') ? 0.0 : $reste,
        ] + $zones;
    }

    /** Hauteur d'une ligne de texte (mm) : 1 pt = 0,3528 mm ; interligne de 1,25, au-delà du dessin des lettres de DejaVu. */
    public static function hauteurLigne(float $points): float
    {
        return round($points * 0.3528 * 1.25, 2);
    }

    /**
     * Les étiquettes à imprimer, dans l'ordre des lignes, chacune répétée autant de fois que demandé.
     *
     * @param  list<array{produit_id: int, code_barre_id?: ?int, copies: int}>  $lignes
     * @param  array{liste_prix_id?: ?int, groupe_taxe_id?: ?int, langue?: ?string}  $options
     * @return list<array{description: string, numero: string, code: ?string, image: ?string, prix: ?string}>
     */
    public function etiquettes(EtiquetteFormat $format, array $lignes, array $options = []): array
    {
        $total = array_sum(array_map(fn (array $ligne) => (int) $ligne['copies'], $lignes));
        if ($total > self::MAXIMUM) {
            throw ValidationException::withMessages(['lignes' => __('Au plus :maximum étiquettes par impression (:total demandées).', [
                'maximum' => number_format(self::MAXIMUM, 0, ',', ' '),
                'total' => number_format($total, 0, ',', ' '),
            ])]);
        }
        $ids = array_values(array_unique(array_map(fn (array $ligne) => (int) $ligne['produit_id'], $lignes)));
        $produits = ProduitTenant::query()->whereIn('id', $ids)->with('codesBarres')->get()->keyBy('id');
        $prix = in_array('prix', $format->contenu, true) ? $this->prix($format, $produits, $options) : [];
        $disposition = $this->disposition($format);

        // Dessins et textes calculés une fois par code et par produit, quel que soit le nombre d'exemplaires.
        $images = [];
        $textes = [];
        $etiquettes = [];
        foreach ($lignes as $rang => $ligne) {
            $produit = $produits->get((int) $ligne['produit_id']);
            if (! $produit) {
                throw ValidationException::withMessages(["lignes.{$rang}.produit_id" => __('Produit introuvable.')]);
            }
            [$code, $type] = $this->code($produit, $ligne['code_barre_id'] ?? null, $rang);
            if (! array_key_exists($code, $images)) {
                $images[$code] = $this->image($code, $type);
            }
            $cle = $produit->id.'|'.$code;
            $textes[$cle] ??= $this->textes($format, $disposition, $produit, $code, $prix[$produit->id] ?? null);
            $etiquette = [
                'description' => (string) $produit->description,
                'numero' => (string) $produit->numero,
                'code' => $code,
                'image' => $images[$code],
                'prix' => $prix[$produit->id] ?? null,
                'texte' => $textes[$cle],
            ];
            for ($copie = 0; $copie < (int) $ligne['copies']; $copie++) {
                $etiquettes[] = $etiquette;
            }
        }

        return $etiquettes;
    }

    /**
     * Les textes d'une étiquette, ajustés à leur place : lignes de la description (coupées entre les mots), chiffres du
     * code, numéro arrêté avant le prix (absent s'il répète les chiffres du code), taille du prix (réduite s'il est long).
     *
     * @return array{description: list<string>, code: string, numero: ?string, taille_prix: int}
     */
    private function textes(EtiquetteFormat $format, array $disposition, ProduitTenant $produit, string $code, ?string $prix): array
    {
        $a = fn (string $element) => in_array($element, $format->contenu, true);
        $police = $disposition['police'];
        $interieur = $disposition['interieur'];
        $taillePrix = $police + 3;
        while ($prix !== null && $taillePrix > $police && $this->largeur($prix, $taillePrix, true) > $interieur) {
            $taillePrix--;
        }
        $largeurPrix = $prix !== null && $a('prix') ? $this->largeur($prix, $taillePrix, true) + 1.0 : 0.0;
        $numero = (string) $produit->numero;

        return [
            'description' => $a('description')
                ? $this->lignes((string) $produit->description, $disposition['lignes_description'], $interieur, $police, true)
                : [],
            'code' => $this->ajuster($code, $interieur, $police - 1, false, self::ESPACEMENT_CHIFFRES),
            'numero' => $a('numero') && ! ($a('chiffres') && $numero === $code)
                ? $this->ajuster($numero, max(0.0, $interieur - $largeurPrix), $police - 1, false)
                : null,
            'taille_prix' => $taillePrix,
        ];
    }

    /** Largeur d'un texte dans la police des étiquettes, en millimètres (métriques de DomPDF). */
    private function largeur(string $texte, float $points, bool $gras, float $espacement = 0.0): float
    {
        $this->metriques ??= app('dompdf.wrapper')->getDomPDF()->getFontMetrics();
        $police = $this->metriques->getFont(self::POLICE, $gras ? 'bold' : 'normal');
        if ($police === null) {
            // Police introuvable : environ 0,6 em par caractère.
            return mb_strlen($texte) * $points * 0.3528 * ($gras ? 0.65 : 0.6);
        }

        return $this->metriques->getTextWidth($texte, $police, $points, 0.0, $espacement) / self::POINTS_PAR_MM;
    }

    /** Le texte sur une ligne, coupé (points de suspension) s'il ne tient pas dans la largeur. */
    private function ajuster(string $texte, float $largeur, float $points, bool $gras, float $espacement = 0.0): string
    {
        $texte = trim((string) preg_replace('/\s+/u', ' ', $texte));
        if ($this->largeur($texte, $points, $gras, $espacement) <= $largeur) {
            return $texte;
        }
        // Le plus long début qui tient avec « … ».
        [$bas, $haut] = [0, mb_strlen($texte) - 1];
        while ($bas < $haut) {
            $milieu = intdiv($bas + $haut + 1, 2);
            if ($this->largeur(rtrim(mb_substr($texte, 0, $milieu)).'…', $points, $gras, $espacement) <= $largeur) {
                $bas = $milieu;
            } else {
                $haut = $milieu - 1;
            }
        }

        return $bas === 0 ? '' : rtrim(mb_substr($texte, 0, $bas)).'…';
    }

    /**
     * Le texte réparti sur au plus $nombre lignes, coupées entre les mots ; la dernière prend le reste, coupé au besoin.
     *
     * @return list<string>
     */
    private function lignes(string $texte, int $nombre, float $largeur, float $points, bool $gras): array
    {
        $mots = preg_split('/\s+/u', trim($texte), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lignes = [];
        $courante = '';
        foreach ($mots as $rang => $mot) {
            $essai = $courante === '' ? $mot : "{$courante} {$mot}";
            if ($courante === '' || $this->largeur($essai, $points, $gras) <= $largeur) {
                $courante = $essai;

                continue;
            }
            if (count($lignes) === $nombre - 1) {
                // Plus de ligne après celle-ci : elle porte la suite du texte.
                $courante = implode(' ', [$courante, ...array_slice($mots, $rang)]);
                break;
            }
            $lignes[] = $courante;
            $courante = $mot;
        }
        if ($courante !== '') {
            $lignes[] = $courante;
        }

        return array_map(fn (string $ligne) => $this->ajuster($ligne, $largeur, $points, $gras), array_slice($lignes, 0, max(1, $nombre)));
    }

    /**
     * Code imprimé et son type : le code-barres choisi (du produit), sinon le principal, sinon le numéro du produit.
     *
     * @return array{string, string}
     */
    private function code(ProduitTenant $produit, mixed $codeBarreId, int $rang): array
    {
        if ($codeBarreId) {
            $choisi = $produit->codesBarres->firstWhere('id', (int) $codeBarreId);
            if (! $choisi) {
                throw ValidationException::withMessages(["lignes.{$rang}.code_barre_id" => __("Ce code-barres n'est pas celui du produit :numero.", ['numero' => $produit->numero])]);
            }

            return [$choisi->code, $choisi->type];
        }
        $principal = $produit->codesBarres->first();

        return $principal ? [$principal->code, $principal->type] : [(string) $produit->numero, CodesBarres::detecter((string) $produit->numero)];
    }

    /** Le code-barres dessiné, ou null pour un code qu'aucune symbologie n'écrit (caractères accentués) : le texte reste. */
    private function image(string $code, string $type): ?string
    {
        try {
            return SvgCodeBarre::dataUri($code, $type);
        } catch (InvalidArgumentException) {
            try {
                return SvgCodeBarre::dataUri($code, 'code128');
            } catch (InvalidArgumentException) {
                return null;
            }
        }
    }

    /**
     * Prix affiché de chaque produit : celui de la liste de prix (prix saisi, sinon prix de base ajusté), sinon le prix
     * de base ; taxes comprises si le format le demande.
     *
     * @return array<int, string> id du produit => prix mis en forme
     */
    private function prix(EtiquetteFormat $format, Collection $produits, array $options): array
    {
        $liste = null;
        if (! empty($options['liste_prix_id'])) {
            $liste = ListePrix::query()->find($options['liste_prix_id']);
            if (! $liste) {
                throw ValidationException::withMessages(['liste_prix_id' => __('Liste de prix introuvable.')]);
            }
        }
        $saisis = $liste
            ? ListePrixProduit::query()->where('liste_prix_id', $liste->id)->whereIn('produit_id', $produits->keys())->pluck('prix', 'produit_id')
            : collect();
        $groupe = null;
        if (in_array('prix_taxes', $format->contenu, true)) {
            $groupe = ! empty($options['groupe_taxe_id'])
                ? GroupeTaxe::query()->find($options['groupe_taxe_id'])
                : GroupeTaxe::query()->where('par_defaut', true)->where('actif', true)->first();
            if (! empty($options['groupe_taxe_id']) && ! $groupe) {
                throw ValidationException::withMessages(['groupe_taxe_id' => __('Groupe de taxes introuvable.')]);
            }
        }

        $anglais = ($options['langue'] ?? 'fr') === 'en';
        $devise = $this->devises->reference();
        $decimales = MontantEnLettres::decimales($devise);
        $symbole = $this->devises->symbole(null);
        $suffixe = $groupe ? ($anglais ? ' incl. tax' : ' TTC') : '';
        $resultat = [];
        foreach ($produits as $produit) {
            $base = $produit->prix_unitaire === null ? null : (float) $produit->prix_unitaire;
            $montant = $liste ? $liste->prixPour($base, isset($saisis[$produit->id]) ? (float) $saisis[$produit->id] : null) : $base;
            if ($montant === null) {
                continue;
            }
            if ($groupe) {
                $montant = $this->taxes->calculer($groupe, $montant)['montant_ttc'];
            }
            $chiffres = $anglais ? number_format($montant, $decimales, '.', ',') : number_format($montant, $decimales, ',', ' ');
            $resultat[$produit->id] = "{$chiffres} {$symbole}{$suffixe}";
        }

        return $resultat;
    }

    /**
     * Le PDF des étiquettes : une page par étiquette sur un rouleau ; sur une planche, la grille remplie ligne par ligne
     * à partir de la position de départ (1 : en haut à gauche).
     *
     * @param  array{liste_prix_id?: ?int, groupe_taxe_id?: ?int, langue?: ?string, depart?: ?int, contours?: ?bool}  $options
     */
    public function pdf(EtiquetteFormat $format, array $lignes, array $options = []): DocumentPdf
    {
        // Les textes sont mesurés avec les polices du document qui les dessinera.
        $document = app('dompdf.wrapper');
        $this->metriques = $document->getDomPDF()->getFontMetrics();
        $etiquettes = $this->etiquettes($format, $lignes, $options);
        $parPage = $format->parPage();
        $depart = $format->support === 'planche' ? (int) ($options['depart'] ?? 1) : 1;
        if ($depart < 1 || $depart > $parPage) {
            throw ValidationException::withMessages(['depart' => __('La position de départ va de 1 à :maximum.', ['maximum' => $parPage])]);
        }

        $pages = [];
        foreach ($etiquettes as $rang => $etiquette) {
            $position = $rang + $depart - 1;
            $place = $position % $parPage;
            $pages[intdiv($position, $parPage)][] = [
                'x' => round($format->marge_gauche_mm + ($place % $format->colonnes) * ($format->largeur_mm + $format->espace_colonnes_mm), 2),
                'y' => round($format->marge_haut_mm + intdiv($place, $format->colonnes) * ($format->hauteur_mm + $format->espace_rangees_mm), 2),
                'etiquette' => $etiquette,
            ];
        }
        [$largeurPage, $hauteurPage] = $format->support === 'planche'
            ? self::PAGES[$format->page] ?? self::PAGES['A4']
            : [$format->largeur_mm, $format->hauteur_mm];
        $disposition = $this->disposition($format);

        return $document->loadView('pdf.etiquettes', [
            'format' => $format,
            'a' => array_fill_keys($format->contenu, true),
            'pages' => array_values($pages),
            'disposition' => $disposition,
            'largeurPage' => $largeurPage,
            'hauteurPage' => $hauteurPage,
            'blanc' => self::BLANC_CODE,
            'espacement' => self::ESPACEMENT_CHIFFRES,
            'entreprise' => $this->ajuster(EntreprisePdf::entete()['entreprise']['nom'], $disposition['interieur'], $disposition['police'] - 1, true),
            'date' => ($options['langue'] ?? 'fr') === 'en' ? now()->format('Y-m-d') : now()->format('d/m/Y'),
            'contours' => (bool) ($options['contours'] ?? false),
        ])
            ->setPaper([0, 0, round($largeurPage * self::POINTS_PAR_MM, 2), round($hauteurPage * self::POINTS_PAR_MM, 2)])
            ->setOption('isFontSubsettingEnabled', true);
    }

    /** Nom du fichier : le format et le nombre d'étiquettes. */
    public function nomFichier(EtiquetteFormat $format, int $nombre): string
    {
        return 'etiquettes-'.Str::slug($format->nom).'-'.$nombre.'.pdf';
    }
}
