<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Produit renvoyé au fournisseur : quantité, prix crédité, raison, et secteur d'où il sort du stock (aucun : il n'y
 * était pas, par exemple un produit défectueux rapporté par un client et mis au rebut).
 */
class RetourFournisseurLigne extends Model
{
    protected $table = 'retour_fournisseur_lignes';

    protected $fillable = [
        'retour_fournisseur_id', 'com_four_ligne_id', 'retour_client_ligne_id', 'produit_id', 'quantite', 'prix_unitaire',
        'raison_id', 'secteur_id', 'mouvement_id', 'mouvement_remplacement_id', 'notes',
        // Produit suivi (phase 2.8c) : le lot qui repart, les numéros de série renvoyés.
        'lot_id', 'numeros_serie',
    ];

    protected $casts = [
        'quantite' => 'integer',
        'prix_unitaire' => 'decimal:2',
        'numeros_serie' => 'array',
    ];

    public function retour(): BelongsTo
    {
        return $this->belongsTo(RetourFournisseur::class, 'retour_fournisseur_id');
    }

    public function ligneCommande(): BelongsTo
    {
        return $this->belongsTo(ComFourLigne::class, 'com_four_ligne_id');
    }

    public function ligneRetourClient(): BelongsTo
    {
        return $this->belongsTo(RetourClientLigne::class, 'retour_client_ligne_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id');
    }

    public function raison(): BelongsTo
    {
        return $this->belongsTo(Raison::class)->withTrashed();
    }

    public function secteur(): BelongsTo
    {
        return $this->belongsTo(Secteur::class)->withTrashed();
    }
}
