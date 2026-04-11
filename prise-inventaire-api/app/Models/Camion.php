<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Camion extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'immatriculation',
        'marque',
        'modele',
        'type',
        'capacite_kg',
        'capacite_m3',
        'date_controle_technique',
        'actif',
    ];

    protected $casts = [
        'capacite_kg' => 'integer',
        'capacite_m3' => 'decimal:2',
        'date_controle_technique' => 'date',
        'actif' => 'boolean',
    ];

    public function tournees(): HasMany
    {
        return $this->hasMany(Tournee::class);
    }

    public function produitLocalisations(): HasMany
    {
        return $this->hasMany(ProduitLocalisation::class, 'localisation_id')
            ->where('localisation_type', 'camion');
    }

    public function estDisponible(string $date): bool
    {
        return ! $this->tournees()
            ->where('date_tournee', $date)
            ->whereIn('statut', ['planifiee', 'en_cours'])
            ->exists();
    }
}
