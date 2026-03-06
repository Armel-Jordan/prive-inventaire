<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComFourEntete extends Model
{
    use HasFactory;

    protected $table = 'com_four_entete';

    protected $fillable = [
        'numero',
        'fournisseur_id',
        'date_commande',
        'date_livraison_prevue',
        'statut',
        'montant_total',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'date_commande' => 'date',
        'date_livraison_prevue' => 'date',
        'montant_total' => 'decimal:2',
    ];

    const STATUT_BROUILLON = 'brouillon';
    const STATUT_ENVOYEE = 'envoyee';
    const STATUT_PARTIELLE = 'partielle';
    const STATUT_COMPLETE = 'complete';
    const STATUT_ANNULEE = 'annulee';

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(ComFourLigne::class, 'com_four_entete_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
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

        return 'CF-' . $year . '-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
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
