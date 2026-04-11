<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProduitLocalisation extends Model
{
    use HasFactory;

    protected $table = 'produit_localisations';

    protected $fillable = [
        'tenant_id', 'produit_id', 'localisation_type', 'localisation_id',
        'quantite', 'statut', 'commande_id',
    ];

    protected $casts = [
        'produit_id' => 'integer',
        'localisation_id' => 'integer',
        'quantite' => 'integer',
        'commande_id' => 'integer',
    ];

    public static function getLocalisationProduit(int $produitId): array
    {
        return self::where('produit_id', $produitId)
            ->where('quantite', '>', 0)
            ->get()
            ->toArray();
    }

    public static function getStockDisponible(int $produitId): int
    {
        return self::where('produit_id', $produitId)
            ->where('statut', 'disponible')
            ->sum('quantite');
    }

    public static function reserver(int $produitId, int $quantite, int $commandeId): bool
    {
        $localisations = self::where('produit_id', $produitId)
            ->where('statut', 'disponible')
            ->where('quantite', '>', 0)
            ->orderBy('quantite', 'desc')
            ->get();

        $resteAReserver = $quantite;
        foreach ($localisations as $loc) {
            if ($resteAReserver <= 0) {
                break;
            }

            $qteAReserver = min($loc->quantite, $resteAReserver);
            $loc->quantite -= $qteAReserver;
            $loc->save();

            self::create([
                'produit_id' => $produitId,
                'localisation_type' => $loc->localisation_type,
                'localisation_id' => $loc->localisation_id,
                'quantite' => $qteAReserver,
                'statut' => 'reserve',
                'commande_id' => $commandeId,
            ]);

            $resteAReserver -= $qteAReserver;
        }

        return $resteAReserver <= 0;
    }
}
