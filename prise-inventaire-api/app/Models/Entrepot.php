<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Lieu de stockage : entrepôt, magasin, véhicule, dépôt… */
class Entrepot extends Model
{
    use BelongsToTenant, SoftDeletes;

    const TYPES = ['entrepot', 'magasin', 'vehicule', 'depot', 'autre'];

    protected $table = 'entrepots';

    protected $fillable = [
        'tenant_id', 'code', 'nom', 'type', 'adresse', 'ville', 'region', 'code_postal',
        'pays', 'telephone', 'responsable', 'par_defaut', 'actif',
    ];

    protected $casts = [
        'par_defaut' => 'boolean',
        'actif' => 'boolean',
    ];

    /** Secteurs rangés dans l'entrepôt (multi-entrepôts). */
    public function secteurs(): HasMany
    {
        return $this->hasMany(Secteur::class, 'entrepot_id');
    }

    /** Adresse de livraison, une ligne par élément renseigné (bon de commande : « Livrer à »). */
    public function lignesAdresse(): array
    {
        $ville = trim(implode(' ', array_filter([$this->code_postal, $this->ville])));

        return array_values(array_filter([$this->adresse, $ville, $this->region, $this->pays, $this->telephone], fn ($valeur) => filled($valeur)));
    }
}
