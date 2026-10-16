<?php

namespace App\Support;

use App\Models\Approbation;
use Illuminate\Support\Facades\DB;

/**
 * Seuils d'approbation des mouvements (réglés dans Approbations › Paramètres, par type de mouvement). Un mouvement
 * dont la quantité atteint le seuil actif de son type devient une demande d'approbation au lieu d'être enregistré.
 *
 * Les seuils n'étaient appliqués que par POST /approbations, que ni l'écran Relocalisation ni l'application mobile
 * n'appellent : un transfert de 5 000 unités passait sans approbation.
 */
class SeuilsApprobation
{
    /** Types de mouvement soumis aux seuils (un ajustement ne l'est pas). */
    public const TYPES = ['arrivage', 'transfert', 'sortie'];

    /** Seuil actif d'un type de mouvement pour l'entreprise, ou null s'il n'y en a pas. */
    public static function seuil(string $type, int $tenantId): ?float
    {
        if (! in_array($type, self::TYPES, true)) {
            return null;
        }

        $parametre = DB::table('parametres_approbation')
            ->where('tenant_id', $tenantId)
            ->where('type_mouvement', $type)
            ->where('actif', true)
            ->first();

        return $parametre ? (float) $parametre->seuil_quantite : null;
    }

    /**
     * Demande d'approbation créée pour ce mouvement s'il atteint le seuil de son type ; null s'il peut être
     * enregistré directement.
     *
     * @param  array{type: string, produit_numero: string, produit_nom?: ?string, secteur_source?: ?string, secteur_destination?: ?string, quantite: float|int|string, unite_mesure?: ?string, motif?: ?string}  $mouvement
     */
    public static function soumettre(array $mouvement, int $tenantId, string $demandeur): ?Approbation
    {
        $seuil = self::seuil($mouvement['type'], $tenantId);
        if ($seuil === null || (float) $mouvement['quantite'] < $seuil) {
            return null;
        }

        return Approbation::create([
            'tenant_id' => $tenantId,
            'type_mouvement' => $mouvement['type'],
            'produit_numero' => $mouvement['produit_numero'],
            'produit_nom' => $mouvement['produit_nom'] ?? null,
            'secteur_source' => $mouvement['secteur_source'] ?? null,
            'secteur_destination' => $mouvement['secteur_destination'] ?? null,
            'quantite' => $mouvement['quantite'],
            'unite_mesure' => $mouvement['unite_mesure'] ?? null,
            'motif' => $mouvement['motif'] ?? null,
            'demandeur' => $demandeur,
            'statut' => 'en_attente',
            'seuil_declenchement' => $seuil,
        ]);
    }
}
