<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Suggestion d'achat : un produit à commander à un fournisseur, venue du stock sous son minimum, d'un reliquat client
 * ou d'un signalement manuel ; acceptée, elle devient une ligne de commande fournisseur.
 */
class SuggestionAchat extends Model
{
    use BelongsToTenant;

    public const EN_ATTENTE = 'en_attente';

    public const ACCEPTEE = 'acceptee';

    public const COMMANDEE = 'commandee';

    public const SOURCES = ['inventaire', 'reliquat', 'manuelle'];

    protected $table = 'suggestions_achat';

    protected $fillable = [
        'tenant_id', 'produit_id', 'fournisseur_id', 'source', 'reliquat_id', 'quantite_suggeree', 'quantite', 'statut', 'remarque',
        'commis_id', 'commis_nom', 'com_four_ligne_id',
    ];

    protected $casts = [
        'quantite_suggeree' => 'integer',
        'quantite' => 'integer',
    ];

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id')->withTrashed();
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class);
    }

    public function reliquat(): BelongsTo
    {
        return $this->belongsTo(ReliquatClient::class, 'reliquat_id');
    }

    public function ligneCommande(): BelongsTo
    {
        return $this->belongsTo(ComFourLigne::class, 'com_four_ligne_id');
    }
}
