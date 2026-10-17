<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Configuration;
use App\Models\Fournisseur;
use App\Models\MouvementTenant;
use App\Models\ProduitTenant;
use App\Models\Raison;
use App\Models\RetourClient;
use App\Models\RetourClientLigne;
use App\Models\RetourFournisseur;
use App\Models\RetourFournisseurLigne;
use App\Models\Secteur;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Retours fournisseurs et avoirs (plan de parité fripv2, phase 3.4), comme les retours au fournisseur de fripv2
 * (ligne négative d'une réception, suivi des retours avec numéro RMA) :
 *
 * - Origine : une commande du fournisseur (au plus ce qui en a été reçu, moins ce qui est déjà reparti), un retour
 *   client traité (produits défectueux rapportés), ou aucune (n'importe quel produit).
 * - Type : remboursement (le fournisseur crédite), remplacement (il renvoie la marchandise), consigne (contenant
 *   rendu, rien d'attendu). Prix crédité par ligne (celui de la commande par défaut), dans la devise du retour.
 * - Brouillon modifiable, puis expédié : numéro (numérotation « retour_fournisseur »), et chaque ligne prise dans un
 *   secteur en sort (mouvement de stock « sortie »). Une ligne sans secteur n'était pas en stock (rebut, quarantaine).
 * - Clos quand le fournisseur a répondu : crédit obtenu et référence de son avoir (remboursement), marchandise de
 *   remplacement entrée dans un secteur (remplacement), ou simple clôture (consigne).
 */
class RetoursFournisseurs
{
    /** Commandes dont la marchandise reçue peut repartir. */
    public const COMMANDES_RETOURNABLES = [ComFourEntete::STATUT_ENVOYEE, ComFourEntete::STATUT_PARTIELLE, ComFourEntete::STATUT_COMPLETE];

    /** Retours qui comptent dans ce qui est déjà reparti (un brouillon n'a encore rien renvoyé). */
    private const PARTIS = [RetourFournisseur::EXPEDIE, RetourFournisseur::CLOS];

    public function __construct(private readonly DevisesVente $devises) {}

    /**
     * Ce qui peut repartir d'une commande : par ligne, les quantités reçue et déjà renvoyée, le reste, le prix de la
     * commande et le secteur de la dernière réception (celui du produit à défaut).
     */
    public function propositionCommande(ComFourEntete $commande): array
    {
        $commande->loadMissing([
            'fournisseur:id,code,raison_sociale,devise',
            'lignes.produit' => fn ($q) => $q->withTrashed()->select('id', 'numero', 'description', 'mesure', 'secteur_id'),
            'lignes.receptions' => fn ($q) => $q->orderByDesc('id'),
        ]);
        $repartis = $this->dejaRepartiCommande($commande);

        return [
            'commande' => $commande->only(['id', 'numero', 'statut', 'fournisseur_id', 'devise', 'taux_change'])
                + ['date_commande' => Carbon::parse($commande->date_commande)->toDateString(), 'fournisseur' => $commande->fournisseur?->only(['id', 'code', 'raison_sociale'])],
            'retournable' => in_array($commande->statut, self::COMMANDES_RETOURNABLES, true),
            'devise' => $this->devise($commande->devise, $commande->taux_change),
            'lignes' => $commande->lignes->map(fn (ComFourLigne $ligne) => [
                'com_four_ligne_id' => $ligne->id,
                'produit_id' => $ligne->produit_id,
                'produit' => $ligne->produit?->only(['id', 'numero', 'description', 'mesure']),
                'quantite_recue' => (int) $ligne->quantite_recue,
                'quantite_retournee' => $repartis[$ligne->id] ?? 0,
                'reste' => max(0, (int) $ligne->quantite_recue - ($repartis[$ligne->id] ?? 0)),
                'prix_unitaire' => (float) $ligne->prix_unitaire,
                'secteur_id' => $ligne->receptions->firstWhere('secteur_id', '!=', null)?->secteur_id ?? $ligne->produit?->secteur_id,
            ])->values()->all(),
        ];
    }

    /**
     * Ce qu'un retour client traité peut renvoyer au fournisseur : chaque ligne, moins ce qui est déjà reparti. Les
     * lignes mises au rebut (marchandise défectueuse, hors stock) sont proposées ; une ligne remise en stock repartira
     * de son secteur. Le fournisseur principal des produits est proposé.
     */
    public function propositionRetourClient(RetourClient $retour): array
    {
        $retour->loadMissing([
            'client:id,code,raison_sociale',
            'lignes.produit' => fn ($q) => $q->withTrashed()->select('id', 'numero', 'description', 'mesure', 'fournisseur_principal_id'),
        ]);
        $repartis = $this->dejaRepartiRetourClient($retour);

        return [
            'retour_client' => $retour->only(['id', 'numero', 'statut', 'client_id'])
                + ['date_retour' => $retour->date_retour->toDateString(), 'client' => $retour->client?->only(['id', 'code', 'raison_sociale'])],
            'retournable' => $retour->statut === RetourClient::TRAITE,
            'fournisseur_propose_id' => $this->fournisseurPrincipal($retour->lignes->pluck('produit_id')->unique()->values()->all()),
            'lignes' => $retour->lignes->map(function (RetourClientLigne $ligne) use ($repartis) {
                $reste = max(0, $ligne->quantite - ($repartis[$ligne->id] ?? 0));

                return [
                    'retour_client_ligne_id' => $ligne->id,
                    'produit_id' => $ligne->produit_id,
                    'produit' => $ligne->produit?->only(['id', 'numero', 'description', 'mesure']),
                    'traitement' => $ligne->traitement,
                    'quantite_retournee_client' => $ligne->quantite,
                    'quantite_repartie' => $repartis[$ligne->id] ?? 0,
                    'reste' => $reste,
                    'quantite_proposee' => $ligne->traitement === RetourClientLigne::REBUT ? $reste : 0,
                    // Remise en stock : elle repart du secteur où elle est rentrée.
                    'secteur_id' => $ligne->traitement === RetourClientLigne::REMISE_EN_STOCK ? $ligne->secteur_id : null,
                    'raison_id' => $ligne->raison_id,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Enregistre un retour brouillon : nouveau, ou remplace le contenu d'un brouillon (son origine reste celle de sa
     * création ; le fournisseur d'un retour de commande est celui de la commande).
     *
     * @param  array{fournisseur_id?: ?int, commande_fournisseur_id?: ?int, retour_client_id?: ?int, type: string, date_retour: string, numero_rma?: ?string, devise?: ?string, taux_change?: mixed, notes?: ?string, lignes: list<array>}  $donnees
     */
    public function enregistrer(array $donnees, ?RetourFournisseur $retour = null, ?int $auteur = null): RetourFournisseur
    {
        if ($retour && $retour->statut !== RetourFournisseur::BROUILLON) {
            throw new DomainException('Un retour expédié ne se modifie plus.');
        }
        $commande = $retour ? $retour->commande : (empty($donnees['commande_fournisseur_id']) ? null : ComFourEntete::find($donnees['commande_fournisseur_id']));
        $retourClient = $retour ? $retour->retourClient : (empty($donnees['retour_client_id']) ? null : RetourClient::find($donnees['retour_client_id']));
        $fournisseurId = $commande?->fournisseur_id ?? (int) ($donnees['fournisseur_id'] ?? $retour?->fournisseur_id);
        if ($commande) {
            if (! empty($donnees['fournisseur_id']) && (int) $donnees['fournisseur_id'] !== $commande->fournisseur_id) {
                throw ValidationException::withMessages(['fournisseur_id' => "La commande {$commande->numero} n'est pas une commande de ce fournisseur."]);
            }
            if (! in_array($commande->statut, self::COMMANDES_RETOURNABLES, true)) {
                throw new DomainException("Seule la marchandise reçue d'une commande envoyée peut repartir au fournisseur.");
            }
            if ($donnees['date_retour'] < Carbon::parse($commande->date_commande)->toDateString()) {
                throw ValidationException::withMessages(['date_retour' => 'Le retour ne peut pas précéder la commande ('.Carbon::parse($commande->date_commande)->format('d/m/Y').').']);
            }
        }
        if ($retourClient) {
            if ($retourClient->statut !== RetourClient::TRAITE) {
                throw new DomainException("Traitez d'abord le retour client {$retourClient->numero}.");
            }
            if ($donnees['date_retour'] < $retourClient->date_retour->toDateString()) {
                throw ValidationException::withMessages(['date_retour' => 'Le retour ne peut pas précéder le retour client ('.$retourClient->date_retour->format('d/m/Y').').']);
            }
        }
        $fournisseur = Fournisseur::find($fournisseurId);
        if (! $fournisseur) {
            throw ValidationException::withMessages(['fournisseur_id' => 'Choisissez le fournisseur.']);
        }
        // Devise : celle de la commande, sinon celle choisie (celle du fournisseur par défaut).
        $devise = $commande
            ? $this->devise($commande->devise, $commande->taux_change)
            : $this->devises->pourDocument($donnees['devise'] ?? $fournisseur->devise, $donnees['taux_change'] ?? null, null);
        $lignes = $this->preparerLignes($commande, $retourClient, $donnees['lignes'], $retour?->id);
        $montant = round(array_sum(array_map(fn (array $l) => $l['quantite'] * $l['prix_unitaire'], $lignes)), 2);

        return DB::transaction(function () use ($donnees, $retour, $auteur, $commande, $retourClient, $fournisseurId, $devise, $lignes, $montant) {
            $entete = [
                'fournisseur_id' => $fournisseurId,
                'type' => $donnees['type'],
                'date_retour' => $donnees['date_retour'],
                'numero_rma' => $donnees['numero_rma'] ?? null,
                'notes' => $donnees['notes'] ?? null,
                'montant' => $montant,
            ] + $devise;
            if ($retour) {
                $retour->lignes()->delete();
                $retour->update($entete);
            } else {
                $retour = RetourFournisseur::create($entete + [
                    'commande_fournisseur_id' => $commande?->id,
                    'retour_client_id' => $retourClient?->id,
                    'statut' => RetourFournisseur::BROUILLON,
                    'cree_par' => $auteur,
                ]);
            }
            foreach ($lignes as $ligne) {
                RetourFournisseurLigne::create(['retour_fournisseur_id' => $retour->id] + $ligne);
            }

            return $retour;
        });
    }

    /**
     * Expédie un retour brouillon : contrôle de nouveau les quantités (un autre retour a pu partir depuis), numéro,
     * puis sortie de stock de chaque ligne prise dans un secteur.
     */
    public function expedier(RetourFournisseur $retour, ?AdminUser $auteur = null, ?string $date = null): RetourFournisseur
    {
        $config = Configuration::pourEntite('retour_fournisseur', $retour->tenant_id);
        if (! $config || ! $config->auto_increment) {
            throw new DomainException('Numéro requis : activez la numérotation automatique des retours fournisseurs (Configuration → Numérotation).');
        }

        return DB::transaction(function () use ($retour, $auteur, $date) {
            // Verrous : deux expéditions simultanées ne peuvent pas renvoyer deux fois la même marchandise.
            $commande = $retour->commande_fournisseur_id ? ComFourEntete::with('lignes')->whereKey($retour->commande_fournisseur_id)->lockForUpdate()->firstOrFail() : null;
            $retourClient = $retour->retour_client_id ? RetourClient::with('lignes')->whereKey($retour->retour_client_id)->lockForUpdate()->firstOrFail() : null;
            $retour = RetourFournisseur::with(['lignes.produit' => fn ($q) => $q->withTrashed(), 'lignes.secteur', 'lignes.raison'])
                ->whereKey($retour->id)->lockForUpdate()->firstOrFail();
            if ($retour->statut !== RetourFournisseur::BROUILLON) {
                throw new DomainException('Ce retour est déjà expédié.');
            }
            $jour = $date ?? now()->toDateString();
            if ($jour < $retour->date_retour->toDateString()) {
                throw ValidationException::withMessages(['date_expedition' => "L'expédition ne peut pas précéder le retour (".$retour->date_retour->format('d/m/Y').').']);
            }
            $saisies = $retour->lignes->map(fn (RetourFournisseurLigne $l) => [
                'com_four_ligne_id' => $l->com_four_ligne_id, 'retour_client_ligne_id' => $l->retour_client_ligne_id, 'quantite' => $l->quantite,
            ])->all();
            if ($commande) {
                $this->verifierQuantitesCommande($commande, $saisies, $retour->id);
            }
            if ($retourClient) {
                $this->verifierQuantitesRetourClient($retourClient, $saisies, $retour->id);
            }

            $retour->numero = Configuration::consommerNumero('retour_fournisseur', $retour->tenant_id);
            $moment = Carbon::parse($jour)->setTimeFrom(now());
            foreach ($retour->lignes as $ligne) {
                if (! $ligne->secteur_id) {
                    continue;
                }
                if (! $ligne->secteur || $ligne->secteur->trashed()) {
                    throw new DomainException("Le secteur d'où sort {$ligne->produit?->numero} n'existe plus : modifiez le retour.");
                }
                $mouvement = $this->mouvement($retour, $ligne, MouvementTenant::TYPE_SORTIE, $ligne->secteur, "Retour fournisseur {$retour->numero}".($ligne->raison ? " : {$ligne->raison->libelle}" : ''), $auteur, $moment);
                $ligne->update(['mouvement_id' => $mouvement->id]);
            }
            $retour->fill(['statut' => RetourFournisseur::EXPEDIE, 'date_expedition' => $jour, 'expedie_par' => $auteur?->id])->save();

            return $retour;
        });
    }

    /**
     * Clôt un retour expédié quand le fournisseur a répondu : crédit obtenu et référence de son avoir
     * (remboursement), marchandise de remplacement entrée dans un secteur (remplacement), ou simple clôture.
     *
     * @param  array{date_cloture: string, montant_credite?: mixed, reference_avoir?: ?string, secteur_remplacement_id?: ?int}  $donnees
     */
    public function cloturer(RetourFournisseur $retour, array $donnees, ?AdminUser $auteur = null): RetourFournisseur
    {
        return DB::transaction(function () use ($retour, $donnees, $auteur) {
            $retour = RetourFournisseur::with(['lignes.produit' => fn ($q) => $q->withTrashed()])->whereKey($retour->id)->lockForUpdate()->firstOrFail();
            if ($retour->statut !== RetourFournisseur::EXPEDIE) {
                throw new DomainException($retour->statut === RetourFournisseur::BROUILLON ? "Expédiez d'abord le retour." : 'Ce retour est déjà clos.');
            }
            if ($donnees['date_cloture'] < $retour->date_expedition->toDateString()) {
                throw ValidationException::withMessages(['date_cloture' => "La clôture ne peut pas précéder l'expédition (".$retour->date_expedition->format('d/m/Y').').']);
            }
            $entete = ['statut' => RetourFournisseur::CLOS, 'date_cloture' => $donnees['date_cloture'], 'clos_par' => $auteur?->id];
            if ($retour->type === RetourFournisseur::REMBOURSEMENT) {
                if (! isset($donnees['montant_credite']) || ! is_numeric($donnees['montant_credite'])) {
                    throw ValidationException::withMessages(['montant_credite' => 'Indiquez le crédit obtenu du fournisseur.']);
                }
                $entete += ['montant_credite' => round((float) $donnees['montant_credite'], 2), 'reference_avoir' => $donnees['reference_avoir'] ?? null];
            }
            if ($retour->type === RetourFournisseur::REMPLACEMENT && ! empty($donnees['secteur_remplacement_id'])) {
                $secteur = Secteur::findOrFail($donnees['secteur_remplacement_id']);
                $moment = Carbon::parse($donnees['date_cloture'])->setTimeFrom(now());
                foreach ($retour->lignes as $ligne) {
                    $mouvement = $this->mouvement($retour, $ligne, MouvementTenant::TYPE_ARRIVAGE, $secteur, "Remplacement fournisseur {$retour->numero}", $auteur, $moment);
                    $ligne->update(['mouvement_remplacement_id' => $mouvement->id]);
                }
                $entete['secteur_remplacement_id'] = $secteur->id;
            }
            $retour->fill($entete)->save();

            return $retour;
        });
    }

    /** Supprime un retour brouillon (un retour expédié reste : sa numérotation est continue). */
    public function supprimer(RetourFournisseur $retour): void
    {
        if ($retour->statut !== RetourFournisseur::BROUILLON) {
            throw new DomainException('Seuls les retours en brouillon peuvent être supprimés.');
        }
        $retour->delete();
    }

    /**
     * Résumé des retours indiqués (requête, lue par la base), dans la devise de l'entreprise : crédits attendus
     * (remboursements expédiés), crédits obtenus (remboursements clos), retours en cours et brouillons.
     */
    public function resume(Builder $retours): array
    {
        $remboursements = (clone $retours)->where('type', RetourFournisseur::REMBOURSEMENT);

        return [
            'credits_attendus' => round((float) (clone $remboursements)->where('statut', RetourFournisseur::EXPEDIE)->sum(DB::raw('montant * taux_change')), 2),
            'credits_obtenus' => round((float) (clone $remboursements)->where('statut', RetourFournisseur::CLOS)->sum(DB::raw('COALESCE(montant_credite, 0) * taux_change')), 2),
            'en_cours' => (clone $retours)->where('statut', RetourFournisseur::EXPEDIE)->count(),
            'brouillons' => (clone $retours)->where('statut', RetourFournisseur::BROUILLON)->count(),
        ];
    }

    /**
     * Lignes du retour : produit de la ligne d'origine (commande, retour client) ou produit choisi, quantité au plus
     * ce qui peut encore repartir, prix crédité (celui de la commande par défaut), raison exigée dès que l'entreprise
     * en a défini.
     */
    private function preparerLignes(?ComFourEntete $commande, ?RetourClient $retourClient, array $saisies, ?int $sauf): array
    {
        $raisonExigee = Raison::where('type', 'retour_fournisseur')->where('actif', true)->exists();
        $lignesCommande = $commande ? $commande->lignes()->get()->keyBy('id') : collect();
        $lignesClient = $retourClient ? $retourClient->lignes()->get()->keyBy('id') : collect();
        $preparees = [];
        foreach (array_values($saisies) as $index => $saisie) {
            $prix = isset($saisie['prix_unitaire']) && $saisie['prix_unitaire'] !== null ? round((float) $saisie['prix_unitaire'], 2) : null;
            $origine = ['com_four_ligne_id' => null, 'retour_client_ligne_id' => null];
            if ($commande) {
                $ligne = $lignesCommande[(int) ($saisie['com_four_ligne_id'] ?? 0)] ?? null;
                if (! $ligne) {
                    throw ValidationException::withMessages(["lignes.{$index}.com_four_ligne_id" => "Cette ligne n'appartient pas à la commande {$commande->numero}."]);
                }
                $produitId = $ligne->produit_id;
                $prix ??= round((float) $ligne->prix_unitaire, 2);
                $origine['com_four_ligne_id'] = $ligne->id;
            } elseif ($retourClient) {
                $ligne = $lignesClient[(int) ($saisie['retour_client_ligne_id'] ?? 0)] ?? null;
                if (! $ligne) {
                    throw ValidationException::withMessages(["lignes.{$index}.retour_client_ligne_id" => "Cette ligne n'appartient pas au retour client {$retourClient->numero}."]);
                }
                $produitId = $ligne->produit_id;
                $origine['retour_client_ligne_id'] = $ligne->id;
            } else {
                $produitId = (int) ($saisie['produit_id'] ?? 0);
                if (! ProduitTenant::whereKey($produitId)->exists()) {
                    throw ValidationException::withMessages(["lignes.{$index}.produit_id" => 'Choisissez le produit renvoyé.']);
                }
            }
            if ($raisonExigee && empty($saisie['raison_id'])) {
                throw ValidationException::withMessages(["lignes.{$index}.raison_id" => 'Choisissez la raison du retour.']);
            }
            $preparees[] = $origine + [
                'produit_id' => $produitId,
                'quantite' => (int) $saisie['quantite'],
                'prix_unitaire' => $prix ?? 0.0,
                'raison_id' => $saisie['raison_id'] ?? null,
                'secteur_id' => empty($saisie['secteur_id']) ? null : (int) $saisie['secteur_id'],
                'notes' => $saisie['notes'] ?? null,
            ];
        }
        if ($commande) {
            $this->verifierQuantitesCommande($commande, $preparees, $sauf);
        }
        if ($retourClient) {
            $this->verifierQuantitesRetourClient($retourClient, $preparees, $sauf);
        }

        return $preparees;
    }

    /** Une ligne de commande repart au plus dans la quantité reçue, moins ce qui en est déjà reparti. */
    private function verifierQuantitesCommande(ComFourEntete $commande, array $lignes, ?int $sauf): void
    {
        $lignesCommande = $commande->lignes->keyBy('id');
        $repartis = $this->dejaRepartiCommande($commande, $sauf);
        $parLigne = [];
        foreach (array_values($lignes) as $index => $ligne) {
            $id = (int) $ligne['com_four_ligne_id'];
            $parLigne[$id] = ($parLigne[$id] ?? 0) + (int) $ligne['quantite'];
            $reste = (int) ($lignesCommande[$id]->quantite_recue ?? 0) - ($repartis[$id] ?? 0);
            if ($parLigne[$id] > $reste) {
                throw ValidationException::withMessages(["lignes.{$index}.quantite" => 'Au plus '.max(0, $reste).' (quantité reçue'.(($repartis[$id] ?? 0) > 0 ? ', retours déjà expédiés déduits' : '').').']);
            }
        }
    }

    /** Une ligne de retour client repart au plus dans sa quantité, moins ce qui en est déjà reparti. */
    private function verifierQuantitesRetourClient(RetourClient $retourClient, array $lignes, ?int $sauf): void
    {
        $lignesClient = $retourClient->lignes->keyBy('id');
        $repartis = $this->dejaRepartiRetourClient($retourClient, $sauf);
        $parLigne = [];
        foreach (array_values($lignes) as $index => $ligne) {
            $id = (int) $ligne['retour_client_ligne_id'];
            $parLigne[$id] = ($parLigne[$id] ?? 0) + (int) $ligne['quantite'];
            $reste = (int) ($lignesClient[$id]->quantite ?? 0) - ($repartis[$id] ?? 0);
            if ($parLigne[$id] > $reste) {
                throw ValidationException::withMessages(["lignes.{$index}.quantite" => 'Au plus '.max(0, $reste).' (quantité rapportée par le client'.(($repartis[$id] ?? 0) > 0 ? ', retours déjà expédiés déduits' : '').').']);
            }
        }
    }

    /** @return array<int, int> quantité déjà repartie par ligne de commande (retours expédiés ou clos, sauf celui indiqué) */
    private function dejaRepartiCommande(ComFourEntete $commande, ?int $sauf = null): array
    {
        return $this->dejaReparti('com_four_ligne_id', 'commande_fournisseur_id', $commande->id, $sauf);
    }

    /** @return array<int, int> quantité déjà repartie par ligne du retour client (retours expédiés ou clos, sauf celui indiqué) */
    private function dejaRepartiRetourClient(RetourClient $retourClient, ?int $sauf = null): array
    {
        return $this->dejaReparti('retour_client_ligne_id', 'retour_client_id', $retourClient->id, $sauf);
    }

    private function dejaReparti(string $colonneLigne, string $colonneOrigine, int $origine, ?int $sauf): array
    {
        return RetourFournisseurLigne::query()
            ->join('retours_fournisseurs', 'retours_fournisseurs.id', '=', 'retour_fournisseur_lignes.retour_fournisseur_id')
            ->where("retours_fournisseurs.{$colonneOrigine}", $origine)->whereIn('retours_fournisseurs.statut', self::PARTIS)
            ->when($sauf, fn ($q) => $q->where('retours_fournisseurs.id', '!=', $sauf))
            ->groupBy("retour_fournisseur_lignes.{$colonneLigne}")
            ->selectRaw("retour_fournisseur_lignes.{$colonneLigne} AS ligne, SUM(retour_fournisseur_lignes.quantite) AS quantite")
            ->pluck('quantite', 'ligne')
            ->map(fn ($quantite) => (int) $quantite)
            ->all();
    }

    /** Fournisseur principal le plus fréquent des produits (fiche produit, sinon catalogue du fournisseur). */
    private function fournisseurPrincipal(array $produits): ?int
    {
        if ($produits === []) {
            return null;
        }
        $principaux = ProduitTenant::withTrashed()->whereIn('id', $produits)->whereNotNull('fournisseur_principal_id')->pluck('fournisseur_principal_id')
            ->merge(DB::table('produit_fournisseur')->whereIn('produit_id', $produits)->where('fournisseur_principal', true)->pluck('fournisseur_id'));
        $actifs = Fournisseur::whereIn('id', $principaux->unique())->where('actif', true)->pluck('id')->all();
        $comptes = $principaux->filter(fn ($id) => in_array($id, $actifs))->countBy();

        return $comptes->isEmpty() ? null : (int) $comptes->sortDesc()->keys()->first();
    }

    /** Devise d'un document d'achat (celle de l'entreprise : null) et son taux, comme les documents de vente. */
    private function devise(?string $devise, mixed $taux): array
    {
        $code = strtoupper(trim((string) $devise));
        if ($code === '' || $code === $this->devises->reference()) {
            return ['devise' => null, 'taux_change' => 1.0];
        }

        return ['devise' => $code, 'taux_change' => is_numeric($taux) && (float) $taux > 0 ? round((float) $taux, 8) : 1.0];
    }

    private function mouvement(RetourFournisseur $retour, RetourFournisseurLigne $ligne, string $type, Secteur $secteur, string $motif, ?AdminUser $auteur, Carbon $moment): MouvementTenant
    {
        $sortie = $type === MouvementTenant::TYPE_SORTIE;

        return MouvementTenant::create([
            'tenant_id' => $retour->tenant_id,
            'type' => $type,
            'produit_numero' => $ligne->produit->numero,
            'produit_nom' => $ligne->produit->description,
            'secteur_source' => $sortie ? ($secteur->code ?: $secteur->nom) : null,
            'secteur_destination' => $sortie ? null : ($secteur->code ?: $secteur->nom),
            'quantite' => $ligne->quantite,
            'unite_mesure' => $ligne->produit->mesure ?: 'unité',
            'motif' => Str::limit($motif, 255, ''),
            'employe' => Str::limit($auteur?->nom ?: 'Retour fournisseur', 100, ''),
            'date_mouvement' => $moment,
        ]);
    }
}
