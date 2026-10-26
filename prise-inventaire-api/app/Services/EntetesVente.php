<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Facture;
use App\Models\Representant;
use App\Models\Transporteur;
use App\Support\TenantRule;
use Illuminate\Database\Eloquent\Model;

/**
 * En-tête commercial des documents de vente (plan de parité fripv2, phase 1.11) : le représentant et le transporteur
 * d'un devis, d'une commande ou d'une facture.
 *
 * - À la création, la valeur demandée (même vide), sinon celle du client.
 * - D'un document à celui qu'on en tire (conversion, copie, reste à facturer), elle suit le document d'origine.
 * - Une facture fige le taux de commission du représentant : un changement de taux ne touche pas les factures faites.
 */
class EntetesVente
{
    /** Champ de l'en-tête => colonne du client qui donne sa valeur par défaut. */
    public const CHAMPS = ['representant_id' => 'representant_id', 'transporteur_id' => 'transporteur_id'];

    /**
     * Règles de validation ; la valeur actuelle du document reste acceptée si elle a été retirée du référentiel depuis.
     * Sur un document (pas sur la fiche d'un client, $tiers), l'option « transporteur obligatoire » s'applique.
     */
    public static function regles(?Model $document = null, bool $tiers = false): array
    {
        $regle = fn (string $table, string $champ) => ['nullable', 'integer', TenantRule::exists($table)->where(fn ($q) => $q->whereNull('deleted_at')
            ->when($document?->getAttribute($champ), fn ($q, $actuel) => $q->orWhere('id', $actuel)))];

        return [
            'representant_id' => $regle('representants', 'representant_id'),
            'transporteur_id' => [...$regle('transporteurs', 'transporteur_id'),
                ...($tiers ? [] : [Transporteur::exige($document, fn () => Client::whereKey(request()->input('client_id', $document?->getAttribute('client_id')))->value('transporteur_id'))])],
        ];
    }

    /** À la création : la valeur demandée (même vide), sinon celle du client. */
    public function pourCreation(array $donnees, ?Client $client): array
    {
        $entete = [];
        foreach (self::CHAMPS as $champ => $colonneClient) {
            $entete[$champ] = array_key_exists($champ, $donnees) ? self::entier($donnees[$champ]) : self::entier($client?->getAttribute($colonneClient));
        }

        return $entete;
    }

    /** À la modification : seulement les champs envoyés. */
    public function pourModification(array $donnees): array
    {
        $entete = [];
        foreach (array_keys(self::CHAMPS) as $champ) {
            if (array_key_exists($champ, $donnees)) {
                $entete[$champ] = self::entier($donnees[$champ]);
            }
        }

        return $entete;
    }

    /** D'un document à celui qu'on en tire. */
    public function copier(Model $source): array
    {
        $entete = [];
        foreach (array_keys(self::CHAMPS) as $champ) {
            $entete[$champ] = self::entier($source->getAttribute($champ));
        }

        return $entete;
    }

    /**
     * Taux de commission figé sur une facture : celui de la facture d'origine pour le même représentant (reste à
     * facturer), sinon le taux actuel du représentant.
     */
    public function commission(?int $representantId, ?Model $source = null): ?float
    {
        if (! $representantId) {
            return null;
        }
        if ($source instanceof Facture && (int) $source->representant_id === $representantId) {
            return $source->taux_commission !== null ? (float) $source->taux_commission : null;
        }
        $taux = Representant::withTrashed()->whereKey($representantId)->value('taux_commission');

        return $taux !== null ? (float) $taux : null;
    }

    private static function entier(mixed $valeur): ?int
    {
        return $valeur === null || $valeur === '' ? null : (int) $valeur;
    }
}
