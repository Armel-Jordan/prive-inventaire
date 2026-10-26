<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Configuration;
use App\Models\Facture;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\ReliquatClient;
use App\Support\DateDuJour;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reliquats clients (back orders, plan de parité fripv2, phase 1.4), comme la gestion des B/O client de fripv2 :
 *
 * - Un reliquat note qu'un client attend un produit faute de stock : saisi à la main ou depuis une commande client
 *   acceptée (prix, remise, devise et date souhaitée de la commande).
 * - « Commander au fournisseur » regroupe des reliquats inscrits en commandes fournisseur brouillons, une par
 *   fournisseur (celui choisi, sinon le fournisseur principal de chaque produit), au dernier prix payé.
 * - Chaque réception de la ligne fournisseur se répartit sur ses reliquats, du plus ancien au plus récent : reçu en
 *   partie, puis reçu. Une commande fournisseur annulée, supprimée ou modifiée rend ses reliquats à « inscrit » ;
 *   close après une réception partielle, elle passe ceux qui ont reçu quelque chose à « reçu ».
 * - « Facturer » crée une facture brouillon par client et par devise, aux quantités reçues.
 */
class ReliquatsClients
{
    /** Commandes client dont les lignes peuvent laisser un reliquat. */
    public const COMMANDES_SOURCES = ['acceptee', 'facturee'];

    public function __construct(private readonly DevisesVente $devises, private readonly FacturesVente $factures) {}

    /**
     * Reliquat saisi à la main.
     *
     * @param  array{client_id: int, produit_id: int, quantite: int, prix_unitaire_ht: float|string, remise_ligne?: mixed, devise?: ?string, taux_change?: mixed, date_requise?: ?string, notes?: ?string}  $donnees
     */
    public function creer(array $donnees, ?int $auteur = null): ReliquatClient
    {
        $client = Client::findOrFail($donnees['client_id']);

        return ReliquatClient::create($this->devises->pourDocument($donnees['devise'] ?? null, $donnees['taux_change'] ?? null, $client) + [
            'client_id' => $client->id,
            'produit_id' => $donnees['produit_id'],
            'quantite' => $donnees['quantite'],
            'prix_unitaire_ht' => $donnees['prix_unitaire_ht'],
            'remise_ligne' => $donnees['remise_ligne'] ?? 0,
            'date_requise' => $donnees['date_requise'] ?? null,
            'notes' => $donnees['notes'] ?? null,
            'statut' => ReliquatClient::CREE,
            'cree_par' => $auteur,
        ]);
    }

    /**
     * Reliquats des lignes d'une commande client acceptée : la quantité qui manque, au prix, à la remise et dans la
     * devise de la commande.
     *
     * @param  list<array{ligne_id: int, quantite: int}>  $lignes
     * @return Collection<int, ReliquatClient>
     */
    public function depuisCommande(ComClientEntete $commande, array $lignes, ?int $auteur = null): Collection
    {
        if (! in_array($commande->statut, self::COMMANDES_SOURCES, true)) {
            throw new DomainException(__('Seule une commande acceptée peut laisser un reliquat.'));
        }
        $lignesCommande = $commande->lignes()->get()->keyBy('id');
        $dejaEnReliquat = ReliquatClient::where('commande_client_id', $commande->id)->where('statut', '!=', ReliquatClient::ANNULE)
            ->selectRaw('commande_client_ligne_id, SUM(quantite) AS quantite')->groupBy('commande_client_ligne_id')->pluck('quantite', 'commande_client_ligne_id');
        $saisies = [];
        foreach (array_values($lignes) as $index => $saisie) {
            $ligne = $lignesCommande[(int) $saisie['ligne_id']] ?? null;
            if (! $ligne) {
                throw ValidationException::withMessages(["lignes.{$index}.ligne_id" => __("Cette ligne n'appartient pas à la commande :numero.", ['numero' => $commande->numero])]);
            }
            $reste = (int) $ligne->quantite - (int) ($dejaEnReliquat[$ligne->id] ?? 0);
            if ((int) $saisie['quantite'] > $reste) {
                throw ValidationException::withMessages(["lignes.{$index}.quantite" => __('Au plus :quantite (quantité commandée, reliquats déjà notés déduits).', ['quantite' => max(0, $reste)])]);
            }
            $saisies[] = [$ligne, (int) $saisie['quantite']];
        }

        return DB::transaction(fn () => collect($saisies)->map(fn (array $s) => ReliquatClient::create([
            'client_id' => $commande->client_id,
            'produit_id' => $s[0]->produit_id,
            'quantite' => $s[1],
            'prix_unitaire_ht' => $s[0]->prix_unitaire_ht,
            'remise_ligne' => $s[0]->remise_ligne ?? 0,
            'devise' => $commande->devise,
            'taux_change' => $commande->taux_change ?? 1,
            'commande_client_id' => $commande->id,
            'commande_client_ligne_id' => $s[0]->id,
            'date_requise' => $commande->date_livraison_souhaitee,
            'statut' => ReliquatClient::CREE,
            'cree_par' => $auteur,
            // Dans l'unité de la ligne commandée (2 caisses restent 2 caisses, à leur prix).
            'unite' => $s[0]->unite,
            'facteur' => $s[0]->facteur,
        ])));
    }

