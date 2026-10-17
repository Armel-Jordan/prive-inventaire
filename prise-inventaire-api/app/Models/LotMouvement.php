<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mouvement d'un lot, en unités de stock (négatif pour une sortie) : réception, annulation de réception… */
class LotMouvement extends Model
{
    use BelongsToTenant;

    public const RECEPTION = 'reception';

    public const ANNULATION_RECEPTION = 'annulation_reception';

    /** Sortie d'un bon de livraison (phase 2.8b). */
    public const LIVRAISON = 'livraison';

    /** Sortie d'une vente au comptoir (phase 2.8b). */
    public const VENTE_COMPTOIR = 'vente_comptoir';

    /** Retour d'un client remis en stock (phase 2.8c). */
    public const RETOUR_CLIENT = 'retour_client';

    /** Retour au fournisseur (phase 2.8c). */
    public const RETOUR_FOURNISSEUR = 'retour_fournisseur';

    /** Sorties chez un client : ce que retrouve un rappel. */
    public const SORTIES_CLIENT = [self::LIVRAISON, self::VENTE_COMPTOIR];

    protected $table = 'lot_mouvements';

    protected $fillable = [
        'tenant_id', 'lot_id', 'type', 'quantite', 'reception_ligne_id', 'date_mouvement', 'cree_par',
        // Sortie : la ligne de bon de livraison ou de vente au comptoir, et le client qui a reçu le lot.
        'bon_livraison_ligne_id', 'facture_ligne_id', 'client_id',
        // Retours (phase 2.8c) : la ligne de retour client ou fournisseur.
        'retour_client_ligne_id', 'retour_fournisseur_ligne_id',
    ];

    protected function casts(): array
    {
        return [
            'quantite' => 'float',
            'date_mouvement' => 'date',
        ];
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'lot_id');
    }

    public function receptionLigne(): BelongsTo
    {
        return $this->belongsTo(ReceptionArrivagesLigne::class, 'reception_ligne_id');
    }

    public function bonLivraisonLigne(): BelongsTo
    {
        return $this->belongsTo(BonLivraisonLigne::class, 'bon_livraison_ligne_id');
    }

    public function factureLigne(): BelongsTo
    {
        return $this->belongsTo(FactureLigne::class, 'facture_ligne_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id')->withTrashed();
    }
}
