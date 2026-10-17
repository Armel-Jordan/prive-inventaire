<?php

namespace Tests\Feature\Achats;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Import d'une grille de prix fournisseur (phase 3.6) : article reconnu par la référence du fournisseur puis par le
 * numéro (au catalogue du fournisseur), lignes ignorées avec leur motif, simulation sans écriture, import journalisé,
 * fournisseur fixé pour tout le fichier, profils d'import ; droits et isolation.
 */
class ImportPrixFournisseursTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    /** @var array<string, int> */
    private array $produits;

    private int $f1;

    private int $f2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['achats']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Joseph Acheteur']);
        $this->chez(function () {
            $this->f1 = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimenterie du Sud', 'actif' => true])->id;
            $this->f2 = Fournisseur::create(['code' => 'F2', 'raison_sociale' => 'Sablière', 'actif' => true])->id;
            foreach (['CIM', 'FER', 'SAB', 'GRA'] as $numero) {
                $this->produits[$numero] = ProduitTenant::create(['numero' => $numero, 'description' => "Produit {$numero}", 'prix_unitaire' => 100])->id;
            }
            $lier = fn (string $produit, int $fournisseur, ?string $reference, ?float $prix) => DB::table('produit_fournisseur')->insert([
                'produit_id' => $this->produits[$produit], 'fournisseur_id' => $fournisseur, 'reference_fournisseur' => $reference, 'prix_achat' => $prix,
                'fournisseur_principal' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $lier('CIM', $this->f1, 'C-50', 80);
            $lier('FER', $this->f1, null, 900);
            $lier('SAB', $this->f2, 'S-1', 30);
        });
        $this->agir($this->admin);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte, ?Tenant $tenant = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', ($tenant ?? $this->tenant)->slug);
    }

    private function prix(string $produit, int $fournisseur): ?float
    {
        $prix = DB::table('produit_fournisseur')->where('produit_id', $this->produits[$produit])->where('fournisseur_id', $fournisseur)->value('prix_achat');

        return $prix === null ? null : (float) $prix;
    }

    private function importer(string $contenu, array $reglages, bool $simuler)
    {
        return $this->post('/api/fournisseurs/import-prix', $reglages + [
            'fichier' => UploadedFile::fake()->createWithContent('prix.csv', $contenu), 'simuler' => $simuler ? '1' : '0',
        ], ['Accept' => 'application/json']);
    }

    public function test_simulation_import_et_profils(): void
    {
        $fichier = "\u{FEFF}Fournisseur;Article;Prix;Note\n"
            ."F1;c-50;85,50;réf. SKU\n"
            ."f1;FER;1 200,00 €;par numéro\n"
            ."F1;GRA;10;pas au catalogue\n"
            ."F9;CIM;10;fournisseur inconnu\n"
            ."F2;S-1;abc;coût illisible\n"
            ."F2;S-1;-5;coût négatif\n"
            ."F2;XYZ;10;produit inconnu\n"
            ."\n"
            ."F2;SAB;30;inchangé\n"
            ."F1;C-50;86;doublon\n";
        $colonnes = ['colonne_fournisseur' => 1, 'colonne_code' => 2, 'colonne_cout' => 3, 'ligne_debut' => 2];

        // Simulation : le rapport, rien n'est écrit.
        $simulation = $this->importer($fichier, $colonnes, true)->assertOk()->json();
        $this->assertSame('Simulation : 2 prix à mettre à jour, 1 inchangé(s), 6 ligne(s) ignorée(s).', $simulation['message']);
        $this->assertEquals([['CIM', 80, 85.5], ['FER', 900, 1200]], array_map(fn ($c) => [$c['produit'], $c['ancien'], $c['nouveau']], $simulation['changements']));
        $this->assertSame([
            [4, 'Produit non rattaché à ce fournisseur'], [5, 'Fournisseur introuvable avec ce numéro'], [6, 'Coût illisible ou négatif'],
            [7, 'Coût illisible ou négatif'], [8, 'Produit introuvable'], [11, 'Déjà présent à la ligne 2'],
        ], array_map(fn ($i) => [$i['ligne'], $i['motif']], $simulation['ignorees']));
        $this->assertEquals([80, 900], [$this->prix('CIM', $this->f1), $this->prix('FER', $this->f1)]);

        // Import : les prix du catalogue changent, chaque changement est journalisé.
        $this->importer($fichier, $colonnes, false)->assertOk()->assertJsonPath('message', '2 prix mis à jour, 1 inchangé(s), 6 ligne(s) ignorée(s).');
        $this->assertEquals([85.5, 1200, 30], [$this->prix('CIM', $this->f1), $this->prix('FER', $this->f1), $this->prix('SAB', $this->f2)]);
        $journal = $this->chez(fn () => AuditLog::where('model_type', 'Prix fournisseur')->orderBy('id')->get());
        $this->assertSame(['F1/CIM', 'F1/FER'], $journal->pluck('model_id')->all());
        $this->assertEquals([80, 85.5, 'Joseph Acheteur'], [$journal[0]->old_values['prix_achat'], $journal[0]->new_values['prix_achat'], $journal[0]->user_name]);

        // Fichier d'un seul fournisseur, sans colonne fournisseur ; sans colonne ni fournisseur fixé, refusé.
        $this->importer("S-1,32.5\n", ['fournisseur_id' => $this->f2, 'colonne_code' => 1, 'colonne_cout' => 2, 'ligne_debut' => 1], false)
            ->assertOk()->assertJsonPath('mises_a_jour', 1);
        $this->assertEquals(32.5, $this->prix('SAB', $this->f2));
        $this->importer("S-1;1\n", ['colonne_code' => 1, 'colonne_cout' => 2], true)->assertUnprocessable()
            ->assertJsonValidationErrors(['colonne_fournisseur' => 'Indiquez la colonne du code fournisseur, ou choisissez le fournisseur de tout le fichier.']);
        $this->importer('', $colonnes, true)->assertUnprocessable()->assertJsonValidationErrors('fichier');

        // Profils d'import : enregistrés, remplacés à nom égal, listés, supprimés.
        $profil = $this->postJson('/api/profils-import', ['type' => 'prix_fournisseur', 'nom' => 'Cimenterie', 'reglages' => $colonnes])->assertCreated()->json();
        $this->postJson('/api/profils-import', ['type' => 'prix_fournisseur', 'nom' => 'Cimenterie', 'reglages' => ['colonne_code' => 2, 'colonne_cout' => 4, 'colonne_fournisseur' => 1]])
            ->assertOk()->assertJsonPath('reglages.colonne_cout', 4);
        $this->assertSame([['Cimenterie', 4]], array_map(fn ($p) => [$p['nom'], $p['reglages']['colonne_cout']], $this->getJson('/api/profils-import?type=prix_fournisseur')->assertOk()->json()));
        $this->deleteJson("/api/profils-import/{$profil['id']}")->assertOk();
        $this->assertSame([], $this->getJson('/api/profils-import?type=prix_fournisseur')->json());
    }

    public function test_droits_et_isolation(): void
    {
        $acheteur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$acheteur->id}", ['groupes' => [], 'exceptions' => ['fournisseurs.importer_prix' => false]])->assertOk();
        $this->agir($acheteur->fresh());
        $this->importer("F1;C-50;90\n", ['colonne_fournisseur' => 1, 'colonne_code' => 2, 'colonne_cout' => 3, 'ligne_debut' => 1], true)->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Importer une grille de prix fournisseur » (Fournisseurs).');
        $this->postJson('/api/profils-import', ['type' => 'prix_fournisseur', 'nom' => 'X', 'reglages' => ['colonne_code' => 1, 'colonne_cout' => 2]])->assertForbidden();

        $this->agir($this->admin);
        $modules = collect(collect($this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets'))->firstWhere('cle', 'achats')['modules'])->keyBy('cle');
        $this->assertContains('fournisseurs.importer_prix', array_column($modules['fournisseurs']['permissions'], 'code'));

        // Une autre entreprise : ses codes ne touchent pas les fournisseurs de celle-ci, et son fournisseur fixé doit être le sien.
        $autre = Tenant::factory()->create(['modules' => ['achats']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->importer("F1;C-50;90\n", ['colonne_fournisseur' => 1, 'colonne_code' => 2, 'colonne_cout' => 3, 'ligne_debut' => 1], false)
            ->assertOk()->assertJsonPath('ignorees.0.motif', 'Fournisseur introuvable avec ce numéro');
        $this->importer("C-50;90\n", ['fournisseur_id' => $this->f1, 'colonne_code' => 1, 'colonne_cout' => 2, 'ligne_debut' => 1], false)
            ->assertUnprocessable()->assertJsonValidationErrors('fournisseur_id');
        $this->assertEquals(80, $this->prix('CIM', $this->f1));
    }
}
