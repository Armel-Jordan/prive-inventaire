<?php

namespace App\Services\Comptabilite;

use App\Models\Acompte;
use App\Models\AcompteRemboursement;
use App\Models\Avoir;
use App\Models\AvoirRemboursement;
use App\Models\CompteBancaire;
use App\Models\CompteComptable;
use App\Models\Debourse;
use App\Models\DocumentTaxe;
use App\Models\EcritureComptable;
use App\Models\Encaissement;
use App\Models\Facture;
use App\Models\FactureFournisseur;
use App\Models\FactureFournisseurPaiement;
use App\Models\FacturePaiement;
use App\Models\JournalComptable;
use App\Models\ParametreComptable;
use App\Models\TransactionBancaire;
use App\Support\TenantContext;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Comptabilisation automatique (plan de parité fripv2, phase 4.3) : les ventes, les achats et les paiements passent au
 * journal, une écriture par document, selon les réglages de l'entreprise :
 *
 *  - facture de vente (émise) : client au débit (TTC), ventes (HT, par catégorie de produit) et taxes collectées (par
 *    taxe) au crédit ; note de crédit émise : l'inverse ;
 *  - paiement d'un client : caisse ou banque selon le mode (acompte appliqué : acomptes clients ; carte cadeau : cartes
 *    cadeaux), escompte accordé au débit, client au crédit ; acompte reçu, remboursements d'acompte et de note de crédit ;
 *  - encaissement réparti sur plusieurs factures (phase 4.4) : une écriture, l'argent reçu en caisse ou en banque, chaque
 *    facture réglée au crédit du client, le surplus au crédit des acomptes clients ;
 *  - facture fournisseur : achats (HT, par catégorie) et taxes déductibles au débit, fournisseur au crédit ; paiement
 *    d'un fournisseur ; déboursé réparti sur plusieurs factures (phase 4.5) : une écriture, chaque facture réglée au
 *    débit du fournisseur, l'argent versé et l'escompte obtenu au crédit ;
 *  - transaction bancaire sans facture (phase 4.6) : dépôt, retrait réparti sur ses comptes de contrepartie, virement
 *    entre deux comptes bancaires ;
 *  - un encaissement, un déboursé ou une transaction passe par le compte comptable et le journal de son compte bancaire
 *    (Finance → Banque) ; sans compte bancaire relié, par le compte « Banque » et le journal de banque des réglages ;
 *  - montants dans la devise de l'entreprise, au taux du document ; le compte du tiers prend la somme des contreparties
 *    arrondies (pas d'écart d'arrondi) ;
 *  - un document se comptabilise une fois (source et source_id de l'écriture) ; annulé ou supprimé ensuite (facture
 *    fournisseur, paiement fournisseur, acompte, encaissement, déboursé, transaction bancaire), son écriture est
 *    contre-passée ;
 *  - seuls les documents datés à partir de la date de début réglée se comptabilisent ; un document refusé (période
 *    fermée, compte non réglé) reste à comptabiliser et son motif est rapporté.
 *
 * Rien n'est écrit pendant les ventes ou les achats : la comptabilisation se lance depuis l'écran ou par la tâche
 * planifiée « comptabilisation ».
 */
class Comptabilisation
{
    public const TYPES = [
        'facture' => 'Factures de vente',
        'avoir' => 'Notes de crédit',
        'paiement_client' => 'Paiements des clients',
        'encaissement' => 'Encaissements des clients',
        'acompte' => 'Acomptes reçus',
        'remboursement_acompte' => "Remboursements d'acomptes",
        'remboursement_avoir' => 'Remboursements de notes de crédit',
        'facture_fournisseur' => 'Factures fournisseurs',
        'paiement_fournisseur' => 'Paiements aux fournisseurs',
        'debourse' => 'Déboursés aux fournisseurs',
        'transaction_bancaire' => 'Transactions bancaires',
    ];

    public const MODES = ['especes', 'cheque', 'virement', 'carte', 'autre'];

    /** Réglages indispensables (les autres comptes ne sont exigés que par les documents qui les emploient). */
    private const ESSENTIELS = [
        'journal_ventes_id' => 'Journal des ventes', 'journal_achats_id' => 'Journal des achats', 'journal_banque_id' => 'Journal de banque',
        'journal_caisse_id' => 'Journal de caisse', 'journal_od_id' => 'Journal des opérations diverses',
        'compte_clients_id' => 'Clients', 'compte_fournisseurs_id' => 'Fournisseurs', 'compte_ventes_id' => 'Ventes',
        'compte_achats_id' => 'Achats', 'compte_tva_collectee_id' => 'Taxes collectées', 'compte_tva_deductible_id' => 'Taxes déductibles',
        'compte_caisse_id' => 'Caisse', 'compte_banque_id' => 'Banque',
    ];

    private const LIBELLES = self::ESSENTIELS + [
        'compte_acomptes_id' => 'Acomptes clients', 'compte_escomptes_id' => 'Escomptes accordés', 'compte_cartes_cadeaux_id' => 'Cartes cadeaux',
        'compte_escomptes_obtenus_id' => 'Escomptes obtenus',
    ];

