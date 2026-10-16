<?php

namespace Tests\Feature\PiecesJointes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\PieceJointe;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pièces jointes (plan de parité fripv2, phase 0.7) : plusieurs fichiers par fiche, stockage sous un
 * nom aléatoire, types dangereux refusés, téléchargement sûr, isolation entre entreprises.
 */
class PiecesJointesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->clientId = $this->chezLeTenant(fn () => $this->client('CLI-1', 'Client Test')->id);
        $this->connecter($this->tenant);
    }

    private function connecter(Tenant $tenant): void
    {
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(['nom' => 'Paul Mbarga']), ['*']);
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

    private function joindre(array $fichiers, array $donnees = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/pieces-jointes', $donnees + ['entite' => 'client', 'entite_id' => $this->clientId, 'fichiers' => $fichiers]);
    }

    public function test_plusieurs_fichiers_joints_a_une_fiche_sous_un_nom_aleatoire(): void
    {
        $this->joindre(
            [UploadedFile::fake()->create('contrat signé.pdf', 120, 'application/pdf'), UploadedFile::fake()->image('photo.png')],
            ['description' => 'Dossier 2026', 'categorie' => 'Contrats'],
        )->assertCreated()
            ->assertJsonCount(2)
            ->assertJsonPath('0.nom_original', 'contrat signé.pdf')
            ->assertJsonPath('0.description', 'Dossier 2026')
            ->assertJsonPath('0.categorie', 'Contrats')
            ->assertJsonPath('0.affichable', true)
            ->assertJsonPath('0.auteur', 'Paul Mbarga')
            ->assertJsonMissingPath('0.chemin');

        $chemins = PieceJointe::withoutTenantScope()->pluck('chemin');
        foreach ($chemins as $chemin) {
            Storage::disk('local')->assertExists($chemin);
            $this->assertStringStartsWith("pieces-jointes/{$this->tenant->id}/client/{$this->clientId}/", $chemin);
            $this->assertStringNotContainsString('contrat', $chemin);
        }

        $this->getJson("/api/pieces-jointes?entite=client&entite_id={$this->clientId}")->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.libelle_entite', 'Client Test');
    }

    public function test_une_description_par_fichier(): void
    {
        $this->joindre([UploadedFile::fake()->image('recto.jpg'), UploadedFile::fake()->image('verso.jpg')], [
            'description' => 'Commune', 'descriptions' => ['Recto de la pièce', ''],
        ])->assertCreated()
            ->assertJsonPath('0.description', 'Recto de la pièce')
            ->assertJsonPath('1.description', 'Commune');
    }

    public function test_executables_et_fichiers_trop_gros_refuses_sans_rien_stocker(): void
    {
        $this->joindre([UploadedFile::fake()->create('facture.pdf.exe', 10)])->assertUnprocessable()->assertJsonValidationErrors('fichiers.0');
        $this->joindre([UploadedFile::fake()->create('script.PS1', 1)])->assertUnprocessable()->assertJsonValidationErrors('fichiers.0');

        config(['pieces_jointes.taille_max_ko' => 100]);
        $this->joindre([UploadedFile::fake()->create('gros.pdf', 200, 'application/pdf')])->assertUnprocessable()
            ->assertJsonValidationErrors(['fichiers.0' => 'Le fichier dépasse la taille maximale autorisée.']);

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, PieceJointe::withoutTenantScope()->count());
    }

    public function test_fiche_d_une_autre_entreprise_introuvable_et_module_requis(): void
    {
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $clientAutre = app(TenantContext::class)->runAsTenant($autre->id, fn () => $this->client('CLI-9', 'Autre')->id);

        $this->joindre([UploadedFile::fake()->create('a.pdf', 1)], ['entite_id' => $clientAutre])->assertNotFound();
        // Les fournisseurs dépendent du module Achats, absent ici.
        $this->joindre([UploadedFile::fake()->create('a.pdf', 1)], ['entite' => 'fournisseur', 'entite_id' => 1])->assertForbidden();
        $this->getJson('/api/pieces-jointes?entite=fournisseur')->assertForbidden();
    }

    public function test_telechargement_du_fichier_d_origine_et_types_a_risque_neutralises(): void
    {
        [$texte, $html] = $this->joindre([
            UploadedFile::fake()->createWithContent('notes.txt', 'Bonjour'),
            UploadedFile::fake()->createWithContent('page.html', '<script>alert(1)</script>'),
        ])->assertCreated()->json('*.id');

        $reponse = $this->get("/api/pieces-jointes/{$texte}/fichier")->assertOk();
        $this->assertStringStartsWith('text/plain', $reponse->headers->get('content-type'));
        $this->assertStringContainsString('attachment; filename=notes.txt', $reponse->headers->get('content-disposition'));
        $this->assertSame('Bonjour', $reponse->streamedContent());

        // Une page HTML n'est jamais servie comme telle : elle se télécharge.
        $reponse = $this->get("/api/pieces-jointes/{$html}/fichier")->assertOk();
        $this->assertSame('application/octet-stream', $reponse->headers->get('content-type'));
        $this->assertSame('nosniff', $reponse->headers->get('x-content-type-options'));
    }

    public function test_modification_suppression_et_isolation(): void
    {
        $id = $this->joindre([UploadedFile::fake()->create('releve.pdf', 5, 'application/pdf')])->json('0.id');
        $chemin = PieceJointe::withoutTenantScope()->findOrFail($id)->chemin;

        $this->putJson("/api/pieces-jointes/{$id}", ['description' => 'Relevé de septembre', 'categorie' => 'Factures'])->assertOk()
            ->assertJsonPath('description', 'Relevé de septembre')->assertJsonPath('categorie', 'Factures');
        $this->getJson('/api/pieces-jointes/categories?entite=client')->assertOk()->assertExactJson(['Factures']);

        // Une autre entreprise ne voit, ne télécharge ni ne supprime la pièce.
        $this->connecter(Tenant::factory()->create(['modules' => ['ventes']]));
        $this->getJson('/api/pieces-jointes')->assertOk()->assertJsonPath('total', 0);
        $this->get("/api/pieces-jointes/{$id}/fichier")->assertNotFound();
        $this->deleteJson("/api/pieces-jointes/{$id}")->assertNotFound();
        Storage::disk('local')->assertExists($chemin);

        $this->connecter($this->tenant);
        $this->deleteJson("/api/pieces-jointes/{$id}")->assertOk();
        Storage::disk('local')->assertMissing($chemin);
        $this->assertSame(0, PieceJointe::withoutTenantScope()->count());
    }

    public function test_recherche_dans_toute_l_entreprise_avec_le_nom_de_la_fiche(): void
    {
        $produitId = $this->chezLeTenant(fn () => ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Ciment 50 kg', 'prix_unitaire' => 100])->id);
        $this->joindre([UploadedFile::fake()->create('contrat-cadre.pdf', 5, 'application/pdf')], ['categorie' => 'Contrats'])->assertCreated();
        $this->joindre([UploadedFile::fake()->image('fiche technique.jpg')], ['entite' => 'produit', 'entite_id' => $produitId, 'description' => 'Contrat fournisseur ciment'])->assertCreated();
        $this->joindre([UploadedFile::fake()->create('autre.pdf', 5, 'application/pdf')])->assertCreated();
        // Pièce d'un module inactif (achats) : masquée de la liste globale.
        $this->chezLeTenant(fn () => PieceJointe::create([
            'entite' => 'fournisseur', 'entite_id' => 1, 'nom_original' => 'contrat-fournisseur.pdf', 'disque' => 'local', 'chemin' => 'x.pdf',
        ]));

        $resultat = $this->getJson('/api/pieces-jointes?recherche=contrat')->assertOk()->assertJsonPath('total', 2)->json('data');

        $this->assertEqualsCanonicalizing(['Client Test', 'PRD-1 — Ciment 50 kg'], array_column($resultat, 'libelle_entite'));
        $this->getJson('/api/pieces-jointes?entite=produit')->assertOk()->assertJsonPath('total', 1);
    }
}
