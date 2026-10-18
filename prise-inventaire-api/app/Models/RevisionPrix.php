<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Révision de prix (phase 1.2d) : de nouveaux prix de base et de listes, préparés en lot puis appliqués tout de suite
 * ou à la date d'entrée en vigueur (tâche planifiée « revisions_prix »). Une révision appliquée ne l'est qu'une fois
 * et reste comme historique des prix.
 */
class RevisionPrix extends Model
{
    use BelongsToTenant;

    public const BROUILLON = 'brouillon';

    public const PLANIFIEE = 'planifiee';

    public const APPLIQUEE = 'appliquee';

    public const ANNULEE = 'annulee';

    /** Prix visés par un ajustement : le prix de base, les prix saisis d'une liste, ou les deux sur toutes les listes. */
    public const CIBLES = ['base', 'liste', 'toutes'];

    public const TYPES_AJUSTEMENT = ['pourcentage', 'montant'];

    protected $table = 'revisions_prix';

    protected $fillable = [
        'nom', 'notes', 'date_effet', 'statut', 'categorie', 'cible', 'liste_prix_id', 'type_ajustement', 'valeur_ajustement',
        'cree_par', 'modifie_par', 'planifiee_par', 'planifiee_le', 'appliquee_par', 'appliquee_le', 'annulee_par', 'annulee_le',
    ];

    protected $casts = [
        'date_effet' => 'date:Y-m-d',
        'liste_prix_id' => 'integer',
        'valeur_ajustement' => 'float',
        'planifiee_le' => 'datetime',
        'appliquee_le' => 'datetime',
        'annulee_le' => 'datetime',
    ];

    public function lignes(): HasMany
    {
        return $this->hasMany(RevisionPrixLigne::class);
    }

    public function listePrix(): BelongsTo
    {
        return $this->belongsTo(ListePrix::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'cree_par');
    }

    public function auteurPlanification(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'planifiee_par');
    }

    public function auteurApplication(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'appliquee_par');
    }

    public function auteurAnnulation(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'annulee_par');
    }

    /** Brouillon ou planifiée : ses lignes se modifient encore, elle peut être appliquée ou annulée. */
    public function enCours(): bool
    {
        return in_array($this->statut, [self::BROUILLON, self::PLANIFIEE], true);
    }
}
