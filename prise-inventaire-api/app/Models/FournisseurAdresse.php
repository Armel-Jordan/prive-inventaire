<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Adresse d'un fournisseur (siège, dépôt, lieu d'enlèvement) ; une seule adresse principale. */
class FournisseurAdresse extends Model
{
    use BelongsToTenant;

    protected $table = 'fournisseur_adresses';

    protected $fillable = [
        'fournisseur_id', 'libelle', 'adresse', 'ville', 'code_postal', 'region', 'pays',
        'enlevement', 'principale', 'instructions', 'actif',
    ];

    protected $casts = [
        'enlevement' => 'boolean',
        'principale' => 'boolean',
        'actif' => 'boolean',
    ];
}
