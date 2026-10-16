<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'code',
        'raison_sociale',
        'condition_paiement_id',
        'adresse_facturation',
        'adresse_livraison',
        'ville',
        'code_postal',
        'telephone',
        'email',
        'contact_nom',
        'contact_telephone',
        'encours_max',
        'encours_actuel',
        'taux_remise_global',
        'actif',
        'categorie_id',
    ];

    protected $casts = [
        'encours_max' => 'decimal:2',
        'encours_actuel' => 'decimal:2',
        'taux_remise_global' => 'decimal:2',
        'actif' => 'boolean',
    ];

    public static function generateCode(): string
    {
        $last = self::orderBy('id', 'desc')->first();
        $number = $last ? intval(substr($last->code, 4)) + 1 : 1;

        return 'CLI-'.str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    /** Condition de paiement du client (référentiel) : reprise par ses factures. */
    public function conditionPaiement(): BelongsTo
    {
        return $this->belongsTo(ConditionPaiement::class)->withTrashed();
    }

    public function conditionsPaiement(): HasMany
    {
        return $this->hasMany(ClientConditionPaiement::class)->orderBy('ordre');
    }

    public function commandes(): HasMany
    {
        return $this->hasMany(ComClientEntete::class);
    }

    public function factures(): HasMany
    {
        return $this->hasMany(Facture::class);
    }

    public function updateEncours(): void
    {
        $this->encours_actuel = $this->factures()
            ->whereIn('statut', ['emise', 'partiellement_payee'])
            ->sum('reste_a_payer');
        $this->save();
    }

    public function peutCommander(float $montant): bool
    {
        if (! $this->encours_max) {
            return true;
        }

        return ($this->encours_actuel + $montant) <= $this->encours_max;
    }

    // Fiche client complète (phase 1.1) : relations des tables ajoutées, sans effet sur les existantes.

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(ClientCategorie::class, 'categorie_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(ClientContact::class)->orderByDesc('principal')->orderBy('nom');
    }

    public function adresses(): HasMany
    {
        return $this->hasMany(ClientAdresse::class)->orderByDesc('principale')->orderBy('libelle');
    }

    public function remarques(): HasMany
    {
        return $this->hasMany(ClientRemarque::class)->orderByDesc('epinglee')->latest()->orderByDesc('id');
    }

    public function favoris(): HasMany
    {
        return $this->hasMany(ClientFavori::class);
    }
}
