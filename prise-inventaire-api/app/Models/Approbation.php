<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Approbation extends Model
{
    use SoftDeletes;

    protected $connection = 'mysql';
    protected $table = 'approbations';

    protected $fillable = [
        'type_mouvement',
        'produit_numero',
        'produit_nom',
        'secteur_source',
        'secteur_destination',
        'quantite',
        'unite_mesure',
        'motif',
        'demandeur',
        'statut',
        'approbateur',
        'date_decision',
        'commentaire_approbateur',
        'seuil_declenchement',
    ];

    protected function casts(): array
    {
        return [
            'date_decision' => 'datetime',
            'quantite' => 'decimal:3',
            'seuil_declenchement' => 'decimal:3',
        ];
    }

    public function isEnAttente(): bool
    {
        return $this->statut === 'en_attente';
    }

    public function isApprouve(): bool
    {
        return $this->statut === 'approuve';
    }

    public function isRejete(): bool
    {
        return $this->statut === 'rejete';
    }
}
