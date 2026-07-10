<?php

namespace Tests\Fixtures;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Modèle jetable, réservé aux tests, pour valider le trait BelongsToTenant
 * indépendamment des modèles de production (table créée à la volée dans le test).
 */
class TenantFoo extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_foos';

    protected $guarded = [];

    public $timestamps = false;
}
