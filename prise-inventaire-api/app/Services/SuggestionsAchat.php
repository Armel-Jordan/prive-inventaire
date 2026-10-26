<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Configuration;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\ReliquatClient;
use App\Models\SuggestionAchat;
use App\Support\DateDuJour;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Suggestions d'achat (plan de parité fripv2, phase 3.2), comme « Suggestion de commandes aux fournisseurs » :
 * - inventaire : un produit actif dont le disponible (stock compté moins réservé, plus ce qui est déjà en commande si on
 *   le demande) passe sous son seuil d'alerte est proposé pour remonter jusqu'à son maximum (son seuil sans maximum) ;
 * - reliquats : ce qu'un client attend et qui n'est pas encore commandé au fournisseur ;
 * - manuelle : un besoin signalé depuis un produit, en attente de la décision de l'acheteur ;
 * - le fournisseur proposé est le fournisseur principal du produit, au dernier prix payé ;
 * - générer de nouveau remplace les suggestions en attente qui venaient d'un calcul ; les acceptées et les manuelles
 *   restent ;
 * - les suggestions acceptées deviennent des commandes fournisseur brouillons, une par fournisseur (ajoutées à une
 *   commande en préparation du fournisseur si on le demande) ; un reliquat commandé est rattaché à sa ligne.
 */
class SuggestionsAchat
{
    public function __construct(private readonly DisponibiliteProduits $disponibilites, private readonly ReliquatsClients $reliquats) {}

    /**
     * Calcule les suggestions selon les sources demandées.
     *
     * @return int le nombre de suggestions proposées
     */
    public function generer(bool $inventaire, bool $reliquats, bool $avecCommandes, ?int $fournisseurId, ?int $produitId): int
    {
        return DB::transaction(function () use ($inventaire, $reliquats, $avecCommandes, $fournisseurId, $produitId) {
            SuggestionAchat::where('statut', SuggestionAchat::EN_ATTENTE)->whereIn('source', ['inventaire', 'reliquat'])->delete();
            $ouvertes = SuggestionAchat::whereIn('statut', [SuggestionAchat::EN_ATTENTE, SuggestionAchat::ACCEPTEE])->get();
            $nombre = 0;

            if ($inventaire) {
                $dejaProposes = $ouvertes->where('source', '!=', 'reliquat')->pluck('produit_id')->unique()->all();
                $produits = ProduitTenant::where('actif', true)->where('seuil_alerte', '>', 0)
                    ->when($produitId, fn ($q) => $q->whereKey($produitId))
                    ->whereNotIn('id', $dejaProposes)->get(['id', 'numero', 'seuil_alerte', 'stock_maximum']);
                $disponibles = $this->disponibilites->calculer($produits->pluck('id')->all())->keyBy('produit_id');
                $enPreparation = $avecCommandes ? $this->enPreparation($produits->pluck('id')->all()) : collect();
                foreach ($produits as $produit) {
                    $d = $disponibles[$produit->id] ?? null;
                    if (! $d) {
                        continue;
                    }
                    $disponible = $d['disponible'] + ($avecCommandes ? $d['en_commande'] + (int) ($enPreparation[$produit->id] ?? 0) : 0);
                    $minimum = (float) $produit->seuil_alerte;
                    if ($disponible >= $minimum) {
                        continue;
                    }
                    $cible = $produit->stock_maximum !== null && (float) $produit->stock_maximum > $minimum ? (float) $produit->stock_maximum : $minimum;
                    $quantite = (int) ceil($cible - $disponible);
                    $fournisseur = $this->reliquats->fournisseurPrincipal($produit->id);
                    if ($quantite <= 0 || ($fournisseurId && $fournisseur !== $fournisseurId)) {
                        continue;
                    }
                    SuggestionAchat::create(['produit_id' => $produit->id, 'fournisseur_id' => $fournisseur, 'source' => 'inventaire', 'quantite_suggeree' => $quantite, 'statut' => SuggestionAchat::EN_ATTENTE]);
                    $nombre++;
                }
            }

            if ($reliquats) {
                $dejaProposes = $ouvertes->whereNotNull('reliquat_id')->pluck('reliquat_id')->all();
                $enAttente = ReliquatClient::where('statut', ReliquatClient::CREE)->whereNotIn('id', $dejaProposes)
                    ->when($produitId, fn ($q) => $q->where('produit_id', $produitId))->orderBy('id')->get();
                foreach ($enAttente as $reliquat) {
                    $fournisseur = $this->reliquats->fournisseurPrincipal($reliquat->produit_id);
                    if ($fournisseurId && $fournisseur !== $fournisseurId) {
                        continue;
                    }
                    SuggestionAchat::create([
                        'produit_id' => $reliquat->produit_id, 'fournisseur_id' => $fournisseur, 'source' => 'reliquat',
                        // En unités de stock : un reliquat de 2 caisses de 12 en suggère 24.
                        'reliquat_id' => $reliquat->id, 'quantite_suggeree' => ReliquatsClients::enStock([$reliquat]), 'statut' => SuggestionAchat::EN_ATTENTE,
                    ]);
                    $nombre++;
                }
            }

            return $nombre;
        });
    }

