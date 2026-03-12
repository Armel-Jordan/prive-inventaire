<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeTenant extends Model
{
    protected $connection = 'mysql';
    protected $table = 'employes';

    protected $fillable = [
        'tenant_id',
        'numero',
        'nom',
        'prenom',
        'email',
        'actif',
        'photo',
        'sexe',
        'date_naissance',
        'telephone',
        'adresse',
        'ville',
        'code_postal',
        'pays',
        'poste',
        'departement',
        'date_embauche',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }
}
