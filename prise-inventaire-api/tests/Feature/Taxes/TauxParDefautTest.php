<?php

namespace Tests\Feature\Taxes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Taux de taxe par défaut de l'entreprise (paramètres, ou taxe marquée « par défaut ») appliqué aux lignes sans
 * taux ni groupe de taxes : commandes, factures, conversion d'un devis. Les documents prenaient 20 % en dur.
 */
class TauxParDefautTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->produitId] = $this->chezLeTenant(fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue du Test', 'ville' => 'Douala', 'code_postal' => '00000'])->id,
            ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function tauxEntreprise(float $taux): void
    {
        $this->chezLeTenant(fn () => TenantParametres::create(['tva_taux' => $taux]));
    }

    private function ligne(): array
    {
        return ['produit_id' => $this->produitId, 'quantite' => 10, 'prix_unitaire_ht' => 100];
    }

    public function test_une_commande_et_une_facture_sans_taux_prennent_celui_de_l_entreprise(): void
    {
        $this->tauxEntreprise(19.25);

        $this->postJson('/api/commandes-client', ['client_id' => $this->clientId, 'date_commande' => '2026-10-16', 'lignes' => [$this->ligne()]])
            ->assertCreated()
            ->assertJsonPath('lignes.0.taux_tva', fn ($taux) => (float) $taux === 19.25)
            ->assertJsonPath('montant_ttc', fn ($ttc) => abs((float) $ttc - 1192.5) < 0.01);

        $this->postJson('/api/factures', ['client_id' => $this->clientId, 'date_facture' => '2026-10-16', 'lignes' => [$this->ligne()]])
            ->assertCreated()
            ->assertJsonPath('montant_ttc', fn ($ttc) => abs((float) $ttc - 1192.5) < 0.01);
    }

    public function test_un_devis_converti_prend_le_taux_de_l_entreprise(): void
    {
        $this->tauxEntreprise(18);
        $devisId = $this->postJson('/api/devis', [
            'client_id' => $this->clientId, 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 10, 'prix_unitaire' => 100]],
        ])->assertCreated()->json('id');
        $this->postJson("/api/devis/{$devisId}/envoyer")->assertOk();
        $this->postJson("/api/devis/{$devisId}/accepter")->assertOk();

        $commandeId = $this->postJson("/api/devis/{$devisId}/convertir")->assertSuccessful()->json('commande.id')
            ?? $this->postJson("/api/devis/{$devisId}/convertir")->json('commande_id');

        $this->getJson("/api/commandes-client/{$commandeId}")->assertOk()
            ->assertJsonPath('lignes.0.taux_tva', fn ($taux) => (float) $taux === 18.0)
            ->assertJsonPath('montant_ttc', fn ($ttc) => abs((float) $ttc - 1180) < 0.01);
    }

    public function test_la_taxe_par_defaut_fixe_le_taux_de_l_entreprise(): void
    {
        $this->postJson('/api/taxes', ['nom' => 'TVA CM', 'taux' => 19.25, 'par_defaut' => true])->assertCreated();

        $this->getJson('/api/parametres')->assertOk()->assertJsonPath('tva_taux', fn ($taux) => (float) $taux === 19.25);
        $this->postJson('/api/commandes-client', ['client_id' => $this->clientId, 'date_commande' => '2026-10-16', 'lignes' => [$this->ligne()]])
            ->assertCreated()
            ->assertJsonPath('montant_ttc', fn ($ttc) => abs((float) $ttc - 1192.5) < 0.01);
    }

    public function test_sans_reglage_le_taux_reste_20(): void
    {
        $this->postJson('/api/commandes-client', ['client_id' => $this->clientId, 'date_commande' => '2026-10-16', 'lignes' => [$this->ligne()]])
            ->assertCreated()
            ->assertJsonPath('montant_ttc', fn ($ttc) => abs((float) $ttc - 1200) < 0.01);
    }
}
