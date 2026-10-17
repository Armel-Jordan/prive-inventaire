<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Récurrence d'une écriture manuelle : copie de son journal, de son libellé et de ses lignes, et dates planifiées où
 * elle se répète (loyer, amortissement…). Chaque date se convertit en écriture, qui porte le numéro de la récurrence.
 */
class RecurrenceComptable extends Model
{
    use BelongsToTenant;

    public const FREQUENCES = ['journaliere', 'hebdomadaire', 'quatorze_jours', 'mensuelle', 'trimestrielle', 'semestrielle', 'annuelle'];

    protected $table = 'recurrences_comptables';

    protected $fillable = [
        'tenant_id', 'numero', 'ecriture_modele_id', 'journal_id', 'libelle', 'reference', 'lignes', 'frequence', 'date_debut',
        'nombre', 'active', 'cree_par',
    ];

    protected $casts = [
        'lignes' => 'array',
        'date_debut' => 'date:Y-m-d',
        'nombre' => 'integer',
        'active' => 'boolean',
    ];

    public function echeances(): HasMany
    {
        return $this->hasMany(RecurrenceEcheance::class, 'recurrence_id')->orderBy('date_prevue');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalComptable::class, 'journal_id');
    }

    public function modele(): BelongsTo
    {
        return $this->belongsTo(EcritureComptable::class, 'ecriture_modele_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'cree_par');
    }
}
