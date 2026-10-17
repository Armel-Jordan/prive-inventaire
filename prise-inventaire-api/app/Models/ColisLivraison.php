<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Colis d'un bon de livraison (voir App\Services\ColisLivraisons). */
class ColisLivraison extends Model
{
    use BelongsToTenant;

    protected $table = 'bl_colis';

    protected $fillable = ['tenant_id', 'bon_livraison_id', 'numero', 'poids_kg', 'notes', 'prepare_par', 'charge_le', 'charge_par'];

    protected $casts = [
        'poids_kg' => 'decimal:3',
        'charge_le' => 'datetime',
    ];

    public function bonLivraison(): BelongsTo
    {
        return $this->belongsTo(BonLivraison::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(ColisLivraisonLigne::class, 'colis_id');
    }

    public function preparateur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'prepare_par');
    }

    /** Qui l'a coché comme chargé dans le camion. */
    public function chargeur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'charge_par');
    }
}
