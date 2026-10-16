<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande d'acompte : un dépôt demandé au client (description et montant, sans taxe), avant des travaux ou une
 * commande. Brouillon, envoyée, puis payée : la marquer payée enregistre l'acompte au dossier du client. Ce n'est pas
 * une facture.
 */
class DemandeAcompte extends Model
{
    use BelongsToTenant;

    public const BROUILLON = 'brouillon';

    public const ENVOYEE = 'envoyee';

    public const PAYEE = 'payee';

    protected $table = 'demandes_acompte';

    protected $fillable = [
        'tenant_id', 'numero', 'client_id', 'date_demande', 'description', 'montant', 'statut',
        'devis_id', 'commande_id', 'acompte_id', 'envoyee_le', 'payee_le', 'cree_par',
    ];

    protected $casts = [
        'date_demande' => 'date',
        'montant' => 'decimal:2',
        'envoyee_le' => 'datetime',
        'payee_le' => 'datetime',
    ];

    protected $appends = ['etat'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function devis(): BelongsTo
    {
        return $this->belongsTo(Devis::class);
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(ComClientEntete::class, 'commande_id');
    }

    public function acompte(): BelongsTo
    {
        return $this->belongsTo(Acompte::class);
    }

    /**
     * Statut affiché : celui de la demande, puis, une fois payée, l'usage de son acompte (partiellement appliquée,
     * appliquée) sur le paiement de factures.
     */
    public function getEtatAttribute(): string
    {
        if ($this->statut !== self::PAYEE || ! $this->relationLoaded('acompte') || ! $this->acompte) {
            return (string) $this->statut;
        }
        $applique = (float) $this->acompte->montant_applique;

        return match (true) {
            $applique <= 0 => self::PAYEE,
            $applique + 0.005 < (float) $this->acompte->montant => 'partiellement_appliquee',
            default => 'appliquee',
        };
    }
}
