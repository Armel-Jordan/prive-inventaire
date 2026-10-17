<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lot de virements aux fournisseurs transmis à la banque : le fichier (SEPA ou CSV), gardé tel quel, et ses déboursés. */
class VirementLot extends Model
{
    use BelongsToTenant;

    public const EMIS = 'emis';

    public const REFUSE = 'refuse';

    protected $table = 'virements_lots';

    protected $fillable = [
        'tenant_id', 'compte_bancaire_id', 'numero', 'format', 'date_execution', 'devise', 'nombre', 'total', 'fichier', 'contenu',
        'statut', 'motif_refus', 'refuse_le', 'refuse_par', 'cree_par',
    ];

    /** Le contenu du fichier ne part qu'au téléchargement. */
    protected $hidden = ['contenu'];

    protected $casts = [
        'date_execution' => 'date',
        'total' => 'decimal:2',
        'refuse_le' => 'datetime',
    ];

    public function compteBancaire(): BelongsTo
    {
        return $this->belongsTo(CompteBancaire::class)->withTrashed();
    }

    public function debourses(): HasMany
    {
        return $this->hasMany(Debourse::class, 'virement_lot_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'cree_par');
    }

    public function auteurRefus(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'refuse_par');
    }
}
