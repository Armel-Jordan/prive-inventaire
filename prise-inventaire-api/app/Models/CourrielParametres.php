<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Paramètres d'envoi des courriels d'une entreprise (une ligne au plus par tenant). */
class CourrielParametres extends Model
{
    use BelongsToTenant;

    protected $table = 'courriel_parametres';

    protected $fillable = [
        'transport',
        'smtp_hote',
        'smtp_port',
        'smtp_chiffrement',
        'smtp_utilisateur',
        'smtp_mot_de_passe',
        'expediteur_adresse',
        'expediteur_nom',
        'repondre_a',
        'copie_cachee',
        'signature',
        // Boutons Accepter / Refuser dans les courriels des devis (voir App\Services\ReponsesDevis).
        'devis_reponse_en_ligne',
    ];

    protected $hidden = ['smtp_mot_de_passe'];

    protected function casts(): array
    {
        return [
            'smtp_port' => 'integer',
            'smtp_mot_de_passe' => 'encrypted',
            'devis_reponse_en_ligne' => 'boolean',
        ];
    }

    public function utiliseSmtp(): bool
    {
        return $this->transport === 'smtp' && filled($this->smtp_hote);
    }
}
