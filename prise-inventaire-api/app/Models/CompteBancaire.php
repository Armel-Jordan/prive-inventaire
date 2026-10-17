<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Compte bancaire de l'entreprise (coordonnées affichées sur les documents, paiements). Relié à son compte comptable
 * et à son journal (Finance → Banque), il reçoit les mouvements des encaissements, des déboursés et des transactions
 * bancaires qui passent par lui.
 */
class CompteBancaire extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'comptes_bancaires';

    protected $fillable = [
        'tenant_id', 'nom', 'banque', 'agence', 'titulaire', 'numero_compte',
        'iban', 'bic', 'devise_code', 'par_defaut', 'actif', 'notes', 'compte_comptable_id', 'journal_id',
        // Prochain numéro du chéquier : les chèques des déboursés le prennent à leur première impression.
        'prochain_cheque',
        // Virements aux fournisseurs : dernier format de fichier, identifiant d'émetteur, prochain numéro de lot.
        'virement_format', 'virement_emetteur', 'prochain_lot',
        // Colonnes du CSV de la banque, mémorisées au dernier import de relevé.
        'import_reglages',
    ];

    protected $casts = [
        'par_defaut' => 'boolean',
        'actif' => 'boolean',
        'import_reglages' => 'array',
    ];

    /** Compte comptable de trésorerie qui porte les mouvements du compte bancaire. */
    public function compteComptable(): BelongsTo
    {
        return $this->belongsTo(CompteComptable::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalComptable::class);
    }
}
