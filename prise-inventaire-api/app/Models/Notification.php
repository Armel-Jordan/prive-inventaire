<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Notification extends Model
{
    use SoftDeletes;

    protected $connection = 'mysql';

    protected $table = 'notifications';

    protected $fillable = [
        'type',
        'titre',
        'message',
        'destinataire',
        'lu',
        'data',
        'lien',
    ];

    protected function casts(): array
    {
        return [
            'lu' => 'boolean',
            'data' => 'array',
        ];
    }

    public static function create_transfert_complete(
        string $produit,
        string $secteurDest,
        float $quantite,
        string $employe
    ): self {
        return self::create([
            'type' => 'transfert_complete',
            'titre' => 'Transfert terminé',
            'message' => "Le transfert de {$quantite} {$produit} vers {$secteurDest} a été effectué par {$employe}",
            'data' => [
                'produit' => $produit,
                'secteur' => $secteurDest,
                'quantite' => $quantite,
                'employe' => $employe,
            ],
            'lien' => '/relocalisation',
        ]);
    }

    public static function create_approbation_requise(
        int $approbationId,
        string $produit,
        float $quantite,
        string $demandeur
    ): self {
        return self::create([
            'type' => 'approbation_requise',
            'titre' => 'Approbation requise',
            'message' => "Une demande d'approbation pour {$quantite} {$produit} par {$demandeur} attend votre validation",
            'data' => [
                'approbation_id' => $approbationId,
                'produit' => $produit,
                'quantite' => $quantite,
                'demandeur' => $demandeur,
            ],
            'lien' => '/approbations',
        ]);
    }

    public static function create_alerte_stock(
        string $produit,
        float $quantiteActuelle,
        float $seuil
    ): self {
        return self::create([
            'type' => 'alerte_stock',
            'titre' => 'Alerte stock bas',
            'message' => "Le produit {$produit} est en dessous du seuil ({$quantiteActuelle}/{$seuil})",
            'data' => [
                'produit' => $produit,
                'quantite_actuelle' => $quantiteActuelle,
                'seuil' => $seuil,
            ],
            'lien' => '/alertes',
        ]);
    }

    public static function create_planification_imminente(
        int $transfertId,
        string $produit,
        string $datePlanifiee
    ): self {
        return self::create([
            'type' => 'planification_imminente',
            'titre' => 'Transfert planifié imminent',
            'message' => "Le transfert de {$produit} est prévu pour {$datePlanifiee}",
            'data' => [
                'transfert_id' => $transfertId,
                'produit' => $produit,
                'date_planifiee' => $datePlanifiee,
            ],
            'lien' => '/planification',
        ]);
    }
}
