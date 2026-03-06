<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ComClientEntete extends Model
{
    use HasFactory;

    protected $table = 'com_client_entete';

    protected $fillable = [
        'numero', 'client_id', 'date_commande', 'date_livraison_souhaitee',
        'statut', 'remise_globale', 'montant_ht', 'montant_tva', 'montant_ttc',
        'notes', 'motif_refus', 'validee_par', 'created_by',
    ];

    protected $casts = [
        'date_commande' => 'date',
        'date_livraison_souhaitee' => 'date',
        'remise_globale' => 'decimal:2',
        'montant_ht' => 'decimal:2',
        'montant_tva' => 'decimal:2',
        'montant_ttc' => 'decimal:2',
    ];

    public static function generateNumero(): string
    {
        $year = date('Y');
        $last = self::where('numero', 'like', "CMD-{$year}-%")->orderBy('id', 'desc')->first();
        $number = $last ? intval(substr($last->numero, -4)) + 1 : 1;
        return "CMD-{$year}-" . str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function lignes(): HasMany { return $this->hasMany(ComClientLigne::class, 'com_entete_id'); }
    public function facture(): HasOne { return $this->hasOne(Facture::class, 'commande_id'); }

    public function calculerMontants(): void
    {
        $this->montant_ht = $this->lignes->sum('montant_ht');
        $this->montant_tva = $this->lignes->sum(fn($l) => $l->montant_ttc - $l->montant_ht);
        $this->montant_ttc = $this->lignes->sum('montant_ttc');
        if ($this->remise_globale > 0) {
            $this->montant_ht *= (1 - $this->remise_globale / 100);
            $this->montant_tva *= (1 - $this->remise_globale / 100);
            $this->montant_ttc *= (1 - $this->remise_globale / 100);
        }
    }
}