    /** Modification d'un reliquat inscrit (pas encore commandé au fournisseur). */
    public function modifier(ReliquatClient $reliquat, array $donnees): ReliquatClient
    {
        $this->verifierInscrit($reliquat, __('Seul un reliquat pas encore commandé au fournisseur se modifie.'));
        $reliquat->update(array_intersect_key($donnees, array_flip(['quantite', 'prix_unitaire_ht', 'remise_ligne', 'date_requise', 'notes'])));

        return $reliquat;
    }

    public function annuler(ReliquatClient $reliquat): ReliquatClient
    {
        $this->verifierInscrit($reliquat, __("Seul un reliquat pas encore commandé au fournisseur s'annule."));
        $reliquat->update(['statut' => ReliquatClient::ANNULE]);

        return $reliquat;
    }

    public function supprimer(ReliquatClient $reliquat): void
    {
        $this->verifierInscrit($reliquat, __('Seul un reliquat pas encore commandé au fournisseur se supprime.'));
        $reliquat->delete();
    }

    /**
     * Commandes fournisseur brouillons pour des reliquats inscrits : une par fournisseur (celui indiqué, sinon le
     * fournisseur principal de chaque produit), une ligne par produit, au dernier prix payé à ce fournisseur.
     *
     * @param  list<int>  $ids
     * @return Collection<int, ComFourEntete>
     */
    public function commander(array $ids, ?int $fournisseurId, int $tenantId, int $auteur): Collection
    {
        $config = Configuration::pourEntite('commande_fournisseur', $tenantId);
        if (! $config || ! $config->auto_increment) {
            throw new DomainException(__('Numéro requis : activez la numérotation automatique des commandes fournisseur (Configuration → Numérotation).'));
        }

        return DB::transaction(function () use ($ids, $fournisseurId, $tenantId, $auteur) {
            $reliquats = ReliquatClient::with(['produit', 'client:id,raison_sociale'])->whereIn('id', $ids)->lockForUpdate()->orderBy('id')->get();
            if ($reliquats->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['ids' => __('Un des reliquats choisis est introuvable.')]);
            }
            foreach ($reliquats as $reliquat) {
                $this->verifierInscrit($reliquat, __('Le reliquat de :produit est déjà commandé, reçu, facturé ou annulé.', ['produit' => $reliquat->produit?->description]));
            }
            $parFournisseur = $reliquats->groupBy(function (ReliquatClient $r) use ($fournisseurId) {
                $fournisseur = $fournisseurId ?? $this->fournisseurPrincipal($r->produit_id);
                if (! $fournisseur) {
                    throw ValidationException::withMessages(['fournisseur_id' => __("Choisissez le fournisseur : :produit n'a pas de fournisseur principal.", ['produit' => $r->produit?->description])]);
                }

                return $fournisseur;
            });

            return $parFournisseur->map(function (Collection $groupe, $fournisseur) use ($tenantId, $auteur) {
                $fiche = Fournisseur::findOrFail($fournisseur);
                $commande = ComFourEntete::create([
                    'tenant_id' => $tenantId,
                    'numero' => Configuration::consommerNumero('commande_fournisseur', $tenantId),
                    'fournisseur_id' => $fiche->id,
                    'date_commande' => DateDuJour::aujourdhui(),
                    'notes' => 'Reliquats clients : '.$groupe->map(fn (ReliquatClient $r) => $r->client?->raison_sociale)->filter()->unique()->implode(', '),
                    'devise' => $fiche->devise ?? 'EUR',
                    'taux_change' => 1.0,
                    'statut' => ComFourEntete::STATUT_BROUILLON,
                    'created_by' => $auteur,
                ]);
                foreach ($groupe->groupBy('produit_id') as $produitId => $duProduit) {
                    $produit = ProduitTenant::withTrashed()->find($produitId);
                    // Le besoin en unités de stock (un reliquat de 2 caisses de 12 : 24), commandé en unités d'achat
                    // entières du fournisseur (ses cartons de 10 : 3).
                    $conditionnement = app(UnitesAchat::class)->pour($produit, $fiche->id);
                    $quantite = UnitesAchat::enAchat(self::enStock($duProduit), $conditionnement['qte_par_unite_achat']);
                    $prix = $this->dernierPrix((int) $produitId, $fiche->id);
                    $ligne = ComFourLigne::create($conditionnement + [
                        'com_four_entete_id' => $commande->id,
                        'produit_id' => $produitId,
                        'quantite_commandee' => $quantite,
                        'prix_unitaire' => $prix,
                        'montant_ligne' => $quantite * $prix,
                    ]);
                    foreach ($duProduit as $reliquat) {
                        $reliquat->update(['com_four_ligne_id' => $ligne->id, 'quantite_commandee' => $reliquat->quantite, 'statut' => ReliquatClient::COMMANDE]);
                    }
                }
                $commande->recalculerMontantTotal();

                return $commande->load('lignes');
            })->values();
        });
    }

    /**
     * Réception d'une ligne fournisseur : répartie sur ses reliquats, du plus ancien au plus récent. Un reliquat dans
     * une autre unité (la caisse de 12) ne reçoit que des unités entières : 18 unités reçues en donnent 1 caisse, les 6
     * autres attendent la réception suivante. La ligne a déjà compté la réception ($quantite) dans ce qu'elle a reçu.
     */
    public function recevoir(ComFourLigne $ligne, int $quantite): void
    {
        $lies = ReliquatClient::withoutGlobalScopes()->where('com_four_ligne_id', $ligne->id)->where('statut', '!=', ReliquatClient::ANNULE)
            ->orderBy('id')->get();
        // Reçu par la ligne (en unités d'achat, converties en unités de stock) et pas encore attribué à ses reliquats.
        $reste = (float) $ligne->quantite_recue * max(1, (int) $ligne->qte_par_unite_achat)
            - $lies->sum(fn (ReliquatClient $r) => (float) $r->quantite_recue * $r->facteurStock());
        $reliquats = $lies->whereIn('statut', [ReliquatClient::COMMANDE, ReliquatClient::RECU_PARTIEL]);
        foreach ($reliquats as $reliquat) {
            if ($reste <= 0) {
                break;
            }
            $facteur = $reliquat->facteurStock();
            $part = min((int) floor($reste / $facteur + 1e-9), $reliquat->quantite - $reliquat->quantite_recue);
            if ($part <= 0) {
                continue;
            }
            $reste -= $part * $facteur;
            $recue = $reliquat->quantite_recue + $part;
            $reliquat->update(['quantite_recue' => $recue, 'statut' => $recue >= $reliquat->quantite ? ReliquatClient::RECU : ReliquatClient::RECU_PARTIEL]);
        }
    }

    /**
     * Quantité de reliquats en unités de stock (chacun multiplié par son facteur), arrondie à l'unité supérieure.
     *
     * @param  iterable<ReliquatClient>  $reliquats
     */
    public static function enStock(iterable $reliquats, string $champ = 'quantite'): int
    {
        $total = 0.0;
        foreach ($reliquats as $reliquat) {
            $total += (float) $reliquat->{$champ} * $reliquat->facteurStock();
        }

        return (int) ceil(round($total, 6));
    }

    /**
     * Lignes fournisseur retirées (commande annulée, supprimée, ligne enlevée ou changée de produit) : leurs reliquats
     * qui n'ont rien reçu redeviennent inscrits, prêts à repartir chez un autre fournisseur.
     *
     * @param  iterable<int>  $lignes
     */
    public function liberer(iterable $lignes): void
    {
        $ids = collect($lignes)->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return;
        }
        ReliquatClient::withoutGlobalScopes()->whereIn('com_four_ligne_id', $ids)->where('quantite_recue', 0)
            ->where('statut', ReliquatClient::COMMANDE)
            ->update(['com_four_ligne_id' => null, 'quantite_commandee' => 0, 'statut' => ReliquatClient::CREE]);
    }

    /**
     * Commande fournisseur close sans tout recevoir : ses reliquats qui ont reçu quelque chose se facturent à la
     * quantité reçue ; les autres redeviennent inscrits.
     */
    public function commandeCloturee(ComFourEntete $commande): void
    {
        $this->lignesCloturees($commande->lignes()->pluck('id'));
    }

    /**
     * Lignes fournisseur clôturées sans tout recevoir (commande close, ou ligne close à la réception) : leurs reliquats
     * qui ont reçu quelque chose se facturent à la quantité reçue ; les autres redeviennent inscrits.
     *
     * @param  iterable<int>  $lignes
     */
    public function lignesCloturees(iterable $lignes): void
    {
        $ids = collect($lignes)->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return;
        }
        ReliquatClient::withoutGlobalScopes()->whereIn('com_four_ligne_id', $ids)->where('statut', ReliquatClient::RECU_PARTIEL)
            ->update(['statut' => ReliquatClient::RECU]);
        $this->liberer($ids);
    }

    /**
     * Réception annulée : les reliquats d'une ligne fournisseur ne peuvent pas avoir reçu plus que la ligne elle-même.
     * Le surplus leur est repris, du plus récent au plus ancien ; s'il ne reste que des reliquats déjà facturés pour le
     * reprendre, l'annulation est refusée.
     */
    public function receptionAnnulee(ComFourLigne $ligne): void
    {
        $reliquats = ReliquatClient::withoutGlobalScopes()->where('com_four_ligne_id', $ligne->id)
            ->whereIn('statut', [ReliquatClient::RECU_PARTIEL, ReliquatClient::RECU, ReliquatClient::FACTURE])
            ->orderByDesc('id')->get();
        // En unités de stock : les reliquats dans une autre unité comptent leur facteur, la ligne fournisseur le sien.
        $exces = self::enStock($reliquats, 'quantite_recue') - max(0, (int) $ligne->quantite_recue) * max(1, (int) $ligne->qte_par_unite_achat);
        foreach ($reliquats->where('statut', '!=', ReliquatClient::FACTURE) as $reliquat) {
            if ($exces <= 0) {
                return;
            }
            $facteur = $reliquat->facteurStock();
            $part = min((int) ceil($exces / $facteur - 1e-9), (int) $reliquat->quantite_recue);
            $exces -= $part * $facteur;
            $recue = $reliquat->quantite_recue - $part;
            $reliquat->update(['quantite_recue' => $recue, 'statut' => $recue > 0 ? ReliquatClient::RECU_PARTIEL : ReliquatClient::COMMANDE]);
        }
        if ($exces > 0) {
            $facture = $reliquats->firstWhere('statut', ReliquatClient::FACTURE);
            $produit = $facture?->produit?->numero ?? $ligne->produit?->numero;
            throw new DomainException(__('La marchandise :produit de cette réception est déjà facturée au client (reliquat :client) : la réception ne peut plus être annulée.', [
                'produit' => $produit, 'client' => $facture?->client?->raison_sociale,
            ]));
        }
    }

    /**
     * Factures brouillons des reliquats reçus : une par client et par devise, une ligne par reliquat à la quantité
     * reçue.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Facture>
     */
    public function facturer(array $ids, string $date, int $tenantId): Collection
    {
        $config = Configuration::pourEntite('facture', $tenantId);
        if (! $config || ! $config->auto_increment) {
            throw new DomainException(__('Numéro requis : activez la numérotation automatique des factures (Configuration → Numérotation).'));
        }

        return DB::transaction(function () use ($ids, $date, $tenantId) {
            $reliquats = ReliquatClient::whereIn('id', $ids)->lockForUpdate()->orderBy('id')->get();
            if ($reliquats->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['ids' => __('Un des reliquats choisis est introuvable.')]);
            }
            if ($reliquats->contains(fn (ReliquatClient $r) => $r->statut !== ReliquatClient::RECU)) {
                throw new DomainException(__('Seuls les reliquats reçus se facturent : attendez la fin de la réception, ou clôturez la commande fournisseur.'));
            }

            return $reliquats->groupBy(fn (ReliquatClient $r) => $r->client_id.'|'.($r->devise ?? ''))->map(function (Collection $groupe) use ($date, $tenantId) {
                $premier = $groupe->first();
                $facture = $this->factures->creer([
                    'client_id' => $premier->client_id,
                    'date_facture' => $date,
                    'devise' => $premier->devise ?? $this->devises->reference(),
                    'taux_change' => $premier->devise ? (float) $premier->taux_change : null,
                    'notes' => 'Reliquats reçus',
                    'lignes' => $groupe->map(fn (ReliquatClient $r) => [
                        'produit_id' => $r->produit_id,
                        'quantite' => $r->quantite_recue,
                        'prix_unitaire_ht' => (float) $r->prix_unitaire_ht,
                        'remise_ligne' => (float) $r->remise_ligne,
                        // Facturé dans l'unité du reliquat.
                        'unite' => $r->unite,
                        'facteur' => $r->facteur,
                    ])->values()->all(),
                ], $tenantId);
                ReliquatClient::whereIn('id', $groupe->pluck('id'))->update(['statut' => ReliquatClient::FACTURE, 'facture_id' => $facture->id]);

                return $facture;
            })->values();
        });
    }

    /** Résumé de la sélection : nombre de reliquats par état. */
    public function resume(Builder $reliquats): array
    {
        $compte = (clone $reliquats)->toBase()->selectRaw('statut, COUNT(*) AS nombre')->groupBy('statut')->pluck('nombre', 'statut');

        return collect(ReliquatClient::STATUTS)->mapWithKeys(fn (string $statut) => [$statut => (int) ($compte[$statut] ?? 0)])->all();
    }

    private function verifierInscrit(ReliquatClient $reliquat, string $message): void
    {
        if ($reliquat->statut !== ReliquatClient::CREE) {
            throw new DomainException($message);
        }
    }

    /** Fournisseur principal actif d'un produit (fiche produit, sinon catalogue du fournisseur) ; sert aussi aux suggestions d'achat. */
    public function fournisseurPrincipal(int $produitId): ?int
    {
        $candidats = collect([ProduitTenant::withTrashed()->whereKey($produitId)->value('fournisseur_principal_id')])
            ->merge(DB::table('produit_fournisseur')->where('produit_id', $produitId)->where('fournisseur_principal', true)->pluck('fournisseur_id'))
            ->filter();

        return Fournisseur::whereIn('id', $candidats)->where('actif', true)->value('id');
    }

    /** Dernier prix payé au fournisseur pour ce produit, sinon son prix d'achat convenu, sinon 0 ; sert aussi aux suggestions d'achat. */
    public function dernierPrix(int $produitId, int $fournisseurId): float
    {
        $dernier = ComFourLigne::query()->join('com_four_entete', 'com_four_entete.id', '=', 'com_four_ligne.com_four_entete_id')
            ->where('com_four_entete.fournisseur_id', $fournisseurId)->where('com_four_ligne.produit_id', $produitId)
            ->where('com_four_ligne.prix_unitaire', '>', 0)
            ->orderByDesc('com_four_entete.date_commande')->orderByDesc('com_four_ligne.id')->value('com_four_ligne.prix_unitaire');
        $prix = $dernier ?? DB::table('produit_fournisseur')->where('produit_id', $produitId)->where('fournisseur_id', $fournisseurId)->value('prix_achat');

        return round((float) ($prix ?? 0), 2);
    }
}
