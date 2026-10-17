<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProduitTenant extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $connection = 'mysql';

    protected $table = 'produits';

    protected $fillable = [
        'tenant_id',
        'numero',
        'description',
        'mesure',
        'unite_achat',
        'qte_par_unite_achat',
        'type',
        'secteur_id',
        'categorie',
        'prix_unitaire',
        'actif',
        'seuil_alerte',
        // Quantité visée par le réassort (suggestions d'achat) ; le seuil d'alerte sert de minimum.
        'stock_maximum',
        'fournisseur_principal_id',
        // Transport : matière dangereuse (numéro ONU, classe, groupe d'emballage), poids et volume unitaires.
        'numero_onu',
        'classe_danger',
        'groupe_emballage',
        'poids_kg',
        'volume_m3',
    ];

    protected function casts(): array
    {
        return [
            'prix_unitaire' => 'decimal:4',
            'actif' => 'boolean',
            'seuil_alerte' => 'decimal:2',
            'stock_maximum' => 'decimal:2',
            'qte_par_unite_achat' => 'integer',
            'poids_kg' => 'decimal:4',
            'volume_m3' => 'decimal:6',
        ];
    }

    /** Matière dangereuse : numéro ONU ou classe de danger renseignés (et pas seulement des zéros), comme dans fripv2. */
    public function estDangereux(): bool
    {
        return (bool) preg_match('/[1-9]/', (string) $this->numero_onu.(string) $this->classe_danger);
    }

    /**
     * Vérifie si le stock est en alerte (sous le seuil)
     */
    public function isEnAlerte(float $quantiteActuelle): bool
    {
        if ($this->seuil_alerte === null || $this->seuil_alerte <= 0) {
            return false;
        }

        return $quantiteActuelle < $this->seuil_alerte;
    }

    public function secteur(): BelongsTo
    {
        return $this->belongsTo(Secteur::class);
    }

    public function fournisseurPrincipal(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_principal_id');
    }

    public function fournisseurs(): BelongsToMany
    {
        return $this->belongsToMany(Fournisseur::class, 'produit_fournisseur', 'produit_id', 'fournisseur_id')
            ->withPivot(['reference_fournisseur', 'prix_achat', 'delai_livraison', 'fournisseur_principal'])
            ->withTimestamps();
    }
}
