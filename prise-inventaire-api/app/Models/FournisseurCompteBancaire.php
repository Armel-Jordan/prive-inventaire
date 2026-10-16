<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Coordonnées bancaires d'un fournisseur (un seul compte principal). On garde qui les a saisies ou
 * modifiées en dernier : un changement de coordonnées est le premier signe d'une fraude au virement.
 */
class FournisseurCompteBancaire extends Model
{
    use BelongsToTenant;

    protected $table = 'fournisseur_comptes_bancaires';

    protected $fillable = [
        'fournisseur_id', 'banque', 'agence', 'titulaire', 'numero_compte', 'iban', 'bic', 'devise',
        'principal', 'notes', 'actif', 'modifie_par',
    ];

    protected $casts = [
        'principal' => 'boolean',
        'actif' => 'boolean',
    ];

    /** Auteur de la dernière saisie (relation nommée autrement que la colonne pour ne pas la masquer en JSON). */
    public function auteurModification(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'modifie_par');
    }
}
