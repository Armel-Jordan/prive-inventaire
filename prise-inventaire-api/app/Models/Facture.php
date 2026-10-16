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
        'date_facture', 'date_echeance', 'statut',
        'montant_ht', 'montant_tva', 'montant_ttc',
        'montant_paye', 'reste_a_payer', 'notes',
    ];

    protected $casts = [
        'date_facture' => 'date',
        'date_echeance' => 'date',
        'montant_ht' => 'decimal:2',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
        'montant_paye' => 'decimal:2',
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

    /** Bon de livraison en cours de la facture : le plus récent qui n'est pas annulé. */
    public function bonLivraison(): HasOne
    {
        return $this->hasOne(BonLivraison::class)->where('statut', '!=', 'annule')->latest('id');
    }

    public function updateMontantPaye(): void
    {
        $this->montant_paye = $this->paiements()->sum('montant');
        $this->reste_a_payer = $this->montant_ttc - $this->montant_paye;
        if ($this->reste_a_payer <= 0) {
            $this->statut = 'payee';
        } elseif ($this->montant_paye > 0) {
            $this->statut = 'partiellement_payee';
        }
        $this->save();
        $this->repartirSurEcheances();
        $this->client->updateEncours();
    }

    /**
     * Répartit le montant payé sur les échéances, dans leur ordre : chacune passe payée, en partie payée
     * ou en attente. Elles restaient « en attente » même une fois la facture entièrement payée.
     */
    private function repartirSurEcheances(): void
    {
        $reste = (float) $this->montant_paye;
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
