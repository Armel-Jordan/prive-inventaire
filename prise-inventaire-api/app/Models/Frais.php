<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Frais additionnel ou de livraison, proposé comme une ligne de son produit sur les devis, commandes et factures :
 * montant fixe ou pourcentage, sur tout document, sur ceux qui contiennent ses produits, ou comme frais de livraison
 * d'un client.
 */
class Frais extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const MONTANT = 'montant';

    public const POURCENTAGE = 'pourcentage';

    public const MODES = [self::MONTANT, self::POURCENTAGE];

    /** Tout document qui contient des produits. */
    public const DOCUMENT = 'document';

    /** Seulement les documents qui contiennent un produit associé au frais. */
    public const PRODUITS = 'produits';

    /** Frais de livraison, choisi dans la fiche d'un client. */
    public const LIVRAISON = 'livraison';

    public const APPLICATIONS = [self::DOCUMENT, self::PRODUITS, self::LIVRAISON];

    /** Une fois par document, ou une fois par ligne concernée. */
    public const REPETITIONS = ['document', 'ligne'];

    protected $table = 'frais';

    protected $fillable = [
        'tenant_id', 'libelle', 'produit_id', 'mode', 'valeur', 'application', 'repetition',
        'sur_devis', 'sur_commandes', 'sur_factures', 'actif',
    ];

    protected $casts = [
        'valeur' => 'decimal:4',
        'sur_devis' => 'boolean',
        'sur_commandes' => 'boolean',
        'sur_factures' => 'boolean',
        'actif' => 'boolean',
    ];

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id')->withTrashed();
    }

    /** Produits qui déclenchent un frais « produits ». */
    public function produits(): BelongsToMany
    {
        return $this->belongsToMany(ProduitTenant::class, 'frais_produits', 'frais_id', 'produit_id');
    }
}
