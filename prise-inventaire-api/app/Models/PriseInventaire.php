<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Session de comptage (prise d'inventaire). Elle regroupe les scans de ses secteurs saisis pendant sa
 * période ; une fois validée, ses lignes forment l'inventaire officiel de ces secteurs.
 */
class PriseInventaire extends Model
{
    use BelongsToTenant;

    public const EN_COURS = 'en_cours';

    public const VALIDEE = 'validee';

    public const ANNULEE = 'annulee';

    protected $table = 'prises_inventaire';

    protected $fillable = [
        'numero', 'libelle', 'statut', 'tous_secteurs', 'date_debut', 'date_fin', 'notes',
        'creee_par', 'cloturee_par', 'cloturee_le',
    ];

    protected $casts = [
        'tous_secteurs' => 'boolean',
        'date_debut' => 'datetime',
        'date_fin' => 'datetime',
        'cloturee_le' => 'datetime',
    ];

    public function secteurs(): BelongsToMany
    {
        return $this->belongsToMany(Secteur::class, 'prises_inventaire_secteurs', 'prise_inventaire_id', 'secteur_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(PriseInventaireLigne::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(PriseInventaireCorrection::class);
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'creee_par');
    }

    public function cloturePar(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'cloturee_par');
    }

    public function scopeValidees(Builder $requete): Builder
    {
        return $requete->where('statut', self::VALIDEE);
    }

    public function couvre(int $secteurId): bool
    {
        return $this->tous_secteurs || $this->secteurs->contains('id', $secteurId);
    }
}
