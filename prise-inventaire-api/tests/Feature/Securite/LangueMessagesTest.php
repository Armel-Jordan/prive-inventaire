<?php

namespace Tests\Feature\Securite;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Tenant;
use App\Support\TenantContext;
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

    private Tenant $tenant;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Paul Vendeur']);
        $this->agir($this->admin);
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
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

    public function test_les_messages_des_controleurs_des_droits_et_des_services_suivent_la_langue_de_l_ecran(): void
    {
        $fr = ['Accept-Language' => 'fr'];
        $en = ['Accept-Language' => 'en'];

        // Message d'un contrôleur.
        $this->postJson('/api/verrous', ['entite' => 'client', 'entite_id' => 999999], $fr)->assertNotFound()->assertJsonPath('message', 'Fiche introuvable.');
        $this->postJson('/api/verrous', ['entite' => 'client', 'entite_id' => 999999], $en)->assertNotFound()->assertJsonPath('message', 'Record not found.');

        // Message d'un service (fiche en cours de modification par un autre compte), avec son paramètre.
        $client = app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => Client::create([
            'code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0',
        ]));
        $this->postJson('/api/verrous', ['entite' => 'client', 'entite_id' => $client->id], $fr)->assertOk();
        $this->agir(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Awa Acheteuse']));
        $this->putJson("/api/clients/{$client->id}", ['raison_sociale' => 'Autre'], $en)->assertStatus(409)
            ->assertJsonPath('message', 'This record is being edited by Paul Vendeur: try again once the editing is finished.');
        $this->putJson("/api/clients/{$client->id}", ['raison_sociale' => 'Autre'], $fr)->assertStatus(409)
            ->assertJsonPath('message', 'Cette fiche est en cours de modification par Paul Vendeur : réessayez quand la modification sera terminée.');

        // Refus de droit : libellés du module et de l'action dans la langue de l'écran.
        $lecteur = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->agir($this->admin);
        $this->putJson("/api/acces-comptes/{$lecteur->id}", ['groupes' => [], 'exceptions' => ['verrous.voir' => false]])->assertOk();
        $this->agir($lecteur);
        $this->getJson('/api/verrous', $fr)->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de consulter dans « Fiches en cours de modification ».');
        $this->getJson('/api/verrous', $en)->assertForbidden()
            ->assertJsonPath('message', 'Insufficient rights: your role does not allow you to view items in “Records being edited”.');
    }
}
