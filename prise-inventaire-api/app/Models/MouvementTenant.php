<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MouvementTenant extends Model
{
    use SoftDeletes;

    protected $connection = 'mysql';

    protected $table = 'mouvement_relocalisation';

    protected $fillable = [
        'tenant_id',
        'type',
        'produit_numero',
        'produit_nom',
        'secteur_source',
        'secteur_destination',
        'quantite',
        'unite_mesure',
        'motif',
        'employe',
        'date_mouvement',
    ];

    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:4',
            'date_mouvement' => 'datetime',
        ];
    }

    // Types de mouvement
    const TYPE_ARRIVAGE = 'arrivage';

    const TYPE_TRANSFERT = 'transfert';

    const TYPE_SORTIE = 'sortie';

    const TYPE_AJUSTEMENT = 'ajustement';

    public static function getTypes(): array
    {
        return [
            self::TYPE_ARRIVAGE => 'Arrivage',
            self::TYPE_TRANSFERT => 'Transfert',
            self::TYPE_SORTIE => 'Sortie',
            self::TYPE_AJUSTEMENT => 'Ajustement',
        ];
    }
}
