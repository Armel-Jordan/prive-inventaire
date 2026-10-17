<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Avoir;
use App\Models\Configuration;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\MouvementTenant;
use App\Models\ProduitTenant;
use App\Models\Raison;
use App\Models\RetourClient;
use App\Models\RetourClientLigne;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Retours clients (plan de parité fripv2, phase 1.7) : la marchandise rapportée par un client, avec la raison du
 * retour, comme les lignes de retour des factures de fripv2.
 *
 * - Retour d'une facture (quantité au plus celle facturée, retours traités déduits) ou sans facture d'origine
 *   (n'importe quel produit).
 * - Chaque ligne est remise en stock dans un secteur ou mise au rebut. Une raison (référentiel, type
 *   « retour_client ») est exigée dès que l'entreprise en a défini.
 * - Brouillon modifiable, puis traité : numéro (numérotation « retour_client »), et chaque remise en stock crée un
 *   arrivage dans son secteur (mouvement de stock, compté comme une entrée par les prises d'inventaire). Le rebut
 *   ne fait entrer rien en stock. Un retour traité ne se modifie plus.
 * - Le retour traité d'une facture donne sa note de crédit (brouillon, quantités retournées au prix facturé).
 */
class RetoursClients
{
    /** Factures dont la marchandise peut revenir : émises, payées ou non, créditées comprises. */
    public const FACTURES_RETOURNABLES = ['emise', 'partiellement_payee', 'payee', 'creditee'];

    public function __construct(private readonly NotesCredit $notes) {}

    /**
     * Ce qu'on peut encore retourner d'une facture : chaque ligne avec les quantités facturée et déjà retournée
     * (retours traités), le reste, et le secteur du produit proposé pour la remise en stock.
     */
    public function proposition(Facture $facture): array
    {
        $facture->loadMissing(['client:id,code,raison_sociale', 'lignes.produit' => fn ($q) => $q->withTrashed()->select('id', 'numero', 'description', 'mesure', 'secteur_id')]);
        $retournes = $this->dejaRetourne($facture);

        return [
            'facture' => $facture->only(['id', 'numero', 'statut', 'client_id'])
                + ['date_facture' => $facture->date_facture?->toDateString(), 'client' => $facture->client?->only(['id', 'code', 'raison_sociale'])],
            'retournable' => in_array($facture->statut, self::FACTURES_RETOURNABLES, true),
            'lignes' => $facture->lignes->map(fn (FactureLigne $ligne) => [
                'facture_ligne_id' => $ligne->id,
                'produit_id' => $ligne->produit_id,
                'produit' => $ligne->produit?->only(['id', 'numero', 'description', 'mesure']),
                'secteur_id' => $ligne->produit?->secteur_id,
                'quantite_facturee' => (int) $ligne->quantite,
                // Unité facturée (la caisse de 12) ; vide : l'unité de stock.
                'unite' => $ligne->unite,
                'quantite_retournee' => $retournes[$ligne->id] ?? 0,
                'reste' => max(0, (int) $ligne->quantite - ($retournes[$ligne->id] ?? 0)),
            ])->values()->all(),
        ];
    }

    /**
     * Enregistre un retour brouillon : nouveau, ou remplace le contenu d'un brouillon (client et facture restent
     * ceux de sa création).
     *
     * @param  array{client_id?: ?int, facture_id?: ?int, date_retour: string, notes?: ?string, lignes: list<array>}  $donnees
     */
    public function enregistrer(array $donnees, ?RetourClient $retour = null, ?int $auteur = null): RetourClient
    {
        if ($retour && $retour->statut !== RetourClient::BROUILLON) {
            throw new DomainException('Un retour traité ne se modifie plus.');
        }
        $facture = $retour ? $retour->facture : (empty($donnees['facture_id']) ? null : Facture::find($donnees['facture_id']));
        $clientId = $retour?->client_id ?? (int) ($donnees['client_id'] ?? $facture?->client_id);
        if ($facture) {
            if ($facture->client_id !== $clientId) {
                throw ValidationException::withMessages(['facture_id' => "La facture {$facture->numero} n'est pas une facture de ce client."]);
            }
            $this->verifierRetournable($facture);
            if ($donnees['date_retour'] < $facture->date_facture->toDateString()) {
                throw ValidationException::withMessages(['date_retour' => 'Le retour ne peut pas précéder la facture ('.$facture->date_facture->format('d/m/Y').').']);
            }
        }
        $lignes = $this->preparerLignes($facture, $donnees['lignes'], $retour?->id);

        return DB::transaction(function () use ($donnees, $retour, $auteur, $facture, $clientId, $lignes) {
            $entete = ['date_retour' => $donnees['date_retour'], 'notes' => $donnees['notes'] ?? null];
            if ($retour) {
                $retour->lignes()->delete();
                $retour->update($entete);
            } else {
                $retour = RetourClient::create($entete + [
                    'client_id' => $clientId,
                    'facture_id' => $facture?->id,
                    'statut' => RetourClient::BROUILLON,
                    'cree_par' => $auteur,
                ]);
            }
            foreach ($lignes as $ligne) {
                RetourClientLigne::create(['retour_client_id' => $retour->id] + $ligne);
            }

            return $retour;
        });
    }

    /**
     * Traite un retour brouillon : contrôle de nouveau les quantités (un autre retour de la facture a pu être
     * traité depuis), numéro, puis remise en stock (un arrivage par ligne) ou rebut.
     */
    public function traiter(RetourClient $retour, ?AdminUser $auteur = null): RetourClient
    {
        $config = Configuration::pourEntite('retour_client', $retour->tenant_id);
        if (! $config || ! $config->auto_increment) {
            throw new DomainException('Numéro requis : activez la numérotation automatique des retours clients (Configuration → Numérotation).');
        }

        return DB::transaction(function () use ($retour, $auteur) {
            // Verrous : deux traitements simultanés ne peuvent pas retourner deux fois la même marchandise.
            $facture = $retour->facture_id ? Facture::with('lignes')->whereKey($retour->facture_id)->lockForUpdate()->firstOrFail() : null;
            $retour = RetourClient::with(['lignes.produit' => fn ($q) => $q->withTrashed(), 'lignes.secteur', 'lignes.raison'])
                ->whereKey($retour->id)->lockForUpdate()->firstOrFail();
            if ($retour->statut !== RetourClient::BROUILLON) {
                throw new DomainException('Ce retour est déjà traité.');
            }
            if ($facture) {
                $this->verifierRetournable($facture);
                $this->verifierQuantites($facture, $retour->lignes->map(fn (RetourClientLigne $l) => [
                    'facture_ligne_id' => $l->facture_ligne_id, 'quantite' => $l->quantite,
                ])->all(), $retour->id);
            }

            $retour->numero = Configuration::consommerNumero('retour_client', $retour->tenant_id);
            $date = Carbon::parse($retour->date_retour->toDateString())->setTimeFrom(now());
            foreach ($retour->lignes as $ligne) {
                if ($ligne->traitement !== RetourClientLigne::REMISE_EN_STOCK) {
                    continue;
                }
                if (! $ligne->secteur || $ligne->secteur->trashed()) {
                    throw new DomainException("Le secteur de remise en stock de {$ligne->produit?->numero} n'existe plus : modifiez le retour.");
                }
                $mouvement = MouvementTenant::create([
                    'tenant_id' => $retour->tenant_id,
                    'type' => MouvementTenant::TYPE_ARRIVAGE,
                    'produit_numero' => $ligne->produit->numero,
                    'produit_nom' => $ligne->produit->description,
                    'secteur_destination' => $ligne->secteur->code ?: $ligne->secteur->nom,
                    // En unités de stock : une caisse de 12 retournée remet 12 unités en stock.
                    'quantite' => $ligne->facteur === null ? $ligne->quantite : round($ligne->quantite * $ligne->facteurStock(), 4),
                    'unite_mesure' => $ligne->produit->mesure ?: 'unité',
                    'motif' => Str::limit("Retour client {$retour->numero}".($ligne->raison ? " : {$ligne->raison->libelle}" : ''), 255, ''),
                    'employe' => Str::limit($auteur?->nom ?: 'Retour client', 100, ''),
                    'date_mouvement' => $date,
                ]);
                $ligne->update(['mouvement_id' => $mouvement->id]);
            }
            $retour->fill(['statut' => RetourClient::TRAITE, 'traite_le' => now(), 'traite_par' => $auteur?->id])->save();

            return $retour;
        });
    }

    /**
     * Note de crédit d'un retour traité : brouillon sur la facture d'origine, les quantités retournées au prix
     * facturé (au plus ce qui reste à créditer de chaque ligne). Elle s'émet ensuite depuis les notes de crédit.
     */
    public function crediter(RetourClient $retour, ?int $auteur = null): Avoir
    {
        return DB::transaction(function () use ($retour, $auteur) {
            $retour = RetourClient::with('lignes', 'facture')->whereKey($retour->id)->lockForUpdate()->firstOrFail();
            if ($retour->statut !== RetourClient::TRAITE) {
                throw new DomainException("Traitez d'abord le retour.");
            }
            if (! $retour->facture) {
                throw new DomainException("Ce retour n'a pas de facture d'origine : il n'y a rien à créditer.");
            }
            $existante = $retour->avoir_id ? Avoir::find($retour->avoir_id) : null;
            if ($existante) {
                throw new DomainException('La note de crédit de ce retour existe déjà ('.($existante->numero ?? 'brouillon').').');
            }

            $facture = $retour->facture;
            $this->notes->verifierCreditable($facture);
            $creditables = collect($this->notes->proposition($facture)['lignes'])->keyBy('facture_ligne_id');
            $lignes = [];
            foreach ($retour->lignes->groupBy('facture_ligne_id') as $ligneId => $retournees) {
                $quantite = min((int) $retournees->sum('quantite'), (int) ($creditables[$ligneId]['quantite_proposee'] ?? 0));
                if ($quantite > 0) {
                    $lignes[] = ['facture_ligne_id' => (int) $ligneId, 'quantite' => $quantite];
                }
            }
            if ($lignes === []) {
                throw new DomainException("Rien à créditer : la facture {$facture->numero} est déjà créditée pour ces produits.");
            }

            $avoir = $this->notes->enregistrer($facture, [
                'date_avoir' => max(now()->toDateString(), $facture->date_facture->toDateString()),
                'motif' => "Retour {$retour->numero}",
                'lignes' => $lignes,
            ], null, $auteur);
            $retour->update(['avoir_id' => $avoir->id]);

            return $avoir;
        });
    }

    /** Supprime un retour brouillon (un retour traité reste : sa numérotation est continue). */
    public function supprimer(RetourClient $retour): void
    {
        if ($retour->statut !== RetourClient::BROUILLON) {
            throw new DomainException('Seuls les retours en brouillon peuvent être supprimés.');
        }
        $retour->delete();
    }

    /**
     * Lignes du retour : produit de la ligne facturée (retour d'une facture) ou produit choisi, quantité au plus
     * ce qui reste à retourner, raison, secteur exigé pour une remise en stock.
     */
    private function preparerLignes(?Facture $facture, array $saisies, ?int $sauf): array
    {
        $raisonExigee = Raison::where('type', 'retour_client')->where('actif', true)->exists();
        $lignesFacture = $facture ? $facture->lignes()->get()->keyBy('id') : collect();
        $preparees = [];
        foreach (array_values($saisies) as $index => $saisie) {
            if ($facture) {
                $ligne = $lignesFacture[(int) ($saisie['facture_ligne_id'] ?? 0)] ?? null;
                if (! $ligne) {
                    throw ValidationException::withMessages(["lignes.{$index}.facture_ligne_id" => "Cette ligne n'appartient pas à la facture {$facture->numero}."]);
                }
                $produitId = $ligne->produit_id;
            } else {
                $produitId = (int) ($saisie['produit_id'] ?? 0);
                if (! ProduitTenant::whereKey($produitId)->exists()) {
                    throw ValidationException::withMessages(["lignes.{$index}.produit_id" => 'Choisissez le produit retourné.']);
                }
            }
            $traitement = $saisie['traitement'];
            if ($traitement === RetourClientLigne::REMISE_EN_STOCK && empty($saisie['secteur_id'])) {
                throw ValidationException::withMessages(["lignes.{$index}.secteur_id" => 'Choisissez le secteur où remettre la marchandise en stock.']);
            }
            if ($raisonExigee && empty($saisie['raison_id'])) {
                throw ValidationException::withMessages(["lignes.{$index}.raison_id" => 'Choisissez la raison du retour.']);
            }
            $preparees[] = [
                'facture_ligne_id' => $facture ? $ligne->id : null,
                'produit_id' => $produitId,
                'quantite' => (int) $saisie['quantite'],
                // Retourné dans l'unité facturée ; sans facture, dans l'unité de stock.
                'unite' => $facture ? $ligne->unite : null,
                'facteur' => $facture ? $ligne->facteur : null,
                'raison_id' => $saisie['raison_id'] ?? null,
                'traitement' => $traitement,
                'secteur_id' => $traitement === RetourClientLigne::REMISE_EN_STOCK ? (int) $saisie['secteur_id'] : null,
                'notes' => $saisie['notes'] ?? null,
            ];
        }
        if ($facture) {
            $this->verifierQuantites($facture, $preparees, $sauf);
        }

        return $preparees;
    }

    /**
     * Une ligne de facture peut revenir en plusieurs lignes (une part en stock, une part au rebut) : leur total ne
     * dépasse pas la quantité facturée moins celle des autres retours traités.
     */
    private function verifierQuantites(Facture $facture, array $lignes, ?int $sauf): void
    {
        $lignesFacture = $facture->lignes->keyBy('id');
        $retournes = $this->dejaRetourne($facture, $sauf);
        $parLigne = [];
        foreach (array_values($lignes) as $index => $ligne) {
            $id = (int) $ligne['facture_ligne_id'];
            $parLigne[$id] = ($parLigne[$id] ?? 0) + (int) $ligne['quantite'];
            $reste = (int) ($lignesFacture[$id]->quantite ?? 0) - ($retournes[$id] ?? 0);
            if ($parLigne[$id] > $reste) {
                throw ValidationException::withMessages(["lignes.{$index}.quantite" => 'Au plus '.max(0, $reste).' (quantité facturée'.(($retournes[$id] ?? 0) > 0 ? ', retours déjà traités déduits' : '').').']);
            }
        }
    }

    /**
     * Quantité déjà retournée par ligne de facture (retours traités, sauf celui indiqué).
     *
     * @return array<int, int>
     */
    private function dejaRetourne(Facture $facture, ?int $sauf = null): array
    {
        return RetourClientLigne::query()
            ->join('retours_clients', 'retours_clients.id', '=', 'retour_client_lignes.retour_client_id')
            ->where('retours_clients.facture_id', $facture->id)->where('retours_clients.statut', RetourClient::TRAITE)
            ->when($sauf, fn ($q) => $q->where('retours_clients.id', '!=', $sauf))
            ->groupBy('retour_client_lignes.facture_ligne_id')
            ->selectRaw('retour_client_lignes.facture_ligne_id AS ligne, SUM(retour_client_lignes.quantite) AS quantite')
            ->pluck('quantite', 'ligne')
            ->map(fn ($quantite) => (int) $quantite)
            ->all();
    }

    private function verifierRetournable(Facture $facture): void
    {
        if (! in_array($facture->statut, self::FACTURES_RETOURNABLES, true)) {
            throw new DomainException('Seule la marchandise d\'une facture émise peut faire l\'objet d\'un retour.');
        }
    }

    /** Résumé des retours indiqués (requête, lue par la base) : quantités par raison et par traitement. */
    public function resume(Builder $retours): array
    {
        $lignes = RetourClientLigne::query()->whereIn('retour_client_id', $retours->select('retours_clients.id'))
            ->leftJoin('raisons', 'raisons.id', '=', 'retour_client_lignes.raison_id')
            ->groupBy('retour_client_lignes.raison_id', 'raisons.libelle', 'retour_client_lignes.traitement')
            ->selectRaw('retour_client_lignes.raison_id, raisons.libelle, retour_client_lignes.traitement, SUM(retour_client_lignes.quantite) AS quantite')
            ->get();

        return [
            'remis_en_stock' => (int) $lignes->where('traitement', RetourClientLigne::REMISE_EN_STOCK)->sum('quantite'),
            'rebut' => (int) $lignes->where('traitement', RetourClientLigne::REBUT)->sum('quantite'),
            'par_raison' => $lignes->groupBy(fn ($l) => (string) $l->raison_id)->map(fn (Collection $groupe) => [
                'raison_id' => $groupe->first()->raison_id,
                'libelle' => $groupe->first()->libelle,
                'quantite' => (int) $groupe->sum('quantite'),
            ])->sortByDesc('quantite')->values()->all(),
        ];
    }
}
