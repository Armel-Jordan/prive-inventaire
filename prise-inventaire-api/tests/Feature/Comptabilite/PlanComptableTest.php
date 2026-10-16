<?php

namespace Tests\Feature\Comptabilite;

use App\Models\AdminUser;
use App\Models\CompteComptable;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Plan comptable (phase 4.1) : plans types, règles des comptes, import et export CSV, droits et isolation.
 */
class PlanComptableTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['finance']]);
        $this->connecter($this->tenant);
    }

    private function connecter(Tenant $tenant, string $role = 'admin'): AdminUser
    {
        $compte = AdminUser::factory()->for($tenant)->create(['role' => $role]);
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);

        return $compte;
    }

    private function compte(string $numero): CompteComptable
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => CompteComptable::where('numero', $numero)->firstOrFail());
    }

    private function fichier(string $contenu): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('plan.csv', $contenu);
    }

    public function test_plan_type_syscohada_avec_hierarchie_et_compte_de_resultat(): void
    {
        $plans = collect($this->getJson('/api/comptabilite/plans-types')->assertOk()->json());
        $this->assertSame(['syscohada', 'pcg', 'canadien'], $plans->pluck('code')->all());

        $this->postJson('/api/comptabilite/comptes/importer-plan', ['plan' => 'syscohada'])->assertOk()
            ->assertJsonPath('crees', $plans->firstWhere('code', 'syscohada')['nombre_comptes']);

        $comptes = collect($this->getJson('/api/comptabilite/comptes')->assertOk()->json())->keyBy('numero');
        $this->assertSame($comptes['40']['id'], $comptes['401']['parent_id']);
        $this->assertSame($comptes['4']['id'], $comptes['40']['parent_id']);
        $this->assertNull($comptes['4']['parent_id']);
        $this->assertSame(['en_tete', false], [$comptes['40']['type'], $comptes['40']['saisie_manuelle']]);
        $this->assertSame(['fournisseurs', 'passif', true], [$comptes['401']['type'], $comptes['401']['nature'], $comptes['401']['saisie_manuelle']]);
        $this->assertSame(['clients', 'actif'], [$comptes['411']['type'], $comptes['411']['nature']]);
        $this->assertSame(['tresorerie', 'actif'], [$comptes['571']['type'], $comptes['571']['nature']]);
        $this->assertSame('resultat', $comptes['131']['type']);
        $this->assertSame(['charge', 'produit'], [$comptes['601']['nature'], $comptes['701']['nature']]);

        // Un plan déjà rempli ne se remplace pas ; le compléter n'ajoute que les comptes absents.
        $this->postJson('/api/comptabilite/comptes/importer-plan', ['plan' => 'pcg'])->assertUnprocessable();
        $this->deleteJson('/api/comptabilite/comptes/'.$comptes['605']['id'])->assertOk();
        $this->postJson('/api/comptabilite/comptes/importer-plan', ['plan' => 'syscohada', 'completer' => true])
            ->assertOk()->assertJsonPath('crees', 1);
        $this->assertSame($comptes['60']['id'], $this->compte('605')->parent_id);
    }

    public function test_plan_canadien_a_cinq_chiffres(): void
    {
        $this->postJson('/api/comptabilite/comptes/importer-plan', ['plan' => 'canadien'])->assertOk();

        $this->assertSame($this->compte('10000')->id, $this->compte('11000')->parent_id);
        $this->assertSame($this->compte('50000')->id, $this->compte('59900')->parent_id);
        $this->assertSame('en_tete', $this->compte('30000')->type);
        $this->assertSame('resultat', $this->compte('31000')->type);
    }

    public function test_regles_d_un_compte(): void
    {
        $classe = $this->postJson('/api/comptabilite/comptes', ['numero' => '6', 'libelle' => 'Charges', 'nature' => 'charge', 'type' => 'en_tete', 'saisie_manuelle' => true])
            ->assertCreated()->assertJsonPath('saisie_manuelle', false)->json();
        $loyer = $this->postJson('/api/comptabilite/comptes', ['numero' => ' 622 ', 'libelle' => 'Loyers', 'nature' => 'charge', 'type' => 'standard', 'parent_id' => $classe['id']])
            ->assertCreated()->assertJsonPath('numero', '622')->json();

        $this->postJson('/api/comptabilite/comptes', ['numero' => '622', 'libelle' => 'Doublon', 'nature' => 'charge', 'type' => 'standard'])
            ->assertUnprocessable()->assertJsonValidationErrors('numero');
        $this->postJson('/api/comptabilite/comptes', ['numero' => '62/2', 'libelle' => 'Barre', 'nature' => 'charge', 'type' => 'standard'])
            ->assertUnprocessable()->assertJsonValidationErrors('numero');

        // Pas de boucle dans la hiérarchie.
        $this->putJson("/api/comptabilite/comptes/{$classe['id']}", ['numero' => '6', 'libelle' => 'Charges', 'nature' => 'charge', 'type' => 'en_tete', 'parent_id' => $loyer['id']])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        // Désactivation : d'abord les sous-comptes ; un compte actif ne dépend pas d'un compte inactif.
        $this->putJson("/api/comptabilite/comptes/{$classe['id']}", ['numero' => '6', 'libelle' => 'Charges', 'nature' => 'charge', 'type' => 'en_tete', 'actif' => false])
            ->assertUnprocessable()->assertJsonValidationErrors('actif');
        $this->putJson("/api/comptabilite/comptes/{$loyer['id']}", ['numero' => '622', 'libelle' => 'Loyers', 'nature' => 'charge', 'type' => 'standard', 'parent_id' => $classe['id'], 'actif' => false])->assertOk();
        $this->putJson("/api/comptabilite/comptes/{$classe['id']}", ['numero' => '6', 'libelle' => 'Charges', 'nature' => 'charge', 'type' => 'en_tete', 'actif' => false])->assertOk();
        $this->putJson("/api/comptabilite/comptes/{$loyer['id']}", ['numero' => '622', 'libelle' => 'Loyers', 'nature' => 'charge', 'type' => 'standard', 'parent_id' => $classe['id'], 'actif' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        // Un seul compte reçoit le résultat, et c'est un compte de capitaux propres.
        $this->postJson('/api/comptabilite/comptes', ['numero' => '120', 'libelle' => 'Résultat', 'nature' => 'passif', 'type' => 'resultat'])
            ->assertUnprocessable()->assertJsonValidationErrors('nature');
        $this->postJson('/api/comptabilite/comptes', ['numero' => '120', 'libelle' => 'Résultat', 'nature' => 'capitaux_propres', 'type' => 'resultat'])->assertCreated();
        $this->postJson('/api/comptabilite/comptes', ['numero' => '121', 'libelle' => 'Autre résultat', 'nature' => 'capitaux_propres', 'type' => 'resultat'])
            ->assertUnprocessable()->assertJsonValidationErrors('type');

        // Suppression refusée tant qu'il reste des sous-comptes.
        $this->deleteJson("/api/comptabilite/comptes/{$classe['id']}")->assertUnprocessable();
        $this->deleteJson("/api/comptabilite/comptes/{$loyer['id']}")->assertOk();
        $this->deleteJson("/api/comptabilite/comptes/{$classe['id']}")->assertOk();
    }

    public function test_import_csv_tout_ou_rien_puis_export(): void
    {
        $this->postJson('/api/comptabilite/comptes', ['numero' => '4', 'libelle' => 'Tiers', 'nature' => 'passif', 'type' => 'en_tete'])->assertCreated();

        $erreurs = $this->post('/api/comptabilite/comptes/importer-fichier', ['fichier' => $this->fichier(
            "Numéro;Libellé;Nature;Type;Compte parent\n401;Fournisseurs;Passif;fournisseurs;\n411;Clients;inconnue;;\n401;Encore;passif;;\n4711;Attente;passif;;999\n"
        )], ['Accept' => 'application/json'])->assertUnprocessable()->json('erreurs');
        $this->assertSame([
            'Ligne 3 : nature « inconnue » inconnue (actif, passif, capitaux_propres, produit, charge).',
            'Ligne 4 : le compte 401 figure deux fois dans le fichier.',
            'Ligne 5 : compte parent « 999 » introuvable.',
        ], $erreurs);
        $this->assertSame(1, app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => CompteComptable::count()));

        $this->post('/api/comptabilite/comptes/importer-fichier', ['fichier' => $this->fichier(
            "\xEF\xBB\xBFnumero;libelle;nature;type;parent\n4;Tiers;passif;en-tête;\n40;Fournisseurs et comptes rattachés;passif;En-tête;\n401;Fournisseurs;passif;fournisseurs;\n4011;Fournisseurs locaux;passif;;401\n411;Clients;Actif;Clients;\n"
        )], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('crees', 4)->assertJsonPath('ignores', 1);
        $this->assertSame($this->compte('40')->id, $this->compte('401')->parent_id);
        $this->assertSame($this->compte('401')->id, $this->compte('4011')->parent_id);
        // Sans colonne parent renseignée : rattaché au plus long numéro préfixe (4 pour 411).
        $this->assertSame($this->compte('4')->id, $this->compte('411')->parent_id);
        $this->assertSame(['en_tete', false], [$this->compte('40')->type, $this->compte('40')->saisie_manuelle]);

        $csv = $this->get('/api/comptabilite/comptes/export')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFnumero;libelle;nature;type;parent", $csv);
        $this->assertStringContainsString('4011;"Fournisseurs locaux";passif;standard;401', $csv);
    }

    public function test_module_finance_droits_et_isolation(): void
    {
        $this->postJson('/api/comptabilite/comptes/importer-plan', ['plan' => 'pcg'])->assertOk();

        // Un autre tenant ne voit pas ce plan et ne peut pas s'y rattacher.
        $autre = Tenant::factory()->create(['modules' => ['finance']]);
        $this->connecter($autre);
        $this->getJson('/api/comptabilite/comptes')->assertOk()->assertJsonCount(0);
        $this->postJson('/api/comptabilite/comptes', ['numero' => '512', 'libelle' => 'Banque', 'nature' => 'actif', 'type' => 'tresorerie', 'parent_id' => $this->compte('51')->id])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        // Sans le module Finance : refus.
        $sansFinance = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->connecter($sansFinance);
        $this->getJson('/api/comptabilite/comptes')->assertForbidden();

        // Un utilisateur consulte mais ne modifie pas.
        $this->connecter($this->tenant, 'user');
        $this->getJson('/api/comptabilite/comptes')->assertOk();
        $this->postJson('/api/comptabilite/comptes', ['numero' => '999', 'libelle' => 'Test', 'nature' => 'charge', 'type' => 'standard'])->assertForbidden();
    }
}
