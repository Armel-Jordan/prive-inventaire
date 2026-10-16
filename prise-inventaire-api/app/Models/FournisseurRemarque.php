<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Remarque datée sur un fournisseur (appel, litige, consigne) ; les remarques épinglées passent en tête. */
class FournisseurRemarque extends Model
{
    use BelongsToTenant;

    protected $table = 'fournisseur_remarques';

    protected $fillable = ['fournisseur_id', 'auteur_id', 'texte', 'epinglee'];

    protected $casts = ['epinglee' => 'boolean'];

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'auteur_id');
    }
}
