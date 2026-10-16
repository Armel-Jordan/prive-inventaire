<?php

namespace Tests\Feature\Recherche;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Devis;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recherche globale (plan de parité fripv2, phase 6.4) : résultats regroupés par type, pertinence (exact,
 * début, reste), modules de l'entreprise, types demandés, limite et total, validation, isolation.
 */
class RechercheGlobaleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->connecter($this->tenant);
    }

    private function connecter(Tenant $tenant): void
    {
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(['role' => 'user']), ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);
    }

    private function chez(Tenant $tenant, callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($tenant->id, $action);
    }

    private function rechercher(string $requete): array
    {
        return collect($this->getJson("/api/recherche?{$requete}")->assertOk()->json('groupes'))->keyBy('type')->all();
    }

    public function test_resultats_regroupes_par_type_avec_details(): void
    {
        $this->chez($this->tenant, function () {
            ProduitTenant::create(['numero' => 'CIM-50', 'description' => 'Ciment 50 kg', 'categorie' => 'Liants', 'prix_unitaire' => 100]);
            $client = Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Cimenterie du Port', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0']);
            Fournisseur::create(['code' => 'F-1', 'raison_sociale' => 'Cimencam', 'email' => 'ventes@cimencam.cm']);
            Devis::create(['numero' => 'DEV-0001', 'client_id' => $client->id, 'date_devis' => '2026-10-15', 'date_validite' => '2026-11-15', 'statut' => 'envoye', 'montant_total' => 1500]);
            ProduitTenant::create(['numero' => 'FER-10', 'description' => 'Fer à béton']);
        });

        $groupes = $this->rechercher('q=cim');

        $this->assertSame(['produits', 'clients', 'fournisseurs', 'devis'], array_keys($groupes));
        $produit = $groupes['produits']['resultats'][0];
        $this->assertSame('CIM-50 — Ciment 50 kg', $produit['titre']);
        $this->assertEquals(['categorie' => 'Liants', 'prix' => 100, 'mesure' => 'UN', 'secteur' => null, 'actif' => true], $produit['details']);
        $this->assertSame('/produits?recherche=CIM-50', $produit['lien']);
        // Clients et fournisseurs : le résultat ouvre leur fiche.
        $this->assertSame("/clients/{$groupes['clients']['resultats'][0]['id']}", $groupes['clients']['resultats'][0]['lien']);
        $this->assertSame("/fournisseurs/{$groupes['fournisseurs']['resultats'][0]['id']}", $groupes['fournisseurs']['resultats'][0]['lien']);
        // Le devis est trouvé par le nom de son client.
        $this->assertEquals(['DEV-0001', 'Cimenterie du Port', 'envoye', 1500], [
            $groupes['devis']['resultats'][0]['titre'], $groupes['devis']['resultats'][0]['sous_titre'],
            $groupes['devis']['resultats'][0]['details']['statut'], $groupes['devis']['resultats'][0]['details']['montant'],
        ]);
    }

    public function test_pertinence_limite_et_total(): void
    {
        $this->chez($this->tenant, function () {
            foreach (['ZAB', 'AB-2', 'AB', 'XAB-1', 'XAB-2', 'XAB-3', 'XAB-4'] as $numero) {
                ProduitTenant::create(['numero' => $numero, 'description' => "Produit {$numero}"]);
            }
        });

        $produits = $this->rechercher('q=AB')['produits'];
        $this->assertSame(7, $produits['total']);
        // Exact, puis commençant par le texte, puis le reste par numéro ; 5 résultats par défaut.
        $this->assertSame(['AB', 'AB-2', 'XAB-1', 'XAB-2', 'XAB-3'], array_map(fn ($r) => explode(' — ', $r['titre'])[0], $produits['resultats']));
        $this->assertCount(2, $this->rechercher('q=AB&limite=2')['produits']['resultats']);
    }

    public function test_modules_types_demandes_validation_et_isolation(): void
    {
        $sansAchats = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->chez($sansAchats, function () {
            Fournisseur::create(['code' => 'F-1', 'raison_sociale' => 'Cimencam']);
            ProduitTenant::create(['numero' => 'CIM-50', 'description' => 'Ciment']);
        });
        $this->chez($this->tenant, fn () => ProduitTenant::create(['numero' => 'CIM-99', 'description' => 'Ciment ailleurs']));

        $this->connecter($sansAchats);
        // Module Achats inactif : pas de fournisseurs ; produits d'une autre entreprise invisibles.
        $groupes = $this->rechercher('q=cim');
        $this->assertSame(['produits'], array_keys($groupes));
        $this->assertSame(['CIM-50 — Ciment'], array_column($groupes['produits']['resultats'], 'titre'));

        // Seuls les types demandés (ceux que l'utilisateur peut consulter) sont cherchés.
        $this->assertSame([], $this->rechercher('q=cim&types=clients,secteurs'));

        $this->getJson('/api/recherche?q=c')->assertUnprocessable()->assertJsonValidationErrors(['q' => 'Saisissez au moins 2 caractères.']);
        $this->getJson('/api/recherche')->assertUnprocessable();
    }
}
