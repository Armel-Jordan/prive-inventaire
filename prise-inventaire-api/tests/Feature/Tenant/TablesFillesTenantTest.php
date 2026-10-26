<?php

namespace Tests\Feature\Tenant;

use App\Models\AdminUser;
use App\Models\Concerns\BelongsToTenant;
use App\Models\DebourseLigne;
use App\Models\EncaissementLigne;
use App\Models\FactureFournisseurLigne;
use App\Models\ProduitTenant;
use App\Models\RecurrenceEcheance;
use App\Models\ReleveBancaireLien;
use App\Models\Tenant;
use App\Models\TransactionBancaireLigne;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `tenant_id` des tables filles (anomalie A14) : les sept tables qui ne l'avaient pas le portent, leurs modèles le posent
 * d'eux-mêmes et ne lisent que l'entreprise courante, et le pivot des frais reçoit celui du frais.
 */
class TablesFillesTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_tables_filles_portent_tenant_id_et_leurs_modeles_l_isolent(): void
    {
        foreach (['recurrence_echeances', 'facture_fournisseur_lignes', 'transaction_bancaire_lignes', 'encaissement_lignes',
            'debourse_lignes', 'releve_bancaire_liens', 'frais_produits'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'tenant_id'), "{$table} : tenant_id manquant");
        }
        foreach ([RecurrenceEcheance::class, FactureFournisseurLigne::class, TransactionBancaireLigne::class, EncaissementLigne::class,
            DebourseLigne::class, ReleveBancaireLien::class] as $modele) {
            $this->assertContains(BelongsToTenant::class, class_uses_recursive($modele), "{$modele} : BelongsToTenant manquant");
        }
    }

    public function test_le_pivot_des_frais_porte_l_entreprise_du_frais(): void
    {
        $tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(), ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);
        [$frais, $produit] = app(TenantContext::class)->runAsTenant($tenant->id, fn () => [
            ProduitTenant::create(['numero' => 'FRAIS', 'description' => 'Frais de manutention', 'prix_unitaire' => 0])->id,
            ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);

        $id = $this->postJson('/api/frais', [
            'libelle' => 'Manutention', 'produit_id' => $frais, 'mode' => 'montant', 'valeur' => 5,
            'application' => 'produits', 'produits' => [$produit],
        ])->assertCreated()->json('id');

        $this->assertSame([$tenant->id], DB::table('frais_produits')->where('frais_id', $id)->pluck('tenant_id')->map(fn ($v) => (int) $v)->all());
    }
}
