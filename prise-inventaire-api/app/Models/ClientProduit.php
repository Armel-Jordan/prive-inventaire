<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Référence d'un produit chez un client (phase 1.2c) : son numéro d'article et, au besoin, sa désignation. Reprise sur
 * les devis, commandes et factures du client, et dans son espace client.
 */
class ClientProduit extends Model
{
    use BelongsToTenant;

    protected $table = 'client_produits';

    protected $fillable = ['tenant_id', 'client_id', 'produit_id', 'reference', 'description'];

    protected $casts = [
        'client_id' => 'integer',
        'produit_id' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id');
    }

    /** Références d'un client par produit : id du produit => [reference, description]. */
    public static function pourClient(?int $clientId, array $produitIds = []): array
    {
        if (! $clientId) {
            return [];
        }

        return self::query()->where('client_id', $clientId)
            ->when($produitIds, fn ($q) => $q->whereIn('produit_id', $produitIds))
            ->get(['produit_id', 'reference', 'description'])
            ->mapWithKeys(fn (self $r) => [$r->produit_id => ['reference' => $r->reference, 'description' => $r->description]])
            ->all();
    }
}
