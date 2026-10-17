<?php

namespace Tests\Feature\Banque;

use App\Models\AdminUser;
use App\Models\CompteComptable;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Rapprochement bancaire (phase 4.6) : relevé (date, solde), pointage des lignes du compte comptable quelle qu'en soit
 * l'origine (soldes d'ouverture, transactions, écriture saisie), écart, fin seulement à écart nul, enchaînement des
 * soldes, verrous (document, écriture, soldes d'ouverture), réouverture du dernier, suppression, rapport, droits.
 */
class RapprochementBancaireTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private int $banque;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-05 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['finance']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Awa Comptable']);
        $this->chez(fn () => TenantParametres::create(['nom_entreprise' => 'Quincaillerie Test', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF', 'tva_taux' => 19.25]));
        $this->agir($this->admin);
        $this->postJson('/api/comptabilite/comptes/importer-plan', ['plan' => 'syscohada'])->assertOk();
        $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2026-01-01', 'date_fin' => '2026-12-31', 'decoupage' => 'mensuel'])->assertCreated();
        $this->chez(fn () => CompteComptable::create([
            'numero' => '5211', 'libelle' => 'Afriland', 'parent_id' => CompteComptable::where('numero', '52')->value('id'),
            'nature' => 'actif', 'type' => 'tresorerie', 'saisie_manuelle' => true, 'actif' => true,
        ]));
        $this->banque = $this->postJson('/api/referentiels/comptes_bancaires', ['nom' => 'Afriland courant', 'banque' => 'Afriland First Bank', 'par_defaut' => true, 'actif' => true])
            ->assertCreated()->json('id');
        $this->putJson("/api/banque/comptes/{$this->banque}", ['compte_comptable_id' => $this->compte('5211')])->assertOk();
        $reglages = $this->getJson('/api/comptabilite/comptabilisation/parametres')->assertOk()->json();
        $this->putJson('/api/comptabilite/comptabilisation/parametres', $reglages['propositions'] + ['date_debut' => '2026-01-01'])->assertOk();
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

    private function transaction(string $type, string $date, float $montant, string $contrepartie): int
    {
        return $this->postJson('/api/banque/transactions', [
            'compte_bancaire_id' => $this->banque, 'date_transaction' => $date, 'type' => $type, 'libelle' => "{$type} {$montant}",
            'lignes' => [['compte_comptable_id' => $this->compte($contrepartie), 'montant' => $montant]],
        ])->assertCreated()->json('id');
    }

    /** Lignes du rapprochement : [date, entrée, sortie, pointée] par numéro de ligne. */
    private function lignes(array $detail): array
    {
        return array_map(fn ($l) => [$l['date'], $l['entree'], $l['sortie'], $l['pointee']], $detail['lignes']);
    }

    public function test_rapprochements_enchaines_verrous_reouverture_et_rapport(): void
    {
        // Soldes d'ouverture 1 000 en banque ; un dépôt de 500, deux retraits de 200 et 50 ; tout passe au journal.
        $this->putJson('/api/comptabilite/soldes-ouverture', ['lignes' => [
            ['compte_id' => $this->compte('5211'), 'debit' => 1000], ['compte_id' => $this->compte('101'), 'credit' => 1000],
        ]])->assertOk();
        $this->transaction('depot', '2026-10-05', 500, '771');
        $retrait = $this->transaction('retrait', '2026-10-10', 200, '631');
        $this->transaction('retrait', '2026-10-20', 50, '631');
        $this->postJson('/api/comptabilite/comptabilisation')->assertOk()->assertJsonPath('creees', 3);

        // Relevé du 15/10 : 1 300. Les mouvements jusqu'au relevé, d'où qu'ils viennent.
        $premier = $this->postJson("/api/banque/comptes/{$this->banque}/rapprochements", ['date_releve' => '2026-10-15', 'solde_releve' => 1300])
            ->assertCreated()->assertJsonPath('statut', 'en_cours')->assertJsonPath('solde_depart', '0.00')->json('id');
        $this->postJson("/api/banque/comptes/{$this->banque}/rapprochements", ['date_releve' => '2026-10-31', 'solde_releve' => 1])->assertUnprocessable()
            ->assertJsonPath('message', 'Un rapprochement est déjà en cours pour ce compte (relevé du 15/10/2026).');
        $detail = $this->getJson("/api/banque/rapprochements/{$premier}")->assertOk()->json();
        $this->assertEquals([['2026-01-01', 1000, 0, false], ['2026-10-05', 500, 0, false], ['2026-10-10', 0, 200, false]], $this->lignes($detail));
        $this->assertSame(['ouverture', 'transaction_bancaire', 'transaction_bancaire'], array_column($detail['lignes'], 'source'));
        $this->assertEquals([0, 1300, 1300], [$detail['resume']['solde_pointe'], $detail['resume']['ecart'], $detail['resume']['solde_comptable']]);
        [$ouverture, $depot, $sortie] = array_column($detail['lignes'], 'id');

        // Pointer : l'écart se réduit ; terminer n'est permis qu'à écart nul.
        $partiel = $this->postJson("/api/banque/rapprochements/{$premier}/pointer", ['lignes' => [$ouverture], 'pointee' => true])->assertOk()->json('resume');
        $this->assertEquals([1000, 300], [$partiel['solde_pointe'], $partiel['ecart']]);
        $this->postJson("/api/banque/rapprochements/{$premier}/terminer")->assertUnprocessable()
            ->assertJsonPath('message', "L'écart avec le relevé doit être nul pour terminer le rapprochement : 300,00.");
        $this->postJson("/api/banque/rapprochements/{$premier}/pointer", ['lignes' => [$depot, $sortie], 'pointee' => true])->assertOk()->assertJsonPath('resume.ecart', 0);
        $this->postJson("/api/banque/rapprochements/{$premier}/terminer")->assertOk()->assertJsonPath('statut', 'termine')->assertJsonPath('auteur_fin.nom', 'Awa Comptable');
        $this->postJson("/api/banque/rapprochements/{$premier}/pointer", ['lignes' => [$sortie], 'pointee' => false])->assertUnprocessable()
            ->assertJsonPath('message', 'Ce rapprochement est terminé : rouvrez-le pour le modifier.');

        // Verrous : la transaction pointée ne s'annule plus, les soldes d'ouverture pointés ne changent plus.
        $this->postJson("/api/banque/transactions/{$retrait}/annuler")->assertUnprocessable()
            ->assertJsonPath('message', "La transaction TB-00002 : son mouvement est pointé dans le rapprochement bancaire du 15/10/2026 ; retirez-le d'abord du rapprochement.");
        $this->putJson('/api/comptabilite/soldes-ouverture', ['lignes' => [
            ['compte_id' => $this->compte('5211'), 'debit' => 900], ['compte_id' => $this->compte('101'), 'credit' => 900],
        ]])->assertUnprocessable()->assertJsonValidationErrors('lignes');

        // Une écriture saisie le 12/10 (frais oubliés), après le premier rapprochement.
        $od = collect($this->getJson('/api/comptabilite/journaux')->json())->firstWhere('code', 'OD')['id'];
        $ecriture = $this->postJson('/api/comptabilite/ecritures', ['journal_id' => $od, 'date' => '2026-10-12', 'libelle' => 'Frais oubliés', 'lignes' => [
            ['compte_id' => $this->compte('631'), 'debit' => 30], ['compte_id' => $this->compte('5211'), 'credit' => 30],
        ]])->assertCreated()->json('id');

        // Relevé du 31/10 : 1 220 ; il part du solde du précédent et pas avant lui.
        $this->postJson("/api/banque/comptes/{$this->banque}/rapprochements", ['date_releve' => '2026-10-14', 'solde_releve' => 1])->assertUnprocessable()
            ->assertJsonValidationErrors(['date_releve' => 'Le relevé doit suivre celui du dernier rapprochement terminé (15/10/2026).']);
        $second = $this->postJson("/api/banque/comptes/{$this->banque}/rapprochements", ['date_releve' => '2026-10-31', 'solde_releve' => 1220])
            ->assertCreated()->assertJsonPath('solde_depart', '1300.00')->json('id');
        $detail = $this->getJson("/api/banque/rapprochements/{$second}")->assertOk()->json();
        $this->assertEquals([['2026-10-12', 0, 30, false], ['2026-10-20', 0, 50, false]], $this->lignes($detail));
        $this->postJson("/api/banque/rapprochements/{$second}/pointer", ['lignes' => array_column($detail['lignes'], 'id'), 'pointee' => true])->assertOk()->assertJsonPath('resume.ecart', 0);
        $this->postJson("/api/banque/rapprochements/{$second}/terminer")->assertOk();
        // L'écriture pointée ne se modifie ni ne se supprime.
        $this->deleteJson("/api/comptabilite/ecritures/{$ecriture}")->assertUnprocessable()
            ->assertJsonPath('message', "Cette écriture est pointée dans un rapprochement bancaire : retirez-la d'abord du rapprochement.");

        // Seul le dernier se rouvre ; rouvert, il se corrige ou se supprime (ses lignes sont libérées).
        $this->postJson("/api/banque/rapprochements/{$premier}/rouvrir")->assertUnprocessable()->assertJsonPath('message', 'Seul le dernier rapprochement terminé du compte se rouvre.');
        $this->postJson("/api/banque/rapprochements/{$second}/rouvrir")->assertOk()->assertJsonPath('statut', 'en_cours');
        $this->putJson("/api/banque/rapprochements/{$second}", ['date_releve' => '2026-10-15', 'solde_releve' => 1250])->assertUnprocessable()->assertJsonValidationErrors('date_releve');
        $this->putJson("/api/banque/rapprochements/{$second}", ['date_releve' => '2026-10-18', 'solde_releve' => 1270])->assertOk()
            ->assertJsonPath('resume.ecart', 0)->assertJsonCount(1, 'lignes');
        $this->deleteJson("/api/banque/rapprochements/{$second}")->assertOk();
        $this->deleteJson("/api/comptabilite/ecritures/{$ecriture}")->assertOk();
        $this->assertCount(1, $this->getJson("/api/banque/comptes/{$this->banque}/rapprochements")->assertOk()->json());

        // Le premier, terminé : la ligne du 20/10 n'est pas à sa date ; la preuve tient (pointé + en circulation = comptable).
        $rapport = $this->getJson("/api/banque/rapprochements/{$premier}")->assertOk()->json('resume');
        $this->assertEquals([1300, 0, 0, 0, 1300], [$rapport['solde_pointe'], $rapport['ecart'], $rapport['entrees_en_circulation'], $rapport['sorties_en_circulation'], $rapport['solde_comptable']]);
        $this->assertStringStartsWith('%PDF', $this->get("/api/banque/rapprochements/{$premier}/pdf")->assertOk()->getContent());
    }

    public function test_droits_et_isolation(): void
    {
        $rapprochement = $this->postJson("/api/banque/comptes/{$this->banque}/rapprochements", ['date_releve' => '2026-10-31', 'solde_releve' => 0])->assertCreated()->json('id');
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [], 'exceptions' => ['banque.voir' => true, 'banque.rapprocher' => false]])->assertOk();
        $this->agir($compte);
        $this->getJson("/api/banque/comptes/{$this->banque}/rapprochements")->assertOk();
        $this->getJson("/api/banque/rapprochements/{$rapprochement}")->assertOk();
        $this->postJson("/api/banque/rapprochements/{$rapprochement}/terminer")->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Faire le rapprochement bancaire » (Banque).');
        $this->deleteJson("/api/banque/rapprochements/{$rapprochement}")->assertForbidden();

        $autre = Tenant::factory()->create(['modules' => ['finance']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->getJson("/api/banque/rapprochements/{$rapprochement}")->assertNotFound();
        $this->postJson("/api/banque/rapprochements/{$rapprochement}/pointer", ['lignes' => [1], 'pointee' => true])->assertNotFound();
        $this->getJson("/api/banque/comptes/{$this->banque}/rapprochements")->assertNotFound();
    }
}
