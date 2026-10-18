<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Message d'une demande de support : de l'usager (compte de l'entreprise) ou de l'éditeur (super-administrateur). */
class DemandeSupportMessage extends Model
{
    use BelongsToTenant;

    protected $table = 'demandes_support_messages';

    protected $fillable = ['tenant_id', 'demande_support_id', 'auteur', 'admin_user_id', 'super_admin_id', 'message'];

    public function compte(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function superAdmin(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'super_admin_id');
    }
}
