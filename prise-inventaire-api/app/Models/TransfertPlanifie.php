<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransfertPlanifie extends Model
{
    protected $connection = 'mysql';
    protected $table = 'transferts_planifies';

    protected $fillable = [
        'type',
        'produit_numero',
        'produit_nom',
        'secteur_source',
        'secteur_destination',
        'quantite',
        'unite_mesure',
        'motif',
        'employe',
        'date_planifiee',
        'statut',
        'cree_par',
        'execute_le',
        'execute_par',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date_planifiee' => 'datetime',
            'execute_le' => 'datetime',
            'quantite' => 'decimal:3',
        ];
    }

    public function isPlanifie(): bool
    {
        return $this->statut === 'planifie';
    }

    public function isExecute(): bool
    {
        return $this->statut === 'execute';
    }

    public function isAnnule(): bool
    {
        return $this->statut === 'annule';
    }
}
