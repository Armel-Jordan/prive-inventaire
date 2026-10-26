<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fournisseur extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

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
        // Transporteur qui livre ce fournisseur, repris par ses commandes.
        'transporteur_id',
    ];

    protected $casts = [
        'actif' => 'boolean',
        'transporteur_id' => 'integer',
    ];

    public function produits(): BelongsToMany
    {
        // Clés explicites : la table pivot porte « produit_id » (Laravel déduirait « produit_tenant_id »).
        return $this->belongsToMany(ProduitTenant::class, 'produit_fournisseur', 'fournisseur_id', 'produit_id')
            ->withPivot(['reference_fournisseur', 'prix_achat', 'delai_livraison', 'fournisseur_principal', 'unite_achat', 'qte_par_unite_achat'])
            ->withTimestamps();
    }

    /** Transporteur (même retiré du référentiel depuis). */
    public function transporteur(): BelongsTo
    {
        return $this->belongsTo(Transporteur::class)->withTrashed();
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(FournisseurContact::class)->orderByDesc('principal')->orderBy('nom');
    }

    public function adresses(): HasMany
    {
        return $this->hasMany(FournisseurAdresse::class)->orderByDesc('principale')->orderBy('libelle');
    }

    public function comptesBancaires(): HasMany
    {
        return $this->hasMany(FournisseurCompteBancaire::class)->orderByDesc('principal')->orderBy('banque');
    }

    public function remarques(): HasMany
    {
        return $this->hasMany(FournisseurRemarque::class)->orderByDesc('epinglee')->latest()->orderByDesc('id');
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

        return 'FOUR-'.str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
