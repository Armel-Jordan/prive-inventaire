<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BonLivraison extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'bons_livraison';

    protected $fillable = [
        'tenant_id', 'numero', 'facture_id', 'mode_livraison', 'statut',
        'date_preparation', 'date_pret', 'date_livraison',
        'preparateur_id', 'signature_client', 'notes_livraison',
        // Signataire et moment de la signature électronique.
        'signataire_nom', 'signe_le',
    ];

    protected $casts = [
        'date_preparation' => 'datetime',
        'date_pret' => 'datetime',
        'date_livraison' => 'datetime',
        'signe_le' => 'datetime',
    ];

    /** L'image de la signature (lourde) ne sort qu'avec le détail du bon ; les listes disent seulement s'il est signé. */
    protected $hidden = ['signature_client'];

    protected $appends = ['a_signature'];

    public function getASignatureAttribute(): bool
    {
        return filled($this->attributes['signature_client'] ?? null);
    }

    public static function generateNumero(): string
    {
        $year = date('Y');
        $last = self::where('numero', 'like', "BL-{$year}-%")->orderBy('id', 'desc')->first();
        $number = $last ? intval(substr($last->numero, -4)) + 1 : 1;

        return "BL-{$year}-".str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    public function facture(): BelongsTo
    {
        return $this->belongsTo(Facture::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(BonLivraisonLigne::class, 'bon_id');
    }

    /** Colis du bon (préparation des boîtes). */
    public function colis(): HasMany
    {
        return $this->hasMany(ColisLivraison::class);
    }

    public function tourneeBon(): HasOne
    {
        return $this->hasOne(TourneeBon::class, 'bon_livraison_id');
    }

    public function estComplet(): bool
    {
        return $this->lignes->every(fn ($l) => $l->quantite_livree >= $l->quantite_a_livrer);
    }

    public function estPartiel(): bool
    {
        $totalLivre = $this->lignes->sum('quantite_livree');
        $totalALivrer = $this->lignes->sum('quantite_a_livrer');

        return $totalLivre > 0 && $totalLivre < $totalALivrer;
    }
}
