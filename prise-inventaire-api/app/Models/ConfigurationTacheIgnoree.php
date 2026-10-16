<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Réglage recommandé écarté par l'entreprise (« ne nous concerne pas »), avec sa raison. */
class ConfigurationTacheIgnoree extends Model
{
    use BelongsToTenant;

    protected $table = 'configuration_taches_ignorees';

    protected $fillable = ['cle', 'raison', 'ignore_par'];

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'ignore_par');
    }
}
