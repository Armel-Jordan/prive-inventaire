<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Database\Eloquent\Model;

/**
 * Client de passage (plan de parité fripv2, phase 1.1 : le « client comptant ») : une fiche de l'entreprise, créée à sa
 * première utilisation, pour les ventes à des clients qui n'ont pas de fiche. Leurs coordonnées se notent sur le
 * document (devis, commande, facture), passent d'un document à l'autre et remplacent celles de la fiche sur les PDF
 * et les courriels.
 */
class ClientsDePassage
{
    public const CHAMPS = ['passage_nom', 'passage_telephone', 'passage_courriel', 'passage_adresse'];

    /** Fiche du client de passage de l'entreprise courante, créée au besoin. */
    public function client(): Client
    {
        $existant = Client::where('de_passage', true)->orderBy('id')->first();
        if ($existant) {
            return $existant;
        }
        $code = 'PASSAGE';
        for ($i = 2; Client::withTrashed()->where('code', $code)->exists(); $i++) {
            $code = "PASSAGE-{$i}";
        }

        return Client::create([
            'code' => $code,
            'raison_sociale' => 'Client de passage',
            'adresse_facturation' => '—',
            'ville' => '—',
            'code_postal' => '—',
            'actif' => true,
            'de_passage' => true,
        ]);
    }

    /** Règles de validation des coordonnées du client de passage d'un document. */
    public static function regles(): array
    {
        return [
            'passage_nom' => 'nullable|string|max:150',
            'passage_telephone' => 'nullable|string|max:30',
            'passage_courriel' => 'nullable|email|max:150',
            'passage_adresse' => 'nullable|string|max:255',
        ];
    }

    /** Coordonnées à enregistrer sur un document : seulement pour le client de passage. */
    public function coordonnees(array $donnees, ?Client $client): array
    {
        $deCeClient = $client?->de_passage;

        return collect(self::CHAMPS)->mapWithKeys(fn (string $champ) => [
            $champ => $deCeClient && filled($donnees[$champ] ?? null) ? trim((string) $donnees[$champ]) : null,
        ])->all();
    }

    /**
     * Coordonnées à mettre à jour sur un document modifié : toutes si le client change, sinon celles transmises
     * (une modification partielle n'efface pas les autres).
     */
    public function pourModification(array $donnees, Model $document, ?Client $client): array
    {
        if ($client && (int) $client->id !== (int) $document->getAttribute('client_id')) {
            return $this->coordonnees($donnees, $client);
        }

        return array_intersect_key($this->coordonnees($donnees, $client), array_intersect_key($donnees, array_flip(self::CHAMPS)));
    }

    /** Coordonnées d'un document reprises par celui qui en découle (devis → commande → facture). */
    public function copier(?Model $source): array
    {
        return collect(self::CHAMPS)->mapWithKeys(fn (string $champ) => [$champ => $source?->getAttribute($champ)])->all();
    }
}
