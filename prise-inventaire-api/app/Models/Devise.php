<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Devise utilisée par l'entreprise. `taux` = valeur d'une unité de cette devise dans la
 * devise de référence du tenant (tenant_parametres.devise_code). Chaque taux est historisé.
 */
class Devise extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $table = 'devises';

    protected $fillable = [
        'tenant_id', 'code', 'libelle', 'symbole', 'decimales', 'taux', 'actif',
    ];

    protected $casts = [
        'decimales' => 'integer',
        'taux' => 'decimal:8',
        'actif' => 'boolean',
    ];

    public function historiqueTaux(): HasMany
    {
        return $this->hasMany(DeviseTaux::class)->orderByDesc('date_effet')->orderByDesc('id');
    }
}
