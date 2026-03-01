<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProduitTenant extends Model
{
    protected $connection = 'mysql';
    protected $table = 'produits';

    protected $fillable = [
        'numero',
        'description',
        'mesure',
        'type',
        'categorie',
        'prix_unitaire',
        'actif',
        'seuil_alerte',
    ];

    protected function casts(): array
    {
        return [
            'prix_unitaire' => 'decimal:4',
            'actif' => 'boolean',
            'seuil_alerte' => 'decimal:2',
        ];
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
}
