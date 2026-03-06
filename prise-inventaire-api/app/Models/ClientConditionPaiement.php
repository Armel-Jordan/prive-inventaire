<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientConditionPaiement extends Model
{
    use HasFactory;

    protected $table = 'client_conditions_paiement';

    protected $fillable = [
        'client_id',
        'libelle',
        'nb_jours',
        'pourcentage',
        'ordre',
    ];

    protected $casts = [
        'nb_jours' => 'integer',
        'pourcentage' => 'decimal:2',
        'ordre' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
