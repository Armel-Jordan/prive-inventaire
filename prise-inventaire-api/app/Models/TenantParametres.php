<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantParametres extends Model
{
    protected $table = 'tenant_parametres';

    protected $fillable = [
        'tenant_id',
        'nom_entreprise',
        'adresse',
        'telephone',
        'email',
        'siret',
        'tva_numero',
        'logo_url',
        'devise_symbole',
        'devise_code',
        'tva_taux',
        'delai_paiement_jours',
        'delai_livraison_jours',
        'stock_alerte_email',
        'stock_seuil_defaut',
    ];

    protected $casts = [
        'tva_taux'              => 'decimal:2',
        'delai_paiement_jours'  => 'integer',
        'delai_livraison_jours' => 'integer',
        'stock_seuil_defaut'    => 'integer',
    ];
}
