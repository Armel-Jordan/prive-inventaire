<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\ConditionPaiement;
use App\Models\Configuration;
use App\Models\FactureFournisseur;
use App\Models\FactureFournisseurLigne;
use App\Models\FactureFournisseurPaiement;
use App\Models\Fournisseur;
use App\Models\Reception;
use App\Models\ReceptionArrivagesLigne;
use App\Models\TenantParametres;
use App\Support\TauxTaxe;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Factures fournisseurs (plan de parité fripv2, phase 3.5 : « Compte à payer ») :
 *
 *  - saisie à la main (services, frais) ou depuis des réceptions d'un même fournisseur, qui passent alors « facturées » :
 *    une ligne par ligne reçue, à la quantité reçue et au coût réel (sinon au prix commandé) ;
 *  - taxes par ligne (taux, ou groupe de taxes comme les ventes) ; échéance d'après la condition de paiement ;
 *  - rapprochement : l'écart entre le montant facturé et la valeur reçue est montré ligne par ligne ; le coût réel des
 *    réceptions peut s'aligner sur le prix facturé (la valorisation de l'inventaire suit) ;
 *  - paiements (montant, date, mode, référence) jusqu'au reste à payer ; annulation d'un paiement saisi par erreur ;
 *  - une facture qui a reçu un paiement ne se modifie plus ; annulée, elle libère ses réceptions.
 */
class FacturesFournisseurs
{
    public function __construct(
        private readonly TaxesDocument $taxes,
        private readonly ConditionsPaiement $conditions,
        private readonly ReceptionsFournisseur $receptions,
    ) {}

    /**
     * Facture proposée depuis des réceptions d'un même fournisseur, pas encore facturées : une ligne par ligne reçue
     * (quantité reçue, coût réel sinon prix commandé), dans la devise de leurs commandes.
     *
     * @param  list<int>  $ids
     */
    public function proposition(array $ids): array
    {
        $receptions = $this->receptionsAFacturer($ids, null);
        $lignes = $receptions->flatMap(fn (Reception $r) => $r->lignes->where('annulee', false)->map(fn (ReceptionArrivagesLigne $l) => [
            'produit_id' => $l->ligneCommande?->produit_id,
            'description' => trim(($l->ligneCommande?->produit?->numero ?? '').' '.($l->ligneCommande?->produit?->description ?? '')).' — '.$r->numero,
            'quantite' => (float) $l->quantite_recue,
            'prix_unitaire' => (float) ($l->cout_unitaire ?? $l->ligneCommande?->prix_unitaire ?? 0),
            'reception_ligne_id' => $l->id,
        ]))->values();
        $commandes = $receptions->flatMap(fn (Reception $r) => $r->lignes->map(fn ($l) => $l->ligneCommande?->commande))->filter();
        $devises = $commandes->map(fn ($c) => $this->devise($c->devise))->unique()->values();
        if ($devises->count() > 1) {
            throw ValidationException::withMessages(['receptions' => 'Les réceptions choisies sont dans des devises différentes : facturez-les séparément.']);
        }

        return [
            'fournisseur_id' => $receptions->first()->fournisseur_id,
            'devise' => $devises->first(),
            'taux_change' => $devises->first() ? (float) ($commandes->first()->taux_change ?: 1) : 1.0,
            'receptions' => $receptions->map(fn (Reception $r) => ['id' => $r->id, 'numero' => $r->numero, 'bon_livraison' => $r->bon_livraison])->values()->all(),
            'lignes' => $lignes->all(),
        ];
    }

