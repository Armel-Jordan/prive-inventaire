<?php

namespace Tests\Feature\Securite;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Devis;
use App\Models\Tenant;
use App\Support\Droits;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Commentaires des fiches et des documents (plan de parité fripv2, socle S4.6) : signés et datés, non-lus par compte,
 * avec le droit de voir la fiche ; chacun ne retire que les siens ; rien d'une autre entreprise.
 */
class CommentairesTest extends TestCase
{
    use RefreshDatabase;

    private const VOIR = ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false];

    private Tenant $tenant;

    private AdminUser $awa;

    private AdminUser $paul;

    private int $devisId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->awa = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Awa']);
        $this->paul = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Paul']);
        $this->devisId = app(TenantContext::class)->runAsTenant($this->tenant->id, function () {
            $client = Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => 'Rue', 'ville' => 'Douala', 'code_postal' => '0']);

            return Devis::create(['numero' => 'DEV-1', 'client_id' => $client->id, 'date_devis' => '2026-11-10', 'date_validite' => '2026-12-10', 'statut' => 'brouillon', 'montant_total' => 0])->id;
        });
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->reset();
        app(Droits::class)->oublier();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $compte->tenant->slug ?? $this->tenant->slug);
    }

    private function fiche(): string
    {
        return "entite=devis&entite_id={$this->devisId}";
    }

    public function test_ecrire_lire_et_non_lus(): void
    {
        $this->agir($this->awa);
        $this->postJson('/api/commentaires', ['entite' => 'devis', 'entite_id' => $this->devisId, 'texte' => '  Le client veut une remise.  '])
            ->assertCreated()->assertJsonPath('texte', 'Le client veut une remise.')->assertJsonPath('auteur', 'Awa')->assertJsonPath('moi', true);
        $this->postJson('/api/commentaires', ['entite' => 'devis', 'entite_id' => $this->devisId, 'texte' => ''])->assertUnprocessable();

        // Paul : un non-lu, puis lu une fois ouvert.
        $this->agir($this->paul);
        $this->getJson("/api/commentaires/compteurs?entite=devis&ids={$this->devisId},999")->assertOk()
            ->assertJsonPath("{$this->devisId}.total", 1)->assertJsonPath("{$this->devisId}.non_lus", 1)->assertJsonMissingPath('999');
        $this->getJson('/api/commentaires?'.$this->fiche())->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.non_lu', true)->assertJsonPath('0.moi', false)->assertJsonPath('0.auteur', 'Awa');
        $this->getJson("/api/commentaires/compteurs?entite=devis&ids={$this->devisId}")->assertJsonPath("{$this->devisId}.non_lus", 0);
        $reponse = $this->postJson('/api/commentaires', ['entite' => 'devis', 'entite_id' => $this->devisId, 'texte' => 'Accordé : 5 %.'])->assertCreated()->json('id');

        // Awa : la réponse de Paul est non lue ; ses propres commentaires ne le sont jamais.
        $this->agir($this->awa);
        $this->getJson("/api/commentaires/compteurs?entite=devis&ids={$this->devisId}")->assertJsonPath("{$this->devisId}.total", 2)->assertJsonPath("{$this->devisId}.non_lus", 1);

        // Chacun ne retire que les siens.
        $this->deleteJson("/api/commentaires/{$reponse}")->assertForbidden();
        $this->agir($this->paul);
        $this->deleteJson("/api/commentaires/{$reponse}")->assertOk();
        $this->getJson('/api/commentaires?'.$this->fiche())->assertJsonCount(1);
    }

    public function test_droits_fiche_inconnue_et_isolation(): void
    {
        // Sans le droit de voir les devis : refusé.
        $this->agir($this->awa);
        $roleId = $this->postJson('/api/roles-custom', ['nom' => 'Clients seulement', 'permissions' => ['clients' => self::VOIR]])->assertCreated()->json('role_id');
        $lecteur = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->postJson("/api/users/{$lecteur->id}/assign-role", ['role_id' => $roleId])->assertOk();
        $this->agir($lecteur->fresh());
        $this->getJson('/api/commentaires?'.$this->fiche())->assertForbidden();
        $this->postJson('/api/commentaires', ['entite' => 'devis', 'entite_id' => $this->devisId, 'texte' => 'Vu'])->assertForbidden();

        // Fiche inconnue ou type inconnu.
        $this->agir($this->awa);
        $this->getJson('/api/commentaires?entite=devis&entite_id=999')->assertNotFound();
        $this->getJson('/api/commentaires?entite=inconnue&entite_id=1')->assertUnprocessable();

        // Une autre entreprise ne voit pas ce devis.
        $this->postJson('/api/commentaires', ['entite' => 'devis', 'entite_id' => $this->devisId, 'texte' => 'Interne'])->assertCreated();
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']));
        $this->getJson('/api/commentaires?'.$this->fiche())->assertNotFound();
        $this->assertSame([], (array) $this->getJson("/api/commentaires/compteurs?entite=devis&ids={$this->devisId}")->assertOk()->json());
    }
}
