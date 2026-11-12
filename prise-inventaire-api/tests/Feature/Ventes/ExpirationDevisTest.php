<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Devis;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Services\ReponsesDevis;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Expiration des devis au choix (plan de parité fripv2, DE3) : bloquante par défaut (le devis passe à « Expiré » et ne
 * s'accepte plus), ou simple repère (il reste envoyé et se décide toujours, en ligne aussi).
 */
class ExpirationDevisTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->ids = app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => [
            'client' => Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => 'Rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
            'ciment' => ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    /** Devis envoyé dont la validité est passée. */
    private function devisEchu(): int
    {
        $id = $this->postJson('/api/devis', ['client_id' => $this->ids['client'], 'date_devis' => '2026-10-01', 'date_validite' => '2026-10-15',
            'lignes' => [['produit_id' => $this->ids['ciment'], 'quantite' => 1, 'prix_unitaire' => 100]]])->assertCreated()->json('id');
        $this->postJson("/api/devis/{$id}/envoyer")->assertOk();

        return $id;
    }

    private function page(int $id): array
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => app(ReponsesDevis::class)->presenter(Devis::with('lignes.produit', 'client')->findOrFail($id)));
    }

    public function test_bloquante_par_defaut(): void
    {
        $id = $this->devisEchu();
        $page = $this->page($id);
        $this->assertSame(['expire', false, false], [$page['devis']['statut'], $page['devis']['validite_depassee'], $page['peut_repondre']]);

        $this->postJson("/api/devis/{$id}/accepter")->assertUnprocessable();
        $this->getJson('/api/devis')->assertOk();
        $this->getJson("/api/devis/{$id}")->assertJsonPath('statut', 'expire');
    }

    public function test_simple_repere_si_reglee(): void
    {
        $this->getJson('/api/parametres')->assertOk()->assertJsonPath('devis_expiration_bloquante', true)->assertJsonPath('devis_validite_jours', 30);
        $this->putJson('/api/parametres', ['devis_expiration_bloquante' => false, 'devis_validite_jours' => 45])->assertOk()
            ->assertJsonPath('parametres.devis_expiration_bloquante', false)->assertJsonPath('parametres.devis_validite_jours', 45);
        $this->putJson('/api/parametres', ['devis_validite_jours' => 0])->assertUnprocessable()->assertJsonValidationErrors(['devis_validite_jours']);

        // La liste ne le passe plus à « Expiré » ; la page en ligne avertit et laisse répondre ; il s'accepte.
        $id = $this->devisEchu();
        $this->getJson('/api/devis')->assertOk();
        $this->getJson("/api/devis/{$id}")->assertJsonPath('statut', 'envoye');
        $page = $this->page($id);
        $this->assertSame(['envoye', true, true], [$page['devis']['statut'], $page['devis']['validite_depassee'], $page['peut_repondre']]);
        $this->postJson("/api/devis/{$id}/accepter")->assertOk()->assertJsonPath('devis.statut', 'accepte');
    }
}
