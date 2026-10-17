<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reliquat client (back order) : un produit qu'un client attend faute de stock. Créé à la main ou depuis une commande
 * client, commandé au fournisseur, suivi à la réception, puis facturé quand il est arrivé.
 */
class ReliquatClient extends Model
{
    use BelongsToTenant;

    /** Inscrit, pas encore commandé au fournisseur. Seul état modifiable, annulable ou supprimable. */
    public const CREE = 'cree';

    public const COMMANDE = 'commande';

    public const RECU_PARTIEL = 'recu_partiel';

    /** Tout reçu (ou la commande fournisseur close après une réception partielle) : à facturer. */
    public const RECU = 'recu';

    public const FACTURE = 'facture';

    public const ANNULE = 'annule';

    public const STATUTS = [self::CREE, self::COMMANDE, self::RECU_PARTIEL, self::RECU, self::FACTURE, self::ANNULE];

    protected $table = 'reliquats_clients';

    protected $fillable = [
        'tenant_id', 'client_id', 'produit_id', 'quantite', 'quantite_commandee', 'quantite_recue', 'prix_unitaire_ht',
        'remise_ligne', 'devise', 'taux_change', 'commande_client_id', 'commande_client_ligne_id', 'com_four_ligne_id',
        'facture_id', 'date_requise', 'statut', 'notes', 'cree_par',
    ];

    protected $casts = [
        'quantite' => 'integer',
        'quantite_commandee' => 'integer',
        'quantite_recue' => 'integer',
        'prix_unitaire_ht' => 'decimal:2',
        'remise_ligne' => 'decimal:2',
        'date_requise' => 'date',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(ProduitTenant::class, 'produit_id')->withTrashed();
    }

    public function commandeClient(): BelongsTo
    {
        return $this->belongsTo(ComClientEntete::class, 'commande_client_id');
    }

    public function ligneCommandeFournisseur(): BelongsTo
    {
        return $this->belongsTo(ComFourLigne::class, 'com_four_ligne_id');
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'cree_par');
    }
}