    /**
     * Enregistre une facture (nouvelle, ou modifiée tant qu'elle n'a reçu aucun paiement) : lignes, taxes, échéance,
     * réceptions facturées.
     *
     * @param  array<string, mixed>  $donnees
     */
    public function enregistrer(array $donnees, ?FactureFournisseur $facture, int $tenantId, ?AdminUser $auteur): FactureFournisseur
    {
        if ($facture?->statut === FactureFournisseur::ANNULEE) {
            throw new DomainException('Une facture annulée ne se modifie plus.');
        }
        if ($facture && (float) $facture->montant_paye > 0) {
            throw new DomainException('Cette facture a déjà reçu un paiement : elle ne se modifie plus.');
        }

        return DB::transaction(function () use ($donnees, $facture, $tenantId, $auteur) {
            $fournisseur = Fournisseur::query()->findOrFail($donnees['fournisseur_id']);
            $numeroFournisseur = filled($donnees['numero_fournisseur'] ?? null) ? trim($donnees['numero_fournisseur']) : null;
            if ($numeroFournisseur) {
                $doublon = FactureFournisseur::query()->where('fournisseur_id', $fournisseur->id)->where('numero_fournisseur', $numeroFournisseur)
                    ->where('statut', FactureFournisseur::ENREGISTREE)->when($facture, fn ($q) => $q->whereKeyNot($facture->id))->first();
                if ($doublon) {
                    throw ValidationException::withMessages(['numero_fournisseur' => "La facture {$numeroFournisseur} de ce fournisseur est déjà enregistrée ({$doublon->numero})."]);
                }
            }
            $devise = $this->devise($donnees['devise'] ?? null);
            $taux = $devise ? (float) ($donnees['taux_change'] ?? 0) : 1.0;
            if ($devise && $taux <= 0) {
                throw ValidationException::withMessages(['taux_change' => "Indiquez le taux de change {$devise} → {$this->reference()}."]);
            }

            // Réceptions facturées : du fournisseur, enregistrées, pas déjà facturées ailleurs ; leurs lignes seules.
            $receptions = $this->receptionsAFacturer(array_map('intval', $donnees['receptions'] ?? []), $facture, $fournisseur->id);
            $lignesRecues = $receptions->flatMap(fn (Reception $r) => $r->lignes->where('annulee', false)->pluck('id'))->all();
            foreach (array_values($donnees['lignes']) as $index => $ligne) {
                if (! empty($ligne['reception_ligne_id']) && ! in_array((int) $ligne['reception_ligne_id'], $lignesRecues, true)) {
                    throw ValidationException::withMessages(["lignes.{$index}.reception_ligne_id" => "Cette ligne de réception n'appartient pas aux réceptions facturées."]);
                }
            }

            $calcul = $this->calculer($donnees['lignes']);
            $condition = ! empty($donnees['condition_paiement_id'])
                ? ConditionPaiement::query()->where('actif', true)->find($donnees['condition_paiement_id'])
                : ConditionPaiement::query()->where('actif', true)->where('par_defaut', true)->first();
            $echeance = ($donnees['date_echeance'] ?? null)
                ?: ($condition ? $this->conditions->echeance($condition, $donnees['date_facture'])->toDateString() : $donnees['date_facture']);
            $champs = [
                'fournisseur_id' => $fournisseur->id,
                'numero_fournisseur' => $numeroFournisseur,
                'date_facture' => $donnees['date_facture'],
                'date_echeance' => $echeance,
                'condition_paiement_id' => $condition?->id,
                'devise' => $devise,
                'taux_change' => $taux,
                'montant_ht' => $calcul['montant_ht'],
                'montant_tva' => $calcul['montant_tva'],
                'montant_ttc' => $calcul['montant_ttc'],
                'notes' => $donnees['notes'] ?? null,
            ];

            if ($facture) {
                $facture->update($champs);
                $anciennes = $facture->lignes()->pluck('id');
                $this->taxes->supprimer('facture_fournisseur', $anciennes);
                FactureFournisseurLigne::query()->whereIn('id', $anciennes)->delete();
            } else {
                $facture = FactureFournisseur::create($champs + [
                    'tenant_id' => $tenantId, 'numero' => $this->numero($tenantId), 'statut' => FactureFournisseur::ENREGISTREE, 'cree_par' => $auteur?->id,
                ]);
            }
            foreach ($calcul['lignes'] as $ligne) {
                $creee = FactureFournisseurLigne::create(['facture_fournisseur_id' => $facture->id] + $ligne['champs']);
                $this->taxes->enregistrer('facture_fournisseur', $creee->id, $ligne['detail']);
            }

            Reception::query()->where('facture_fournisseur_id', $facture->id)->whereNotIn('id', $receptions->pluck('id'))->update(['facture_fournisseur_id' => null]);
            Reception::query()->whereIn('id', $receptions->pluck('id'))->update(['facture_fournisseur_id' => $facture->id]);

            return $facture->fresh($this->relations());
        });
    }

