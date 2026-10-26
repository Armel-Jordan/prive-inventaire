<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class TenantParametres extends Model
{
    use BelongsToTenant;

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
        // Document de transport des matières dangereuses : téléphone d'urgence et déclaration (selon le pays).
        'md_telephone_urgence',
        'md_declaration',
        // Numéro de facture attribué à l'émission (un brouillon porte un numéro provisoire « BR-… »).
        'numero_facture_a_l_emission',
        // Transporteur exigé sur les devis, commandes, factures et commandes fournisseur saisis (phase 1.11b).
        'transporteur_obligatoire',
    ];

    protected $casts = [
        'tva_taux' => 'decimal:2',
        'delai_paiement_jours' => 'integer',
        'delai_livraison_jours' => 'integer',
        'stock_seuil_defaut' => 'integer',
        'numero_facture_a_l_emission' => 'boolean',
        'transporteur_obligatoire' => 'boolean',
    ];
}
