<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Coût annexe d'une réception (transport, douane, assurance, manutention…) : réparti sur ses lignes au prorata de la
 * valeur, de la quantité, du poids ou du volume, il s'ajoute au coût de revient de la marchandise reçue.
 */
class ReceptionFrais extends Model
{
    use BelongsToTenant;

    public const TYPES = ['transport', 'douane', 'assurance', 'manutention', 'autre'];

    public const REPARTITIONS = ['valeur', 'quantite', 'poids', 'volume'];

    protected $table = 'reception_frais';

    protected $fillable = [
        'tenant_id', 'reception_id', 'type', 'libelle', 'fournisseur_id', 'reference', 'montant', 'devise', 'taux_change', 'repartition',
    ];

    protected $casts = [
        'montant' => 'decimal:2',
        'taux_change' => 'decimal:6',
    ];

    public function reception(): BelongsTo
    {
        return $this->belongsTo(Reception::class);
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class)->withTrashed();
    }

    /** Montant dans la devise de l'entreprise. */
    public function montantReference(): float
    {
        return round((float) $this->montant * ((float) $this->taux_change ?: 1.0), 2);
    }
}
