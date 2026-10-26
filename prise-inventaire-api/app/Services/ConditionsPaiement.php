<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ConditionPaiement;
use App\Models\Facture;
use App\Support\TenantRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Conditions de paiement (référentiel, plan de parité fripv2, phase 1.5) appliquées aux factures :
 * - la condition d'une facture : celle choisie, sinon celle du client, sinon celle par défaut de l'entreprise ;
 * - son échéance : date de la facture plus le délai, au dernier jour du mois pour une condition « fin de mois » ;
 * - l'escompte pour paiement rapide (« 2/10 Net 30 ») : proposé, comme dans fripv2, au premier paiement de la
 *   facture fait au plus tard à sa date plus les jours d'escompte ; il porte sur tout ce qui reste dû (notes de crédit
 *   déduites) et n'est accordé que si le paiement règle ce reste.
 *
 * Les devis et les commandes portent aussi leur condition (socle S4.1) : celle choisie, sinon celle du client, sinon
 * celle par défaut ; elle passe du devis à la commande, puis à la facture, qui en calcule l'échéance.
 */
class ConditionsPaiement
{
    /** Factures qui peuvent encore recevoir un paiement (donc un escompte). */
    private const FACTURES_DUES = ['emise', 'partiellement_payee'];

    public function pourFacture(?int $conditionId, ?Client $client): ?ConditionPaiement
    {
        $id = $conditionId ?: $client?->condition_paiement_id;
        $condition = $id ? ConditionPaiement::where('actif', true)->find($id) : null;

        return $condition ?? ConditionPaiement::where('actif', true)->where('par_defaut', true)->first();
    }

    /** Règle d'un devis ou d'une commande : une condition du référentiel ; celle du document reste acceptée si elle a été retirée. */
    public static function regle(?Model $document = null): array
    {
        return ['nullable', 'integer', TenantRule::exists('conditions_paiement')->where(fn ($q) => $q->whereNull('deleted_at')
            ->when($document?->getAttribute('condition_paiement_id'), fn ($q, $actuel) => $q->orWhere('id', $actuel)))];
    }

    /** Condition d'un nouveau devis ou d'une nouvelle commande : celle choisie, sinon celle du client, sinon celle par défaut. */
    public function pourCreation(array $donnees, ?Client $client): array
    {
        $choisie = ! empty($donnees['condition_paiement_id']) ? (int) $donnees['condition_paiement_id'] : null;

        return ['condition_paiement_id' => $this->pourFacture($choisie, $client)?->id];
    }

    /** À la modification : seulement si elle est envoyée et change (vide : celle du client, sinon celle par défaut). */
    public function pourModification(array $donnees, Model $document, ?Client $client): array
    {
        if (! array_key_exists('condition_paiement_id', $donnees)
            || (int) $donnees['condition_paiement_id'] === (int) $document->getAttribute('condition_paiement_id')) {
            return [];
        }

        return $this->pourCreation($donnees, $client);
    }

    public function echeance(ConditionPaiement $condition, Carbon|string $date): Carbon
    {
        $echeance = Carbon::parse($date)->startOfDay()->addDays((int) $condition->delai_jours);

        return $condition->fin_de_mois ? $echeance->endOfMonth()->startOfDay() : $echeance;
    }

    /**
     * Escompte possible sur la facture pour un paiement à cette date, ou null si sa condition n'en prévoit pas, ou
     * si la facture a déjà reçu un paiement.
     *
     * @return array{applicable: bool, condition: string, pourcentage: float, jours: int, date_limite: string, escompte: float, a_payer: float}|null
     */
    public function escompte(Facture $facture, string $date): ?array
    {
        $condition = $facture->condition_paiement_id ? ConditionPaiement::withTrashed()->find($facture->condition_paiement_id) : null;
        $taux = (float) ($condition?->escompte_pourcentage ?? 0);
        if (! $condition || $taux <= 0 || (float) $facture->montant_paye > 0 || ! in_array($facture->statut, self::FACTURES_DUES, true)) {
            return null;
        }
        $limite = $facture->date_facture->copy()->addDays((int) $condition->escompte_jours);
        $reste = (float) $facture->reste_a_payer;
        $escompte = round($reste * $taux / 100, 2);

        return [
            'applicable' => $date <= $limite->toDateString() && $escompte > 0,
            'condition' => $condition->libelle,
            'pourcentage' => $taux,
            'jours' => (int) $condition->escompte_jours,
            'date_limite' => $limite->toDateString(),
            'escompte' => $escompte,
            'a_payer' => round($reste - $escompte, 2),
        ];
    }
}
