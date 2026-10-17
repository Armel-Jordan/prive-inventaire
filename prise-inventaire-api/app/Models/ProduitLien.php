<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lien entre deux produits (voir App\Services\LiensProduits) : composant d'un ensemble, annexe ajoutée d'office à la
 * vente, ou équivalent (substitut, dans les deux sens).
 */
class ProduitLien extends Model
{
    use BelongsToTenant;

    public const COMPOSANT = 'composant';

    public const ANNEXE = 'annexe';

    public const EQUIVALENT = 'equivalent';

    public const TYPES = [self::COMPOSANT, self::ANNEXE, self::EQUIVALENT];

    protected $table = 'produit_liens';

    protected $fillable = ['tenant_id', 'type', 'produit_id', 'lie_id', 'quantite', 'remarque'];

    protected $casts = [
        'quantite' => 'float',
    ];

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id');
    }

    /** Le produit lié (composant, annexe ou équivalent), même désactivé. */
    public function lie(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'lie_id')->withTrashed();
    }
}
