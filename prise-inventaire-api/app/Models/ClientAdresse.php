<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Adresse supplémentaire d'un client (chantier, succursale, entrepôt) ; une seule principale par client. */
class ClientAdresse extends Model
{
    use BelongsToTenant;

    protected $table = 'client_adresses';

    protected $fillable = [
        'client_id', 'libelle', 'adresse', 'ville', 'code_postal', 'region', 'pays',
        'livraison', 'principale', 'instructions', 'actif',
    ];

    protected $casts = [
        'livraison' => 'boolean',
        'principale' => 'boolean',
        'actif' => 'boolean',
    ];
}
