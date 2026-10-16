<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Valeur d'un champ personnalisé pour une fiche ou un document. */
class ChampPersonnaliseValeur extends Model
{
    use BelongsToTenant;

    protected $table = 'champs_personnalises_valeurs';

    protected $fillable = ['champ_personnalise_id', 'entite_id', 'valeur'];

    protected function casts(): array
    {
        return [
            'entite_id' => 'integer',
        ];
    }

    public function champ(): BelongsTo
    {
        return $this->belongsTo(ChampPersonnalise::class, 'champ_personnalise_id');
    }
}
