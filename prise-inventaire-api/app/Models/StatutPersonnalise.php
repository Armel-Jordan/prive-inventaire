<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Statut de suivi propre à l'entreprise, par type de document, avec sa couleur d'affichage. */
class StatutPersonnalise extends Model
{
    use BelongsToTenant, SoftDeletes;

    const DOCUMENTS = ['devis', 'commande_client', 'bon_livraison', 'facture', 'commande_fournisseur', 'reception', 'autre'];

    protected $table = 'statuts_personnalises';

    protected $fillable = [
        'tenant_id', 'document', 'code', 'libelle', 'couleur', 'ordre', 'actif',
    ];

    protected $casts = [
        'ordre' => 'integer',
        'actif' => 'boolean',
    ];
}
