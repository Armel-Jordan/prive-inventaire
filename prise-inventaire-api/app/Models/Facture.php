<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Facture extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'numero', 'commande_id', 'client_id', 'facture_mere_id',
        'date_facture', 'date_echeance', 'condition_paiement_id', 'statut',
        'montant_ht', 'montant_tva', 'montant_ttc',
        'montant_paye', 'montant_credite', 'montant_escompte', 'reste_a_payer', 'notes',
    ];

    protected $casts = [
        'date_facture' => 'date',
        'date_echeance' => 'date',
        'montant_ht' => 'decimal:2',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
        'montant_paye' => 'decimal:2',
        'montant_credite' => 'decimal:2',
        'montant_escompte' => 'decimal:2',
        'reste_a_payer' => 'decimal:2',
    ];

    public static function generateNumero(): string
    {
        $year = date('Y');
        $last = self::where('numero', 'like', "FAC-{$year}-%")->orderBy('id', 'desc')->first();
        $number = $last ? intval(substr($last->numero, -4)) + 1 : 1;

        return "FAC-{$year}-".str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(ComClientEntete::class, 'commande_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function factureMere(): BelongsTo
    {
        return $this->belongsTo(Facture::class, 'facture_mere_id');
    }

    public function facturesFilles(): HasMany
    {
        return $this->hasMany(Facture::class, 'facture_mere_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(FactureLigne::class);
    }

    public function echeances(): HasMany
    {
        return $this->hasMany(FactureEcheance::class)->orderBy('ordre');
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(FacturePaiement::class);
    }

    /** Condition de paiement de la facture (délai, escompte pour paiement rapide). */
    public function conditionPaiement(): BelongsTo
    {
        return $this->belongsTo(ConditionPaiement::class)->withTrashed();
    }

    /** Notes de crédit (avoirs) établies sur la facture. */
    public function avoirs(): HasMany
    {
        return $this->hasMany(Avoir::class);
    }

    /** Relances de paiement de la facture. */
    public function relances(): HasMany
    {
        return $this->hasMany(FactureRelance::class);
    }

    /** Crédits imputés sur la facture (de ses propres notes de crédit ou d'autres notes du client). */
    public function imputations(): HasMany
    {
        return $this->hasMany(AvoirImputation::class);
    }

    /** Bon de livraison en cours de la facture : le plus récent qui n'est pas annulé. */
    public function bonLivraison(): HasOne
    {
        return $this->hasOne(BonLivraison::class)->where('statut', '!=', 'annule')->latest('id');
    }

    /**
     * Recalcule ce qui est payé et ce qui reste dû. Les crédits imputés sur la facture (montant_credite) et les
     * escomptes accordés à ses paiements (montant_escompte) réduisent le reste à payer. Une facture soldée sans aucun
     * paiement est « creditee » ; soldée avec des paiements, « payee ».
     */
    public function updateMontantPaye(): void
    {
        $this->montant_paye = $this->paiements()->sum('montant');
        $this->montant_escompte = $this->paiements()->sum('escompte');
        $this->montant_credite = $this->imputations()->sum('montant');
        $this->reste_a_payer = round((float) $this->montant_ttc - (float) $this->montant_paye - (float) $this->montant_credite - (float) $this->montant_escompte, 2);
        if ($this->reste_a_payer <= 0) {
            $this->statut = (float) $this->montant_paye > 0 || (float) $this->montant_credite <= 0 ? 'payee' : 'creditee';
        } elseif ($this->montant_paye > 0) {
            $this->statut = 'partiellement_payee';
        }
        $this->save();
        $this->repartirSurEcheances();
        $this->client->updateEncours();
    }

    /**
     * Répartit le montant réglé (paiements, escomptes et notes de crédit imputées) sur les échéances, dans leur ordre :
     * chacune passe payée, en partie payée ou en attente. Elles restaient « en attente » même une fois la facture
     * entièrement payée.
     */
    private function repartirSurEcheances(): void
    {
        $reste = (float) $this->montant_paye + (float) $this->montant_credite + (float) $this->montant_escompte;
        foreach ($this->echeances()->get() as $echeance) {
            $paye = round(min((float) $echeance->montant, max(0.0, $reste)), 2);
            $reste -= $paye;
            $echeance->update([
                'montant_paye' => $paye,
                'statut' => $paye >= (float) $echeance->montant ? 'payee' : ($paye > 0 ? 'partiellement_payee' : 'en_attente'),
            ]);
        }
    }
}