    /**
     * Rapprochement : pour chaque ligne qui facture une ligne de réception, la valeur reçue (quantité × coût réel, sinon
     * prix commandé) et l'écart avec le montant facturé, dans la devise de la facture.
     *
     * @return array<int, array{valeur_recue: float, ecart: float, cout_recu: float}>
     */
    public function rapprochement(FactureFournisseur $facture): array
    {
        $resultat = [];
        foreach ($facture->lignes as $ligne) {
            $recue = $ligne->ligneReception;
            if (! $recue || $recue->annulee) {
                continue;
            }
            $cout = (float) ($recue->cout_unitaire ?? $recue->ligneCommande?->prix_unitaire ?? 0);
            $valeur = round($recue->quantite_recue * $cout, 2);
            $resultat[$ligne->id] = ['valeur_recue' => $valeur, 'ecart' => round((float) $ligne->montant_ht - $valeur, 2), 'cout_recu' => $cout];
        }

        return $resultat;
    }

    /**
     * Aligne le coût réel des réceptions sur le prix facturé, pour les lignes rapprochées dont le prix diffère (comme
     * l'ajustement des coûtants de fripv2) : la valorisation de l'inventaire et les coûts annexes répartis à la valeur
     * suivent. Seules les lignes dans la devise de leur commande sont alignées.
     */
    public function alignerCouts(FactureFournisseur $facture): int
    {
        if ($facture->statut === FactureFournisseur::ANNULEE) {
            throw new DomainException('Une facture annulée ne se rapproche plus.');
        }

        return DB::transaction(function () use ($facture) {
            $alignees = 0;
            $receptions = [];
            $lignes = $facture->lignes()->whereNotNull('reception_ligne_id')->with('ligneReception.ligneCommande.commande')->get();
            foreach ($lignes as $ligne) {
                $recue = $ligne->ligneReception;
                if (! $recue || $recue->annulee || $this->devise($recue->ligneCommande?->commande?->devise) !== ($facture->devise ?: null)) {
                    continue;
                }
                $actuel = (float) ($recue->cout_unitaire ?? $recue->ligneCommande?->prix_unitaire ?? 0);
                if (abs($actuel - (float) $ligne->prix_unitaire) < 0.00005) {
                    continue;
                }
                $recue->update(['cout_unitaire' => round((float) $ligne->prix_unitaire, 4)]);
                $receptions[$recue->reception_id] = true;
                $alignees++;
            }
            foreach (array_keys($receptions) as $id) {
                $this->receptions->repartirFrais(Reception::query()->findOrFail($id));
            }

            return $alignees;
        });
    }

    /** Paiement versé au fournisseur : jusqu'au reste à payer. */
    public function payer(FactureFournisseur $facture, array $donnees, ?AdminUser $auteur): FactureFournisseur
    {
        return DB::transaction(function () use ($facture, $donnees, $auteur) {
            $facture = FactureFournisseur::query()->lockForUpdate()->findOrFail($facture->id);
            if ($facture->statut === FactureFournisseur::ANNULEE) {
                throw new DomainException('Une facture annulée ne se paie pas.');
            }
            $reste = $facture->reste_a_payer;
            if ($reste <= 0) {
                throw new DomainException('Cette facture est déjà payée.');
            }
            $montant = round((float) $donnees['montant'], 2);
            if ($montant > $reste + 0.005) {
                throw ValidationException::withMessages(['montant' => 'Le paiement dépasse le reste à payer ('.number_format($reste, 2, ',', ' ').').']);
            }
            FactureFournisseurPaiement::create([
                'tenant_id' => $facture->tenant_id,
                'facture_fournisseur_id' => $facture->id,
                'date_paiement' => $donnees['date_paiement'],
                'montant' => $montant,
                'mode' => $donnees['mode'],
                'reference' => $donnees['reference'] ?? null,
                'notes' => $donnees['notes'] ?? null,
                'enregistre_par' => $auteur?->id,
            ]);
            $facture->update(['montant_paye' => round((float) $facture->montant_paye + $montant, 2)]);

            return $facture->fresh($this->relations());
        });
    }

