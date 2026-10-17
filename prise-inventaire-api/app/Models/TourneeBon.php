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
        // Chargement du camion : marchandise hors colis de l'arrêt cochée comme chargée.
        'vrac_charge_le', 'vrac_charge_par',
    ];

    protected $casts = [
        'ordre_livraison' => 'integer',
        'heure_livraison' => 'datetime:H:i',
        'vrac_charge_le' => 'datetime',
    ];

    public function chargeurVrac(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'vrac_charge_par');
    }

    public function tournee(): BelongsTo
    {
        return $this->belongsTo(Tournee::class);
    }

    public function bonLivraison(): BelongsTo
    {
        return $this->belongsTo(BonLivraison::class);
    }
}