    /**
     * Besoin signalé depuis un produit, en attente de la décision de l'acheteur. Une suggestion ouverte du même produit
     * est signalée, sauf si on veut tout de même en créer une seconde.
     */
    public function suggerer(int $produitId, int $quantite, ?string $remarque, bool $forcer, AdminUser $auteur): SuggestionAchat
    {
        $existante = SuggestionAchat::where('produit_id', $produitId)->whereIn('statut', [SuggestionAchat::EN_ATTENTE, SuggestionAchat::ACCEPTEE])->first();
        if ($existante && ! $forcer) {
            throw ValidationException::withMessages(['deja' => $existante->statut === SuggestionAchat::ACCEPTEE
                ? __('Une suggestion existe déjà pour ce produit (:quantite unité(s), acceptée).', ['quantite' => $existante->quantite_suggeree])
                : __('Une suggestion existe déjà pour ce produit (:quantite unité(s), en attente).', ['quantite' => $existante->quantite_suggeree])]);
        }

        return SuggestionAchat::create([
            'produit_id' => $produitId,
            'fournisseur_id' => $this->reliquats->fournisseurPrincipal($produitId),
            'source' => 'manuelle',
            'quantite_suggeree' => $quantite,
            'statut' => SuggestionAchat::EN_ATTENTE,
            'remarque' => $remarque ?: 'Suggestion manuelle',
            'commis_id' => $auteur->id,
            'commis_nom' => $auteur->nom,
        ]);
    }

    /**
     * Accepte (ou remet en attente) des suggestions ; l'acceptation retient la quantité à commander (la suggérée si
     * elle n'est pas fixée) et le nom de qui accepte.
     *
     * @param  list<int>  $ids
     */
    public function accepter(array $ids, bool $accepte, AdminUser $auteur): int
    {
        $suggestions = SuggestionAchat::whereIn('id', $ids)->whereIn('statut', [SuggestionAchat::EN_ATTENTE, SuggestionAchat::ACCEPTEE])->get();
        foreach ($suggestions as $suggestion) {
            $suggestion->update($accepte
                ? ['statut' => SuggestionAchat::ACCEPTEE, 'quantite' => $suggestion->quantite ?: $suggestion->quantite_suggeree, 'commis_id' => $auteur->id, 'commis_nom' => $auteur->nom]
                : ['statut' => SuggestionAchat::EN_ATTENTE]);
        }

        return $suggestions->count();
    }

