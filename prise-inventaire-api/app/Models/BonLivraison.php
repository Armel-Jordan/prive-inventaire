<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BonLivraison extends Model
{
    use HasFactory;

    protected $table = 'bons_livraison';

    protected $fillable = [
        'tenant_id', 'numero', 'facture_id', 'mode_livraison', 'statut',
        'date_preparation', 'date_pret', 'date_livraison',
        'preparateur_id', 'signature_client', 'notes_livraison',
    ];

    protected $casts = [
        'date_preparation' => 'datetime',
        'date_pret' => 'datetime',
        'date_livraison' => 'datetime',
    ];

    public static function generateNumero(): string
    {
        $year = date('Y');
        $last = self::where('numero', 'like', "BL-{$year}-%")->orderBy('id', 'desc')->first();
        $number = $last ? intval(substr($last->numero, -4)) + 1 : 1;
        return "BL-{$year}-" . str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    public function facture(): BelongsTo { return $this->belongsTo(Facture::class); }
    public function lignes(): HasMany { return $this->hasMany(BonLivraisonLigne::class, 'bon_id'); }
    public function tourneeBon(): HasOne { return $this->hasOne(TourneeBon::class, 'bon_livraison_id'); }

    public function estComplet(): bool
    {
        return $this->lignes->every(fn($l) => $l->quantite_livree >= $l->quantite_a_livrer);
    }

    public function estPartiel(): bool
    {
        $totalLivre = $this->lignes->sum('quantite_livree');
        $totalALivrer = $this->lignes->sum('quantite_a_livrer');
        return $totalLivre > 0 && $totalLivre < $totalALivrer;
    }
}
