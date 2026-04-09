<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComFourLigne extends Model
{
    use HasFactory;

    protected $table = 'com_four_ligne';

    protected $fillable = [
        'com_four_entete_id',
        'produit_id',
        'quantite_commandee',
        'quantite_recue',
        'unite_achat',
        'qte_par_unite_achat',
        'prix_unitaire',
        'montant_ligne',
    ];

    protected $casts = [
        'quantite_commandee' => 'integer',
        'quantite_recue' => 'integer',
        'qte_par_unite_achat' => 'integer',
        'prix_unitaire' => 'decimal:2',
        'montant_ligne' => 'decimal:2',
    ];

    public function commande(): BelongsTo
    {
        return $this->belongsTo(ComFourEntete::class, 'com_four_entete_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id');
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(ReceptionArrivagesLigne::class, 'com_four_ligne_id');
    }

    public function getQuantiteRestanteAttribute(): int
    {
        return $this->quantite_commandee - $this->quantite_recue;
    }

    public function isComplete(): bool
    {
        return $this->quantite_recue >= $this->quantite_commandee;
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($ligne) {
            $ligne->montant_ligne = $ligne->quantite_commandee * $ligne->prix_unitaire;
        });

        static::saved(function ($ligne) {
            $ligne->commande->recalculerMontantTotal();
        });

        static::deleted(function ($ligne) {
            $ligne->commande->recalculerMontantTotal();
        });
    }
}
