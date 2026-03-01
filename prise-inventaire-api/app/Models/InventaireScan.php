<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class InventaireScan extends Model
{
    protected $connection = 'oracle';
    protected $table = 'INVENTAIRE_SCAN';
    
    public $timestamps = true;
    const CREATED_AT = 'CREATED_AT';
    const UPDATED_AT = 'UPDATED_AT';

    protected $primaryKey = 'ID';
    public $incrementing = true;

    protected $fillable = [
        'NUMERO',
        'TYPE',
        'QUANTITE',
        'UNITE_MESURE',
        'EMPLOYE',
        'SECTEUR',
        'DATE_SAISIE',
        'SCANNEUR',
    ];

    protected function casts(): array
    {
        return [
            'DATE_SAISIE' => 'datetime',
            'QUANTITE' => 'decimal:4',
            'CREATED_AT' => 'datetime',
            'UPDATED_AT' => 'datetime',
            'DELETED_AT' => 'datetime',
        ];
    }

    public function softDelete(): bool
    {
        $pdo = DB::connection($this->getConnectionName())->getPdo();
        $stmt = $pdo->prepare(
            "UPDATE INVENTAIRE_SCAN SET DELETED_AT = SYSTIMESTAMP, UPDATED_AT = SYSTIMESTAMP WHERE ID = :id"
        );
        $id = $this->getKey();
        $stmt->bindParam(':id', $id);
        $result = $stmt->execute();
        $pdo->commit();

        return $result;
    }

    protected static function booted(): void
    {
        static::addGlobalScope('not-deleted', function (Builder $builder) {
            $builder->whereNull('DELETED_AT');
        });
    }
}
