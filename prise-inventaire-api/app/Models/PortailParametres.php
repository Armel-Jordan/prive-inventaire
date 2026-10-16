<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Réglages de l'espace client d'une entreprise : ouverture, message d'accueil, catégories proposées, adresse du
 * site de l'espace client (transmise par le back-office, pour les liens des courriels).
 */
class PortailParametres extends Model
{
    use BelongsToTenant;

    protected $table = 'portail_parametres';

    protected $fillable = ['actif', 'message_accueil', 'categories', 'url_espace_client'];

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