    public function __construct(private readonly Ecritures $ecritures) {}

    /** Réglages de l'entreprise, créés à la première lecture. */
    public function parametres(): ParametreComptable
    {
        return ParametreComptable::query()->firstOrCreate(['tenant_id' => (int) app(TenantContext::class)->getTenantId()]);
    }

    /**
     * Réglages proposés : journaux par défaut, comptes trouvés dans le plan (par leur type, puis par les numéros usuels
     * des plans OHADA, français et canadien).
     *
     * @return array<string, int|null>
     */
    public function propositions(): array
    {
        JournalComptable::assurerParDefaut();
        $journaux = JournalComptable::query()->where('actif', true)->pluck('id', 'code');
        $comptes = CompteComptable::query()->where('actif', true)->where('type', '!=', CompteComptable::EN_TETE)->get(['id', 'numero', 'type']);
        $parType = fn (string $type) => $comptes->firstWhere('type', $type)?->id;
        $parNumeros = function (array $numeros) use ($comptes): ?int {
            foreach ($numeros as $numero) {
                if ($compte = $comptes->firstWhere('numero', $numero)) {
                    return $compte->id;
                }
            }

            return null;
        };

        return [
            'journal_ventes_id' => $journaux['VE'] ?? null,
            'journal_achats_id' => $journaux['AC'] ?? null,
            'journal_banque_id' => $journaux['BQ'] ?? null,
            'journal_caisse_id' => $journaux['CA'] ?? null,
            'journal_od_id' => $journaux['OD'] ?? null,
            'compte_clients_id' => $parType('clients') ?? $parNumeros(['411', '11000']),
            'compte_fournisseurs_id' => $parType('fournisseurs') ?? $parNumeros(['401', '20100']),
            'compte_ventes_id' => $parNumeros(['701', '707', '40100']),
            'compte_achats_id' => $parNumeros(['601', '607', '50200']),
            'compte_tva_collectee_id' => $parNumeros(['443', '4457', '44571', '22000']),
            'compte_tva_deductible_id' => $parNumeros(['445', '4456', '44566', '22100']),
            'compte_caisse_id' => $parNumeros(['571', '530', '10100']),
            'compte_banque_id' => $parNumeros(['521', '512', '10200']),
            'compte_acomptes_id' => $parNumeros(['4191', '419']),
            'compte_escomptes_id' => $parNumeros(['673', '665', '709', '40900']),
            'compte_cartes_cadeaux_id' => $parNumeros(['4198', '4196']),
            'compte_escomptes_obtenus_id' => $parNumeros(['773', '765', '50300']),
        ];
    }

    /** Réglages indispensables encore vides, par libellé. */
    public function manquants(ParametreComptable $parametres): array
    {
        $manquants = array_keys(array_filter(self::ESSENTIELS, fn (string $libelle, string $champ) => ! $parametres->{$champ}, ARRAY_FILTER_USE_BOTH));
        if (! $parametres->date_debut) {
            $manquants[] = 'date_debut';
        }

        return $manquants;
    }

    /** Documents à comptabiliser de la période (et contre-passations à faire), par type, avec leur montant. */
    public function apercu(Carbon $du, Carbon $au): array
    {
        $parametres = $this->parametres();
        $debut = $parametres->date_debut ? Carbon::parse(max($du->toDateString(), $parametres->date_debut->toDateString())) : null;
        $types = [];
        foreach (self::TYPES as $type => $libelle) {
            $documents = $debut && $debut->lte($au) ? $this->aComptabiliser($type, $debut, $au)->get() : collect();
            $types[] = [
                'type' => $type,
                'libelle' => $libelle,
                'nombre' => $documents->count(),
                'montant' => round($documents->sum(fn (Model $d) => $this->montantDocument($type, $d)), 2),
            ];
        }

        return [
            'date_debut' => $parametres->date_debut?->toDateString(),
            'types' => $types,
            'contre_passations' => $this->aContrePasser()->count(),
            'manquants' => $this->manquants($parametres),
        ];
    }

