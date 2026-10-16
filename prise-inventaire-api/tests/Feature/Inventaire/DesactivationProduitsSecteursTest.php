<?php

namespace Tests\Feature\Inventaire;

use App\Models\AdminUser;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * « Supprimer » un produit ou un secteur le désactive : il quitte les listes de choix, se retrouve par le filtre
 * « Désactivés » et se réactive ; son numéro (ou son code) repris à la création donne un message qui l'explique.
 */
class DesactivationProduitsSecteursTest extends TestCase
{
    use RefreshDatabase;

    private int $secteurId;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::factory()->create();
        $this->secteurId = app(TenantContext::class)->runAsTenant($tenant->id, fn () => Secteur::create(['code' => 'A1', 'nom' => 'Allée 1', 'actif' => true])->id);
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(), ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);
    }

    public function test_un_produit_desactive_se_retrouve_et_se_reactive(): void
    {
        $id = $this->postJson('/api/produits', ['numero' => 'P-1', 'description' => 'Ciment', 'secteur_id' => $this->secteurId])
            ->assertCreated()->json('produit.id');

        $this->deleteJson("/api/produits/{$id}")->assertOk();
        $this->assertSame([], collect($this->getJson('/api/produits')->json())->pluck('id')->all());
        $this->assertSame([$id], collect($this->getJson('/api/produits?statut=inactifs')->json())->pluck('id')->all());

        $this->postJson('/api/produits', ['numero' => 'P-1', 'description' => 'Ciment bis', 'secteur_id' => $this->secteurId])
            ->assertUnprocessable()
            ->assertJsonPath('errors.numero.0', 'Le numéro « P-1 » appartient au produit désactivé « Ciment » : réactivez-le (filtre « Désactivés » de la liste des produits).');

        $this->putJson("/api/produits/{$id}", ['actif' => true])->assertOk();
        $this->assertSame([$id], collect($this->getJson('/api/produits')->json())->pluck('id')->all());
    }

    public function test_un_secteur_desactive_se_retrouve_et_se_reactive(): void
    {
        $this->deleteJson("/api/secteurs/{$this->secteurId}")->assertOk();
        $this->assertSame([], collect($this->getJson('/api/secteurs')->json())->pluck('id')->all());
        $this->assertSame([$this->secteurId], collect($this->getJson('/api/secteurs?statut=tous')->json())->pluck('id')->all());

        $this->postJson('/api/secteurs', ['code' => 'A1', 'nom' => 'Nouvelle allée'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Le code « A1 » appartient au secteur désactivé « Allée 1 » : réactivez-le (filtre « Désactivés » de la liste des secteurs).');

        $this->putJson("/api/secteurs/{$this->secteurId}", ['actif' => true])->assertOk();
        $this->assertSame([$this->secteurId], collect($this->getJson('/api/secteurs')->json())->pluck('id')->all());
    }
}