    /** Paiement saisi par erreur : retiré, le reste à payer revient. */
    public function annulerPaiement(FactureFournisseur $facture, int $paiementId): FactureFournisseur
    {
        return DB::transaction(function () use ($facture, $paiementId) {
            $facture = FactureFournisseur::query()->lockForUpdate()->findOrFail($facture->id);
            $paiement = $facture->paiements()->whereKey($paiementId)->firstOrFail();
            $paiement->delete();
            $facture->update(['montant_paye' => max(0, round((float) $facture->montant_paye - (float) $paiement->montant, 2))]);

            return $facture->fresh($this->relations());
        });
    }

    /** Facture saisie par erreur : annulée si elle n'a reçu aucun paiement ; ses réceptions redeviennent à facturer. */
    public function annuler(FactureFournisseur $facture, ?string $motif, ?AdminUser $auteur): FactureFournisseur
    {
        return DB::transaction(function () use ($facture, $motif, $auteur) {
            $facture = FactureFournisseur::query()->lockForUpdate()->findOrFail($facture->id);
            if ($facture->statut === FactureFournisseur::ANNULEE) {
                throw new DomainException('Cette facture est déjà annulée.');
            }
            if ((float) $facture->montant_paye > 0) {
                throw new DomainException("Cette facture a reçu des paiements : annulez-les d'abord.");
            }
            $facture->update([
                'statut' => FactureFournisseur::ANNULEE, 'annulee_le' => now(), 'annulee_par' => $auteur?->id,
                'motif_annulation' => filled($motif) ? $motif : null,
            ]);
            Reception::query()->where('facture_fournisseur_id', $facture->id)->update(['facture_fournisseur_id' => null]);

            return $facture->fresh($this->relations());
        });
    }

    /**
     * Totaux d'une liste, dans la devise de l'entreprise (reste × taux de la facture) : à payer, dont en retard.
     *
     * @return array{a_payer: float, en_retard: float, nombre_a_payer: int, nombre_en_retard: int}
     */
    public function resume(Builder $factures): array
    {
        $reste = fn (Builder $q) => (float) (clone $q)->selectRaw('COALESCE(SUM((montant_ttc - montant_paye) * taux_change), 0) AS total')->value('total');
        $aPayer = (clone $factures)->etat('a_payer');
        $enRetard = (clone $factures)->etat('en_retard');

        return [
            'a_payer' => round($reste($aPayer), 2),
            'en_retard' => round($reste($enRetard), 2),
            'nombre_a_payer' => (clone $aPayer)->count(),
            'nombre_en_retard' => (clone $enRetard)->count(),
        ];
    }

    /** Relations d'une facture affichée. */
    public function relations(): array
    {
        return [
            'fournisseur:id,code,raison_sociale,devise', 'condition:id,code,libelle', 'auteur:id,nom', 'auteurAnnulation:id,nom',
            'lignes.produit:id,numero,description', 'lignes.ligneReception.ligneCommande.commande:id,numero,devise',
            'paiements.auteur:id,nom', 'receptions:id,numero,bon_livraison,date_reception,facture_fournisseur_id',
        ];
    }

