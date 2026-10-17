<?php

namespace App\Models;

use App\Services\ReliquatsClients;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceptionArrivagesLigne extends Model
{
    use HasFactory;

    protected $table = 'reception_arrivages_ligne';

    protected $fillable = [
        'reception_id',
        'com_four_ligne_id',
        'date_reception',
        'quantite_recue',
        'cout_unitaire',
        'frais_unitaire',
        'secteur_id',
        'lot_numero',
        'date_peremption',
        // Lot reçu, pour un produit suivi par lot (phase 2.8).
        'lot_id',
        'notes',
        'received_by',
        'annulee',
    ];

    protected $casts = [
        'date_reception' => 'date',
        'date_peremption' => 'date',
        'quantite_recue' => 'integer',
        'cout_unitaire' => 'decimal:4',
        'frais_unitaire' => 'decimal:4',
        'annulee' => 'boolean',
    ];

    /** Document de la réception (vide pour les réceptions enregistrées avant leur numérotation). */
    public function reception(): BelongsTo
    {
        return $this->belongsTo(Reception::class);
    }

    public function ligneCommande(): BelongsTo
    {
        return $this->belongsTo(ComFourLigne::class, 'com_four_ligne_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'lot_id');
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

            // Reliquats clients en attente de cette ligne : la réception se répartit sur eux.
            app(ReliquatsClients::class)->recevoir($ligneCommande, (int) $reception->quantite_recue);
        });
    }
}
