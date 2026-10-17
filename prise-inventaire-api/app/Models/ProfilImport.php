<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Réglages d'un import mémorisés sous un nom (colonnes du fichier, ligne de début…), réutilisés aux imports suivants. */
class ProfilImport extends Model
{
    use BelongsToTenant;

    public const TYPES = ['prix_fournisseur'];

    protected $table = 'profils_import';

    protected $fillable = ['tenant_id', 'type', 'nom', 'reglages', 'cree_par'];

    protected $casts = [
        'reglages' => 'array',
    ];
}
