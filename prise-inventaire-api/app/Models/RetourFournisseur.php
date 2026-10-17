<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Retour de marchandise à un fournisseur : reçue d'une de ses commandes, rapportée par un client, ou sans origine.
 * Brouillon modifiable, expédié (numéroté, ce qui sort du stock quitte son secteur), puis clos quand le fournisseur a
 * répondu : crédit obtenu (avoir), marchandise de remplacement reçue, ou contenant consigné rendu.
 */
class RetourFournisseur extends Model
{
    use BelongsToTenant;

    public const BROUILLON = 'brouillon';

    public const EXPEDIE = 'expedie';

    public const CLOS = 'clos';

    public const REMBOURSEMENT = 'remboursement';

    public const REMPLACEMENT = 'remplacement';

    public const CONSIGNE = 'consigne';

    public const TYPES = [self::REMBOURSEMENT, self::REMPLACEMENT, self::CONSIGNE];

    protected $table = 'retours_fournisseurs';

    protected $fillable = [
        'tenant_id', 'numero', 'fournisseur_id', 'commande_fournisseur_id', 'retour_client_id', 'type', 'statut',
        'date_retour', 'date_expedition', 'date_cloture', 'numero_rma', 'devise', 'taux_change', 'montant',
        'montant_credite', 'reference_avoir', 'secteur_remplacement_id', 'notes', 'cree_par', 'expedie_par', 'clos_par',
    ];

    protected $casts = [
        'date_retour' => 'date',
        'date_expedition' => 'date',
        'date_cloture' => 'date',
        'montant' => 'decimal:2',
        'montant_credite' => 'decimal:2',
    ];

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class)->withTrashed();
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(ComFourEntete::class, 'commande_fournisseur_id');
    }

    public function retourClient(): BelongsTo
    {
        return $this->belongsTo(RetourClient::class);
    }

    public function secteurRemplacement(): BelongsTo
    {
        return $this->belongsTo(Secteur::class, 'secteur_remplacement_id')->withTrashed();
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(RetourFournisseurLigne::class)->orderBy('id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'cree_par');
    }

    /** Qui a expédié le retour. */
    public function expediteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'expedie_par');
    }

    /** Qui a clos le retour. */
    public function operateur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'clos_par');
    }
}
