<?php

namespace Tests\Feature\Balises;

use App\Models\AdminUser;
use App\Models\BaliseAffectation;
use App\Models\BaliseItem;
use App\Models\Client;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Balises et remarques rapides (plan de parité fripv2, phase 0.9) : balises à un ou plusieurs choix,
 * pose sur les fiches, recherche, items inactifs, isolation ; textes réutilisables.
 */
class BalisesEtRemarquesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientA;

    private int $clientB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientA, $this->clientB] = $this->chezLeTenant(fn () => [
            $this->client('CLI-A', 'Client A')->id,
            $this->client('CLI-B', 'Client B')->id,
        ]);
        $this->connecter($this->tenant);
    }

    private function connecter(Tenant $tenant): void
    {
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(), ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function client(string $code, string $nom): Client
    {
        return Client::create(['code' => $code, 'raison_sociale' => $nom, 'adresse_facturation' => '1 rue du Test', 'ville' => 'Douala', 'code_postal' => '00000']);
    }

    /** @return array{0: int, 1: list<int>} id de la balise et ids de ses items */
    private function balise(string $nom, string $type, array $items, array $entites = ['client']): array
    {
        $id = $this->postJson('/api/balises', ['nom' => $nom, 'type' => $type, 'entites' => $entites])->assertCreated()->json('id');

        return [$id, array_map(fn (string $libelle) => $this->postJson("/api/balises/{$id}/items", ['libelle' => $libelle])->assertCreated()->json('id'), $items)];
    }

    private function poser(int $clientId, array $items): \Illuminate\Testing\TestResponse
    {
        return $this->putJson('/api/balises/affectations', ['entite' => 'client', 'entite_id' => $clientId, 'items' => $items]);
    }

    public function test_balise_avec_items_et_types_de_fiche(): void
    {
        [$region] = $this->balise('Région', 'choix', ['Nord', 'Sud'], ['client', 'fournisseur']);
        $this->postJson("/api/balises/{$region}/items", ['libelle' => ' nord '])->assertUnprocessable()
            ->assertJsonValidationErrors(['libelle' => 'Cet item existe déjà dans la balise.']);
        $this->postJson("/api/balises/{$region}/items", ['libelle' => 'Est', 'couleur' => 'violet'])->assertCreated()->assertJsonPath('couleur', 'violet');
        $this->postJson('/api/balises', ['nom' => 'Sans fiche', 'type' => 'choix', 'entites' => []])->assertUnprocessable()->assertJsonValidationErrors('entites');

        $this->getJson('/api/balises?entite=client')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.nom', 'Région')->assertJsonPath('0.items.0.libelle', 'Nord')->assertJsonCount(3, '0.items');
        $this->getJson('/api/balises?entite=produit')->assertOk()->assertJsonCount(0);
    }

    public function test_pose_sur_une_fiche_avec_choix_unique(): void
    {
        [, [$nord, $sud]] = $this->balise('Région', 'choix', ['Nord', 'Sud']);
        [, [$maintenance, $audit]] = $this->balise('Services', 'options', ['Maintenance', 'Audit']);
        [, [$saisonnier]] = $this->balise('Catégorie produit', 'options', ['Saisonnier'], ['produit']);

        $this->poser($this->clientA, [$nord, $maintenance, $audit])->assertOk()->assertJsonCount(3, 'items');
        $this->poser($this->clientA, [$nord, $sud])->assertUnprocessable()
            ->assertJsonValidationErrors(['items' => '« Région » n\'accepte qu\'un seul choix.']);
        $this->poser($this->clientA, [$saisonnier])->assertUnprocessable()->assertJsonValidationErrors('items');

        $poses = $this->getJson("/api/balises/affectations?entite=client&ids={$this->clientA},{$this->clientB}")->assertOk()->json();
        $this->assertEqualsCanonicalizing([$nord, $maintenance, $audit], $poses[$this->clientA]);
        $this->assertArrayNotHasKey($this->clientB, $poses);

        // Remplacement complet, puis item désactivé : il n'apparaît plus.
        $this->poser($this->clientA, [$sud])->assertOk();
        $this->putJson("/api/balises/items/{$sud}", ['libelle' => 'Sud', 'actif' => false])->assertOk();
        $this->assertSame([], (array) $this->getJson("/api/balises/affectations?entite=client&ids={$this->clientA}")->json());
    }

    public function test_passage_a_un_seul_choix_refuse_si_des_fiches_en_ont_plusieurs(): void
    {
        [$services, [$maintenance, $audit]] = $this->balise('Services', 'options', ['Maintenance', 'Audit']);
        $this->poser($this->clientA, [$maintenance, $audit])->assertOk();

        $this->putJson("/api/balises/{$services}", ['nom' => 'Services', 'type' => 'choix', 'entites' => ['client']])->assertUnprocessable()
            ->assertJsonValidationErrors('type');
        $this->poser($this->clientA, [$maintenance])->assertOk();
        $this->putJson("/api/balises/{$services}", ['nom' => 'Services', 'type' => 'choix', 'entites' => ['client']])->assertOk()->assertJsonPath('type', 'choix');
    }

    public function test_recherche_des_fiches_par_items(): void
    {
        [, [$nord]] = $this->balise('Région', 'choix', ['Nord', 'Sud']);
        [, [$maintenance]] = $this->balise('Services', 'options', ['Maintenance', 'Audit']);
        $this->poser($this->clientA, [$nord, $maintenance])->assertOk();
        $this->poser($this->clientB, [$nord])->assertOk();

        $un = $this->getJson("/api/balises/recherche?entite=client&items[]={$nord}&items[]={$maintenance}")->assertOk()->json();
        $this->assertEqualsCanonicalizing(['Client A', 'Client B'], array_column($un, 'libelle_entite'));
        $this->getJson("/api/balises/recherche?entite=client&mode=tous&items[]={$nord}&items[]={$maintenance}")->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.libelle_entite', 'Client A');
    }

    public function test_supprimer_un_item_ou_une_balise_retire_ses_poses(): void
    {
        [$region, [$nord, $sud]] = $this->balise('Région', 'choix', ['Nord', 'Sud']);
        $this->poser($this->clientA, [$nord])->assertOk();
        $this->poser($this->clientB, [$sud])->assertOk();

        $this->deleteJson("/api/balises/items/{$nord}")->assertOk();
        $this->assertSame(1, BaliseAffectation::withoutTenantScope()->count());

        $this->deleteJson("/api/balises/{$region}")->assertOk();
        $this->assertSame(0, BaliseAffectation::withoutTenantScope()->count());
        $this->assertSame(0, BaliseItem::withoutTenantScope()->count());
    }

    public function test_isolation_entre_entreprises_et_module_requis(): void
    {
        [, [$nord]] = $this->balise('Région', 'choix', ['Nord'], ['client', 'fournisseur']);
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $clientAutre = app(TenantContext::class)->runAsTenant($autre->id, fn () => $this->client('CLI-Z', 'Autre')->id);

        $this->poser($clientAutre, [$nord])->assertNotFound();
        // Les fournisseurs dépendent du module Achats, absent ici.
        $this->putJson('/api/balises/affectations', ['entite' => 'fournisseur', 'entite_id' => 1, 'items' => [$nord]])->assertForbidden();

        $this->connecter($autre);
        $this->getJson('/api/balises')->assertOk()->assertJsonCount(0);
        $this->putJson('/api/balises/affectations', ['entite' => 'client', 'entite_id' => $clientAutre, 'items' => [$nord]])->assertUnprocessable();
    }

    public function test_remarques_rapides(): void
    {
        $livraison = $this->postJson('/api/remarques-rapides', ['categorie' => ' Livraison ', 'texte' => 'Livrer derrière le bâtiment, porte vitrée.'])->assertCreated()
            ->assertJsonPath('categorie', 'Livraison')->json('id');
        $this->postJson('/api/remarques-rapides', ['categorie' => '', 'texte' => 'Merci de votre confiance.'])->assertCreated()->assertJsonPath('categorie', null);
        $this->postJson('/api/remarques-rapides', ['categorie' => 'Facturation', 'texte' => 'Paiement à 30 jours.'])->assertCreated();
        $this->postJson('/api/remarques-rapides', ['texte' => ''])->assertUnprocessable()->assertJsonValidationErrors('texte');

        // Catégories dans l'ordre, les non classées à la fin.
        $this->getJson('/api/remarques-rapides')->assertOk()->assertJsonCount(3)
            ->assertJsonPath('0.categorie', 'Facturation')->assertJsonPath('1.categorie', 'Livraison')->assertJsonPath('2.categorie', null);

        $this->putJson("/api/remarques-rapides/{$livraison}", ['categorie' => 'Livraison', 'texte' => 'Livrer par la porte arrière.'])->assertOk()
            ->assertJsonPath('texte', 'Livrer par la porte arrière.');

        $this->connecter(Tenant::factory()->create());
        $this->getJson('/api/remarques-rapides')->assertOk()->assertJsonCount(0);
        $this->deleteJson("/api/remarques-rapides/{$livraison}")->assertNotFound();
    }
}
