<?php

namespace App\Services;

use App\Models\BonLivraison;
use App\Models\ComClientEntete;
use App\Models\ComFourEntete;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\Reception;
use App\Models\StatutPersonnalise;
use App\Support\TenantRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Statuts personnalisés des documents (plan de parité fripv2, phase 1.11c) : une étiquette libre de repérage, en
 * couleur, sans effet sur le cycle du document.
 *
 * - Une seule étiquette par document, choisie parmi celles de son type de document ou « autre » (tous les documents).
 * - Elle se pose ou se retire même sur un document clos (livré, payé, annulé…), avec le droit de modifier le document.
 * - Une étiquette désactivée n'est plus proposée, mais reste sur les documents qui la portent.
 */
class StatutsPersonnalises
{
    /** Type de document (celui du référentiel) => modèle. */
    public const DOCUMENTS = [
        'devis' => Devis::class,
        'commande_client' => ComClientEntete::class,
        'facture' => Facture::class,
        'bon_livraison' => BonLivraison::class,
        'commande_fournisseur' => ComFourEntete::class,
        'reception' => Reception::class,
    ];

    /** Colonnes de l'étiquette renvoyées avec les documents. */
    public const COLONNES = 'statutPersonnalise:id,libelle,couleur,couleur_texte,actif';

    /** Règle : une étiquette active de l'entreprise, de ce type de document ou « autre » (l'actuelle reste acceptée). */
    public static function regle(string $document, ?int $actuel = null): array
    {
        return ['nullable', 'integer', TenantRule::exists('statuts_personnalises')->where(fn ($q) => $q
            ->whereIn('document', [$document, 'autre'])->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('actif', true)->when($actuel, fn ($q, $id) => $q->orWhere('id', $id))))];
    }

    /** Pose (ou retire, avec null) l'étiquette d'un document, quel que soit son statut. */
    public function poser(Model $document, ?int $statutId): ?StatutPersonnalise
    {
        $document->forceFill(['statut_personnalise_id' => $statutId])->save();

        return $statutId ? StatutPersonnalise::withTrashed()->find($statutId, ['id', 'libelle', 'couleur', 'couleur_texte', 'actif']) : null;
    }
}