    /**
     * Comptabilise les documents de la période (datés à partir de la date de début) puis contre-passe les écritures des
     * documents annulés ou supprimés. Chaque document se comptabilise seul : un refus n'arrête pas les autres.
     *
     * @return array{creees: int, par_type: array<string, int>, contre_passations: int, erreurs: list<array{type: string, document: string, message: string}>}
     */
    public function comptabiliser(Carbon $du, Carbon $au, ?int $auteur): array
    {
        $parametres = $this->parametres();
        if (! $parametres->date_debut) {
            throw new DomainException("Réglez d'abord la date à partir de laquelle les documents se comptabilisent.");
        }
        // Une comptabilisation à la fois par entreprise : deux lancements simultanés ne doublent aucune écriture.
        $verrou = Cache::lock('comptabilisation:'.$parametres->tenant_id, 300);
        if (! $verrou->get()) {
            throw new DomainException('Une comptabilisation est déjà en cours : réessayez dans un instant.');
        }

        try {
            $debut = Carbon::parse(max($du->toDateString(), $parametres->date_debut->toDateString()));
            $parType = [];
            $erreurs = [];
            foreach (array_keys(self::TYPES) as $type) {
                if ($debut->gt($au)) {
                    break;
                }
                foreach ($this->aComptabiliser($type, $debut, $au)->get() as $document) {
                    try {
                        DB::transaction(function () use ($type, $document, $parametres, $auteur) {
                            $ecriture = $this->ecritures->enregistrer($this->ecriture($type, $document, $parametres), $type, null, $auteur);
                            $ecriture->update(['source_id' => $document->getKey()]);
                        });
                        $parType[$type] = ($parType[$type] ?? 0) + 1;
                    } catch (ValidationException|DomainException $erreur) {
                        $erreurs[] = ['type' => $type, 'document' => $this->reference($type, $document), 'message' => $this->message($erreur)];
                    }
                }
            }

            $contrePassations = 0;
            foreach ($this->aContrePasser() as [$ecriture, $date]) {
                try {
                    $ecriture->loadMissing('lignes');
                    $inverse = $this->ecritures->enregistrer([
                        'journal_id' => $ecriture->journal_id,
                        'date' => $date,
                        'libelle' => mb_substr("Annulation : {$ecriture->libelle}", 0, 255),
                        'reference' => $ecriture->numero,
                        'lignes' => $ecriture->lignes->map(fn ($ligne) => [
                            'compte_id' => $ligne->compte_id, 'libelle' => $ligne->libelle, 'debit' => $ligne->credit, 'credit' => $ligne->debit,
                        ])->all(),
                    ], $ecriture->source, null, $auteur, $ecriture->id);
                    $inverse->update(['source_id' => $ecriture->source_id]);
                    $contrePassations++;
                } catch (ValidationException|DomainException $erreur) {
                    $erreurs[] = ['type' => $ecriture->source, 'document' => $ecriture->reference ?? $ecriture->numero, 'message' => $this->message($erreur)];
                }
            }

            return ['creees' => array_sum($parType), 'par_type' => $parType, 'contre_passations' => $contrePassations, 'erreurs' => $erreurs];
        } finally {
            $verrou->release();
        }
    }

    /**
     * Passe tout de suite au journal un document qui n'y est pas encore (une transaction bancaire créée depuis une
     * opération du relevé, par exemple), daté à partir de la date de début ; les mêmes règles que la comptabilisation.
     */
    public function comptabiliserDocument(string $type, int $id, ?int $auteur): EcritureComptable
    {
        $parametres = $this->parametres();
        if (! $parametres->date_debut) {
            throw new DomainException("Réglez d'abord la date à partir de laquelle les documents se comptabilisent.");
        }
        $document = $this->aComptabiliser($type, Carbon::parse($parametres->date_debut), Carbon::parse('2999-12-31'))->whereKey($id)->first();
        if (! $document) {
            throw new DomainException('Ce document est déjà au journal, ou antérieur à la date de début de la comptabilisation automatique.');
        }
        // La même exclusion que la comptabilisation : jamais deux écritures pour un document.
        $verrou = Cache::lock('comptabilisation:'.$parametres->tenant_id, 300);
        if (! $verrou->get()) {
            throw new DomainException('Une comptabilisation est en cours : réessayez dans un instant.');
        }
        try {
            return DB::transaction(function () use ($type, $document, $parametres, $auteur, $id) {
                $ecriture = $this->ecritures->enregistrer($this->ecriture($type, $document, $parametres), $type, null, $auteur);
                $ecriture->update(['source_id' => $id]);

                return $ecriture;
            });
        } finally {
            $verrou->release();
        }
    }

    /** Documents d'un type datés dans la période, sans écriture (hors contre-passations). */
    private function aComptabiliser(string $type, Carbon $du, Carbon $au): Builder
    {
        [$requete, $date] = match ($type) {
            // Une facture créditée en entier reste une vente : sa note de crédit l'annule au journal.
            'facture' => [Facture::query()->whereIn('statut', ['emise', 'partiellement_payee', 'payee', 'creditee'])->where('montant_ttc', '!=', 0)->with(['client', 'lignes.produit']), 'date_facture'],
            'avoir' => [Avoir::query()->whereIn('statut', Avoir::EMISES)->where('montant_ttc', '!=', 0)->with(['client', 'lignes.produit']), 'date_avoir'],
            'paiement_client' => [FacturePaiement::query()->whereHas('facture')->whereNull('encaissement_id')
                ->where(fn ($q) => $q->where('montant', '>', 0)->orWhere('escompte', '>', 0))->with('facture.client'), 'date_paiement'],
            'encaissement' => [Encaissement::query()->where('statut', Encaissement::VALIDE)->with(['client', 'lignes.facture', 'compteBancaire']), 'date_encaissement'],
            'acompte' => [Acompte::query()->where('montant', '>', 0)
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('encaissements')->whereColumn('encaissements.acompte_id', 'acomptes.id'))
                ->with('client'), 'date_acompte'],
            'remboursement_acompte' => [AcompteRemboursement::query()->whereHas('acompte')->where('montant', '>', 0)->with('acompte.client'), 'date_remboursement'],
            'remboursement_avoir' => [AvoirRemboursement::query()->whereHas('avoir')->where('montant', '>', 0)->with('avoir.client'), 'date_remboursement'],
            'facture_fournisseur' => [FactureFournisseur::query()->where('statut', FactureFournisseur::ENREGISTREE)->where('montant_ttc', '!=', 0)->with(['fournisseur', 'lignes.produit']), 'date_facture'],
            'paiement_fournisseur' => [FactureFournisseurPaiement::query()->where('montant', '>', 0)->whereNull('debourse_id')->whereHas('facture')->with('facture.fournisseur'), 'date_paiement'],
            'debourse' => [Debourse::query()->where('statut', Debourse::VALIDE)->with(['fournisseur', 'lignes.facture', 'compteBancaire']), 'date_debourse'],
            'transaction_bancaire' => [TransactionBancaire::query()->where('statut', TransactionBancaire::VALIDE)->with(['compteBancaire', 'destination', 'lignes']), 'date_transaction'],
        };
        $table = $requete->getModel()->getTable();

