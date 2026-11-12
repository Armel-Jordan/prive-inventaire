<?php

namespace App\Services;

use App\Models\Avoir;
use App\Models\ClientContact;
use App\Models\ComClientEntete;
use App\Models\ConditionPaiement;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\Representant;
use App\Models\Transporteur;
use App\Support\EntreprisePdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Identité figée des documents de vente (plan de parité fripv2, socle S4.5).
 *
 * - Quand un devis, une commande, une facture ou une note de crédit quitte le brouillon (ou naît hors brouillon), il
 *   retient une fois pour toutes : les coordonnées de l'entreprise, le destinataire, le code du client et les libellés
 *   de l'en-tête (représentant, transporteur, condition de paiement, contact). Son PDF les reprend : renommer le client
 *   ou changer l'adresse de l'entreprise ne change plus un document envoyé ou émis.
 * - Les factures et notes de crédit émises comptent leurs copies réelles (PDF ouvert, courriel envoyé) ; à partir de la
 *   deuxième, le PDF porte la mention « Réimpression ».
 */
class IdentitesFigees
{
    /** Documents qui retiennent leur identité en quittant le brouillon. */
    public const DOCUMENTS = [Devis::class, ComClientEntete::class, Facture::class, Avoir::class];

    /** Tables des documents qui comptent leurs copies. */
    private const COPIES = [Facture::class => 'factures', Avoir::class => 'avoirs'];

    /** Avant chaque enregistrement : un document qui quitte le brouillon (ou naît hors brouillon) fige son identité. */
    public function surEnregistrement(Model $document): void
    {
        if ($document->getAttribute('identite_figee') !== null || $document->getAttribute('statut') === 'brouillon') {
            return;
        }
        if ($document->exists && (! $document->isDirty('statut') || $document->getOriginal('statut') !== 'brouillon')) {
            return;
        }
        $document->setAttribute('identite_figee', $this->identite($document));
    }

    /**
     * Copie réelle d'une facture ou d'une note de crédit émise (PDF ouvert, courriel envoyé) : comptée, sans toucher au
     * journal des modifications. Un brouillon ne compte pas.
     */
    public function compterCopie(Model $document): void
    {
        $table = self::COPIES[$document::class] ?? null;
        if (! $table || $document->getAttribute('statut') === 'brouillon') {
            return;
        }
        DB::table($table)->where('id', $document->getKey())->increment('nb_impressions');
        $document->setAttribute('nb_impressions', (int) $document->getAttribute('nb_impressions') + 1);
        $document->syncOriginalAttribute('nb_impressions');
    }

    /**
     * @return array{le: string, entreprise: array, client_code: ?string, destinataire: array, representant: ?string, transporteur: ?string, condition_paiement: ?string, contact: ?string}
     */
    private function identite(Model $document): array
    {
        $client = $document->getRelationValue('client');
        // Le client de passage d'une note de crédit a ses coordonnées sur sa facture.
        $coordonnees = $document instanceof Avoir ? $document->getRelationValue('facture') : $document;
        $contact = $document->getAttribute('contact_id') ? ClientContact::query()->find($document->getAttribute('contact_id')) : null;

        return [
            'le' => now()->toIso8601String(),
            'entreprise' => EntreprisePdf::entete(),
            'client_code' => $client?->code,
            'destinataire' => app(DocumentsVentePdf::class)->destinataire($client, false, $coordonnees),
            'representant' => $this->nom(Representant::class, $document->getAttribute('representant_id'), 'nom'),
            'transporteur' => $this->nom(Transporteur::class, $document->getAttribute('transporteur_id'), 'nom'),
            'condition_paiement' => $this->nom(ConditionPaiement::class, $document->getAttribute('condition_paiement_id'), 'libelle'),
            'contact' => $contact ? implode(' — ', array_filter([$contact->nom, $contact->telephone])) : null,
            // Note de bas de page du jour (socle S4.4) : une réimpression ressort la même.
            'note_bas_page' => app(NotesBasPage::class)->actuelle($document),
        ];
    }

    /** @param  class-string<Model>  $modele */
    private function nom(string $modele, mixed $id, string $colonne): ?string
    {
        return $id ? $modele::withTrashed()->whereKey($id)->value($colonne) : null;
    }
}
