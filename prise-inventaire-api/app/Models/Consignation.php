<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bon de dépôt en consignation chez un client (voir App\Services\Consignations). */
class Consignation extends Model
{
    use BelongsToTenant;

    protected $table = 'consignations';

    protected $fillable = ['tenant_id', 'numero', 'client_id', 'date_depot', 'notes', 'cree_par'];

    protected $casts = ['date_depot' => 'date'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(ConsignationLigne::class);
    }
}
