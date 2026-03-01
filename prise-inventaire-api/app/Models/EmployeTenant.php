<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeTenant extends Model
{
    protected $connection = 'mysql';
    protected $table = 'employes';

    protected $fillable = [
        'numero',
        'nom',
        'prenom',
        'email',
        'actif',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }
}