    /**
     * Commandes fournisseur brouillons des suggestions acceptées : une par fournisseur (ou ajoutées à sa commande en
     * préparation la plus récente si on le demande), une ligne par produit au dernier prix payé.
     *
     * @return Collection<int, ComFourEntete>
     */
    public function commander(bool $fusionner, int $tenantId, AdminUser $auteur): Collection
    {
        $config = Configuration::pourEntite('commande_fournisseur', $tenantId);
        if (! $config || ! $config->auto_increment) {
            throw new DomainException(__('Numéro requis : activez la numérotation automatique des commandes fournisseur (Configuration → Numérotation).'));
        }

        return DB::transaction(function () use ($fusionner, $tenantId, $auteur) {
            $acceptees = SuggestionAchat::with(['produit', 'reliquat'])->where('statut', SuggestionAchat::ACCEPTEE)->lockForUpdate()->orderBy('id')->get();
            if ($acceptees->isEmpty()) {
                throw new DomainException(__('Aucune suggestion acceptée : cochez celles à commander.'));
            }
            $sansFournisseur = $acceptees->first(fn (SuggestionAchat $s) => ! $s->fournisseur_id);
            if ($sansFournisseur) {
                throw ValidationException::withMessages(['fournisseur_id' => __("Choisissez le fournisseur de :produit : il n'a pas de fournisseur principal.", ['produit' => $sansFournisseur->produit?->numero])]);
            }
            $reliquatPris = $acceptees->first(fn (SuggestionAchat $s) => $s->reliquat && $s->reliquat->statut !== ReliquatClient::CREE);
            if ($reliquatPris) {
                throw new DomainException(__('Le reliquat de :produit est déjà commandé, reçu ou annulé : retirez sa suggestion.', ['produit' => $reliquatPris->produit?->numero]));
            }

            return $acceptees->groupBy('fournisseur_id')->map(function (Collection $groupe, $fournisseurId) use ($fusionner, $tenantId, $auteur) {
                $fiche = Fournisseur::findOrFail($fournisseurId);
                $commande = ($fusionner ? ComFourEntete::where('fournisseur_id', $fiche->id)->where('statut', ComFourEntete::STATUT_BROUILLON)->latest('id')->first() : null)
                    ?? ComFourEntete::create([
                        'tenant_id' => $tenantId,
                        'numero' => Configuration::consommerNumero('commande_fournisseur', $tenantId),
                        'fournisseur_id' => $fiche->id,
                        'date_commande' => DateDuJour::aujourdhui(),
                        'notes' => "Suggestions d'achat",
                        'devise' => $fiche->devise ?? 'EUR',
                        'taux_change' => 1.0,
                        'statut' => ComFourEntete::STATUT_BROUILLON,
                        'created_by' => $auteur->id,
                    ]);
                foreach ($groupe->groupBy('produit_id') as $produitId => $duProduit) {
                    // Les suggestions se comptent en unités de stock ; la commande, en unités d'achat entières.
                    $besoin = (float) $duProduit->sum(fn (SuggestionAchat $s) => $s->quantite ?: $s->quantite_suggeree);
                    $ligne = $commande->lignes()->where('produit_id', $produitId)->first();
                    if ($ligne) {
                        $quantite = UnitesAchat::enAchat($besoin, max(1, (int) $ligne->qte_par_unite_achat));
                        $ligne->update(['quantite_commandee' => $ligne->quantite_commandee + $quantite, 'montant_ligne' => ($ligne->quantite_commandee + $quantite) * (float) $ligne->prix_unitaire]);
                    } else {
                        $produit = $duProduit->first()->produit;
                        $prix = $this->reliquats->dernierPrix((int) $produitId, $fiche->id);
                        $conditionnement = app(UnitesAchat::class)->pour($produit, $fiche->id);
                        $quantite = UnitesAchat::enAchat($besoin, $conditionnement['qte_par_unite_achat']);
                        $ligne = ComFourLigne::create($conditionnement + [
                            'com_four_entete_id' => $commande->id,
                            'produit_id' => $produitId,
                            'quantite_commandee' => $quantite,
                            'prix_unitaire' => $prix,
                            'montant_ligne' => $quantite * $prix,
                        ]);
                    }
                    foreach ($duProduit as $suggestion) {
                        $suggestion->update(['statut' => SuggestionAchat::COMMANDEE, 'com_four_ligne_id' => $ligne->id]);
                        // Le reliquat du client est désormais commandé, sur cette ligne.
                        $suggestion->reliquat?->update(['com_four_ligne_id' => $ligne->id, 'quantite_commandee' => $suggestion->reliquat->quantite, 'statut' => ReliquatClient::COMMANDE]);
                    }
                }
                $commande->recalculerMontantTotal();

                return $commande->load('lignes');
            })->values();
        });
    }

    /** Quantités des commandes fournisseur en préparation (brouillons), par produit, en unités de stock. */
    private function enPreparation(array $produits): Collection
    {
        return ComFourLigne::query()->join('com_four_entete', 'com_four_entete.id', '=', 'com_four_ligne.com_four_entete_id')
            ->whereIn('com_four_entete.id', ComFourEntete::where('statut', ComFourEntete::STATUT_BROUILLON)->select('id'))
            ->whereIn('com_four_ligne.produit_id', $produits)
            ->groupBy('com_four_ligne.produit_id')
            ->selectRaw('com_four_ligne.produit_id AS produit, SUM(com_four_ligne.quantite_commandee * com_four_ligne.qte_par_unite_achat) AS quantite')
            ->pluck('quantite', 'produit');
    }
}
