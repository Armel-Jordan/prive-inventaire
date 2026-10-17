<?php

namespace Tests\Feature\Inventaire;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Fabricant;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fiche produit enrichie (phase 2.4) : fabricant et référence chez lui, description longue, descriptions traduites,
 * image principale (une image jointe, pas de SVG) ; l'espace client les montre dans la langue du visiteur, avec
 * l'image ; isolation.
 */
class FicheProduitTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $secteurId;

    private int $fabricantId;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        Cache::flush();
        $this->tenant = Tenant::factory()->create(['slug' => 'quincaillerie', 'modules' => ['ventes']]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
        app(TenantContext::class)->runAsTenant($this->tenant->id, function () {
            TenantParametres::create(['nom_entreprise' => 'Quincaillerie du Port']);
            $this->secteurId = Secteur::create(['code' => 'A1', 'nom' => 'Allée 1', 'actif' => true])->id;
            $this->fabricantId = Fabricant::create(['nom' => 'Brasserie du Nord', 'actif' => true])->id;
            $this->clientId = Client::create(['code' => 'C-1', 'raison_sociale' => 'Bar du Port', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'actif' => true])->id;
        });
    }

    private function joindre(int $produit, UploadedFile $fichier): int
    {
        return $this->postJson('/api/pieces-jointes', ['entite' => 'produit', 'entite_id' => $produit, 'fichiers' => [$fichier]])->assertCreated()->json('0.id');
    }

    /** Ouvre l'espace client à un compte du client et renvoie son jeton de session. */
    private function sessionPortail(): string
    {
        $origine = 'http://127.0.0.1:5185';
        $this->putJson('/api/portail-clients/parametres', ['actif' => true, 'origine' => $origine])->assertOk();
        $lien = $this->postJson('/api/portail-clients/comptes', ['client_id' => $this->clientId, 'nom' => 'Awa', 'email' => 'awa@bar.test', 'origine' => $origine])
            ->assertCreated()->json('lien');
        parse_str((string) parse_url($lien, PHP_URL_QUERY), $parametres);
        $jeton = $this->postJson('/api/portail/quincaillerie/activation', ['jeton' => $parametres['jeton'], 'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1'])
            ->assertOk()->json('token');
        $this->app['auth']->forgetGuards();

        return $jeton;
    }

    public function test_fiche_enrichie_et_espace_client(): void
    {
        // Fabricant, référence, description longue et traduction anglaise.
        $produit = $this->postJson('/api/produits', ['numero' => 'BIERE', 'description' => 'Bière blonde 33 cl', 'secteur_id' => $this->secteurId,
            'prix_unitaire' => 100, 'fabricant_id' => $this->fabricantId, 'reference_fabricant' => 'BDN-33', 'description_longue' => 'Bière de garde, 5 % vol.',
            'traductions' => [['langue' => 'en', 'description' => 'Blond beer 33 cl', 'description_longue' => 'Lager, 5% ABV.']]])->assertCreated()->json('produit');
        $this->assertEquals(['Brasserie du Nord', 'BDN-33', 'Bière de garde, 5 % vol.', 'en', 'Blond beer 33 cl'], [$produit['fabricant']['nom'], $produit['reference_fabricant'],
            $produit['description_longue'], $produit['traductions'][0]['langue'], $produit['traductions'][0]['description']]);
        $this->postJson('/api/produits', ['numero' => 'X', 'description' => 'X', 'secteur_id' => $this->secteurId, 'image_id' => 1])->assertUnprocessable()
            ->assertJsonValidationErrors(['image_id' => 'Choisissez une image jointe à cette fiche produit.']);
        $this->postJson('/api/produits', ['numero' => 'Y', 'description' => 'Y', 'secteur_id' => $this->secteurId, 'traductions' => [['langue' => 'de', 'description' => 'Bier']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['traductions.0.langue']);

        // Image principale : une image jointe à cette fiche, pas un SVG ni l'image d'un autre produit.
        $png = $this->joindre($produit['id'], UploadedFile::fake()->create('biere.png', 20, 'image/png'));
        $svg = $this->joindre($produit['id'], UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml'));
        $autre = $this->postJson('/api/produits', ['numero' => 'VIN', 'description' => 'Vin rouge', 'secteur_id' => $this->secteurId, 'prix_unitaire' => 200])->assertCreated()->json('produit.id');
        $imageAutre = $this->joindre($autre, UploadedFile::fake()->create('vin.png', 20, 'image/png'));
        foreach ([$svg, $imageAutre] as $refusee) {
            $this->putJson("/api/produits/{$produit['id']}", ['image_id' => $refusee])->assertUnprocessable()
                ->assertJsonValidationErrors(['image_id' => 'Choisissez une image jointe à cette fiche produit.']);
        }
        $this->putJson("/api/produits/{$produit['id']}", ['image_id' => $png])->assertOk()->assertJsonPath('produit.image_id', $png);
        $liste = collect($this->getJson('/api/produits')->assertOk()->json())->firstWhere('numero', 'BIERE');
        $this->assertSame(['Brasserie du Nord', 'Blond beer 33 cl'], [$liste['fabricant']['nom'], $liste['traductions'][0]['description']]);

        // Fabricant supprimé du référentiel depuis : la fiche se réenregistre telle quelle, un autre produit ne peut plus le choisir.
        app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => Fabricant::query()->whereKey($this->fabricantId)->delete());
        $this->putJson("/api/produits/{$produit['id']}", ['description' => 'Bière blonde 33 cl', 'fabricant_id' => $this->fabricantId])->assertOk()
            ->assertJsonPath('produit.fabricant.nom', 'Brasserie du Nord');
        $this->postJson('/api/produits', ['numero' => 'Z', 'description' => 'Z', 'secteur_id' => $this->secteurId, 'fabricant_id' => $this->fabricantId])
            ->assertUnprocessable()->assertJsonValidationErrors(['fabricant_id']);

        // Espace client : dans la langue du visiteur (sinon la fiche), avec la description longue, le fabricant, l'image.
        $jeton = $this->sessionPortail();
        $catalogue = fn (string $parametres = '') => collect($this->withHeaders(['Authorization' => "Bearer {$jeton}"])
            ->getJson('/api/espace-client/catalogue'.$parametres)->assertOk()->json('data'))->keyBy('numero');
        $enAnglais = $catalogue('?langue=en');
        $this->assertEquals(['Blond beer 33 cl', 'Lager, 5% ABV.', 'Brasserie du Nord', 'BDN-33', true], [$enAnglais['BIERE']['description'],
            $enAnglais['BIERE']['description_longue'], $enAnglais['BIERE']['fabricant'], $enAnglais['BIERE']['reference_fabricant'], $enAnglais['BIERE']['image']]);
        $this->assertSame(['Vin rouge', false], [$enAnglais['VIN']['description'], $enAnglais['VIN']['image']]);
        $this->assertSame('Bière blonde 33 cl', $catalogue()['BIERE']['description']);
        $this->assertSame(['BIERE'], $catalogue('?langue=en&recherche=blond')->keys()->all());
        $image = $this->withHeaders(['Authorization' => "Bearer {$jeton}"])->get("/api/espace-client/produits/{$produit['id']}/image")->assertOk();
        $this->assertSame('image/png', $image->headers->get('Content-Type'));
        $this->withHeaders(['Authorization' => "Bearer {$jeton}"])->get("/api/espace-client/produits/{$autre}/image")->assertNotFound();

        // Traduction vidée : retirée ; le visiteur anglais lit la fiche.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeaders(['Authorization' => null]);
        $this->putJson("/api/produits/{$produit['id']}", ['traductions' => [['langue' => 'en', 'description' => '', 'description_longue' => '']]])
            ->assertOk()->assertJsonCount(0, 'produit.traductions');
    }

    public function test_isolation(): void
    {
        $produit = $this->postJson('/api/produits', ['numero' => 'BIERE', 'description' => 'Bière', 'secteur_id' => $this->secteurId, 'fabricant_id' => $this->fabricantId])
            ->assertCreated()->json('produit.id');
        $autre = Tenant::factory()->create();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        // Le fabricant d'une autre entreprise n'existe pas ici.
        $secteur = app(TenantContext::class)->runAsTenant($autre->id, fn () => Secteur::create(['code' => 'B1', 'nom' => 'Allée B', 'actif' => true])->id);
        $this->postJson('/api/produits', ['numero' => 'X', 'description' => 'X', 'secteur_id' => $secteur, 'fabricant_id' => $this->fabricantId])
            ->assertUnprocessable()->assertJsonValidationErrors(['fabricant_id']);
        $this->getJson("/api/produits/{$produit}")->assertNotFound();
    }
}
