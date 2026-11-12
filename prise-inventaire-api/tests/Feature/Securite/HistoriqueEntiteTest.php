<?php

namespace Tests\Feature\Securite;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ProduitTenant;
use App\Models\Representant;
use App\Models\Tenant;
use App\Support\Droits;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Historique d'une fiche ou d'un document (plan de parité fripv2, socle S4.6, bouton « Historique ») : ses créations et
 * modifications, champ par champ, avec des libellés lisibles et le nom des fiches désignées ; il faut le droit de voir
 * l'historique et celui de voir la fiche ou le document ; rien d'une autre entreprise.
 */
class HistoriqueEntiteTest extends TestCase
{
    use RefreshDatabase;

    private const VOIR = ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false];

    private Tenant $tenant;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->administrateur();
        $this->ids = $this->chez(fn () => [
            'client' => Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
            'produit' => ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
            'awa' => Representant::create(['code' => 'AWA', 'nom' => 'Awa Ndiaye', 'actif' => true])->id,
        ]);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function administrateur(): void
    {
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Admin Alpha']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function compteAvecDroits(array $permissions): void
    {
        $this->administrateur();
        $roleId = $this->postJson('/api/roles-custom', ['nom' => 'Rôle '.uniqid(), 'permissions' => $permissions])->assertCreated()->json('role_id');
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->postJson("/api/users/{$compte->id}/assign-role", ['role_id' => $roleId])->assertOk();
        app(Droits::class)->oublier();
        Sanctum::actingAs($compte->fresh(), ['*']);
    }

    public function test_creation_puis_modifications_champ_par_champ(): void
    {
        $commande = $this->postJson('/api/commandes-client', ['client_id' => $this->ids['client'], 'date_commande' => '2026-11-10',
            'lignes' => [['produit_id' => $this->ids['produit'], 'quantite' => 1, 'prix_unitaire_ht' => 100]]])->assertCreated()->json('id');
        $this->putJson("/api/commandes-client/{$commande}", ['notes' => 'Livrer le matin', 'representant_id' => $this->ids['awa']])->assertOk();

        $historique = $this->getJson("/api/audit/entite/commande_client/{$commande}")->assertOk()->json();
        $this->assertSame('create', end($historique)['action']);
        $this->assertSame([], end($historique)['champs']);
        $modification = collect($historique)->first(fn ($e) => $e['action'] === 'update' && collect($e['champs'])->contains('champ', 'notes'));
        $this->assertNotNull($modification);
        $this->assertSame('Admin Alpha', $modification['auteur']);
        $notes = collect($modification['champs'])->firstWhere('champ', 'notes');
        $this->assertSame(['notes', null, 'Livrer le matin'], [$notes['libelle'], $notes['avant'], $notes['apres']]);
        // Un champ qui désigne une autre fiche donne son nom.
        $representant = collect($historique)->flatMap(fn ($e) => $e['champs'])->firstWhere('champ', 'representant_id');
        $this->assertSame('Awa Ndiaye', $representant['apres_texte']);

        // La fiche client a aussi son historique ; une entité inconnue n'en a pas.
        $this->putJson("/api/clients/{$this->ids['client']}", ['ville' => 'Yaoundé'])->assertOk();
        $ville = collect($this->getJson("/api/audit/entite/client/{$this->ids['client']}")->assertOk()->json('0.champs'))->firstWhere('champ', 'ville');
        $this->assertSame(['Douala', 'Yaoundé'], [$ville['avant'], $ville['apres']]);
        $this->getJson('/api/audit/entite/inconnue/1')->assertNotFound();
    }

    public function test_droits_et_isolation(): void
    {
        $commande = $this->postJson('/api/commandes-client', ['client_id' => $this->ids['client'], 'date_commande' => '2026-11-10',
            'lignes' => [['produit_id' => $this->ids['produit'], 'quantite' => 1, 'prix_unitaire_ht' => 100]]])->json('id');

        // Le droit de voir l'historique ne suffit pas : il faut aussi voir le document.
        $this->compteAvecDroits(['historique' => self::VOIR]);
        $this->getJson("/api/audit/entite/commande_client/{$commande}")->assertForbidden()->assertJsonPath('module', 'commandes_client');
        $this->compteAvecDroits(['commandes_client' => self::VOIR]);
        $this->getJson("/api/audit/entite/commande_client/{$commande}")->assertForbidden()->assertJsonPath('module', 'historique');
        $this->compteAvecDroits(['historique' => self::VOIR, 'commandes_client' => self::VOIR]);
        $this->assertNotEmpty($this->getJson("/api/audit/entite/commande_client/{$commande}")->assertOk()->json());

        // Une autre entreprise ne voit rien de cette commande.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->getJson("/api/audit/entite/commande_client/{$commande}")->assertOk()->assertExactJson([]);
    }
}
