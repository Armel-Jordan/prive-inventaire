<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Configuration;
use App\Models\Fournisseur;
use App\Models\HistoriquePrixAchat;
use App\Models\Reception;
use App\Models\ReceptionArrivagesLigne;
use App\Models\ReceptionFrais;
use App\Models\TenantParametres;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Réception fournisseur documentée (plan de parité fripv2, phase 3.3 : « Réception des commandes fournisseur ») :
 *
 *  - une livraison donne un document numéroté (REC) qui regroupe ses lignes, d'une ou de plusieurs commandes envoyées
 *    du même fournisseur, avec le bon de livraison du fournisseur, le connaissement et une note ;
 *  - chaque ligne garde sa quantité (en unité d'achat), son secteur, son lot, sa péremption et le coût unitaire
 *    facturé ; une ligne de commande peut se répartir sur plusieurs secteurs ;
 *  - recevoir plus que le reste demande une confirmation explicite (« accepter_surplus ») ;
 *  - une ligne que le fournisseur ne livrera pas au complet se clôture : la quantité commandée est ramenée au reçu et
 *    le reste abandonné reste visible (« quantite_abandonnee ») ; ses reliquats clients deviennent facturables ;
 *  - un coût différent du prix commandé est inscrit à l'historique des prix d'achat et au catalogue du fournisseur ;
 *  - une réception se corrige (date, références, coûts : jamais les quantités) ou s'annule : ses quantités sont alors
 *    retirées des commandes et des reliquats clients, et elle ne compte plus nulle part ;
 *  - ses coûts annexes (transport, douane, assurance…) se répartissent sur ses lignes au prorata de la valeur, de la
 *    quantité, du poids ou du volume : « frais_unitaire », par unité de stock dans la devise de l'entreprise, s'ajoute
 *    au coût de revient que retient la valorisation de l'inventaire.
 *
 * Les réceptions enregistrées avant cette version n'ont pas de document et comptent comme avant.
 */
class ReceptionsFournisseur
{
    /** Commandes qui attendent encore de la marchandise. */
    public const RECEVABLES = [ComFourEntete::STATUT_ENVOYEE, ComFourEntete::STATUT_PARTIELLE];

    public function __construct(private readonly ReliquatsClients $reliquats, private readonly RetoursFournisseurs $retours, private readonly LotsSeries $lots) {}

    /**
     * Enregistre une livraison, tout ou rien : le document et ses lignes, puis la clôture des lignes indiquées.
     *
     * @param  array{date_reception: string, bon_livraison?: ?string, connaissement?: ?string, notes?: ?string, accepter_surplus?: bool,
     *     receptions?: list<array{com_four_ligne_id: int, quantite_recue: int, secteur_id?: ?int, lot_numero?: ?string,
     *     date_peremption?: ?string, notes?: ?string, cout_unitaire?: float|string|null}>, clotures?: list<int>}  $donnees
     * @return array{reception: ?Reception, lignes: list<ReceptionArrivagesLigne>, cloturees: list<int>}
     */
    public function enregistrer(array $donnees, int $tenantId, ?AdminUser $auteur): array
    {
        $saisies = array_values($donnees['receptions'] ?? []);
        $clotures = array_values(array_unique(array_map('intval', $donnees['clotures'] ?? [])));
        if ($saisies === [] && $clotures === []) {
            throw ValidationException::withMessages(['receptions' => 'Saisissez au moins une quantité reçue, ou clôturez une ligne.']);
        }
        if ($saisies === [] && ($donnees['frais'] ?? []) !== []) {
            throw ValidationException::withMessages(['frais' => 'Les coûts annexes accompagnent une réception : saisissez au moins une quantité reçue.']);
        }

        return DB::transaction(function () use ($donnees, $saisies, $clotures, $tenantId, $auteur) {
            // Lignes verrouillées : deux réceptions simultanées ne peuvent pas dépasser le reste à recevoir.
            $ids = array_values(array_unique([...array_map(fn (array $s) => (int) $s['com_four_ligne_id'], $saisies), ...$clotures]));
            $lignes = ComFourLigne::query()->whereIn('id', $ids)->whereHas('commande')->with('produit')->lockForUpdate()->get()->keyBy('id');
            foreach ($saisies as $index => $saisie) {
                if (! $lignes->has((int) $saisie['com_four_ligne_id'])) {
                    throw ValidationException::withMessages(["receptions.{$index}.com_four_ligne_id" => 'Ligne de commande introuvable.']);
                }
            }
            foreach ($clotures as $index => $id) {
                if (! $lignes->has($id)) {
                    throw ValidationException::withMessages(["clotures.{$index}" => 'Ligne de commande introuvable.']);
                }
            }

            $commandes = ComFourEntete::query()->whereIn('id', $lignes->pluck('com_four_entete_id')->unique())->lockForUpdate()->get()->keyBy('id');
            if ($commandes->pluck('fournisseur_id')->unique()->count() > 1) {
                throw ValidationException::withMessages(['receptions' => "Une réception ne regroupe que des commandes d'un même fournisseur."]);
            }
            foreach ($commandes as $commande) {
                if (! in_array($commande->statut, self::RECEVABLES, true)) {
                    throw new DomainException("La commande {$commande->numero} n'attend pas de livraison : seules les commandes envoyées ou reçues en partie se réceptionnent.");
                }
            }
            $this->verifierQuantites($saisies, $lignes, (bool) ($donnees['accepter_surplus'] ?? false));
            // Produits suivis par lot ou par numéro de série (phase 2.8).
            $this->lots->verifierReception($saisies, $lignes);

            $fournisseur = Fournisseur::withTrashed()->findOrFail($commandes->first()->fournisseur_id);
            $reception = null;
            $creees = [];
            if ($saisies !== []) {
                $reception = Reception::create([
                    'tenant_id' => $tenantId,
                    'numero' => $this->numero($tenantId),
                    'fournisseur_id' => $fournisseur->id,
                    'date_reception' => $donnees['date_reception'],
                    'bon_livraison' => $donnees['bon_livraison'] ?? null,
                    'connaissement' => $donnees['connaissement'] ?? null,
                    'notes' => $donnees['notes'] ?? null,
                    'statut' => Reception::ENREGISTREE,
                    'recue_par' => $auteur?->id,
                ]);
                foreach ($saisies as $saisie) {
                    $ligne = $lignes[(int) $saisie['com_four_ligne_id']];
                    $cout = $this->cout($saisie['cout_unitaire'] ?? null);
                    // Le modèle répercute la quantité sur la ligne de commande, son statut et ses reliquats clients.
                    $creees[] = ReceptionArrivagesLigne::create([
                        'reception_id' => $reception->id,
                        'com_four_ligne_id' => $ligne->id,
                        'date_reception' => $donnees['date_reception'],
                        'quantite_recue' => (int) $saisie['quantite_recue'],
                        'cout_unitaire' => $cout,
                        'secteur_id' => $saisie['secteur_id'] ?? null,
                        'lot_numero' => $saisie['lot_numero'] ?? null,
                        'date_peremption' => $saisie['date_peremption'] ?? null,
                        'notes' => $saisie['notes'] ?? null,
                        'received_by' => $auteur?->id,
                    ]);
                    $this->lots->enregistrerReception(end($creees), $ligne, $saisie, $fournisseur, $auteur);
                }
                $this->retenirCouts($reception, $creees, $lignes, $commandes, $fournisseur, $auteur);
                $this->remplacerFrais($reception, $donnees['frais'] ?? []);
            }

            $cloturees = $this->cloturer($clotures);
            foreach ($commandes->keys() as $commandeId) {
                $commande = ComFourEntete::query()->findOrFail($commandeId);
                if ($cloturees !== [] && (int) $commande->lignes()->sum('quantite_recue') === 0
                    && ! $commande->lignes()->whereColumn('quantite_recue', '<', 'quantite_commandee')->exists()) {
                    throw new DomainException("Rien n'a été reçu sur la commande {$commande->numero} : annulez-la plutôt que de clôturer toutes ses lignes.");
                }
                $commande->updateStatut();
            }

            return ['reception' => $reception?->load($this->relations()), 'lignes' => $creees, 'cloturees' => $cloturees];
        });
    }

    /**
     * Corrige une réception enregistrée : date, bon de livraison, connaissement, note, coût de ses lignes et coûts
     * annexes (la liste remplace la précédente). Les quantités ne se corrigent pas (elles ont été reportées sur les
     * commandes) : on annule et on refait la réception.
     *
     * @param  array{date_reception?: string, bon_livraison?: ?string, connaissement?: ?string, notes?: ?string,
     *     lignes?: list<array{id: int, cout_unitaire?: float|string|null}>, frais?: list<array<string, mixed>>}  $donnees
     */
    public function modifier(Reception $reception, array $donnees): Reception
    {
        if ($reception->statut !== Reception::ENREGISTREE) {
            throw new DomainException('Une réception annulée ne se modifie plus.');
        }

        return DB::transaction(function () use ($reception, $donnees) {
            $reception->update(array_intersect_key($donnees, array_flip(['date_reception', 'bon_livraison', 'connaissement', 'notes'])));
            if (isset($donnees['date_reception'])) {
                $reception->lignes()->update(['date_reception' => $donnees['date_reception']]);
            }
            foreach (array_values($donnees['lignes'] ?? []) as $index => $saisie) {
                $ligne = $reception->lignes()->whereKey((int) $saisie['id'])->first()
                    ?? throw ValidationException::withMessages(["lignes.{$index}.id" => "Cette ligne n'appartient pas à la réception."]);
                if (array_key_exists('cout_unitaire', $saisie)) {
                    $ligne->update(['cout_unitaire' => $this->cout($saisie['cout_unitaire'])]);
                }
            }
            if (array_key_exists('frais', $donnees)) {
                $reception->frais()->delete();
                $this->remplacerFrais($reception, $donnees['frais'] ?? []);
            } else {
                // Une répartition à la valeur suit les coûts corrigés.
                $this->repartirFrais($reception);
            }

            return $reception->fresh($this->relations());
        });
    }

    /**
     * Annule une réception saisie par erreur : ses quantités sont retirées des lignes de commande (qui redeviennent à
     * recevoir) et des reliquats clients, le statut des commandes est recalculé et ses lignes ne comptent plus nulle
     * part. Refusé si la marchandise est déjà repartie au fournisseur ou facturée au client d'un reliquat.
     */
    public function annuler(Reception $reception, ?string $motif, ?AdminUser $auteur): Reception
    {
        return DB::transaction(function () use ($reception, $motif, $auteur) {
            $reception = Reception::query()->lockForUpdate()->findOrFail($reception->id);
            if ($reception->statut !== Reception::ENREGISTREE) {
                throw new DomainException('Cette réception est déjà annulée.');
            }
            if ($reception->facture_fournisseur_id) {
                $facture = $reception->factureFournisseur()->value('numero');
                throw new DomainException("Cette réception est facturée ({$facture}) : annulez d'abord la facture fournisseur.");
            }

            $lignes = $reception->lignes()->where('annulee', false)->get();
            $commandes = [];
            foreach ($lignes->groupBy('com_four_ligne_id') as $ligneId => $groupe) {
                $ligneCommande = ComFourLigne::query()->with(['produit', 'commande'])->lockForUpdate()->findOrFail($ligneId);
                $ligneCommande->quantite_recue = max(0, $ligneCommande->quantite_recue - (int) $groupe->sum('quantite_recue'));
                $repartis = $this->retours->dejaRepartiCommande($ligneCommande->commande)[$ligneCommande->id] ?? 0;
                if ($ligneCommande->quantite_recue < $repartis) {
                    throw new DomainException("{$repartis} {$ligneCommande->produit?->numero} de la commande {$ligneCommande->commande->numero} sont déjà repartis au fournisseur (retour) : la réception ne peut plus être annulée.");
                }
                $ligneCommande->save();
                $this->reliquats->receptionAnnulee($ligneCommande);
                $commandes[$ligneCommande->com_four_entete_id] = true;
            }

            // Lots contre-passés, numéros de série retirés (refusé si un exemplaire est déjà sorti).
            $this->lots->receptionAnnulee($lignes, $auteur);
            ReceptionArrivagesLigne::query()->whereIn('id', $lignes->pluck('id'))->update(['annulee' => true]);
            $reception->update([
                'statut' => Reception::ANNULEE, 'annulee_le' => now(), 'annulee_par' => $auteur?->id,
                'motif_annulation' => filled($motif) ? $motif : null,
            ]);
            foreach (array_keys($commandes) as $commandeId) {
                $this->recalculerStatut(ComFourEntete::query()->findOrFail($commandeId));
            }

            return $reception->fresh($this->relations());
        });
    }

    /** Relations d'une réception affichée : fournisseur, auteurs, lignes avec leur commande, produit et secteur. */
    public function relations(): array
    {
        return [
            'fournisseur:id,code,raison_sociale,adresse,ville,telephone,email,devise',
            'auteur:id,nom', 'auteurAnnulation:id,nom', 'frais.fournisseur:id,code,raison_sociale', 'factureFournisseur:id,numero,numero_fournisseur',
            'lignes.ligneCommande.commande:id,numero,devise,taux_change,date_commande,statut',
            'lignes.ligneCommande.produit' => fn ($q) => $q->withTrashed(),
            'lignes.secteur' => fn ($q) => $q->withTrashed(),
        ];
    }

    /**
     * Enregistre les coûts annexes d'une réception puis les répartit. Un coût dans une autre devise que celle de
     * l'entreprise demande son taux de change.
     *
     * @param  list<array<string, mixed>>  $frais
     */
    private function remplacerFrais(Reception $reception, array $frais): void
    {
        $reference = strtoupper(trim((string) TenantParametres::query()->value('devise_code')));
        foreach (array_values($frais) as $index => $saisie) {
            $devise = strtoupper(trim((string) ($saisie['devise'] ?? '')));
            $etrangere = $devise !== '' && $devise !== $reference;
            $taux = (float) ($saisie['taux_change'] ?? 0);
            if ($etrangere && $taux <= 0) {
                throw ValidationException::withMessages(["frais.{$index}.taux_change" => "Indiquez le taux de change {$devise} → {$reference}."]);
            }
            ReceptionFrais::create([
                'tenant_id' => $reception->tenant_id,
                'reception_id' => $reception->id,
                'type' => $saisie['type'],
                'libelle' => $saisie['libelle'] ?? null,
                'fournisseur_id' => $saisie['fournisseur_id'] ?? null,
                'reference' => $saisie['reference'] ?? null,
                'montant' => round((float) $saisie['montant'], 2),
                'devise' => $etrangere ? $devise : null,
                'taux_change' => $etrangere ? $taux : 1,
                'repartition' => $saisie['repartition'] ?? 'valeur',
            ]);
        }
        $this->repartirFrais($reception);
    }

    /**
     * Répartit les coûts annexes sur les lignes de la réception, au prorata de la base choisie pour chacun : valeur
     * reçue (devise de l'entreprise), quantité, poids ou volume (unités de stock × poids ou volume de la fiche produit).
     * Chaque ligne retient ses coûts par unité de stock (« frais_unitaire »).
     */
    public function repartirFrais(Reception $reception): void
    {
        $lignes = $reception->lignes()->where('annulee', false)
            ->with(['ligneCommande.commande', 'ligneCommande.produit' => fn ($q) => $q->withTrashed()])->get();
        $frais = $reception->frais()->get();
        $alloue = array_fill_keys($lignes->pluck('id')->all(), 0.0);
        foreach ($frais as $index => $cout) {
            $bases = $lignes->mapWithKeys(fn (ReceptionArrivagesLigne $l) => [$l->id => $this->base($l, $cout->repartition)]);
            $total = $bases->sum();
            if ($total <= 0) {
                $mesures = ['poids' => 'de poids', 'volume' => 'de volume', 'valeur' => 'de valeur', 'quantite' => 'de quantité'];
                throw ValidationException::withMessages([
                    "frais.{$index}.repartition" => "Impossible de répartir ce coût : aucune ligne de la réception n'a ".($mesures[$cout->repartition] ?? 'de base')
                        .($cout->repartition === 'poids' || $cout->repartition === 'volume' ? ' sur sa fiche produit' : '').'. Choisissez une autre répartition.',
                ]);
            }
            foreach ($bases as $id => $base) {
                $alloue[$id] += $cout->montantReference() * $base / $total;
            }
        }
        foreach ($lignes as $ligne) {
            $unites = $ligne->quantite_recue * max(1, (int) $ligne->ligneCommande?->qte_par_unite_achat);
            $ligne->update(['frais_unitaire' => $frais->isEmpty() || $unites <= 0 ? null : round($alloue[$ligne->id] / $unites, 4)]);
        }
    }

    /** Base de répartition d'une ligne : valeur (devise de l'entreprise), unités de stock, poids ou volume. */
    private function base(ReceptionArrivagesLigne $ligne, string $repartition): float
    {
        $commande = $ligne->ligneCommande;
        $unites = $ligne->quantite_recue * max(1, (int) $commande?->qte_par_unite_achat);

        return match ($repartition) {
            'quantite' => (float) $unites,
            'poids' => $unites * (float) ($commande?->produit?->poids_kg ?? 0),
            'volume' => $unites * (float) ($commande?->produit?->volume_m3 ?? 0),
            default => $ligne->quantite_recue * (float) ($ligne->cout_unitaire ?? $commande?->prix_unitaire ?? 0)
                * ((float) $commande?->commande?->taux_change ?: 1.0),
        };
    }

    /** Une ligne de commande reçoit au plus son reste (toutes saisies confondues), sauf sur-réception acceptée. */
    private function verifierQuantites(array $saisies, Collection $lignes, bool $surplus): void
    {
        $parLigne = [];
        foreach ($saisies as $index => $saisie) {
            $ligne = $lignes[(int) $saisie['com_four_ligne_id']];
            $parLigne[$ligne->id] = ($parLigne[$ligne->id] ?? 0) + (int) $saisie['quantite_recue'];
            $reste = max(0, $ligne->quantite_commandee - $ligne->quantite_recue);
            if (! $surplus && $parLigne[$ligne->id] > $reste) {
                // Clé « receptions » : celle de l'erreur de sur-réception avant les documents de réception.
                throw ValidationException::withMessages([
                    'receptions' => "La quantité reçue ({$parLigne[$ligne->id]}) dépasse la quantité restante ({$reste}) pour le produit {$ligne->produit?->description} : confirmez la sur-réception.",
                ]);
            }
        }
    }

    /** Ferme les lignes indiquées : la quantité commandée est ramenée au reçu, le reste est noté abandonné. */
    private function cloturer(array $ids): array
    {
        $cloturees = [];
        foreach ($ids as $id) {
            $ligne = ComFourLigne::query()->lockForUpdate()->findOrFail($id);
            $abandon = $ligne->quantite_commandee - $ligne->quantite_recue;
            if ($abandon <= 0) {
                continue;
            }
            $ligne->quantite_abandonnee += $abandon;
            $ligne->quantite_commandee = $ligne->quantite_recue;
            $ligne->save();
            $cloturees[] = $ligne->id;
        }
        $this->reliquats->lignesCloturees($cloturees);

        return $cloturees;
    }

    /**
     * Coût facturé différent du prix commandé : inscrit à l'historique des prix d'achat et, si la commande est dans la
     * devise du fournisseur, au catalogue du fournisseur (journalisé comme l'import d'une grille de prix).
     */
    private function retenirCouts(Reception $reception, array $creees, Collection $lignes, Collection $commandes, Fournisseur $fournisseur, ?AdminUser $auteur): void
    {
        $retenus = [];
        foreach ($creees as $creee) {
            $ligne = $lignes[$creee->com_four_ligne_id];
            $cout = $creee->cout_unitaire === null ? null : (float) $creee->cout_unitaire;
            if ($cout === null || abs($cout - (float) $ligne->prix_unitaire) < 0.00005 || isset($retenus[$ligne->produit_id])) {
                continue;
            }
            $retenus[$ligne->produit_id] = true;
            $commande = $commandes[$ligne->com_four_entete_id];
            HistoriquePrixAchat::create([
                'produit_id' => $ligne->produit_id,
                'fournisseur_id' => $fournisseur->id,
                'prix_achat' => $cout,
                'date_effet' => $reception->date_reception,
                'com_four_entete_id' => $commande->id,
            ]);
            if (($commande->devise ?: null) !== ($fournisseur->devise ?: null)) {
                continue;
            }
            $catalogue = DB::table('produit_fournisseur')->where('produit_id', $ligne->produit_id)->where('fournisseur_id', $fournisseur->id);
            $ancien = (clone $catalogue)->value('prix_achat');
            if ($ancien === null && ! (clone $catalogue)->exists()) {
                continue;
            }
            $catalogue->update(['prix_achat' => $cout, 'updated_at' => now()]);
            AuditLog::create([
                'tenant_id' => $reception->tenant_id,
                'action' => 'update',
                'model_type' => 'Prix fournisseur',
                'model_id' => "{$fournisseur->code}/{$ligne->produit?->numero}",
                'user_id' => $auteur ? (string) $auteur->getAuthIdentifier() : null,
                'user_name' => $auteur?->nom ?: $auteur?->email,
                'old_values' => ['prix_achat' => $ancien === null ? null : (float) $ancien],
                'new_values' => ['prix_achat' => $cout, 'source' => "Réception {$reception->numero}"],
                'ip_address' => app()->runningInConsole() ? null : request()->ip(),
                'user_agent' => app()->runningInConsole() ? null : request()->userAgent(),
            ]);
        }
    }

    /** Statut d'une commande d'après ses quantités reçues : envoyée (rien de reçu), reçue en partie ou complète. */
    private function recalculerStatut(ComFourEntete $commande): void
    {
        if (! in_array($commande->statut, [...self::RECEVABLES, ComFourEntete::STATUT_COMPLETE], true)) {
            return;
        }
        $lignes = $commande->lignes()->get();
        $statut = match (true) {
            $lignes->isNotEmpty() && $lignes->every(fn (ComFourLigne $l) => $l->quantite_recue >= $l->quantite_commandee) => ComFourEntete::STATUT_COMPLETE,
            $lignes->sum('quantite_recue') > 0 => ComFourEntete::STATUT_PARTIELLE,
            default => ComFourEntete::STATUT_ENVOYEE,
        };
        $commande->update(['statut' => $statut]);
    }

    private function numero(int $tenantId): string
    {
        $config = Configuration::pourEntite('reception', $tenantId);
        if (! $config?->auto_increment) {
            throw new DomainException('Numéro requis : activez la numérotation automatique des réceptions (Configuration → Numérotation).');
        }

        return Configuration::consommerNumero('reception', $tenantId);
    }

    /** Coût saisi : vide = prix de la ligne de commande. */
    private function cout(mixed $valeur): ?float
    {
        return $valeur === null || $valeur === '' ? null : round((float) $valeur, 4);
    }
}
