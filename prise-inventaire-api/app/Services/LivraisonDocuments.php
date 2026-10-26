<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientAdresse;
use App\Models\ClientContact;
use App\Support\TenantRule;
use Illuminate\Database\Eloquent\Model;

/**
 * « Livrer à » et contact des documents de vente (plan de parité fripv2, socle S4.1) : le contact du client et son
 * adresse de livraison sur un devis, une commande, une facture ou un bon de livraison.
 *
 * - À la création : la valeur demandée (même vide), sinon le contact principal et l'adresse de livraison principale du
 *   client.
 * - L'adresse est copiée sur le document au moment où elle est choisie (`livraison_adresse`, avec ses instructions) :
 *   une adresse modifiée ou retirée ensuite ne change pas les documents faits.
 * - D'un document à celui qu'on en tire (conversion, copie, facture d'une commande, bon d'une facture), tout suit.
 * - Le contact et l'adresse doivent être ceux du client du document.
 */
class LivraisonDocuments
{
    /** Colonnes copiées d'un document à l'autre. */
    public const COLONNES = ['contact_id', 'adresse_livraison_id', 'livraison_adresse', 'instructions_livraison'];

    /**
     * Règles : un contact et une adresse actifs du client du document (ceux déjà sur le document restent acceptés).
     *
     * @param  Model|null  $document  le document modifié (null à la création)
     */
    public static function regles(?Model $document = null): array
    {
        $client = fn () => request()->input('client_id', $document?->getAttribute('client_id'));
        $regle = fn (string $table, string $champ) => ['nullable', 'integer', TenantRule::exists($table)->where(fn ($q) => $q
            ->where('client_id', $client())
            ->where(fn ($q) => $q->where('actif', true)->when($document?->getAttribute($champ), fn ($q, $actuel) => $q->orWhere('id', $actuel))))];

        return [
            'contact_id' => $regle('client_contacts', 'contact_id'),
            'adresse_livraison_id' => $regle('client_adresses', 'adresse_livraison_id'),
        ];
    }

    /** À la création : la valeur demandée (même vide), sinon le contact principal et l'adresse de livraison principale. */
    public function pourCreation(array $donnees, ?Client $client): array
    {
        if (! $client || $client->de_passage) {
            return array_fill_keys(self::COLONNES, null);
        }
        $contact = array_key_exists('contact_id', $donnees)
            ? $donnees['contact_id']
            : ClientContact::query()->where('client_id', $client->id)->where('actif', true)->orderByDesc('principal')->orderBy('id')->value('id');
        $adresse = array_key_exists('adresse_livraison_id', $donnees)
            ? $donnees['adresse_livraison_id']
            : ClientAdresse::query()->where('client_id', $client->id)->where('actif', true)->where('livraison', true)
                ->orderByDesc('principale')->orderBy('id')->value('id');

        return ['contact_id' => $contact ? (int) $contact : null] + $this->adresse($adresse ? (int) $adresse : null);
    }

    /** À la modification : seulement ce qui est envoyé ; l'adresse choisie est recopiée. */
    public function pourModification(array $donnees, Model $document): array
    {
        $valeurs = [];
        if (array_key_exists('contact_id', $donnees)) {
            $valeurs['contact_id'] = $donnees['contact_id'] ? (int) $donnees['contact_id'] : null;
        }
        if (array_key_exists('adresse_livraison_id', $donnees)) {
            $id = $donnees['adresse_livraison_id'] ? (int) $donnees['adresse_livraison_id'] : null;
            // La même adresse garde sa copie (celle du jour du document) ; une autre est recopiée.
            if ($id !== $document->getAttribute('adresse_livraison_id')) {
                $valeurs += $this->adresse($id);
            }
        }

        return $valeurs;
    }

    /** D'un document à celui qu'on en tire. */
    public function copier(Model $source): array
    {
        return array_combine(self::COLONNES, array_map(fn (string $colonne) => $source->getAttribute($colonne), self::COLONNES));
    }

    /** L'adresse et sa copie (libellé, adresse, ville et code postal, pays), avec ses instructions de livraison. */
    private function adresse(?int $id): array
    {
        $adresse = $id ? ClientAdresse::query()->find($id) : null;
        if (! $adresse) {
            return ['adresse_livraison_id' => null, 'livraison_adresse' => null, 'instructions_livraison' => null];
        }

        return [
            'adresse_livraison_id' => $adresse->id,
            'livraison_adresse' => implode("\n", array_filter([
                $adresse->libelle,
                $adresse->adresse,
                trim(($adresse->code_postal ?? '').' '.($adresse->ville ?? '')),
                $adresse->pays,
            ], fn ($ligne) => filled($ligne))),
            'instructions_livraison' => filled($adresse->instructions) ? mb_substr((string) $adresse->instructions, 0, 500) : null,
        ];
    }
}
