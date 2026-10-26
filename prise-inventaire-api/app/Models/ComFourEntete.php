<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComFourEntete extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'com_four_entete';

    protected $fillable = [
        'tenant_id',
        'numero',
        'fournisseur_id',
        'date_commande',
        'date_livraison_prevue',
        'statut',
        'envoyee_le',
        'envoyee_par',
        'montant_total',
        'devise',
        'taux_change',
        'notes',
        'created_by',
        // Entrepôt où livrer (« Livrer à ») ; vide : l'adresse de l'entreprise.
        'entrepot_id',
        // Transporteur ; à la création, celui du fournisseur s'il n'est pas donné (voir booted()).
        'transporteur_id',
        // Statut personnalisé : étiquette de repérage, sans effet sur le cycle (phase 1.11c).
        'statut_personnalise_id',
    ];

    protected $casts = [
        'date_commande' => 'date',
        'date_livraison_prevue' => 'date',
        'envoyee_le' => 'datetime',
        'montant_total' => 'decimal:2',
        'taux_change' => 'decimal:6',
    ];

    const STATUT_BROUILLON = 'brouillon';

    const STATUT_ENVOYEE = 'envoyee';

    const STATUT_PARTIELLE = 'partielle';

    const STATUT_COMPLETE = 'complete';

    const STATUT_ANNULEE = 'annulee';

    /**
     * Transporteur par défaut (phase 1.11b) : celui du fournisseur, quelle que soit la façon dont la commande est
     * créée (saisie, reliquats, suggestions). Une valeur donnée, même vide, est gardée.
     */
    protected static function booted(): void
    {
        static::creating(function (self $commande) {
            if (! array_key_exists('transporteur_id', $commande->getAttributes()) && $commande->fournisseur_id) {
                $commande->transporteur_id = Fournisseur::whereKey($commande->fournisseur_id)->value('transporteur_id');
            }
        });
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_id');
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
        return $this->hasMany(ComFourLigne::class, 'com_four_entete_id');
    }

    /** Entrepôt où livrer la commande (gardé s'il a été retiré du référentiel depuis). */
    public function entrepot(): BelongsTo
    {
        return $this->belongsTo(Entrepot::class, 'entrepot_id')->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    /** Auteur de l'envoi au fournisseur (bouton « Valider et envoyer » ou courriel). */
    public function auteurEnvoi(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'envoyee_par');
    }

    public static function generateNumero(): string
    {
        $year = date('Y');
        $lastCommande = self::whereYear('created_at', $year)->orderBy('id', 'desc')->first();
        $nextNumber = 1;

        if ($lastCommande) {
            $parts = explode('-', $lastCommande->numero);
            if (count($parts) === 3) {
                $nextNumber = intval($parts[2]) + 1;
            }
        }

        return 'CF-'.$year.'-'.str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    }

    public function recalculerMontantTotal(): void
    {
        $this->montant_total = $this->lignes()->sum('montant_ligne');
        $this->save();
    }

    public function updateStatut(): void
    {
        if ($this->statut === self::STATUT_ANNULEE) {
            return;
        }

        $lignes = $this->lignes;
        $toutRecu = true;
        $partielRecu = false;

        foreach ($lignes as $ligne) {
            if ($ligne->quantite_recue < $ligne->quantite_commandee) {
                $toutRecu = false;
            }
            if ($ligne->quantite_recue > 0) {
                $partielRecu = true;
            }
        }

        if ($toutRecu && $lignes->count() > 0) {
            $this->statut = self::STATUT_COMPLETE;
        } elseif ($partielRecu) {
            $this->statut = self::STATUT_PARTIELLE;
        }

        $this->save();
    }
}
