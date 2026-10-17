<?php

namespace Tests\Feature\Comptabilite;

use App\Models\AdminUser;
use App\Models\Avoir;
use App\Models\AvoirLigne;
use App\Models\Client;
use App\Models\CompteComptable;
use App\Models\ConditionPaiement;
use App\Models\Configuration;
use App\Models\EcritureComptable;
use App\Models\Facture;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Comptabilisation automatique (phase 4.3) : réglages proposés d'après le plan, aperçu par type de document,
 * écritures des ventes, notes de crédit, paiements (caisse, banque), acomptes, achats et paiements fournisseurs
 * (comptes, montants, journaux, catégories), une seule fois par document ; refus d'une période fermée et d'un compte
 * non réglé ; contre-passation des documents annulés ou supprimés ; tâche planifiée ; droits et isolation.
 */
class ComptabilisationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private int $client;

    private int $produit;

    private int $fournisseur;

    private array $exercice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['finance', 'ventes', 'achats']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Awa Comptable']);
        $this->chez(function () {
            TenantParametres::create(['nom_entreprise' => 'Quincaillerie Test', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF', 'tva_taux' => 19.25]);
            Configuration::create(['entite' => 'facture', 'prefixe' => 'FAC', 'separateur' => '-', 'longueur' => 5, 'auto_increment' => true, 'prochain_numero' => 1]);
            $this->client = Client::create([
                'code' => 'CLI-1', 'raison_sociale' => 'Bâtisseurs SA', 'adresse_facturation' => '1 rue du Port', 'ville' => 'Douala', 'code_postal' => '00000',
            ])->id;
            $this->produit = ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment 50 kg', 'prix_unitaire' => 100, 'categorie' => 'Ciment'])->id;
            $this->fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimenterie du Sud', 'actif' => true, 'devise' => 'XAF'])->id;
        });
        $this->agir($this->admin);
        $this->postJson('/api/comptabilite/comptes/importer-plan', ['plan' => 'syscohada'])->assertOk();
        $this->exercice = $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2026-01-01', 'date_fin' => '2026-12-31', 'decoupage' => 'mensuel'])->json();
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte, ?Tenant $tenant = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', ($tenant ?? $this->tenant)->slug);
    }

    private function compte(string $numero): int
    {
        return $this->chez(fn () => CompteComptable::where('numero', $numero)->value('id'));
    }

    /** Facture émise : quantité × 100 HT, TVA 19,25 %. */
    private function facture(string $date, int $quantite): array
    {
        $facture = $this->postJson('/api/factures', [
            'client_id' => $this->client, 'date_facture' => $date,
            'lignes' => [['produit_id' => $this->produit, 'quantite' => $quantite, 'prix_unitaire_ht' => 100, 'taux_tva' => 19.25]],
        ])->assertCreated()->json();
        $this->postJson("/api/factures/{$facture['id']}/emettre")->assertOk();

        return $facture;
    }

    private function payer(int $facture, float $montant, string $date, string $mode): void
    {
        $this->postJson("/api/factures/{$facture}/paiement", ['montant' => $montant, 'date_paiement' => $date, 'mode_paiement' => $mode])->assertOk();
    }

    /** Écriture générée d'un document : journal et lignes [compte, débit, crédit]. */
    private function ecriture(string $source, int $id, bool $inverse = false): array
    {
        $ecriture = $this->chez(fn () => EcritureComptable::with(['journal', 'lignes.compte'])->where('source', $source)->where('source_id', $id)
            ->when($inverse, fn ($q) => $q->whereNotNull('contrepassation_de'), fn ($q) => $q->whereNull('contrepassation_de'))->sole());

        return [$ecriture->journal->code, $ecriture->lignes->map(fn ($l) => [$l->compte->numero, (float) $l->debit, (float) $l->credit])->all(), $ecriture->date->toDateString()];
    }

    private function regler(array $changements = []): void
    {
        $reglages = $this->getJson('/api/comptabilite/comptabilisation/parametres')->assertOk()->json();
        $this->putJson('/api/comptabilite/comptabilisation/parametres', $changements + $reglages['propositions'] + ['date_debut' => '2026-09-01'])->assertOk();
    }

    public function test_ventes_paiements_achats_comptabilises_une_fois(): void
    {
        // Réglages proposés d'après le plan OHADA.
        $reglages = $this->getJson('/api/comptabilite/comptabilisation/parametres')->assertOk()->json();
        $numeros = collect($reglages['comptes'])->pluck('numero', 'id');
        $this->assertSame(['411', '401', '701', '601', '443', '445', '571', '521', '419'], array_map(fn ($champ) => $numeros[$reglages['propositions'][$champ]], [
            'compte_clients_id', 'compte_fournisseurs_id', 'compte_ventes_id', 'compte_achats_id', 'compte_tva_collectee_id',
            'compte_tva_deductible_id', 'compte_caisse_id', 'compte_banque_id', 'compte_acomptes_id',
        ]));
        $this->assertContains('date_debut', $reglages['manquants']);

        // Une facture de 10 sacs (1 000 HT, 192,50 de TVA), payée 500 en espèces et 692,50 par virement.
        $facture = $this->facture('2026-10-10', 10);
        $this->payer($facture['id'], 500, '2026-10-12', 'especes');
        $this->payer($facture['id'], 692.5, '2026-10-13', 'virement');
        // Un acompte reçu par virement, une note de crédit d'un sac.
        $acompte = $this->postJson('/api/acomptes', ['client_id' => $this->client, 'date_acompte' => '2026-10-13', 'mode' => 'virement', 'montant' => 300])->assertCreated()->json();
        $avoir = $this->chez(function () use ($facture) {
            $avoir = Avoir::create([
                'numero' => 'AV-00001', 'facture_id' => $facture['id'], 'client_id' => $this->client, 'date_avoir' => '2026-10-15', 'statut' => Avoir::EMIS,
                'motif' => 'Sac abîmé', 'montant_ht' => 100, 'montant_tva' => 19.25, 'montant_ttc' => 119.25, 'taux_change' => 1, 'cree_par' => $this->admin->id,
            ]);
            AvoirLigne::create([
                'avoir_id' => $avoir->id, 'facture_ligne_id' => Facture::find($facture['id'])->lignes()->value('id'), 'produit_id' => $this->produit,
                'quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 19.25, 'montant_ht' => 100, 'montant_tva' => 19.25, 'montant_ttc' => 119.25,
            ]);

            return $avoir->id;
        });
        // Une facture fournisseur (2 000 HT, TVA 19,25 %) payée 1 000 par chèque.
        $achat = $this->postJson('/api/factures-fournisseurs', [
            'fournisseur_id' => $this->fournisseur, 'numero_fournisseur' => 'CS-778', 'date_facture' => '2026-10-14',
            'lignes' => [['produit_id' => $this->produit, 'description' => 'Ciment', 'quantite' => 20, 'prix_unitaire' => 100, 'taux_tva' => 19.25]],
        ])->assertCreated()->json();
        $paiementAchat = $this->postJson("/api/factures-fournisseurs/{$achat['id']}/paiements", ['date_paiement' => '2026-10-15', 'montant' => 1000, 'mode' => 'cheque'])
            ->assertOk()->json('paiements.0.id');

        // Sans date de début, rien ne se comptabilise.
        $this->postJson('/api/comptabilite/comptabilisation')->assertUnprocessable()
            ->assertJsonPath('message', "Réglez d'abord la date à partir de laquelle les documents se comptabilisent.");
        // Le ciment se vend sur un compte de vente de sa catégorie.
        $this->regler(['correspondances' => ['categories' => ['Ciment' => ['vente' => $this->compte('702')]]]]);

        $apercu = collect($this->getJson('/api/comptabilite/comptabilisation/apercu')->assertOk()->assertJsonPath('manquants', [])->json('types'))->keyBy('type');
        $this->assertEquals([1, 1192.5], [$apercu['facture']['nombre'], $apercu['facture']['montant']]);
        $this->assertEquals([2, 1192.5], [$apercu['paiement_client']['nombre'], $apercu['paiement_client']['montant']]);
        $this->assertEquals([1, 1, 1, 1, 0], [$apercu['acompte']['nombre'], $apercu['avoir']['nombre'], $apercu['facture_fournisseur']['nombre'], $apercu['paiement_fournisseur']['nombre'], $apercu['remboursement_avoir']['nombre']]);

        $this->postJson('/api/comptabilite/comptabilisation')->assertOk()
            ->assertJsonPath('creees', 7)->assertJsonPath('erreurs', [])->assertJsonPath('message', '7 écriture(s) créée(s).');

        $this->assertSame(['VE', [['702', 0.0, 1000.0], ['443', 0.0, 192.5], ['411', 1192.5, 0.0]], '2026-10-10'], $this->ecriture('facture', $facture['id']));
        $paiements = $this->chez(fn () => Facture::find($facture['id'])->paiements()->orderBy('id')->pluck('id')->all());
        $this->assertSame(['CA', [['571', 500.0, 0.0], ['411', 0.0, 500.0]], '2026-10-12'], $this->ecriture('paiement_client', $paiements[0]));
        $this->assertSame(['BQ', [['521', 692.5, 0.0], ['411', 0.0, 692.5]], '2026-10-13'], $this->ecriture('paiement_client', $paiements[1]));
        $this->assertSame(['BQ', [['521', 300.0, 0.0], ['419', 0.0, 300.0]], '2026-10-13'], $this->ecriture('acompte', $acompte['id']));
        $this->assertSame(['VE', [['702', 100.0, 0.0], ['443', 19.25, 0.0], ['411', 0.0, 119.25]], '2026-10-15'], $this->ecriture('avoir', $avoir));
        $this->assertSame(['AC', [['601', 2000.0, 0.0], ['445', 385.0, 0.0], ['401', 0.0, 2385.0]], '2026-10-14'], $this->ecriture('facture_fournisseur', $achat['id']));
        $this->assertSame(['BQ', [['401', 1000.0, 0.0], ['521', 0.0, 1000.0]], '2026-10-15'], $this->ecriture('paiement_fournisseur', $paiementAchat));
        $this->getJson('/api/comptabilite/ecritures?source=facture')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.modifiable', false);

        // Une seule fois par document.
        $this->postJson('/api/comptabilite/comptabilisation')->assertOk()->assertJsonPath('creees', 0)->assertJsonPath('message', 'Aucune écriture à créer.');

        // Paiement fournisseur annulé puis facture annulée ; acompte supprimé : leurs écritures sont contre-passées.
        $this->deleteJson("/api/factures-fournisseurs/{$achat['id']}/paiements/{$paiementAchat}")->assertOk();
        $this->postJson("/api/factures-fournisseurs/{$achat['id']}/annuler")->assertOk();
        $this->deleteJson("/api/acomptes/{$acompte['id']}")->assertOk();
        $this->assertSame(3, $this->getJson('/api/comptabilite/comptabilisation/apercu')->json('contre_passations'));
        $this->postJson('/api/comptabilite/comptabilisation')->assertOk()->assertJsonPath('contre_passations', 3)
            ->assertJsonPath('message', '0 écriture(s) créée(s), 3 contre-passation(s).');
        $this->assertSame(['AC', [['601', 0.0, 2000.0], ['445', 0.0, 385.0], ['401', 2385.0, 0.0]], '2026-10-16'], $this->ecriture('facture_fournisseur', $achat['id'], true));
        $this->assertSame(['BQ', [['521', 0.0, 300.0], ['419', 300.0, 0.0]], '2026-10-16'], $this->ecriture('acompte', $acompte['id'], true));
        $this->postJson('/api/comptabilite/comptabilisation')->assertOk()->assertJsonPath('contre_passations', 0);
    }

    public function test_encaissement_comptabilise_en_une_ecriture_puis_contre_passe(): void
    {
        $this->regler();
        $premiere = $this->facture('2026-10-10', 10);
        $seconde = $this->facture('2026-10-11', 2);
        // Un chèque de 1 500 pour les deux factures (1 192,50 + 200) : 107,50 restent au crédit du client.
        $cheque = $this->postJson('/api/encaissements', [
            'client_id' => $this->client, 'date_encaissement' => '2026-10-14', 'mode' => 'cheque', 'reference' => 'CHQ-5', 'montant' => 1500,
            'lignes' => [['facture_id' => $premiere['id'], 'montant' => 1192.5], ['facture_id' => $seconde['id'], 'montant' => 200]],
        ])->assertCreated()->json();
        // 30 en espèces pour solder les 38,50 restants : 8,50 d'escompte.
        $especes = $this->postJson('/api/encaissements', [
            'client_id' => $this->client, 'date_encaissement' => '2026-10-15', 'mode' => 'especes', 'montant' => 30, 'escompte' => true,
            'lignes' => [['facture_id' => $seconde['id'], 'montant' => 38.5]],
        ])->assertCreated()->json();
        // Une avance annulée avant d'être comptabilisée ne passe pas au journal.
        $avance = $this->postJson('/api/encaissements', ['client_id' => $this->client, 'date_encaissement' => '2026-10-15', 'mode' => 'virement', 'montant' => 50])
            ->assertCreated()->json('id');
        $this->postJson("/api/encaissements/{$avance}/annuler")->assertOk();

        // Les paiements et le surplus d'un encaissement passent avec lui, en une écriture.
        $apercu = collect($this->getJson('/api/comptabilite/comptabilisation/apercu')->assertOk()->json('types'))->keyBy('type');
        $this->assertEquals([0, 2, 1538.5, 0], [$apercu['paiement_client']['nombre'], $apercu['encaissement']['nombre'], $apercu['encaissement']['montant'], $apercu['acompte']['nombre']]);
        $this->postJson('/api/comptabilite/comptabilisation')->assertOk()->assertJsonPath('creees', 4)->assertJsonPath('par_type.encaissement', 2);
        $this->assertSame(['BQ', [['521', 1500.0, 0.0], ['411', 0.0, 1192.5], ['411', 0.0, 200.0], ['419', 0.0, 107.5]], '2026-10-14'], $this->ecriture('encaissement', $cheque['id']));
        $this->assertSame(['CA', [['571', 30.0, 0.0], ['673', 8.5, 0.0], ['411', 0.0, 38.5]], '2026-10-15'], $this->ecriture('encaissement', $especes['id']));
        $this->getJson('/api/comptabilite/ecritures?source=encaissement')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.modifiable', false);

        // Annulé ensuite : son écriture est contre-passée à la date de l'annulation.
        $this->postJson("/api/encaissements/{$cheque['id']}/annuler", ['motif' => 'Chèque refusé'])->assertOk();
        $this->postJson('/api/comptabilite/comptabilisation')->assertOk()->assertJsonPath('creees', 0)->assertJsonPath('contre_passations', 1);
        $this->assertSame(['BQ', [['521', 0.0, 1500.0], ['411', 1192.5, 0.0], ['411', 200.0, 0.0], ['419', 107.5, 0.0]], '2026-10-16'], $this->ecriture('encaissement', $cheque['id'], true));
        $this->postJson('/api/comptabilite/comptabilisation')->assertOk()->assertJsonPath('contre_passations', 0);
    }

    public function test_debourse_comptabilise_en_une_ecriture_puis_contre_passe(): void
    {
        $this->regler();
        $deuxDix = $this->chez(fn () => ConditionPaiement::create([
            'code' => '2-10', 'libelle' => '2/10 Net 30', 'delai_jours' => 30, 'escompte_pourcentage' => 2, 'escompte_jours' => 10, 'actif' => true,
        ])->id);
        $achat = fn (string $date, float $prix, float $taxe, ?int $condition) => $this->postJson('/api/factures-fournisseurs', array_filter([
            'fournisseur_id' => $this->fournisseur, 'date_facture' => $date, 'condition_paiement_id' => $condition,
            'lignes' => [['produit_id' => $this->produit, 'description' => 'Ciment', 'quantite' => 1, 'prix_unitaire' => $prix, 'taux_tva' => $taxe]],
        ], fn ($v) => $v !== null))->assertCreated()->json('id');
        $premiere = $achat('2026-10-08', 2000, 19.25, null);
        $seconde = $achat('2026-10-09', 1000, 0, $deuxDix);
        // Un virement : la première en entier (2 385), la seconde avec ses 2 % d'escompte (980 + 20).
        $debourse = $this->postJson('/api/debourses', [
            'fournisseur_id' => $this->fournisseur, 'date_debourse' => '2026-10-15', 'mode' => 'virement', 'reference' => 'VIR-77',
            'lignes' => [['facture_fournisseur_id' => $premiere, 'montant' => 2385], ['facture_fournisseur_id' => $seconde, 'montant' => 980, 'escompte' => 20]],
        ])->assertCreated()->json('id');

        // Ses paiements passent avec lui, en une écriture : rien comme paiements fournisseurs.
        $apercu = collect($this->getJson('/api/comptabilite/comptabilisation/apercu')->assertOk()->json('types'))->keyBy('type');
        $this->assertEquals([0, 1, 3385], [$apercu['paiement_fournisseur']['nombre'], $apercu['debourse']['nombre'], $apercu['debourse']['montant']]);
        $this->postJson('/api/comptabilite/comptabilisation')->assertOk()->assertJsonPath('creees', 3)->assertJsonPath('par_type.debourse', 1);
        $this->assertSame(['BQ', [['401', 2385.0, 0.0], ['401', 1000.0, 0.0], ['521', 0.0, 3365.0], ['773', 0.0, 20.0]], '2026-10-15'], $this->ecriture('debourse', $debourse));

        // Annulé ensuite : son écriture est contre-passée à la date de l'annulation.
        $this->postJson("/api/debourses/{$debourse}/annuler")->assertOk();
        $this->postJson('/api/comptabilite/comptabilisation')->assertOk()->assertJsonPath('creees', 0)->assertJsonPath('contre_passations', 1);
        $this->assertSame(['BQ', [['401', 0.0, 2385.0], ['401', 0.0, 1000.0], ['521', 3365.0, 0.0], ['773', 20.0, 0.0]], '2026-10-16'], $this->ecriture('debourse', $debourse, true));
    }

    public function test_refus_tache_droits_et_isolation(): void
    {
        $this->regler();
        // Septembre fermé : sa facture est refusée, celle d'octobre passe.
        $septembre = $this->facture('2026-09-20', 1);
        $octobre = $this->facture('2026-10-05', 2);
        $this->postJson('/api/comptabilite/periodes/'.$this->exercice['periodes'][8]['id'].'/fermer')->assertOk();
        $resultat = $this->postJson('/api/comptabilite/comptabilisation')->assertOk()->assertJsonPath('creees', 1)->json();
        $this->assertSame([['type' => 'facture', 'document' => $septembre['numero'], 'message' => 'La période du 01/09/2026 au 30/09/2026 est fermée.']], $resultat['erreurs']);
        $this->ecriture('facture', $octobre['id']);

        // Compte non réglé : la facture attend avec son motif, puis passe une fois le compte réglé.
        $this->regler(['compte_tva_collectee_id' => null]);
        $troisieme = $this->facture('2026-10-15', 3);
        $this->postJson('/api/comptabilite/comptabilisation')->assertOk()->assertJsonPath('creees', 0)
            ->assertJsonPath('erreurs.1.message', 'Compte « Taxes collectées » non réglé (Finance → Comptabilisation automatique).');
        $this->regler();

        // La tâche planifiée comptabilise ce qui reste.
        $tache = collect($this->getJson('/api/taches-planifiees')->assertOk()->json('taches'))->firstWhere('code', 'comptabilisation');
        $this->postJson("/api/taches-planifiees/{$tache['id']}/executer")->assertOk()
            ->assertJsonPath('execution.message', '1 écriture(s) créée(s), 0 contre-passation(s). Refus ('.$septembre['numero'].') : La période du 01/09/2026 au 30/09/2026 est fermée.');
        $this->ecriture('facture', $troisieme['id']);

        // Un compte d'en-tête ne se règle pas.
        $this->putJson('/api/comptabilite/comptabilisation/parametres', ['compte_ventes_id' => $this->compte('70')])->assertUnprocessable()->assertJsonValidationErrors('comptes');

        // Une comptable qui consulte sans saisir d'écritures.
        $lectrice = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$lectrice->id}", ['groupes' => [], 'exceptions' => ['comptabilite.voir' => true, 'comptabilite.ecrire' => false]])->assertOk();
        $this->agir($lectrice);
        $this->getJson('/api/comptabilite/comptabilisation/apercu')->assertOk();
        $this->postJson('/api/comptabilite/comptabilisation')->assertForbidden();
        $this->putJson('/api/comptabilite/comptabilisation/parametres', ['date_debut' => '2026-01-01'])->assertForbidden();

        // Une autre entreprise a ses propres réglages et ne voit rien de ces documents.
        $autre = Tenant::factory()->create(['modules' => ['finance']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->getJson('/api/comptabilite/comptabilisation/apercu')->assertOk()->assertJsonPath('date_debut', null)
            ->assertJsonPath('types.0.nombre', 0)->assertJsonPath('contre_passations', 0);
    }
}
