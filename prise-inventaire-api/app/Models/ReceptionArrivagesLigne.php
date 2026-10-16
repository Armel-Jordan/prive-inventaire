<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceptionArrivagesLigne extends Model
{
    use HasFactory;

    protected $table = 'reception_arrivages_ligne';

    protected $fillable = [
        'com_four_ligne_id',
        'date_reception',
        'quantite_recue',
        'secteur_id',
        'lot_numero',
        'date_peremption',
        'notes',
        'received_by',
    ];

    protected $casts = [
        'date_reception' => 'date',
        'date_peremption' => 'date',
        'quantite_recue' => 'integer',
    ];

    public function ligneCommande(): BelongsTo
    {
        return $this->belongsTo(ComFourLigne::class, 'com_four_ligne_id');
    }

    public function secteur(): BelongsTo
    {
        return $this->belongsTo(Secteur::class, 'secteur_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'received_by');
    }

    protected static function boot()
    {
        parent::boot();

        static::created(function ($reception) {
            $ligneCommande = $reception->ligneCommande;
            $ligneCommande->quantite_recue += $reception->quantite_recue;
            $ligneCommande->save();

            // Les produits n'ont pas de colonne de stock (le stock de PI vient des comptages) : la mise à
            // jour de « quantite_stock » qui figurait ici faisait échouer toute réception (colonne inexistante).

            $ligneCommande->commande->updateStatut();
        });
    }
}
