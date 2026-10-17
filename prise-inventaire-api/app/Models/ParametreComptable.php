<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Réglages de la comptabilisation automatique d'une entreprise : date de début, journaux, comptes de contrepartie et
 * correspondances (mode de paiement → caisse ou banque, taxe → comptes, catégorie de produit → comptes).
 */
class ParametreComptable extends Model
{
    use BelongsToTenant;

    public const JOURNAUX = ['journal_ventes_id', 'journal_achats_id', 'journal_banque_id', 'journal_caisse_id', 'journal_od_id'];

    public const COMPTES = [
        'compte_clients_id', 'compte_fournisseurs_id', 'compte_ventes_id', 'compte_achats_id', 'compte_tva_collectee_id',
        'compte_tva_deductible_id', 'compte_caisse_id', 'compte_banque_id', 'compte_acomptes_id', 'compte_escomptes_id',
        'compte_cartes_cadeaux_id', 'compte_escomptes_obtenus_id',
    ];

    protected $table = 'parametres_comptables';

    protected $fillable = ['tenant_id', 'date_debut', 'correspondances', ...self::JOURNAUX, ...self::COMPTES];

    protected $casts = [
        'date_debut' => 'date:Y-m-d',
        'correspondances' => 'array',
    ];

    /** Correspondance réglée (« modes », « taxes », « categories ») pour une clé, ou null. */
    public function correspondance(string $groupe, string|int $cle): mixed
    {
        return ($this->correspondances[$groupe] ?? [])[(string) $cle] ?? null;
    }
}
