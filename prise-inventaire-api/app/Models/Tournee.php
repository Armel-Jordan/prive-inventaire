<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tournee extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'numero', 'date_tournee', 'camion_id', 'livreur_id', 'zone',
        'statut', 'heure_depart', 'heure_retour', 'km_depart', 'km_retour',
    ];

    protected $casts = [
        'date_tournee' => 'date',
        'heure_depart' => 'datetime:H:i',
        'heure_retour' => 'datetime:H:i',
        'km_depart' => 'integer',
        'km_retour' => 'integer',
    ];

    public static function generateNumero(): string
    {
        $year = date('Y');
        $last = self::where('numero', 'like', "TRN-{$year}-%")->orderBy('id', 'desc')->first();
        $number = $last ? intval(substr($last->numero, -4)) + 1 : 1;

        return "TRN-{$year}-".str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    public function camion(): BelongsTo
    {
        return $this->belongsTo(Camion::class);
    }

    public function tourneeBons(): HasMany
    {
        return $this->hasMany(TourneeBon::class)->orderBy('ordre_livraison');
    }

    public function bonsLivraison()
    {
        return $this->hasManyThrough(BonLivraison::class, TourneeBon::class, 'tournee_id', 'id', 'id', 'bon_livraison_id');
    }
}
