<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Compte bancaire de l'entreprise (coordonnées affichées sur les documents, paiements). */
class CompteBancaire extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'comptes_bancaires';

    protected $fillable = [
        'tenant_id', 'nom', 'banque', 'agence', 'titulaire', 'numero_compte',
        'iban', 'bic', 'devise_code', 'par_defaut', 'actif', 'notes',
    ];

    protected $casts = [
        'par_defaut' => 'boolean',
        'actif' => 'boolean',
    ];
}
