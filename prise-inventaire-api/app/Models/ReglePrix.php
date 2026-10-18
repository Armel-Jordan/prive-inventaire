<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Règle de prix : prix client, palier de quantité ou promotion. Chaque critère de portée laissé vide vaut
 * « tous » ; l'effet s'applique au prix de la liste du client.
 */
class ReglePrix extends Model
{
    use BelongsToTenant;

    public const TYPES = ['prix_fixe', 'remise_pourcentage', 'remise_montant'];

    protected $table = 'regles_prix';

    protected $fillable = [
        'libelle', 'client_id', 'categorie_client_id', 'liste_prix_id', 'produit_id', 'categorie_produit',
        'quantite_min', 'date_debut', 'date_fin', 'type', 'valeur', 'actif',
    ];

    protected $casts = [
        'categorie_client_id' => 'integer',
        'quantite_min' => 'integer',
        'date_debut' => 'date:Y-m-d',
        'date_fin' => 'date:Y-m-d',
        'valeur' => 'float',
        'actif' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Catégorie de clients visée (phase 1.2c). */
    public function categorieClient(): BelongsTo
    {
        return $this->belongsTo(ClientCategorie::class, 'categorie_client_id');
    }

    public function listePrix(): BelongsTo
    {
        return $this->belongsTo(ListePrix::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id');
    }

    /** Règles actives à une date (bornes incluses, dates vides = sans limite). */
    public function scopeEnVigueur(Builder $requete, CarbonInterface $date): Builder
    {
        $jour = $date->toDateString();

        return $requete->where('actif', true)
            ->where(fn ($q) => $q->whereNull('date_debut')->orWhereDate('date_debut', '<=', $jour))
            ->where(fn ($q) => $q->whereNull('date_fin')->orWhereDate('date_fin', '>=', $jour));
    }

    /** Prix obtenu par la règle à partir du prix de la liste du client. */
    public function appliquer(float $prixListe): float
    {
        return match ($this->type) {
            'prix_fixe' => $this->valeur,
            'remise_pourcentage' => $prixListe * (1 - $this->valeur / 100),
            'remise_montant' => $prixListe - $this->valeur,
        };
    }
}
