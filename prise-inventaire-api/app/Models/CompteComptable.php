<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Compte du plan comptable de l'entreprise. La nature place le compte dans les états financiers (bilan :
 * actif, passif, capitaux propres ; résultat : produits, charges) ; le type marque les comptes particuliers
 * (en-tête de regroupement, clients, fournisseurs, trésorerie, résultat de l'exercice).
 */
class CompteComptable extends Model
{
    use BelongsToTenant;

    public const NATURES = ['actif', 'passif', 'capitaux_propres', 'produit', 'charge'];

    public const TYPES = ['standard', 'en_tete', 'clients', 'fournisseurs', 'tresorerie', 'resultat'];

    /** Compte de regroupement : il cumule ses sous-comptes et ne reçoit pas d'écriture. */
    public const EN_TETE = 'en_tete';

    /** Compte qui reçoit le résultat à la clôture de l'exercice (un seul par entreprise). */
    public const RESULTAT = 'resultat';

    protected $table = 'comptes_comptables';

    protected $fillable = ['numero', 'libelle', 'parent_id', 'nature', 'type', 'saisie_manuelle', 'actif', 'notes'];

    protected $casts = [
        'saisie_manuelle' => 'boolean',
        'actif' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function enfants(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Le compte peut-il recevoir des écritures ? Les en-têtes ne servent qu'à regrouper. */
    public function estImputable(): bool
    {
        return $this->type !== self::EN_TETE;
    }
}
