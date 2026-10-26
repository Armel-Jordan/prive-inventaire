<?php

namespace Tests\Feature\Securite;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\CourrielEnvoi;
use App\Models\Tenant;
use App\Support\Droits;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lectures et écritures rattachées à d'autres données, longtemps ouvertes à tout compte de l'entreprise :
 * - le journal des modifications (valeurs avant et après de toutes les fiches) demande le droit de voir l'historique ;
 * - les remarques rapides se lisent librement (pour les insérer) mais se gèrent avec le droit de configurer ;
 * - les pièces jointes suivent leur fiche : la voir pour consulter ses pièces, la modifier pour en ajouter ou en
 *   retirer ; la liste de toutes les pièces ne montre que celles des fiches que le compte voit ;
 * - les courriels envoyés : ceux d'un document avec le droit de voir ce document, tout l'historique avec le droit
 *   de voir la configuration.
 */
class DroitsDonneesRattacheesTest extends TestCase
{
    use RefreshDatabase;

    private const VOIR = ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false];

    private const MODIFIER = ['can_view' => true, 'can_create' => true, 'can_edit' => true, 'can_delete' => false];

    private Tenant $tenant;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->clientId = $this->chezLeTenant(fn () => Client::create([
            'code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue du Test', 'ville' => 'Douala', 'code_postal' => '00000',
        ])->id);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function administrateur(): void
    {
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    /** Compte dont le seul groupe est un rôle de l'entreprise réglé avec ces droits (module => droits de base). */
    private function compteAvecDroits(array $permissions): void
    {
        $this->administrateur();
        $roleId = $this->postJson('/api/roles-custom', ['nom' => 'Rôle '.count($permissions).'-'.uniqid(), 'permissions' => $permissions])
            ->assertCreated()->json('role_id');
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->postJson("/api/users/{$compte->id}/assign-role", ['role_id' => $roleId])->assertOk();

        app(Droits::class)->oublier();
        Sanctum::actingAs($compte->fresh(), ['*']);
    }

    private function joindre(): TestResponse
    {
        return $this->postJson('/api/pieces-jointes', [
            'entite' => 'client', 'entite_id' => $this->clientId, 'fichiers' => [UploadedFile::fake()->create('contrat.pdf', 12, 'application/pdf')],
        ]);
    }

    public function test_le_journal_des_modifications_demande_le_droit_de_voir_l_historique(): void
    {
        $this->compteAvecDroits(['clients' => self::VOIR]);
        $this->getJson('/api/audit')->assertForbidden()->assertJsonPath('module', 'historique');
        $this->getJson('/api/audit/stats')->assertForbidden();
        $this->getJson('/api/audit/client/'.$this->clientId)->assertForbidden();

        $this->compteAvecDroits(['historique' => self::VOIR]);
        $this->getJson('/api/audit')->assertOk();
        $this->getJson('/api/audit/stats')->assertOk();
    }

    public function test_remarques_rapides_lues_par_tous_et_gerees_avec_la_configuration(): void
    {
        $remarque = ['categorie' => 'Livraison', 'texte' => 'Livrer avant midi.'];

        $this->compteAvecDroits(['clients' => self::VOIR]);
        $this->getJson('/api/remarques-rapides')->assertOk();
        $this->postJson('/api/remarques-rapides', $remarque)->assertForbidden()->assertJsonPath('module', 'configuration');

        $this->compteAvecDroits(['configuration' => ['can_delete' => true] + self::MODIFIER]);
        $id = $this->postJson('/api/remarques-rapides', $remarque)->assertCreated()->json('id');
        $this->putJson("/api/remarques-rapides/{$id}", ['texte' => 'Livrer avant 11 h.'])->assertOk();
        $this->deleteJson("/api/remarques-rapides/{$id}")->assertOk();
    }

    public function test_les_pieces_jointes_suivent_les_droits_de_leur_fiche(): void
    {
        $this->administrateur();
        $pieceId = $this->joindre()->assertCreated()->json('0.id');

        // Voit les clients sans pouvoir les modifier : consulte les pièces, sans en ajouter ni en retirer.
        $this->compteAvecDroits(['clients' => self::VOIR]);
        $this->getJson("/api/pieces-jointes?entite=client&entite_id={$this->clientId}")->assertOk()->assertJsonCount(1, 'data');
        $this->get("/api/pieces-jointes/{$pieceId}/fichier")->assertOk();
        $this->joindre()->assertForbidden()->assertJsonPath('module', 'clients')->assertJsonPath('action', 'modifier');
        $this->putJson("/api/pieces-jointes/{$pieceId}", ['description' => 'Signé'])->assertForbidden();
        $this->deleteJson("/api/pieces-jointes/{$pieceId}")->assertForbidden();

        // Ne voit pas les clients : leurs pièces restent cachées, même dans la liste de toutes les pièces.
        $this->compteAvecDroits(['produits' => self::VOIR]);
        $this->getJson("/api/pieces-jointes?entite=client&entite_id={$this->clientId}")->assertForbidden()->assertJsonPath('module', 'clients');
        $this->getJson('/api/pieces-jointes?entite=client')->assertForbidden();
        $this->getJson('/api/pieces-jointes')->assertOk()->assertJsonCount(0, 'data');
        $this->get("/api/pieces-jointes/{$pieceId}/fichier")->assertForbidden();

        // Peut modifier les clients : ajoute, décrit et retire.
        $this->compteAvecDroits(['clients' => self::MODIFIER]);
        $this->getJson('/api/pieces-jointes')->assertOk()->assertJsonCount(1, 'data');
        $this->joindre()->assertCreated();
        $this->putJson("/api/pieces-jointes/{$pieceId}", ['description' => 'Signé'])->assertOk();
        $this->deleteJson("/api/pieces-jointes/{$pieceId}")->assertOk();
    }

    public function test_les_courriels_envoyes_suivent_le_droit_de_voir_leur_document(): void
    {
        $envoiId = $this->chezLeTenant(fn () => CourrielEnvoi::create([
            'type' => 'facture', 'document_id' => 999, 'document_numero' => 'FAC-0001', 'destinataires' => ['achats@client.cm'],
            'expediteur_adresse' => 'noreply@exemple.com', 'objet' => 'Facture', 'corps_html' => '<p>Bonjour</p>', 'corps_texte' => 'Bonjour',
            'statut' => 'envoye',
        ])->id);

        // Voit les factures : l'historique de la facture et ses envois, pas celui de toute l'entreprise ni des rapports.
        $this->compteAvecDroits(['factures' => self::VOIR]);
        $this->getJson('/api/courriels/envois?type=facture&document_id=999')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/courriels/envois/{$envoiId}")->assertOk()->assertJsonPath('corps_texte', 'Bonjour');
        $this->getJson('/api/courriels/envois')->assertForbidden()->assertJsonPath('module', 'configuration');
        $this->getJson('/api/courriels/envois?type=rapport&rapport=ventes-par-client')->assertForbidden()->assertJsonPath('module', 'rapports');
        // Le droit est vérifié avant de chercher le document (inexistant ici).
        $this->getJson('/api/courriels/preparer?type=facture&document_id=999')->assertNotFound();

        // Ne voit pas les factures : ni leurs envois, ni la préparation d'un courriel.
        $this->compteAvecDroits(['clients' => self::VOIR]);
        $this->getJson('/api/courriels/envois?type=facture&document_id=999')->assertForbidden()->assertJsonPath('module', 'factures');
        $this->getJson("/api/courriels/envois/{$envoiId}")->assertForbidden();
        $this->getJson('/api/courriels/preparer?type=facture&document_id=999')->assertForbidden();

        // L'écran Courriels envoyés : tout l'historique.
        $this->compteAvecDroits(['configuration' => self::VOIR]);
        $this->getJson('/api/courriels/envois')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/courriels/envois/{$envoiId}")->assertOk();
    }
}
