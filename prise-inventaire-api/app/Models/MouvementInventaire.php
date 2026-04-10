<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MouvementInventaire extends Model
{
    protected $table = 'mouvement_inventaire';

    protected $fillable = [
        'tenant_id',
        'scan_id',
        'type_mouvement',
        'quantite_avant',
        'quantite_apres',
        'motif',
        'utilisateur',
        'date_mouvement',
    ];

    protected function casts(): array
    {
        return [
            'quantite_avant' => 'decimal:4',
            'quantite_apres' => 'decimal:4',
            'date_mouvement' => 'datetime',
        ];
    }
}
