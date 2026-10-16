<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Écriture comptable en partie double : ses lignes débitent et créditent des comptes pour un même total. */
class EcritureComptable extends Model
{
    use BelongsToTenant;

    public const MANUELLE = 'manuelle';

    public const OUVERTURE = 'ouverture';

    public const CLOTURE = 'cloture';

    public const RECURRENTE = 'recurrente';

    protected $table = 'ecritures_comptables';

    protected $fillable = [
        'journal_id', 'exercice_id', 'periode_id', 'numero', 'date', 'libelle', 'reference', 'source', 'source_id',
        'contrepassation_de', 'total', 'creee_par',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'total' => 'decimal:2',
    ];

    public function lignes(): HasMany
    {
        return $this->hasMany(EcritureComptableLigne::class, 'ecriture_id')->orderBy('ordre');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalComptable::class, 'journal_id');
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(PeriodeComptable::class, 'periode_id');
    }

    public function exercice(): BelongsTo
    {
        return $this->belongsTo(ExerciceComptable::class, 'exercice_id');
    }

    public function createur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'creee_par');
    }

    public function origine(): BelongsTo
    {
        return $this->belongsTo(self::class, 'contrepassation_de');
    }
}
