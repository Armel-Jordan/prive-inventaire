<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fournisseur extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'fournisseurs';

    protected $fillable = [
        'code',
        'raison_sociale',
        'adresse',
        'telephone',
        'email',
        'contact_nom',
        'contact_telephone',
        'conditions_paiement',
        'actif',
        'devise',
    ];

    protected $casts = [
        'actif' => 'boolean',
    ];

    public function produits(): BelongsToMany
    {
        return $this->belongsToMany(ProduitTenant::class, 'produit_fournisseur')
            ->withPivot(['reference_fournisseur', 'prix_achat', 'delai_livraison', 'fournisseur_principal'])
            ->withTimestamps();
    }

    public function commandes(): HasMany
    {
        return $this->hasMany(ComFourEntete::class, 'fournisseur_id');
    }

    public function historiquePrix(): HasMany
    {
        return $this->hasMany(HistoriquePrixAchat::class, 'fournisseur_id');
    }

    public static function generateCode(): string
    {
        $lastFournisseur = self::orderBy('id', 'desc')->first();
        $nextNumber = $lastFournisseur ? $lastFournisseur->id + 1 : 1;
        return 'FOUR-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
