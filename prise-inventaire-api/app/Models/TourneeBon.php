<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TourneeBon extends Model
{
    use HasFactory;

    protected $table = 'tournee_bons';

    protected $fillable = [
        'tournee_id', 'bon_livraison_id', 'ordre_livraison',
        'heure_livraison', 'statut', 'motif_echec',
    ];

    protected $casts = [
        'ordre_livraison' => 'integer',
        'heure_livraison' => 'datetime:H:i',
    ];

    public function tournee(): BelongsTo
    {
        return $this->belongsTo(Tournee::class);
    }

    public function bonLivraison(): BelongsTo
    {
        return $this->belongsTo(BonLivraison::class);
    }
}
