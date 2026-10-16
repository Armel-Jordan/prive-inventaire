<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Réglages du portail client d'une entreprise : ouverture, message d'accueil, catégories proposées. */
class PortailParametres extends Model
{
    use BelongsToTenant;

    protected $table = 'portail_parametres';

    protected $fillable = ['actif', 'message_accueil', 'categories'];

    protected $casts = [
        'actif' => 'boolean',
        'categories' => 'array',
    ];

    /** Réglages de l'entreprise courante (portail fermé tant qu'ils n'existent pas). */
    public static function courants(): self
    {
        return static::first() ?? new static(['actif' => false]);
    }
}
