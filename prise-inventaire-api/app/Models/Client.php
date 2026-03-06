<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'raison_sociale',
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
        return 'CLI-' . str_pad($number, 4, '0', STR_PAD_LEFT);
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
        if (!$this->encours_max) {
            return true;
        }
        return ($this->encours_actuel + $montant) <= $this->encours_max;
    }
}
