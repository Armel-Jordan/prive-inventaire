<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeTenant extends Model
{
    use SoftDeletes;

    protected $connection = 'mysql';

    protected $table = 'employes';

    protected $fillable = [
        'tenant_id',
        'admin_user_id',
        'numero',
        'nom',
        'prenom',
        'email',
        'actif',
        'photo',
        'sexe',
        'date_naissance',
        'telephone',
        'adresse',
        'ville',
        'code_postal',
        'pays',
        'poste',
        'departement',
        'date_embauche',
    ];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
        ];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
