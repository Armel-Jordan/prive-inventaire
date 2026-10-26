<?php

namespace App\Support;

use App\Models\StatutDocumentReglage;
use Illuminate\Support\Facades\Cache;

/**
 * Statuts des documents (plan de parité fripv2, « Statuts transactions », docs/reste-a-faire/01 §19) : les statuts
 * système de chaque type de document, avec leur libellé (fr, en) et leurs couleurs (fond, texte) par défaut, que
 * l'entreprise peut changer. Seul l'affichage change : les codes et les cycles des documents restent ceux de
 * l'application (les ajouts passent par les statuts personnalisés).
 */
class StatutsDocuments
{
    /** Couleurs par défaut, celles des écrans avant ce réglage : [fond, texte]. */
    private const GRIS = ['#f3f4f6', '#1f2937'];

    private const GRIS_PALE = ['#f3f4f6', '#6b7280'];

    private const BLEU = ['#dbeafe', '#1e40af'];

    private const VERT = ['#dcfce7', '#166534'];

    private const ROUGE = ['#fee2e2', '#991b1b'];

    private const JAUNE = ['#fef9c3', '#854d0e'];

    private const VIOLET = ['#f3e8ff', '#6b21a8'];

    private const ORANGE = ['#ffedd5', '#9a3412'];

    /**
     * Document => code => [libellé fr, libellé en, [fond, texte]], dans l'ordre du cycle.
     *
     * @var array<string, array<string, array{0: string, 1: string, 2: array{0: string, 1: string}}>>
     */
    public const CATALOGUE = [
        'devis' => [
            'brouillon' => ['Brouillon', 'Draft', self::GRIS],
            'envoye' => ['Envoyé', 'Sent', self::BLEU],
            'accepte' => ['Accepté', 'Accepted', self::VERT],
            'refuse' => ['Refusé', 'Declined', self::ROUGE],
            'expire' => ['Expiré', 'Expired', self::JAUNE],
        ],
        'commande_client' => [
            'brouillon' => ['Brouillon', 'Draft', self::GRIS],
            'en_attente' => ['En attente', 'Pending', self::JAUNE],
            'acceptee' => ['Acceptée', 'Accepted', self::VERT],
            'refusee' => ['Refusée', 'Declined', self::ROUGE],
            'facturee' => ['Facturée', 'Invoiced', self::BLEU],
            'annulee' => ['Annulée', 'Cancelled', self::GRIS_PALE],
        ],
        'facture' => [
            'brouillon' => ['Brouillon', 'Draft', self::GRIS],
            'emise' => ['Émise', 'Issued', self::BLEU],
            'partiellement_payee' => ['Partiel', 'Partly paid', self::JAUNE],
            'payee' => ['Payée', 'Paid', self::VERT],
            'creditee' => ['Créditée', 'Credited', self::VIOLET],
            'annulee' => ['Annulée', 'Cancelled', self::ROUGE],
        ],
        'bon_livraison' => [
            'cree' => ['Créé', 'Created', self::GRIS],
            'en_preparation' => ['En préparation', 'Being prepared', self::JAUNE],
            'pret' => ['Prêt', 'Ready', self::BLEU],
            'en_livraison' => ['En livraison', 'Out for delivery', self::VIOLET],
            'livre_complet' => ['Livré', 'Delivered', self::VERT],
            'livre_partiel' => ['Partiel', 'Partly delivered', self::ORANGE],
            'annule' => ['Annulé', 'Cancelled', self::ROUGE],
        ],
        'commande_fournisseur' => [
            'brouillon' => ['Brouillon', 'Draft', self::GRIS],
            'envoyee' => ['Envoyée', 'Sent', self::BLEU],
            'partielle' => ['Partielle', 'Partly received', self::JAUNE],
            'complete' => ['Complète', 'Complete', self::VERT],
            'annulee' => ['Annulée', 'Cancelled', self::ROUGE],
        ],
    ];

    /**
     * Statuts de l'entreprise : le catalogue, avec ses réglages par-dessus (une minute en cache par entreprise).
     *
     * @return array<string, list<array{code: string, libelle_fr: string, libelle_en: string, couleur_fond: string, couleur_texte: string, regle: bool}>>
     */
    public function tous(): array
    {
        $tenant = app(TenantContext::class)->getTenantId();

        return Cache::remember("statuts-documents:{$tenant}", 60, function () {
            $reglages = StatutDocumentReglage::query()->get()->keyBy(fn (StatutDocumentReglage $r) => "{$r->document}:{$r->code}");
            $resultat = [];
            foreach (self::CATALOGUE as $document => $statuts) {
                foreach ($statuts as $code => [$fr, $en, [$fond, $texte]]) {
                    $reglage = $reglages->get("{$document}:{$code}");
                    $resultat[$document][] = [
                        'code' => $code,
                        'libelle_fr' => $reglage?->libelle_fr ?: $fr,
                        'libelle_en' => $reglage?->libelle_en ?: $en,
                        'couleur_fond' => $reglage?->couleur_fond ?: $fond,
                        'couleur_texte' => $reglage?->couleur_texte ?: $texte,
                        'regle' => $reglage !== null,
                        'defaut' => ['libelle_fr' => $fr, 'libelle_en' => $en, 'couleur_fond' => $fond, 'couleur_texte' => $texte],
                    ];
                }
            }

            return $resultat;
        });
    }

    public static function existe(string $document, string $code): bool
    {
        return isset(self::CATALOGUE[$document][$code]);
    }

    /** Les réglages viennent de changer : la liste de l'entreprise est recalculée. */
    public function oublier(): void
    {
        Cache::forget('statuts-documents:'.app(TenantContext::class)->getTenantId());
    }
}
