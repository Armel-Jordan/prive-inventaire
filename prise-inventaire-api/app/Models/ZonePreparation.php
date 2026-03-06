<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ZonePreparation extends Model
{
    use HasFactory;

    protected $table = 'zones_preparation';

    protected $fillable = [
        'code',
        'nom',
        'description',
        'actif',
    ];

    protected $casts = [
        'actif' => 'boolean',
    ];

    public function produitLocalisations(): HasMany
    {
        return $this->hasMany(ProduitLocalisation::class, 'localisation_id')
            ->where('localisation_type', 'zone_preparation');
    }
}
