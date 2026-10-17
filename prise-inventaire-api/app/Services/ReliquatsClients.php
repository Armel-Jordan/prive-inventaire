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
            throw new DomainException('Seule une commande acceptée peut laisser un reliquat.');
        }
        $lignesCommande = $commande->lignes()->get()->keyBy('id');
        $dejaEnReliquat = ReliquatClient::where('commande_client_id', $commande->id)->where('statut', '!=', ReliquatClient::ANNULE)
            ->selectRaw('commande_client_ligne_id, SUM(quantite) AS quantite')->groupBy('commande_client_ligne_id')->pluck('quantite', 'commande_client_ligne_id');
        $saisies = [];
        foreach (array_values($lignes) as $index => $saisie) {
            $ligne = $lignesCommande[(int) $saisie['ligne_id']] ?? null;
            if (! $ligne) {
                throw ValidationException::withMessages(["lignes.{$index}.ligne_id" => "Cette ligne n'appartient pas à la commande {$commande->numero}."]);
            }
            $reste = (int) $ligne->quantite - (int) ($dejaEnReliquat[$ligne->id] ?? 0);
            if ((int) $saisie['quantite'] > $reste) {
                throw ValidationException::withMessages(["lignes.{$index}.quantite" => 'Au plus '.max(0, $reste).' (quantité commandée, reliquats déjà notés déduits).']);
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
        ])));
    }

    /** Modification d'un reliquat inscrit (pas encore commandé au fournisseur). */
    public function modifier(ReliquatClient $reliquat, array $donnees): ReliquatClient
    {
        $this->verifierInscrit($reliquat, 'Seul un reliquat pas encore commandé au fournisseur se modifie.');
        $reliquat->update(array_intersect_key($donnees, array_flip(['quantite', 'prix_unitaire_ht', 'remise_ligne', 'date_requise', 'notes'])));

        return $reliquat;
    }

    public function annuler(ReliquatClient $reliquat): ReliquatClient
    {
        $this->verifierInscrit($reliquat, 'Seul un reliquat pas encore commandé au fournisseur s\'annule.');
        $reliquat->update(['statut' => ReliquatClient::ANNULE]);

        return $reliquat;
    }

    public function supprimer(ReliquatClient $reliquat): void
    {
        $this->verifierInscrit($reliquat, 'Seul un reliquat pas encore commandé au fournisseur se supprime.');
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
            throw new DomainException('Numéro requis : activez la numérotation automatique des commandes fournisseur (Configuration → Numérotation).');
        }

        return DB::transaction(function () use ($ids, $fournisseurId, $tenantId, $auteur) {
            $reliquats = ReliquatClient::with(['produit', 'client:id,raison_sociale'])->whereIn('id', $ids)->lockForUpdate()->orderBy('id')->get();
            if ($reliquats->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['ids' => 'Un des reliquats choisis est introuvable.']);
            }
            foreach ($reliquats as $reliquat) {
                $this->verifierInscrit($reliquat, "Le reliquat de {$reliquat->produit?->description} est déjà commandé, reçu, facturé ou annulé.");
            }
            $parFournisseur = $reliquats->groupBy(function (ReliquatClient $r) use ($fournisseurId) {
                $fournisseur = $fournisseurId ?? $this->fournisseurPrincipal($r->produit_id);
                if (! $fournisseur) {
                    throw ValidationException::withMessages(['fournisseur_id' => "Choisissez le fournisseur : {$r->produit?->description} n'a pas de fournisseur principal."]);
                }

                return $fournisseur;
            });

            return $parFournisseur->map(function (Collection $groupe, $fournisseur) use ($tenantId, $auteur) {
                $fiche = Fournisseur::findOrFail($fournisseur);
                $commande = ComFourEntete::create([
                    'tenant_id' => $tenantId,
                    'numero' => Configuration::consommerNumero('commande_fournisseur', $tenantId),
                    'fournisseur_id' => $fiche->id,
                    'date_commande' => now()->toDateString(),
                    'notes' => 'Reliquats clients : '.$groupe->map(fn (ReliquatClient $r) => $r->client?->raison_sociale)->filter()->unique()->implode(', '),
                    'devise' => $fiche->devise ?? 'EUR',
                    'taux_change' => 1.0,
                    'statut' => ComFourEntete::STATUT_BROUILLON,
                    'created_by' => $auteur,
                ]);
                foreach ($groupe->groupBy('produit_id') as $produitId => $duProduit) {
                    $produit = ProduitTenant::withTrashed()->find($produitId);
                    $quantite = (int) $duProduit->sum('quantite');
                    $prix = $this->dernierPrix((int) $produitId, $fiche->id);
                    $ligne = ComFourLigne::create([
                        'com_four_entete_id' => $commande->id,
                        'produit_id' => $produitId,
                        'quantite_commandee' => $quantite,
                        'unite_achat' => $produit?->unite_achat,
                        'qte_par_unite_achat' => $produit?->qte_par_unite_achat ?? 1,
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

    /** Réception d'une ligne fournisseur : répartie sur ses reliquats, du plus ancien au plus récent. */
    public function recevoir(ComFourLigne $ligne, int $quantite): void
    {
        $reste = $quantite;
        $reliquats = ReliquatClient::withoutGlobalScopes()->where('com_four_ligne_id', $ligne->id)
            ->whereIn('statut', [ReliquatClient::COMMANDE, ReliquatClient::RECU_PARTIEL])->orderBy('id')->get();
        foreach ($reliquats as $reliquat) {
            if ($reste <= 0) {
                break;
            }
            $part = min($reste, $reliquat->quantite - $reliquat->quantite_recue);
            if ($part <= 0) {
                continue;
            }
            $reste -= $part;
            $recue = $reliquat->quantite_recue + $part;
            $reliquat->update(['quantite_recue' => $recue, 'statut' => $recue >= $reliquat->quantite ? ReliquatClient::RECU : ReliquatClient::RECU_PARTIEL]);
        }
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
        $lignes = $commande->lignes()->pluck('id');
        ReliquatClient::withoutGlobalScopes()->whereIn('com_four_ligne_id', $lignes)->where('statut', ReliquatClient::RECU_PARTIEL)
            ->update(['statut' => ReliquatClient::RECU]);
        $this->liberer($lignes);
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
            throw new DomainException('Numéro requis : activez la numérotation automatique des factures (Configuration → Numérotation).');
        }

        return DB::transaction(function () use ($ids, $date, $tenantId) {
            $reliquats = ReliquatClient::whereIn('id', $ids)->lockForUpdate()->orderBy('id')->get();
            if ($reliquats->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['ids' => 'Un des reliquats choisis est introuvable.']);
            }
            if ($reliquats->contains(fn (ReliquatClient $r) => $r->statut !== ReliquatClient::RECU)) {
                throw new DomainException('Seuls les reliquats reçus se facturent : attendez la fin de la réception, ou clôturez la commande fournisseur.');
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
