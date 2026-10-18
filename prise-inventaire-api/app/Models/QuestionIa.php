<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Question posée à l'IA sur les rapports (phase 5.4) : la question, la réponse, les rapports exécutés pour y répondre,
 * le modèle et les jetons consommés. Chaque compte ne voit que les siennes.
 */
class QuestionIa extends Model
{
    use BelongsToTenant;

    public const EN_COURS = 'en_cours';

    public const REUSSIE = 'reussie';

    public const ECHEC = 'echec';

    protected $table = 'questions_ia';

    protected $fillable = [
        'tenant_id', 'admin_user_id', 'question', 'langue', 'statut', 'titre', 'reponse', 'rapports', 'rapport', 'modele',
        'jetons_entree', 'jetons_sortie', 'duree_ms', 'erreur',
    ];

    protected function casts(): array
    {
        return [
            'rapports' => 'array',
            'rapport' => 'array',
            'jetons_entree' => 'integer',
            'jetons_sortie' => 'integer',
            'duree_ms' => 'integer',
        ];
    }
}
