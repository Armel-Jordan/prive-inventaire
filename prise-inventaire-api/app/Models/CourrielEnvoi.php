<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Courriel de la file d'envoi, conservé ensuite comme historique. */
class CourrielEnvoi extends Model
{
    use BelongsToTenant;

    public const EN_ATTENTE = 'en_attente';

    public const EN_COURS = 'en_cours';

    public const ENVOYE = 'envoye';

    public const ECHEC = 'echec';

    public const STATUTS = [self::EN_ATTENTE, self::EN_COURS, self::ENVOYE, self::ECHEC];

    /** Tentatives automatiques au plus ; la relance manuelle reste possible ensuite. */
    public const TENTATIVES_MAX = 3;

    protected $table = 'courriel_envois';

    protected $fillable = [
        'type',
        'document_id',
        'document_numero',
        'destinataires',
        'copies',
        'copies_cachees',
        'expediteur_adresse',
        'expediteur_nom',
        'repondre_a',
        'objet',
        'corps_html',
        'corps_texte',
        'piece_jointe',
        'accuse_lecture',
        'statut',
        'tentatives',
        'dernier_essai',
        'envoye_le',
        'erreur',
        'envoye_par',
    ];

    protected function casts(): array
    {
        return [
            'destinataires' => 'array',
            'copies' => 'array',
            'copies_cachees' => 'array',
            'accuse_lecture' => 'boolean',
            'tentatives' => 'integer',
            'dernier_essai' => 'datetime',
            'envoye_le' => 'datetime',
        ];
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'envoye_par');
    }
}