    /**
     * Montants des lignes : HT, taxes (groupe de taxes, sinon taux de la ligne, sinon taux de l'entreprise), TTC.
     *
     * @return array{lignes: list<array{champs: array<string, mixed>, detail: ?array}>, montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    private function calculer(array $lignes): array
    {
        $tauxDefaut = TauxTaxe::parDefaut();
        $resultat = [];
        $totaux = ['montant_ht' => 0.0, 'montant_tva' => 0.0, 'montant_ttc' => 0.0];
        foreach (array_values($lignes) as $ligne) {
            $ht = round((float) $ligne['quantite'] * (float) $ligne['prix_unitaire'], 2);
            $groupe = $this->taxes->groupePour($ligne);
            $detail = $groupe ? $this->taxes->calculer($groupe, $ht) : null;
            $taux = $detail ? (float) $detail['taux_effectif'] : (float) ($ligne['taux_tva'] ?? $tauxDefaut);
            $tva = $detail ? round((float) $detail['total_taxes'], 2) : round($ht * $taux / 100, 2);
            $resultat[] = [
                'champs' => [
                    'produit_id' => $ligne['produit_id'] ?? null,
                    'description' => $ligne['description'],
                    'quantite' => (float) $ligne['quantite'],
                    'prix_unitaire' => round((float) $ligne['prix_unitaire'], 4),
                    'montant_ht' => $ht,
                    'groupe_taxe_id' => $groupe?->id,
                    'taux_tva' => $taux,
                    'montant_tva' => $tva,
                    'montant_ttc' => round($ht + $tva, 2),
                    'reception_ligne_id' => $ligne['reception_ligne_id'] ?? null,
                ],
                'detail' => $detail,
            ];
            $totaux['montant_ht'] += $ht;
            $totaux['montant_tva'] += $tva;
            $totaux['montant_ttc'] += $ht + $tva;
        }

        return ['lignes' => $resultat] + array_map(fn ($v) => round($v, 2), $totaux);
    }

    /**
     * Réceptions à facturer : enregistrées, d'un seul fournisseur (celui de la facture s'il est donné), pas déjà
     * facturées par une autre facture.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Reception>
     */
    private function receptionsAFacturer(array $ids, ?FactureFournisseur $facture, ?int $fournisseurId = null): Collection
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return collect();
        }
        $receptions = Reception::query()->whereIn('id', $ids)->lockForUpdate()
            ->with(['lignes.ligneCommande.commande', 'lignes.ligneCommande.produit' => fn ($q) => $q->withTrashed()])->get();
        if ($receptions->count() !== count($ids)) {
            throw ValidationException::withMessages(['receptions' => 'Réception introuvable.']);
        }
        foreach ($receptions as $reception) {
            if ($reception->statut !== Reception::ENREGISTREE) {
                throw ValidationException::withMessages(['receptions' => "La réception {$reception->numero} est annulée."]);
            }
            if ($reception->facture_fournisseur_id && (int) $reception->facture_fournisseur_id !== (int) $facture?->id) {
                $numero = FactureFournisseur::query()->whereKey($reception->facture_fournisseur_id)->value('numero');
                throw ValidationException::withMessages(['receptions' => "La réception {$reception->numero} est déjà facturée ({$numero})."]);
            }
        }
        $fournisseurs = $receptions->pluck('fournisseur_id')->map(fn ($id) => (int) $id)->unique();
        if ($fournisseurs->count() > 1 || ($fournisseurId && $fournisseurs->first() !== $fournisseurId)) {
            throw ValidationException::withMessages(['receptions' => 'Les réceptions facturées sont celles du fournisseur de la facture.']);
        }

        return $receptions;
    }

    private function numero(int $tenantId): string
    {
        $config = Configuration::pourEntite('facture_fournisseur', $tenantId);
        if (! $config?->auto_increment) {
            throw new DomainException('Numéro requis : activez la numérotation automatique des factures fournisseurs (Configuration → Numérotation).');
        }

        return Configuration::consommerNumero('facture_fournisseur', $tenantId);
    }

    /** Devise saisie : vide pour celle de l'entreprise. */
    private function devise(?string $devise): ?string
    {
        $devise = strtoupper(trim((string) $devise));

        return $devise === '' || $devise === $this->reference() ? null : $devise;
    }

    private function reference(): string
    {
        return strtoupper(trim((string) TenantParametres::query()->value('devise_code')));
    }
}
