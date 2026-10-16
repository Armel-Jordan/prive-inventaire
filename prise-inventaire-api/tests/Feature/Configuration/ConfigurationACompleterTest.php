<?php

namespace Tests\Feature\Configuration;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ProduitTenant;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Configuration à compléter (plan de parité fripv2, phase 0.11) : réglages vérifiés dans les données,
 * modules respectés, réglages recommandés écartés avec une raison, compteurs de qualité, isolation.
 */
class ConfigurationACompleterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->connecter($this->tenant);
    }

    private function connecter(Tenant $tenant, string $role = 'admin'): void
    {
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(['role' => $role, 'nom' => 'Paul Mbarga']), ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);
    }

    private function chez(Tenant $tenant, callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($tenant->id, $action);
    }

    /** @return array<string, array{faite: bool, obligatoire: bool, ignoree: ?array}> */
    private function taches(array $reponse): array
    {
        return collect($reponse['etapes'])->flatMap(fn ($etape) => collect($etape['taches'])->keyBy('cle'))->all();
    }

    public function test_une_entreprise_neuve_voit_ses_reglages_obligatoires_selon_ses_modules(): void
    {
        $reponse = $this->getJson('/api/configuration-a-completer')->assertOk()->json();

        $this->assertSame(['entreprise', 'inventaire', 'tiers', 'utilisateurs'], array_column($reponse['etapes'], 'cle'));
        $taches = $this->taches($reponse);
        // Module Ventes actif, Achats non : pas de fournisseurs.
        $this->assertArrayHasKey('clients', $taches);
        $this->assertArrayNotHasKey('fournisseurs', $taches);
        $this->assertSame(
            ['entreprise_nom', 'devise', 'secteurs', 'produits', 'clients'],
            array_keys(array_filter($taches, fn ($t) => $t['obligatoire'] && ! $t['faite'])),
        );
        $this->assertSame(5, $reponse['restantes']['obligatoires']);
        $this->assertSame('/secteurs', $taches['secteurs']['lien']);
    }

    public function test_les_reglages_se_cochent_d_eux_memes_et_se_decochent(): void
    {
        $this->chez($this->tenant, function () {
            $parametres = TenantParametres::create(['nom_entreprise' => 'Ma Société SARL']);
            // Enregistrement ultérieur : la devise est confirmée.
            $this->travel(1)->minutes();
            $parametres->update(['devise_symbole' => 'FCFA', 'devise_code' => 'XAF']);
            Secteur::create(['code' => 'A-01', 'nom' => 'Allée A']);
            ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Ciment', 'prix_unitaire' => 10]);
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0']);
        });

        $reponse = $this->getJson('/api/configuration-a-completer')->assertOk()->json();
        $this->assertSame(0, $reponse['restantes']['obligatoires']);

        // Le seul secteur supprimé : la ligne se décoche.
        $this->chez($this->tenant, fn () => Secteur::query()->forceDelete());
        $taches = $this->taches($this->getJson('/api/configuration-a-completer')->json());
        $this->assertFalse($taches['secteurs']['faite']);
    }

    public function test_compteurs_de_qualite_des_donnees(): void
    {
        $this->chez($this->tenant, function () {
            ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Sans prix', 'prix_unitaire' => 0, 'actif' => true]);
            ProduitTenant::create(['numero' => 'PRD-2', 'description' => 'Complet', 'prix_unitaire' => 10, 'actif' => true]);
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Sans courriel', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'telephone' => '600', 'actif' => true]);
        });

        $qualite = collect($this->getJson('/api/configuration-a-completer')->assertOk()->json('qualite'))->pluck('nombre', 'cle')->all();

        $this->assertSame(1, $qualite['produits_sans_prix']);
        $this->assertSame(2, $qualite['produits_sans_secteur']);
        $this->assertSame(1, $qualite['clients_sans_courriel']);
        // Compteurs à zéro ou hors module (fournisseurs) absents.
        $this->assertArrayNotHasKey('clients_sans_telephone', $qualite);
        $this->assertArrayNotHasKey('fournisseurs_sans_courriel', $qualite);
    }

    public function test_ecarter_un_reglage_recommande_avec_une_raison_puis_le_reprendre(): void
    {
        $avant = $this->getJson('/api/configuration-a-completer')->json('restantes.recommandees');

        $this->postJson('/api/configuration-a-completer/employes/ignorer', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['raison' => 'Indiquez pourquoi ce réglage ne vous concerne pas.']);
        $this->postJson('/api/configuration-a-completer/produits/ignorer', ['raison' => 'Pas de stock'])->assertUnprocessable();
        $this->postJson('/api/configuration-a-completer/inconnu/ignorer', ['raison' => 'x'])->assertNotFound();

        $reponse = $this->postJson('/api/configuration-a-completer/employes/ignorer', ['raison' => 'Nous sommes deux, sans fiches employés.'])->assertOk()->json();
        $this->assertSame(['raison' => 'Nous sommes deux, sans fiches employés.', 'par' => 'Paul Mbarga'], array_intersect_key($this->taches($reponse)['employes']['ignoree'], ['raison' => 1, 'par' => 1]));
        $this->assertSame($avant - 1, $reponse['restantes']['recommandees']);

        $reponse = $this->deleteJson('/api/configuration-a-completer/employes/ignorer')->assertOk()->json();
        $this->assertNull($this->taches($reponse)['employes']['ignoree']);
        $this->assertSame($avant, $reponse['restantes']['recommandees']);
    }

    public function test_seul_un_administrateur_ecarte_un_reglage_et_chaque_entreprise_a_le_sien(): void
    {
        $this->postJson('/api/configuration-a-completer/employes/ignorer', ['raison' => 'Pas d\'employés'])->assertOk();
        $this->chez($this->tenant, fn () => Secteur::create(['code' => 'A-01', 'nom' => 'Allée A']));

        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->connecter($autre, 'user');
        $taches = $this->taches($this->getJson('/api/configuration-a-completer')->assertOk()->json());
        $this->assertNull($taches['employes']['ignoree']);
        $this->assertFalse($taches['secteurs']['faite']);
        $this->postJson('/api/configuration-a-completer/employes/ignorer', ['raison' => 'x'])->assertForbidden();
    }
}
