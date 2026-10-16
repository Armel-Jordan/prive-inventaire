<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande de réinitialisation du mot de passe. Le jeton est stocké haché (sha256) ; le lien envoyé par
 * courriel contient le jeton en clair. Hors contexte d'entreprise : la demande se fait avant la connexion.
 */
class ReinitialisationMotDePasse extends Model
{
    protected $table = 'reinitialisations_mot_de_passe';

    protected $fillable = ['admin_user_id', 'jeton', 'expire_le'];

    protected $casts = ['expire_le' => 'datetime'];

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }
}
