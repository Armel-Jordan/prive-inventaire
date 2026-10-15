<?php

namespace Tests\Feature\Taxes;

use App\Models\AdminUser;
use App\Models\GroupeTaxe;
use App\Models\GroupeTaxeLigne;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\TenantTaxe;
use App\Services\CalculTaxes;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Groupes de taxes (plan de parité fripv2, phase 0.4a) : combinaisons de taxes et calcul,
 * pour des cas réels de plusieurs pays.
 */
class GroupesTaxesTest extends TestCase
{
    use RefreshDatabase;

    private function connecter(Tenant $tenant): static
    {
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(), ['*']);

        return $this->withHeader('X-Tenant-Slug', $tenant->slug);
    }

    private function taxe(string $nom, float $taux): int
    {
        return $this->postJson('/api/taxes', ['nom' => $nom, 'taux' => $taux])->assertCreated()->json('id');
    }

    private function groupe(string $code, array $lignes, array $autres = []): array
    {
        return $this->postJson('/api/groupes-taxes', ['code' => $code, 'nom' => $code, 'lignes' => $lignes] + $autres)
            ->assertCreated()
            ->json();
    }

    public function test_cameroun_tva_et_cac_sur_la_tva(): void
    {
        $this->connecter(Tenant::factory()->create());
        $tva = $this->taxe('TVA', 17.5);
        $cac = $this->taxe('CAC', 10);

        $groupe = $this->groupe('TVA_CAC', [
            ['tenant_taxe_id' => $tva, 'applique_sur' => 'base'],
            ['tenant_taxe_id' => $cac, 'applique_sur' => 'taxe_precedente'],
        ]);
        $this->assertEquals(19.25, $groupe['taux_effectif']);

        $calcul = $this->postJson("/api/groupes-taxes/{$groupe['id']}/calculer", ['montant_ht' => 1000])->assertOk();
        $this->assertEquals([175, 17.5], $calcul->json('taxes.*.montant'));
        // La CAC s'applique sur le montant de la TVA.
        $this->assertEquals(175, $calcul->json('taxes.1.base'));
        $this->assertEquals(192.5, $calcul->json('total_taxes'));
        $this->assertEquals(1192.5, $calcul->json('montant_ttc'));
    }

    public function test_quebec_tps_et_tvq_sur_le_ht(): void
    {
        $this->connecter(Tenant::factory()->create());
        $groupe = $this->groupe('TPS_TVQ', [
            ['tenant_taxe_id' => $this->taxe('TPS', 5), 'applique_sur' => 'base'],
            ['tenant_taxe_id' => $this->taxe('TVQ', 9.975), 'applique_sur' => 'base'],
        ]);
        $this->assertEquals(14.975, $groupe['taux_effectif']);

        // 9,975 % de 100 = 9,975 : arrondi au centime supérieur, malgré la représentation flottante.
        $calcul = $this->postJson("/api/groupes-taxes/{$groupe['id']}/calculer", ['montant_ht' => 100])->assertOk();
        $this->assertEquals([5, 9.98], $calcul->json('taxes.*.montant'));
        $this->assertEquals(114.98, $calcul->json('montant_ttc'));
    }

    public function test_taxe_composee_sur_le_ht_et_les_taxes_precedentes(): void
    {
        $this->connecter(Tenant::factory()->create());
        $groupe = $this->groupe('COMPOSE', [
            ['tenant_taxe_id' => $this->taxe('TPS', 5), 'applique_sur' => 'base'],
            ['tenant_taxe_id' => $this->taxe('TVQ ancienne', 9.5), 'applique_sur' => 'base_et_taxes_precedentes'],
        ]);
        $this->assertEquals(14.975, $groupe['taux_effectif']);

        $calcul = $this->postJson("/api/groupes-taxes/{$groupe['id']}/calculer", ['montant_ht' => 100])->assertOk();
        $this->assertEquals(105, $calcul->json('taxes.1.base'));
        $this->assertEquals(9.98, $calcul->json('taxes.1.montant'));
    }

    public function test_groupe_exonere_sans_taxe(): void
    {
        $this->connecter(Tenant::factory()->create());
        $groupe = $this->groupe('EXO', []);
        $this->assertEquals(0, $groupe['taux_effectif']);

        $this->postJson("/api/groupes-taxes/{$groupe['id']}/calculer", ['montant_ht' => 250])
            ->assertOk()
            ->assertJsonPath('taxes', [])
            ->assertJsonPath('montant_ttc', 250);
    }

    public function test_validations(): void
    {
        $a = Tenant::factory()->create();
        $taxeA = app(TenantContext::class)->runAsTenant($a->id, fn () => TenantTaxe::create(['nom' => 'TVA A', 'taux' => 20]));

        $this->connecter(Tenant::factory()->create());
        $tva = $this->taxe('TVA', 20);

        $this->postJson('/api/groupes-taxes', ['code' => 'X', 'nom' => 'X', 'lignes' => [['tenant_taxe_id' => $tva, 'applique_sur' => 'taxe_precedente']]])
            ->assertStatus(422)->assertJsonValidationErrors(['lignes.0.applique_sur']);
        $this->postJson('/api/groupes-taxes', ['code' => 'X', 'nom' => 'X', 'lignes' => [['tenant_taxe_id' => $taxeA->id, 'applique_sur' => 'base']]])
            ->assertStatus(422)->assertJsonValidationErrors(['lignes.0.tenant_taxe_id']);
        $this->postJson('/api/groupes-taxes', ['code' => 'X', 'nom' => 'X', 'lignes' => [
            ['tenant_taxe_id' => $tva, 'applique_sur' => 'base'],
            ['tenant_taxe_id' => $tva, 'applique_sur' => 'base'],
        ]])->assertStatus(422)->assertJsonValidationErrors(['lignes.0.tenant_taxe_id']);
        $this->postJson('/api/groupes-taxes', ['code' => 'X', 'nom' => 'X', 'lignes' => [['tenant_taxe_id' => $tva, 'applique_sur' => 'ailleurs']]])
            ->assertStatus(422)->assertJsonValidationErrors(['lignes.0.applique_sur']);
        $this->postJson('/api/groupes-taxes', ['code' => 'X', 'nom' => 'X'])
            ->assertStatus(422)->assertJsonValidationErrors(['lignes']);

        $this->groupe('TVA20', [['tenant_taxe_id' => $tva, 'applique_sur' => 'base']]);
        $this->postJson('/api/groupes-taxes', ['code' => 'TVA20', 'nom' => 'Doublon', 'lignes' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    public function test_modifier_remplace_la_composition(): void
    {
        $this->connecter(Tenant::factory()->create());
        $tps = $this->taxe('TPS', 5);
        $tvq = $this->taxe('TVQ', 9.975);
        $groupe = $this->groupe('QC', [['tenant_taxe_id' => $tps, 'applique_sur' => 'base']]);

        $this->putJson("/api/groupes-taxes/{$groupe['id']}", ['code' => 'QC', 'nom' => 'Québec', 'lignes' => [
            ['tenant_taxe_id' => $tvq, 'applique_sur' => 'base'],
            ['tenant_taxe_id' => $tps, 'applique_sur' => 'base'],
        ]])
            ->assertOk()
            ->assertJsonPath('nom', 'Québec')
            ->assertJsonPath('lignes.0.taxe.nom', 'TVQ')
            ->assertJsonPath('lignes.1.taxe.nom', 'TPS')
            ->assertJsonPath('taux_effectif', 14.975);

        $this->assertSame(2, GroupeTaxeLigne::where('groupe_taxe_id', $groupe['id'])->count());
    }

    public function test_un_seul_groupe_par_defaut(): void
    {
        $this->connecter(Tenant::factory()->create());
        $premier = $this->groupe('A', [], ['par_defaut' => true]);
        $second = $this->groupe('B', [], ['par_defaut' => true]);

        $parDefaut = collect($this->getJson('/api/groupes-taxes')->json())->where('par_defaut', true)->pluck('id')->all();
        $this->assertSame([$second['id']], $parDefaut);
        $this->assertNotSame($premier['id'], $second['id']);
    }

    public function test_taxe_utilisee_dans_un_groupe_non_supprimable(): void
    {
        $this->connecter(Tenant::factory()->create());
        $utilisee = $this->taxe('TVA', 20);
        $libre = $this->taxe('Autre', 5);
        $groupe = $this->groupe('TVA20', [['tenant_taxe_id' => $utilisee, 'applique_sur' => 'base']]);

        $this->deleteJson("/api/taxes/{$utilisee}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cette taxe est utilisée dans un groupe de taxes.');
        // Comportement inchangé pour une taxe qui n'est dans aucun groupe.
        $this->deleteJson("/api/taxes/{$libre}")->assertOk();

        // Une fois le groupe supprimé, sa taxe n'est plus utilisée.
        $this->deleteJson("/api/groupes-taxes/{$groupe['id']}")->assertOk();
        GroupeTaxeLigne::where('groupe_taxe_id', $groupe['id'])->delete();
        $this->deleteJson("/api/taxes/{$utilisee}")->assertOk();
    }

    public function test_isolation_entre_entreprises(): void
    {
        $a = Tenant::factory()->create();
        $groupeA = app(TenantContext::class)->runAsTenant($a->id, fn () => GroupeTaxe::create(['code' => 'A', 'nom' => 'Chez A']));

        $this->connecter(Tenant::factory()->create());
        $this->assertSame([], $this->getJson('/api/groupes-taxes')->json());
        $this->putJson("/api/groupes-taxes/{$groupeA->id}", ['code' => 'A', 'nom' => 'Piraté', 'lignes' => []])->assertNotFound();
        $this->postJson("/api/groupes-taxes/{$groupeA->id}/calculer", ['montant_ht' => 100])->assertNotFound();
        $this->deleteJson("/api/groupes-taxes/{$groupeA->id}")->assertNotFound();

        foreach ([GroupeTaxe::class, GroupeTaxeLigne::class] as $modele) {
            $this->assertArrayHasKey(TenantScope::class, (new $modele)->getGlobalScopes());
        }
    }

    public function test_arrondi_au_centime(): void
    {
        $this->assertSame(9.98, CalculTaxes::arrondir(9.975));
        $this->assertSame(0.01, CalculTaxes::arrondir(0.005));
        $this->assertSame(-9.98, CalculTaxes::arrondir(-9.975));
        $this->assertSame(1.23, CalculTaxes::arrondir(1.234));
    }
}
