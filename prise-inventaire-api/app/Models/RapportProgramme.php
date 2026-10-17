<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Envoi programmé d'un rapport de la bibliothèque (phase 5.2) : le rapport, ses paramètres et sa période relative, le
 * format et la langue, les destinataires, la fréquence. Il part avec les droits du compte qui l'a réglé en dernier.
 */
class RapportProgramme extends Model
{
    use BelongsToTenant;

    public const FREQUENCES = ['quotidienne', 'hebdomadaire', 'mensuelle'];

    protected $table = 'rapports_programmes';

    protected $fillable = [
        'tenant_id', 'code', 'nom', 'parametres', 'periode', 'format', 'langue', 'destinataires', 'frequence', 'jour', 'actif',
        'compte_id', 'prochain_envoi', 'dernier_envoi_le', 'dernier_envoi_id', 'derniere_erreur',
    ];

    protected function casts(): array
    {
        return [
            'parametres' => 'array',
            'destinataires' => 'array',
            'jour' => 'integer',
            'actif' => 'boolean',
            'prochain_envoi' => 'date',
            'dernier_envoi_le' => 'datetime',
        ];
    }

    /** Compte dont les droits s'appliquent à l'envoi. */
    public function compte(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'compte_id');
    }

    public function dernierEnvoi(): BelongsTo
    {
        return $this->belongsTo(CourrielEnvoi::class, 'dernier_envoi_id');
    }
}
