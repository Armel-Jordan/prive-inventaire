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
    ];

    protected function casts(): array
    {
        return [
            'prix_unitaire' => 'decimal:4',
            'actif' => 'boolean',
        ];
    }
}
