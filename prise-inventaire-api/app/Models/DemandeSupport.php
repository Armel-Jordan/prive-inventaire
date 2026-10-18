<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Demande d'aide ou suggestion adressée à l'éditeur du logiciel (phase 6.7) : son sujet, sa description, sa priorité,
 * son statut et l'échange de messages entre l'usager et l'éditeur.
 */
class DemandeSupport extends Model
{
    use BelongsToTenant;

    public const TYPES = ['support', 'suggestion'];

    public const PRIORITES = ['basse', 'normale', 'haute', 'urgente'];

    /** ouverte → en_cours → en_attente (réponse de l'usager attendue) ↔ en_cours → resolue → fermee. */
    public const STATUTS = ['ouverte', 'en_cours', 'en_attente', 'resolue', 'fermee'];

    protected $table = 'demandes_support';

    protected $fillable = [
        'tenant_id', 'admin_user_id', 'type', 'sujet', 'description', 'priorite', 'statut', 'ecran',
        'dernier_message_le', 'dernier_auteur', 'resolue_le',
    ];

    protected function casts(): array
    {
        return [
            'dernier_message_le' => 'datetime',
            'resolue_le' => 'datetime',
        ];
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'admin_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DemandeSupportMessage::class, 'demande_support_id')->orderBy('id');
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
