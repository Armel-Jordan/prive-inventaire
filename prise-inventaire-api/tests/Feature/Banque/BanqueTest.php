<?php

namespace Tests\Feature\Banque;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\CompteComptable;
use App\Models\Configuration;
use App\Models\EcritureComptable;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Banque (phase 4.6) : comptes bancaires reliés à leur compte comptable, transactions bancaires (dépôt, retrait,
 * virement) et leurs contrôles, comptabilisation (comptes de chaque banque, encaissement par un compte bancaire,
 * contre-passation d'une transaction annulée), mouvements et soldes, droits et isolation.
 */
class BanqueTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private int $afriland;

    private int $sgbc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['finance', 'ventes']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $this->chez(fn () => TenantParametres::create(['nom_entreprise' => 'Quincaillerie Test', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF', 'tva_taux' => 19.25]));
        $this->agir($this->admin);
        $this->postJson('/api/comptabilite/comptes/importer-plan', ['plan' => 'syscohada'])->assertOk();
        $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2026-01-01', 'date_fin' => '2026-12-31', 'decoupage' => 'mensuel'])->assertCreated();
        // Un compte comptable par banque, sous « 52 Banques ».
        $this->chez(function () {
            $banques = CompteComptable::where('numero', '52')->value('id');
            foreach (['5211' => 'Afriland First Bank', '5212' => 'SGBC'] as $numero => $libelle) {
                CompteComptable::create(['numero' => $numero, 'libelle' => $libelle, 'parent_id' => $banques, 'nature' => 'actif', 'type' => 'tresorerie', 'saisie_manuelle' => true, 'actif' => true]);
            }
        });
        $this->afriland = $this->postJson('/api/referentiels/comptes_bancaires', ['nom' => 'Afriland courant', 'banque' => 'Afriland First Bank', 'par_defaut' => true, 'actif' => true])->assertCreated()->json('id');
        $this->sgbc = $this->postJson('/api/referentiels/comptes_bancaires', ['nom' => 'SGBC', 'banque' => 'Société Générale', 'actif' => true])->assertCreated()->json('id');
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

    private function relier(): void
    {
        $this->putJson("/api/banque/comptes/{$this->afriland}", ['compte_comptable_id' => $this->compte('5211')])->assertOk();
        $this->putJson("/api/banque/comptes/{$this->sgbc}", ['compte_comptable_id' => $this->compte('5212')])->assertOk();
    }

    private function transaction(string $type, array $autres): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/banque/transactions', $autres + [
            'compte_bancaire_id' => $this->afriland, 'date_transaction' => '2026-10-10', 'type' => $type, 'libelle' => 'Mouvement',
        ]);
    }

    /** Écriture d'une transaction : journal et lignes [compte, débit, crédit]. */
    private function ecriture(string $source, int $id, bool $inverse = false): array
    {
        $ecriture = $this->chez(fn () => EcritureComptable::with(['journal', 'lignes.compte'])->where('source', $source)->where('source_id', $id)
            ->when($inverse, fn ($q) => $q->whereNotNull('contrepassation_de'), fn ($q) => $q->whereNull('contrepassation_de'))->sole());

        return [$ecriture->journal->code, $ecriture->lignes->map(fn ($l) => [$l->compte->numero, (float) $l->debit, (float) $l->credit])->all()];
    }

    public function test_comptes_relies_transactions_comptabilisees_et_mouvements(): void
    {
        // Un compte comptable de trésorerie de détail, propre à chaque compte bancaire.
        $this->putJson("/api/banque/comptes/{$this->afriland}", ['compte_comptable_id' => $this->compte('601')])->assertUnprocessable()->assertJsonValidationErrors('compte_comptable_id');
        $this->putJson("/api/banque/comptes/{$this->afriland}", ['compte_comptable_id' => $this->compte('52')])->assertUnprocessable()->assertJsonValidationErrors('compte_comptable_id');
        $this->relier();
        $this->putJson("/api/banque/comptes/{$this->sgbc}", ['compte_comptable_id' => $this->compte('5211')])->assertUnprocessable()
            ->assertJsonValidationErrors(['compte_comptable_id' => 'Le compte 5211 est déjà celui du compte bancaire « Afriland courant » : chaque compte bancaire a le sien.']);

        // Intérêts reçus, frais prélevés, dépôt d'une recette net de frais, virement vers l'autre banque.
        $interets = $this->transaction('depot', ['libelle' => 'Intérêts créditeurs', 'lignes' => [['compte_comptable_id' => $this->compte('771'), 'montant' => 50]]])
            ->assertCreated()->json();
        $this->assertSame(['TB-00001', 'valide', '50.00'], [$interets['numero'], $interets['statut'], $interets['montant']]);
        $frais = $this->transaction('retrait', ['libelle' => 'Frais de tenue de compte', 'reference' => 'PRLV-10', 'lignes' => [['compte_comptable_id' => $this->compte('631'), 'montant' => 12.5]]])
            ->assertCreated()->json('id');
        $recette = $this->transaction('depot', ['libelle' => 'Vente sans facture', 'montant' => 980, 'lignes' => [
            ['compte_comptable_id' => $this->compte('701'), 'montant' => 1000], ['compte_comptable_id' => $this->compte('631'), 'libelle' => 'Commission', 'montant' => -20],
        ]])->assertCreated()->assertJsonPath('montant', '980.00')->json('id');
        $virement = $this->transaction('virement', ['libelle' => 'Alimentation SGBC', 'compte_bancaire_destination_id' => $this->sgbc, 'montant' => 300])
            ->assertCreated()->assertJsonPath('destination.nom', 'SGBC')->json('id');

        // Contrôles de la saisie.
        $this->transaction('depot', ['lignes' => []])->assertUnprocessable()->assertJsonValidationErrors(['lignes' => 'Ajoutez au moins une ligne : un compte et un montant.']);
        $this->transaction('retrait', ['lignes' => [['compte_comptable_id' => $this->compte('631'), 'montant' => -10]]])->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes' => 'Le total de la transaction doit être positif.']);
        $this->transaction('depot', ['montant' => 60, 'lignes' => [['compte_comptable_id' => $this->compte('771'), 'montant' => 50]]])->assertUnprocessable()->assertJsonValidationErrors('montant');
        $this->transaction('depot', ['lignes' => [['compte_comptable_id' => $this->compte('77'), 'montant' => 50]]])->assertUnprocessable()->assertJsonValidationErrors('lignes.0.compte_comptable_id');
        $this->transaction('depot', ['lignes' => [['compte_comptable_id' => $this->compte('5211'), 'montant' => 50]]])->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes.0.compte_comptable_id' => 'Ligne 1 : le compte 5211 est celui du compte bancaire lui-même.']);
        $this->transaction('virement', ['compte_bancaire_destination_id' => $this->afriland, 'montant' => 10])->assertUnprocessable()->assertJsonValidationErrors('compte_bancaire_destination_id');
        $euros = $this->postJson('/api/referentiels/comptes_bancaires', ['nom' => 'Compte euros', 'banque' => 'Afriland First Bank', 'devise_code' => 'EUR', 'actif' => true])->assertCreated()->json('id');
        $this->transaction('virement', ['compte_bancaire_destination_id' => $euros, 'montant' => 10])->assertUnprocessable()
            ->assertJsonValidationErrors(['compte_bancaire_destination_id' => 'Un virement se fait entre deux comptes de la même devise.']);
        $this->transaction('depot', ['compte_bancaire_id' => $euros, 'lignes' => [['compte_comptable_id' => $this->compte('771'), 'montant' => 5]]])->assertUnprocessable()->assertJsonValidationErrors('taux_change');
        $this->transaction('depot', ['date_transaction' => '2026-10-25', 'lignes' => [['compte_comptable_id' => $this->compte('771'), 'montant' => 5]]])->assertUnprocessable()->assertJsonValidationErrors('date_transaction');

        // Une transaction annulée avant la comptabilisation ne passe pas au journal.
        $erreur = $this->transaction('retrait', ['libelle' => 'Saisie en double', 'lignes' => [['compte_comptable_id' => $this->compte('631'), 'montant' => 12.5]]])->assertCreated()->json('id');
        $this->postJson("/api/banque/transactions/{$erreur}/annuler", ['motif' => 'Doublon'])->assertOk()->assertJsonPath('statut', 'annule')->assertJsonPath('motif_annulation', 'Doublon');
        $this->postJson("/api/banque/transactions/{$erreur}/annuler")->assertUnprocessable();

        // Un encaissement par chèque déposé à la SGBC passe par son compte.
        $client = $this->chez(fn () => Client::create(['code' => 'C1', 'raison_sociale' => 'Client', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id);
        $produit = $this->chez(fn () => ProduitTenant::create(['numero' => 'P1', 'description' => 'Ciment', 'prix_unitaire' => 100])->id);
        $facture = $this->postJson('/api/factures', ['client_id' => $client, 'date_facture' => '2026-10-05', 'lignes' => [['produit_id' => $produit, 'quantite' => 2, 'prix_unitaire_ht' => 100, 'taux_tva' => 0]]])
            ->assertCreated()->json('id');
        $this->postJson("/api/factures/{$facture}/emettre")->assertOk();
        $encaissement = $this->postJson('/api/encaissements', [
            'client_id' => $client, 'date_encaissement' => '2026-10-12', 'mode' => 'cheque', 'montant' => 200, 'compte_bancaire_id' => $this->sgbc,
            'lignes' => [['facture_id' => $facture, 'montant' => 200]],
        ])->assertCreated()->assertJsonPath('compte_bancaire.nom', 'SGBC')->json('id');

        // Comptabilisation : chaque banque sur son compte, au journal de banque.
        $reglages = $this->getJson('/api/comptabilite/comptabilisation/parametres')->assertOk()->json();
        $this->putJson('/api/comptabilite/comptabilisation/parametres', $reglages['propositions'] + ['date_debut' => '2026-10-01'])->assertOk();
        $this->postJson('/api/comptabilite/comptabilisation')->assertOk()->assertJsonPath('par_type.transaction_bancaire', 4)->assertJsonPath('erreurs', []);
        $this->assertSame(['BQ', [['5211', 50.0, 0.0], ['771', 0.0, 50.0]]], $this->ecriture('transaction_bancaire', $interets['id']));
        $this->assertSame(['BQ', [['631', 12.5, 0.0], ['5211', 0.0, 12.5]]], $this->ecriture('transaction_bancaire', $frais));
        $this->assertSame(['BQ', [['5211', 980.0, 0.0], ['701', 0.0, 1000.0], ['631', 20.0, 0.0]]], $this->ecriture('transaction_bancaire', $recette));
        $this->assertSame(['BQ', [['5212', 300.0, 0.0], ['5211', 0.0, 300.0]]], $this->ecriture('transaction_bancaire', $virement));
        $this->assertSame(['BQ', [['5212', 200.0, 0.0], ['411', 0.0, 200.0]]], $this->ecriture('encaissement', $encaissement));

        // Mouvements du compte Afriland : ses lignes, avec le solde progressif ; soldes des deux comptes.
        $mouvements = $this->getJson("/api/banque/comptes/{$this->afriland}/mouvements?du=2026-10-01&au=2026-10-31")->assertOk()->json();
        $this->assertEquals([[50, 0, 50], [0, 12.5, 37.5], [980, 0, 1017.5], [0, 300, 717.5]], array_map(fn ($l) => [$l['debit'], $l['credit'], $l['solde']], $mouvements['lignes']));
        $this->assertSame(['transaction_bancaire'], array_values(array_unique(array_column($mouvements['lignes'], 'source'))));
        $soldes = collect($this->getJson('/api/banque/comptes')->assertOk()->json('comptes'))->keyBy('nom');
        $this->assertEquals([717.5, 500.0, null], [$soldes['Afriland courant']['solde'], $soldes['SGBC']['solde'], $soldes['Compte euros']['solde']]);
        $this->getJson("/api/banque/comptes/{$euros}/mouvements")->assertUnprocessable()
            ->assertJsonPath('message', "Reliez d'abord le compte bancaire « Compte euros » à son compte comptable.");

        // Annulée après coup : contre-passée à la date de l'annulation.
        $this->postJson("/api/banque/transactions/{$frais}/annuler")->assertOk();
        $this->postJson('/api/comptabilite/comptabilisation')->assertOk()->assertJsonPath('contre_passations', 1);
        $this->assertSame(['BQ', [['631', 0.0, 12.5], ['5211', 12.5, 0.0]]], $this->ecriture('transaction_bancaire', $frais, true));

        // Liste : un virement figure sur les deux comptes.
        $this->assertSame(['TB-00004'], array_column($this->getJson("/api/banque/transactions?compte_bancaire_id={$this->sgbc}")->assertOk()->json('data'), 'numero'));
        $this->assertSame(5, $this->getJson('/api/banque/transactions')->json('total'));

        // Sans numérotation automatique, la transaction est refusée.
        $this->chez(fn () => Configuration::pourEntite('transaction_bancaire', $this->tenant->id)->update(['auto_increment' => false]));
        $this->transaction('depot', ['lignes' => [['compte_comptable_id' => $this->compte('771'), 'montant' => 5]]])->assertUnprocessable()
            ->assertJsonPath('message', 'Numéro requis : activez la numérotation automatique des transactions bancaires (Configuration → Numérotation).');
    }

    public function test_droits_et_isolation(): void
    {
        $this->relier();
        $transaction = $this->transaction('depot', ['lignes' => [['compte_comptable_id' => $this->compte('771'), 'montant' => 50]]])->assertCreated()->json('id');

        // Une comptable qui consulte sans saisir ni relier les comptes.
        $lectrice = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$lectrice->id}", ['groupes' => [], 'exceptions' => [
            'banque.voir' => true, 'banque.saisir' => false, 'banque.annuler' => false, 'banque.configurer' => false,
        ]])->assertOk();
        $this->agir($lectrice);
        $this->getJson('/api/banque/comptes')->assertOk();
        $this->getJson("/api/banque/transactions/{$transaction}")->assertOk();
        $this->transaction('depot', ['lignes' => [['compte_comptable_id' => $this->compte('771'), 'montant' => 5]]])->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Saisir une transaction bancaire » (Banque).');
        $this->postJson("/api/banque/transactions/{$transaction}/annuler")->assertForbidden();
        $this->putJson("/api/banque/comptes/{$this->afriland}", ['compte_comptable_id' => null])->assertForbidden();

        // Catalogue : le module et ses actions.
        $this->agir($this->admin);
        $modules = collect(collect($this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets'))->firstWhere('cle', 'finance')['modules'])->keyBy('cle');
        $this->assertSame(['banque.voir', 'banque.saisir', 'banque.annuler', 'banque.rapprocher', 'banque.configurer'], array_column($modules['banque']['permissions'], 'code'));

        // Une autre entreprise ne voit ni n'annule ces transactions.
        $autre = Tenant::factory()->create(['modules' => ['finance']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->assertSame([], $this->getJson('/api/banque/comptes')->assertOk()->json('comptes'));
        $this->getJson("/api/banque/transactions/{$transaction}")->assertNotFound();
        $this->postJson("/api/banque/transactions/{$transaction}/annuler")->assertNotFound();
        $this->postJson('/api/banque/transactions', ['compte_bancaire_id' => $this->afriland, 'date_transaction' => '2026-10-10', 'type' => 'depot', 'libelle' => 'x',
            'lignes' => [['compte_comptable_id' => 1, 'montant' => 5]]])->assertUnprocessable()->assertJsonValidationErrors('compte_bancaire_id');
    }
}
