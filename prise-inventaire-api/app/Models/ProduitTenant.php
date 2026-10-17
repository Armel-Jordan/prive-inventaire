<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProduitTenant extends Model
{
    use BelongsToTenant, SoftDeletes;

    protected $connection = 'mysql';

    /** Suivi des exemplaires : par lot (numéro de lot à la réception) ou par numéro de série (un par unité). */
    public const SUIVIS = ['lot', 'serie'];

    /** Images acceptées comme image principale de la fiche (pas de SVG : il peut porter du script). */
    public const TYPES_IMAGE = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    protected $table = 'produits';

    protected $fillable = [
        'tenant_id',
        'numero',
        'description',
        'mesure',
        'unite_achat',
        'qte_par_unite_achat',
        'type',
        'secteur_id',
        'categorie',
        'prix_unitaire',
        'actif',
        'seuil_alerte',
        // Quantité visée par le réassort (suggestions d'achat) ; le seuil d'alerte sert de minimum.
        'stock_maximum',
        'fournisseur_principal_id',
        // Transport : matière dangereuse (numéro ONU, classe, groupe d'emballage), poids et volume unitaires.
        'numero_onu',
        'classe_danger',
        'groupe_emballage',
        'poids_kg',
        'volume_m3',
        // Fiche enrichie : fabricant et référence chez lui, description longue, image principale (une pièce jointe).
        'fabricant_id',
        'reference_fabricant',
        'description_longue',
        'image_id',
        // Suivi des exemplaires (phase 2.8) : vide, « lot » ou « serie » ; péremption exigée pour un lot.
        'suivi',
        'peremption',
    ];

    protected function casts(): array
    {
        return [
            'prix_unitaire' => 'decimal:4',
            'actif' => 'boolean',
            'seuil_alerte' => 'decimal:2',
            'stock_maximum' => 'decimal:2',
            'qte_par_unite_achat' => 'integer',
            'poids_kg' => 'decimal:4',
            'volume_m3' => 'decimal:6',
            'peremption' => 'boolean',
        ];
    }

    /** Matière dangereuse : numéro ONU ou classe de danger renseignés (et pas seulement des zéros), comme dans fripv2. */
    public function estDangereux(): bool
    {
        return (bool) preg_match('/[1-9]/', (string) $this->numero_onu.(string) $this->classe_danger);
    }

    /**
     * Vérifie si le stock est en alerte (sous le seuil)
     */
    public function isEnAlerte(float $quantiteActuelle): bool
    {
        if ($this->seuil_alerte === null || $this->seuil_alerte <= 0) {
            return false;
        }

        return $quantiteActuelle < $this->seuil_alerte;
    }

    public function secteur(): BelongsTo
    {
        return $this->belongsTo(Secteur::class);
    }

    /** Codes-barres du produit, le principal d'abord. */
    public function codesBarres(): HasMany
    {
        return $this->hasMany(ProduitCodeBarre::class, 'produit_id')->orderByDesc('principal')->orderBy('id');
    }

    /** Produits ajoutés d'office sur ses lignes de vente (annexes), avec leur quantité pour une unité. */
    public function annexes(): HasMany
    {
        return $this->hasMany(ProduitLien::class, 'produit_id')->where('type', ProduitLien::ANNEXE)->orderBy('id');
    }

    /** Composants de l'ensemble (kit), avec leur quantité pour un ensemble ; vide : un produit simple. */
    public function composants(): HasMany
    {
        return $this->hasMany(ProduitLien::class, 'produit_id')->where('type', ProduitLien::COMPOSANT)->orderBy('id');
    }

    /** Unités de vente du produit (en plus de son unité de stock), celle par défaut d'abord, puis par facteur. */
    public function unites(): HasMany
    {
        return $this->hasMany(ProduitUnite::class, 'produit_id')->orderByDesc('par_defaut')->orderBy('facteur')->orderBy('id');
    }

    public function fabricant(): BelongsTo
    {
        return $this->belongsTo(Fabricant::class, 'fabricant_id')->withTrashed();
    }

    /** Descriptions dans d'autres langues. */
    public function traductions(): HasMany
    {
        return $this->hasMany(ProduitTraduction::class, 'produit_id')->orderBy('langue');
    }

    /** Image principale : une image jointe à la fiche. */
    public function image(): BelongsTo
    {
        return $this->belongsTo(PieceJointe::class, 'image_id');
    }

    /** Description dans une langue : sa traduction s'il y en a une, sinon celle de la fiche. */
    public function descriptionEn(?string $langue, string $champ = 'description'): ?string
    {
        $traduction = $langue ? $this->traductions->firstWhere('langue', $langue) : null;

        return ($traduction?->{$champ} ?: null) ?? $this->{$champ};
    }

    public function fournisseurPrincipal(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class, 'fournisseur_principal_id');
    }

    public function fournisseurs(): BelongsToMany
    {
        return $this->belongsToMany(Fournisseur::class, 'produit_fournisseur', 'produit_id', 'fournisseur_id')
            ->withPivot(['reference_fournisseur', 'prix_achat', 'delai_livraison', 'fournisseur_principal', 'unite_achat', 'qte_par_unite_achat'])
            ->withTimestamps();
    }
}