        return $requete->whereDate("{$table}.{$date}", '>=', $du)->whereDate("{$table}.{$date}", '<=', $au)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('ecritures_comptables')
                ->where('ecritures_comptables.tenant_id', (int) app(TenantContext::class)->getTenantId())
                ->where('ecritures_comptables.source', $type)
                ->whereColumn('ecritures_comptables.source_id', "{$table}.id")
                ->whereNull('ecritures_comptables.contrepassation_de'))
            ->orderBy("{$table}.{$date}")->orderBy("{$table}.id");
    }

    /**
     * Écritures dont le document a été annulé (facture fournisseur, encaissement, déboursé, transaction bancaire) ou
     * supprimé (paiement fournisseur, acompte), pas encore contre-passées, avec la date de leur contre-passation (celle
     * de l'annulation, sinon aujourd'hui).
     *
     * @return Collection<int, array{0: EcritureComptable, 1: string}>
     */
    private function aContrePasser(): Collection
    {
        $ecritures = EcritureComptable::query()->whereIn('source', ['facture_fournisseur', 'paiement_fournisseur', 'acompte', 'encaissement', 'debourse', 'transaction_bancaire'])
            ->whereNull('contrepassation_de')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('ecritures_comptables as inverses')
                ->whereColumn('inverses.contrepassation_de', 'ecritures_comptables.id'))
            ->orderBy('id')->get();
        $existants = [
            'facture_fournisseur' => FactureFournisseur::query()->whereIn('id', $ecritures->where('source', 'facture_fournisseur')->pluck('source_id'))->get()->keyBy('id'),
            'paiement_fournisseur' => FactureFournisseurPaiement::query()->whereIn('id', $ecritures->where('source', 'paiement_fournisseur')->pluck('source_id'))->pluck('id', 'id'),
            'acompte' => Acompte::query()->whereIn('id', $ecritures->where('source', 'acompte')->pluck('source_id'))->pluck('id', 'id'),
            'encaissement' => Encaissement::query()->whereIn('id', $ecritures->where('source', 'encaissement')->pluck('source_id'))->get()->keyBy('id'),
            'debourse' => Debourse::query()->whereIn('id', $ecritures->where('source', 'debourse')->pluck('source_id'))->get()->keyBy('id'),
            'transaction_bancaire' => TransactionBancaire::query()->whereIn('id', $ecritures->where('source', 'transaction_bancaire')->pluck('source_id'))->get()->keyBy('id'),
        ];
        $aujourdhui = today()->toDateString();

        return $ecritures->map(function (EcritureComptable $ecriture) use ($existants, $aujourdhui) {
            $document = $existants[$ecriture->source]->get($ecriture->source_id);
            if ($ecriture->source === 'facture_fournisseur' && $document) {
                return $document->statut === FactureFournisseur::ANNULEE ? [$ecriture, $document->annulee_le?->toDateString() ?? $aujourdhui] : null;
            }
            if ($ecriture->source === 'encaissement' && $document) {
                return $document->statut === Encaissement::ANNULE ? [$ecriture, $document->annule_le?->toDateString() ?? $aujourdhui] : null;
            }
            if ($ecriture->source === 'debourse' && $document) {
                return $document->statut === Debourse::ANNULE ? [$ecriture, $document->annule_le?->toDateString() ?? $aujourdhui] : null;
            }
            if ($ecriture->source === 'transaction_bancaire' && $document) {
                return $document->statut === TransactionBancaire::ANNULE ? [$ecriture, $document->annule_le?->toDateString() ?? $aujourdhui] : null;
            }

            return $document ? null : [$ecriture, $aujourdhui];
        })->filter()->values();
    }

    /** Écriture d'un document : journal, date, libellé, référence, lignes. */
    private function ecriture(string $type, Model $document, ParametreComptable $p): array
    {
        return match ($type) {
            'facture' => $this->ecritureVente($document, $p, false),
            'avoir' => $this->ecritureVente($document, $p, true),
            'paiement_client' => $this->ecriturePaiementClient($document, $p),
            'encaissement' => $this->ecritureEncaissement($document, $p),
            'acompte' => $this->ecritureTresorerie($p, $document->mode, $document->date_acompte, $this->montant($document->montant, $document->taux_change),
                $this->compte($p, 'compte_acomptes_id'), true, 'Acompte de '.$this->tiers($document->client), $document->reference),
            'remboursement_acompte' => $this->ecritureTresorerie($p, $document->mode, $document->date_remboursement,
                $this->montant($document->montant, $document->acompte?->taux_change), $this->compte($p, 'compte_acomptes_id'), false,
                "Remboursement d'acompte à ".$this->tiers($document->acompte?->client), $document->reference),
            'remboursement_avoir' => $this->ecritureTresorerie($p, $document->mode, $document->date_remboursement,
                $this->montant($document->montant, $document->avoir?->taux_change), $this->compte($p, 'compte_clients_id'), false,
                "Remboursement de {$document->avoir?->numero} à ".$this->tiers($document->avoir?->client), $document->avoir?->numero),
            'facture_fournisseur' => $this->ecritureAchat($document, $p),
            'paiement_fournisseur' => $this->ecritureTresorerie($p, $document->mode, $document->date_paiement,
                $this->montant($document->montant, $document->facture?->taux_change), $this->compte($p, 'compte_fournisseurs_id'), false,
                "Paiement de {$document->facture?->numero} à ".($document->facture?->fournisseur?->raison_sociale ?? ''), $document->facture?->numero),
            'debourse' => $this->ecritureDebourse($document, $p),
            'transaction_bancaire' => $this->ecritureTransactionBancaire($document, $p),
        };
    }

    /**
     * Facture de vente (ou note de crédit, à l'inverse) : ventes HT par catégorie de produit et taxes collectées par
     * taxe ; le client prend leur somme.
     */
    private function ecritureVente(Facture|Avoir $document, ParametreComptable $p, bool $avoir): array
    {
        $taux = (float) ($document->taux_change ?: 1);
        $taxes = DocumentTaxe::query()->where('document', $avoir ? 'avoir' : 'facture')->whereIn('ligne_id', $document->lignes->pluck('id'))->get()->groupBy('ligne_id');
        $produits = [];
        $tva = [];
        foreach ($document->lignes as $ligne) {
            $compte = $this->compteCategorie($p, $ligne->produit?->categorie, 'vente') ?? $this->compte($p, 'compte_ventes_id');
            $produits[$compte] = ($produits[$compte] ?? 0) + (float) $ligne->montant_ht * $taux;
            $this->ajouterTaxes($tva, $taxes->get($ligne->id), (float) $ligne->montant_tva, $taux, $p, 'collectee');
        }
        // Facture : contreparties au crédit ; note de crédit : au débit.
        $lignes = $this->contreparties([$produits, $tva], ! $avoir);
        $lignes[] = $this->ligne($this->compte($p, 'compte_clients_id'), $this->solde($lignes), $avoir, null);

        return [
            'journal_id' => $this->journal($p, 'journal_ventes_id'),
            'date' => $avoir ? $document->date_avoir->toDateString() : $document->date_facture->toDateString(),
            'libelle' => mb_substr(($avoir ? 'Note de crédit ' : 'Facture ').$document->numero.' — '.$this->tiers($document->client, $document), 0, 255),
            'reference' => $document->numero,
            'lignes' => $lignes,
        ];
    }

    /** Facture fournisseur : achats HT par catégorie de produit et taxes déductibles par taxe ; le fournisseur prend leur somme. */
    private function ecritureAchat(FactureFournisseur $facture, ParametreComptable $p): array
    {
        $taux = (float) ($facture->taux_change ?: 1);
        $taxes = DocumentTaxe::query()->where('document', 'facture_fournisseur')->whereIn('ligne_id', $facture->lignes->pluck('id'))->get()->groupBy('ligne_id');
        $achats = [];
        $tva = [];
        foreach ($facture->lignes as $ligne) {
            $compte = $this->compteCategorie($p, $ligne->produit?->categorie, 'achat') ?? $this->compte($p, 'compte_achats_id');
            $achats[$compte] = ($achats[$compte] ?? 0) + (float) $ligne->montant_ht * $taux;
            $this->ajouterTaxes($tva, $taxes->get($ligne->id), (float) $ligne->montant_tva, $taux, $p, 'deductible');
        }
        $lignes = $this->contreparties([$achats, $tva], false);
        $lignes[] = $this->ligne($this->compte($p, 'compte_fournisseurs_id'), $this->solde($lignes), true, null);

        return [
            'journal_id' => $this->journal($p, 'journal_achats_id'),
            'date' => $facture->date_facture->toDateString(),
            'libelle' => mb_substr("Facture fournisseur {$facture->numero}".($facture->numero_fournisseur ? " ({$facture->numero_fournisseur})" : '')
                .' — '.($facture->fournisseur?->raison_sociale ?? ''), 0, 255),
            'reference' => $facture->numero_fournisseur ?: $facture->numero,
            'lignes' => $lignes,
        ];
    }

    /**
     * Paiement d'un client : caisse ou banque selon le mode (acompte appliqué : acomptes clients ; carte cadeau : cartes
     * cadeaux, au journal des opérations diverses), escompte accordé ; le client est crédité du tout.
     */
    private function ecriturePaiementClient(FacturePaiement $paiement, ParametreComptable $p): array
    {
        $facture = $paiement->facture;
        $montant = $this->montant($paiement->montant, $facture->taux_change);
        $escompte = $this->montant($paiement->escompte, $facture->taux_change);
        [$compte, $journal] = match (true) {
            (bool) $paiement->acompte_id => [$this->compte($p, 'compte_acomptes_id'), $this->journal($p, 'journal_od_id')],
            (bool) $paiement->carte_cadeau_id => [$this->compte($p, 'compte_cartes_cadeaux_id'), $this->journal($p, 'journal_od_id')],
            default => $this->tresorerie($p, $paiement->mode_paiement),
        };
        $lignes = array_values(array_filter([
            $montant > 0 ? $this->ligne($compte, $montant, false, null) : null,
            $escompte > 0 ? $this->ligne($this->compte($p, 'compte_escomptes_id'), $escompte, false, 'Escompte accordé') : null,
        ]));
        $lignes[] = $this->ligne($this->compte($p, 'compte_clients_id'), round($montant + $escompte, 2), true, null);

        return [
            'journal_id' => $journal,
            'date' => $paiement->date_paiement->toDateString(),
            'libelle' => mb_substr("Paiement de {$facture->numero} — ".$this->tiers($facture->client, $facture), 0, 255),
            'reference' => $paiement->reference ?: $facture->numero,
            'lignes' => $lignes,
        ];
    }

    /**
     * Encaissement d'un client, en une écriture : l'argent reçu en caisse ou en banque selon le mode et l'escompte
     * accordé au débit ; chaque facture réglée au crédit du client, le surplus au crédit des acomptes clients. Les parts
     * des factures sont au taux de leur facture, le surplus au taux de l'encaissement.
     */
    private function ecritureEncaissement(Encaissement $encaissement, ParametreComptable $p): array
    {
        [$tresorerie, $journal] = $this->tresorerie($p, $encaissement->mode, $encaissement->compteBancaire);
        $argent = 0.0;
        $escompte = 0.0;
        $credits = [];
        foreach ($encaissement->lignes as $ligne) {
            $taux = $ligne->facture?->taux_change;
            $paye = $this->montant((float) $ligne->montant - (float) $ligne->escompte, $taux);
            $part = $this->montant($ligne->escompte, $taux);
            $argent += $paye;
            $escompte += $part;
            $credits[] = $this->ligne($this->compte($p, 'compte_clients_id'), round($paye + $part, 2), true, $ligne->facture?->numero);
        }
        $surplus = $this->montant($encaissement->surplus, $encaissement->taux_change);
        if ($surplus > 0) {
            $argent += $surplus;
            $credits[] = $this->ligne($this->compte($p, 'compte_acomptes_id'), $surplus, true, 'Surplus laissé en acompte');
        }
        $debits = array_values(array_filter([
            round($argent, 2) > 0 ? $this->ligne($tresorerie, round($argent, 2), false, null) : null,
            round($escompte, 2) > 0 ? $this->ligne($this->compte($p, 'compte_escomptes_id'), round($escompte, 2), false, 'Escompte accordé') : null,
        ]));

        return [
            'journal_id' => $journal,
            'date' => $encaissement->date_encaissement->toDateString(),
            'libelle' => mb_substr("Encaissement {$encaissement->numero} — ".$this->tiers($encaissement->client), 0, 255),
            'reference' => $encaissement->reference ?: $encaissement->numero,
            'lignes' => [...$debits, ...$credits],
        ];
    }

    /**
     * Déboursé à un fournisseur, en une écriture : chaque facture réglée (argent et escompte) au débit du fournisseur ;
     * l'argent versé en caisse ou en banque selon le mode et l'escompte obtenu au crédit. Les parts sont au taux de leur
     * facture.
     */
    private function ecritureDebourse(Debourse $debourse, ParametreComptable $p): array
    {
        [$tresorerie, $journal] = $this->tresorerie($p, $debourse->mode, $debourse->compteBancaire);
        $argent = 0.0;
        $escompte = 0.0;
        $debits = [];
        foreach ($debourse->lignes as $ligne) {
            $taux = $ligne->facture?->taux_change;
            $verse = $this->montant((float) $ligne->montant - (float) $ligne->escompte, $taux);
            $obtenu = $this->montant($ligne->escompte, $taux);
            $argent += $verse;
            $escompte += $obtenu;
            $debits[] = $this->ligne($this->compte($p, 'compte_fournisseurs_id'), round($verse + $obtenu, 2), false,
                $ligne->facture?->numero_fournisseur ?: $ligne->facture?->numero);
        }
        $credits = array_values(array_filter([
            round($argent, 2) > 0 ? $this->ligne($tresorerie, round($argent, 2), true, null) : null,
            round($escompte, 2) > 0 ? $this->ligne($this->compte($p, 'compte_escomptes_obtenus_id'), round($escompte, 2), true, 'Escompte obtenu') : null,
        ]));

        return [
            'journal_id' => $journal,
            'date' => $debourse->date_debourse->toDateString(),
            'libelle' => mb_substr("Déboursé {$debourse->numero} — ".($debourse->fournisseur?->raison_sociale ?? ''), 0, 255),
            'reference' => $debourse->reference ?: $debourse->numero,
            'lignes' => [...$debits, ...$credits],
        ];
    }

    /**
     * Transaction bancaire : un dépôt débite la banque et crédite ses contreparties ; un retrait l'inverse ; un virement
     * débite le compte bancaire qui reçoit et crédite celui qui envoie. Montants au taux de la transaction.
     */
    private function ecritureTransactionBancaire(TransactionBancaire $transaction, ParametreComptable $p): array
    {
        [$banque, $journal] = $this->banque($p, $transaction->compteBancaire);
        $taux = (float) $transaction->taux_change;
        if ($transaction->type === TransactionBancaire::VIREMENT) {
            if (! $transaction->compteBancaire?->compte_comptable_id || ! $transaction->destination?->compte_comptable_id) {
                throw new DomainException('Reliez les deux comptes bancaires du virement à leur compte comptable (Finance → Banque).');
            }
            $montant = $this->montant($transaction->montant, $taux);
            $lignes = [
                $this->ligne($transaction->destination->compte_comptable_id, $montant, false, 'Virement reçu'),
                $this->ligne($banque, $montant, true, null),
            ];
        } else {
            $depot = $transaction->type === TransactionBancaire::DEPOT;
            // Contreparties au crédit d'un dépôt, au débit d'un retrait ; la banque prend leur somme.
            $contreparties = $transaction->lignes->map(fn ($l) => $this->ligne($l->compte_comptable_id, $this->montant($l->montant, $taux), $depot, $l->libelle))->all();
            $total = round(array_sum(array_map(fn (array $l) => $depot ? $l['credit'] - $l['debit'] : $l['debit'] - $l['credit'], $contreparties)), 2);
            $mouvement = $this->ligne($banque, $total, ! $depot, null);
            // Le débit d'abord : la banque pour un dépôt, les contreparties pour un retrait.
            $lignes = $depot ? [$mouvement, ...$contreparties] : [...$contreparties, $mouvement];
        }

        return [
            'journal_id' => $journal,
            'date' => $transaction->date_transaction->toDateString(),
            'libelle' => mb_substr("Transaction {$transaction->numero} — {$transaction->libelle}", 0, 255),
            'reference' => $transaction->reference ?: $transaction->numero,
            'lignes' => $lignes,
        ];
    }

    /**
     * Mouvement de trésorerie : entrée (le compte de contrepartie est crédité) ou sortie (il est débité), en caisse ou
     * en banque selon le mode.
     */
    private function ecritureTresorerie(ParametreComptable $p, ?string $mode, mixed $date, float $montant, int $contrepartie, bool $entree, string $libelle, ?string $reference): array
    {
        [$tresorerie, $journal] = $this->tresorerie($p, $mode);

        return [
            'journal_id' => $journal,
            'date' => Carbon::parse($date)->toDateString(),
            'libelle' => mb_substr($libelle, 0, 255),
            'reference' => $reference,
            // Le débit d'abord : la trésorerie pour une entrée, la contrepartie pour une sortie.
            'lignes' => $entree
                ? [$this->ligne($tresorerie, $montant, false, null), $this->ligne($contrepartie, $montant, true, null)]
                : [$this->ligne($contrepartie, $montant, false, null), $this->ligne($tresorerie, $montant, true, null)],
        ];
    }

    /** Taxes d'une ligne : par taxe du détail (groupe de taxes), sinon le montant de taxe de la ligne au compte par défaut. */
    private function ajouterTaxes(array &$tva, ?Collection $detail, float $montantLigne, float $taux, ParametreComptable $p, string $sens): void
    {
        $defaut = $sens === 'collectee' ? 'compte_tva_collectee_id' : 'compte_tva_deductible_id';
        if ($detail && $detail->isNotEmpty()) {
            foreach ($detail as $taxe) {
                $compte = $this->compteTaxe($p, $taxe->tenant_taxe_id, $sens) ?? $this->compte($p, $defaut);
                $tva[$compte] = ($tva[$compte] ?? 0) + (float) $taxe->montant * $taux;
            }
        } elseif ($montantLigne != 0) {
            $compte = $this->compte($p, $defaut);
            $tva[$compte] = ($tva[$compte] ?? 0) + $montantLigne * $taux;
        }
    }

    /** Lignes de contrepartie arrondies au centime (les montants nuls ne font pas de ligne). */
    private function contreparties(array $groupes, bool $credit): array
    {
        $lignes = [];
        foreach ($groupes as $groupe) {
            foreach ($groupe as $compte => $montant) {
                if (round($montant, 2) != 0) {
                    $lignes[] = $this->ligne((int) $compte, round($montant, 2), $credit, null);
                }
            }
        }

        return $lignes;
    }

    /** Somme des lignes (crédits moins débits, en valeur absolue) : le montant du compte du tiers. */
    private function solde(array $lignes): float
    {
        return round(abs(array_sum(array_column($lignes, 'credit')) - array_sum(array_column($lignes, 'debit'))), 2);
    }

    /** Ligne au débit ou au crédit ; un montant négatif passe de l'autre côté. */
    private function ligne(int $compte, float $montant, bool $credit, ?string $libelle): array
    {
        $credit = $montant < 0 ? ! $credit : $credit;

        return ['compte_id' => $compte, 'libelle' => $libelle, 'debit' => $credit ? 0 : abs($montant), 'credit' => $credit ? abs($montant) : 0];
    }

    /**
     * Compte de trésorerie et son journal selon le mode de paiement (espèces : caisse ; sinon : banque, ou la
     * correspondance réglée) ; en banque, celui du compte bancaire du document s'il est relié.
     */
    private function tresorerie(ParametreComptable $p, ?string $mode, ?CompteBancaire $compteBancaire = null): array
    {
        $choix = $p->correspondance('modes', (string) $mode) ?? ($mode === 'especes' ? 'caisse' : 'banque');

        return $choix === 'caisse'
            ? [$this->compte($p, 'compte_caisse_id'), $this->journal($p, 'journal_caisse_id')]
            : $this->banque($p, $compteBancaire);
    }

    /** Compte comptable et journal d'un compte bancaire : les siens s'il est relié, sinon ceux des réglages. */
    private function banque(ParametreComptable $p, ?CompteBancaire $compteBancaire): array
    {
        return [
            $compteBancaire?->compte_comptable_id ?: $this->compte($p, 'compte_banque_id'),
            $compteBancaire?->journal_id ?: $this->journal($p, 'journal_banque_id'),
        ];
    }

    private function compteCategorie(ParametreComptable $p, ?string $categorie, string $sens): ?int
    {
        $comptes = $categorie !== null && $categorie !== '' ? $p->correspondance('categories', $categorie) : null;

        return isset($comptes[$sens]) && $comptes[$sens] ? (int) $comptes[$sens] : null;
    }

    private function compteTaxe(ParametreComptable $p, ?int $taxe, string $sens): ?int
    {
        $comptes = $taxe ? $p->correspondance('taxes', $taxe) : null;

        return isset($comptes[$sens]) && $comptes[$sens] ? (int) $comptes[$sens] : null;
    }

    private function compte(ParametreComptable $p, string $champ): int
    {
        return $p->{$champ} ?: throw new DomainException('Compte « '.self::LIBELLES[$champ].' » non réglé (Finance → Comptabilisation automatique).');
    }

    private function journal(ParametreComptable $p, string $champ): int
    {
        return $p->{$champ} ?: throw new DomainException('« '.self::LIBELLES[$champ].' » non réglé (Finance → Comptabilisation automatique).');
    }

    /** Montant dans la devise de l'entreprise, au taux du document. */
    private function montant(mixed $montant, mixed $taux): float
    {
        return round((float) $montant * ((float) $taux ?: 1.0), 2);
    }

    /** Montant d'un document dans la devise de l'entreprise (aperçu). */
    private function montantDocument(string $type, Model $document): float
    {
        return match ($type) {
            'facture', 'avoir', 'facture_fournisseur' => $this->montant($document->montant_ttc, $document->taux_change),
            'paiement_client' => $this->montant((float) $document->montant + (float) $document->escompte, $document->facture?->taux_change),
            'encaissement' => $this->montant((float) $document->montant + (float) $document->escompte, $document->taux_change),
            'acompte' => $this->montant($document->montant, $document->taux_change),
            'remboursement_acompte' => $this->montant($document->montant, $document->acompte?->taux_change),
            'remboursement_avoir' => $this->montant($document->montant, $document->avoir?->taux_change),
            'paiement_fournisseur' => $this->montant($document->montant, $document->facture?->taux_change),
            'debourse' => $this->montant((float) $document->montant + (float) $document->escompte, $document->taux_change),
            'transaction_bancaire' => $this->montant($document->montant, $document->taux_change),
        };
    }

    /** Référence d'un document dans le compte rendu. */
    private function reference(string $type, Model $document): string
    {
        return match ($type) {
            'facture', 'avoir', 'facture_fournisseur' => (string) $document->numero,
            'paiement_client' => 'Paiement de '.$document->facture?->numero,
            'encaissement' => 'Encaissement '.$document->numero,
            'acompte' => trim('Acompte '.($document->numero ?? '#'.$document->id)),
            'remboursement_acompte' => 'Remboursement d\'acompte #'.$document->id,
            'remboursement_avoir' => 'Remboursement de '.$document->avoir?->numero,
            'paiement_fournisseur' => 'Paiement de '.$document->facture?->numero,
            'debourse' => 'Déboursé '.$document->numero,
            'transaction_bancaire' => 'Transaction bancaire '.$document->numero,
        };
    }

    /** Nom du client (ou du client de passage noté sur le document). */
    private function tiers(?Model $client, ?Model $document = null): string
    {
        return (string) ($document?->getAttribute('passage_nom') ?: $client?->raison_sociale ?: $client?->nom ?: '');
    }

    private function message(ValidationException|DomainException $erreur): string
    {
        return $erreur instanceof ValidationException ? (collect($erreur->errors())->flatten()->first() ?? $erreur->getMessage()) : $erreur->getMessage();
    }
}
