<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Devis extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'numero',
        'client_id',
        'date_devis',
        'date_validite',
        'statut',
        'montant_total',
        'notes',
        'created_by',
        'commande_id',
        // Facture issue du devis, converti directement (sans commande).
        'facture_id',
        'devise',
        'taux_change',
        // Coordonnées du client de passage (voir App\Services\ClientsDePassage).
        'passage_nom', 'passage_telephone', 'passage_courriel', 'passage_adresse',
        // Envoi au client et réponse, en ligne ou notée par un employé (voir App\Services\ReponsesDevis).
        'envoye_le', 'reponse_le', 'reponse_en_ligne', 'reponse_nom', 'reponse_commentaire',
        // Relances envoyées et date de la dernière (voir App\Services\RelancesDevis).
        'relances', 'derniere_relance_le',
        // Représentant commercial et transporteur (voir App\Services\EntetesVente).
        'representant_id', 'transporteur_id',
        // Contact et « Livrer à » (copie de l'adresse et ses instructions), voir App\Services\LivraisonDocuments.
        'contact_id', 'adresse_livraison_id', 'livraison_adresse', 'instructions_livraison',
        // Statut personnalisé : étiquette de repérage, sans effet sur le cycle (phase 1.11c).
        'statut_personnalise_id',
    ];

    /** Le jeton du lien de réponse ne sort jamais de l'API : seul le lien construit est donné. */
    protected $hidden = ['reponse_jeton', 'reponse_jeton_hash'];

    protected $casts = [
        'date_devis' => 'date',
        'date_validite' => 'date',
        'montant_total' => 'decimal:2',
        'envoye_le' => 'datetime',
        'reponse_le' => 'datetime',
        'reponse_en_ligne' => 'boolean',
        'reponse_jeton' => 'encrypted',
        'relances' => 'integer',
        'derniere_relance_le' => 'datetime',
    ];

    const STATUT_BROUILLON = 'brouillon';

    const STATUT_ENVOYE = 'envoye';

    const STATUT_ACCEPTE = 'accepte';

    const STATUT_REFUSE = 'refuse';

    const STATUT_EXPIRE = 'expire';

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Représentant commercial (même retiré du référentiel depuis). */
    public function representant(): BelongsTo
    {
        return $this->belongsTo(Representant::class)->withTrashed();
    }

    /** Contact du client sur le document (« Livrer à » et contact, socle S4.1). */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(ClientContact::class, 'contact_id');
    }

    /** Statut personnalisé (étiquette de repérage, phase 1.11c ; même retiré du référentiel depuis). */
    public function statutPersonnalise(): BelongsTo
    {
        return $this->belongsTo(StatutPersonnalise::class)->withTrashed();
    }

    /** Transporteur (même retiré du référentiel depuis). */
    public function transporteur(): BelongsTo
    {
        return $this->belongsTo(Transporteur::class)->withTrashed();
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(DevisLigne::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    /** Commande issue de la conversion du devis (un devis ne se convertit qu'une fois). */
    public function commande(): BelongsTo
    {
        return $this->belongsTo(ComClientEntete::class, 'commande_id');
    }

    /** Facture issue du devis converti directement, sans commande. */
    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class, 'facture_id');
    }

    /** Devis envoyé dont la date de validité est passée. */
    public function estExpire(): bool
    {
        return $this->date_validite !== null && $this->date_validite->lt(now()->startOfDay());
    }

    public function recalculerTotal(): void
    {
        $this->montant_total = $this->lignes()->sum('montant_ligne');
        $this->save();
    }
}
