<?php

namespace Tests\Feature\Securite;

use App\Models\AdminUser;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Langue des messages de l'API (phase 6.8) : le français par défaut, l'anglais avec l'en-tête Accept-Language des
 * écrans ; une langue non prise en charge revient au français ; noms de champs lisibles en français.
 */
class LangueMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);
    }

    /**
     * Filtre mémorisé sans nom et avec un écran invalide : deux erreurs de validation. Sans langue, l'en-tête est vide
     * (une requête de test porte sinon « en-us » par défaut, comme un navigateur porte sa propre langue).
     */
    private function erreurs(?string $langue): array
    {
        return $this->postJson('/api/filtres-memorises', ['ecran' => 'Écran!', 'valeurs' => []], ['Accept-Language' => $langue ?? ''])
            ->assertUnprocessable()->json();
    }

    public function test_francais_par_defaut_et_anglais_sur_demande(): void
    {
        $francais = $this->erreurs(null);
        $this->assertSame("Le format du champ ecran n'est pas valide.", $francais['errors']['ecran'][0]);
        $this->assertSame('Le champ nom est obligatoire.', $francais['errors']['nom'][0]);
        $this->assertSame("Le format du champ ecran n'est pas valide. (et 1 autre erreur)", $francais['message']);

        $anglais = $this->erreurs('en-US,en;q=0.9,fr;q=0.8');
        $this->assertSame('The nom field is required.', $anglais['errors']['nom'][0]);
        $this->assertSame('The ecran field format is invalid. (and 1 more error)', $anglais['message']);

        // La langue préférée de l'en-tête l'emporte ; une langue non prise en charge revient au français.
        $this->assertSame('Le champ nom est obligatoire.', $this->erreurs('fr-CA,en;q=0.5')['errors']['nom'][0]);
        $this->assertSame('Le champ nom est obligatoire.', $this->erreurs('de-DE,de;q=0.9')['errors']['nom'][0]);
        $this->assertSame('The nom field is required.', $this->erreurs('de-DE,en;q=0.5')['errors']['nom'][0]);
    }

    public function test_noms_de_champs_lisibles(): void
    {
        $this->withHeader('Accept-Language', 'fr');
        $this->postJson('/api/rapports/programmes', ['code' => 'stock-produits', 'nom' => 'Stock', 'format' => 'pdf', 'langue' => 'fr',
            'destinataires' => ['pas-une-adresse'], 'frequence' => 'quotidienne'])
            ->assertUnprocessable()->assertJsonPath('errors', ['destinataires.0' => ['Adresse de courriel invalide : « pas-une-adresse ».']]);
        // Message du cadre avec le nom lisible d'un champ courant.
        $this->postJson('/api/factures', ['client_id' => 999999, 'date_facture' => '2026-10-01', 'lignes' => []])
            ->assertUnprocessable()->assertJsonPath('errors.client_id.0', "La valeur choisie pour client n'existe pas.");
    }
}
