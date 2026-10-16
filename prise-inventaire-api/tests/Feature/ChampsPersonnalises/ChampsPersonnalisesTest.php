<?php

namespace Tests\Feature\ChampsPersonnalises;

use App\Models\AdminUser;
use App\Models\ChampPersonnaliseValeur;
use App\Models\Client;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Champs personnalisés (plan de parité fripv2, phase 0.8) : définitions par type de fiche, valeurs
 * validées selon le type, valeur par défaut, doublons interdits, recherche, isolation.
 */
class ChampsPersonnalisesTest extends TestCase
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

    private function champ(array $donnees): int
    {
        return $this->postJson('/api/champs-personnalises', $donnees + ['entite' => 'client', 'type' => 'texte'])->assertCreated()->json('id');
    }

    private function enregistrer(int $clientId, array $valeurs): \Illuminate\Testing\TestResponse
    {
        return $this->putJson('/api/champs-personnalises/valeurs', ['entite' => 'client', 'entite_id' => $clientId, 'valeurs' => $valeurs]);
    }

    public function test_definitions_par_type_de_fiche_avec_code_et_ordre(): void
    {
        $premier = $this->postJson('/api/champs-personnalises', ['entite' => 'facture', 'type' => 'texte', 'libelle' => 'No projet'])->assertCreated()
            ->assertJsonPath('code', 'no_projet')->assertJsonPath('ordre', 1)->json('id');
        $second = $this->postJson('/api/champs-personnalises', ['entite' => 'facture', 'type' => 'date', 'libelle' => 'No projet'])->assertCreated()
            ->assertJsonPath('code', 'no_projet_2')->assertJsonPath('ordre', 2)->json('id');
        $this->champ(['libelle' => 'No projet']);

        $this->getJson('/api/champs-personnalises?entite=facture')->assertOk()->assertJsonCount(2)->assertJsonPath('0.id', $premier);
        $this->putJson('/api/champs-personnalises/ordre', ['entite' => 'facture', 'ids' => [$second, $premier]])->assertOk()
            ->assertJsonPath('0.id', $second)->assertJsonPath('1.id', $premier);
    }

    public function test_liste_exige_des_choix_et_une_valeur_par_defaut_valide(): void
    {
        $this->postJson('/api/champs-personnalises', ['entite' => 'client', 'type' => 'liste', 'libelle' => 'Type de client'])
            ->assertUnprocessable()->assertJsonValidationErrors(['options' => 'Ajoutez au moins un choix à la liste.']);
        $this->postJson('/api/champs-personnalises', ['entite' => 'client', 'type' => 'liste', 'libelle' => 'Type de client', 'options' => ['Particulier', 'Entreprise'], 'valeur_defaut' => 'Organisme'])
            ->assertUnprocessable()->assertJsonValidationErrors('valeur_defaut');
        $this->postJson('/api/champs-personnalises', ['entite' => 'client', 'type' => 'liste', 'libelle' => 'Type de client', 'options' => ['Particulier', 'Entreprise'], 'valeur_defaut' => 'Particulier'])
            ->assertCreated()->assertJsonPath('valeur_defaut', 'Particulier')->assertJsonPath('options', ['Particulier', 'Entreprise']);
    }

    public function test_le_type_et_la_fiche_ne_changent_pas_a_la_modification(): void
    {
        $id = $this->champ(['libelle' => 'No contrat']);

        $this->putJson("/api/champs-personnalises/{$id}", ['libelle' => 'Numéro de contrat', 'type' => 'nombre', 'entite' => 'facture', 'obligatoire' => true])->assertOk()
            ->assertJsonPath('libelle', 'Numéro de contrat')->assertJsonPath('type', 'texte')->assertJsonPath('entite', 'client')->assertJsonPath('obligatoire', true);
    }

    public function test_valeurs_avec_defaut_et_validation_selon_le_type(): void
    {
        $contrat = $this->champ(['libelle' => 'No contrat', 'obligatoire' => true]);
        $plafond = $this->champ(['libelle' => 'Plafond', 'type' => 'nombre', 'format' => 'montant', 'decimales' => 2]);
        $visite = $this->champ(['libelle' => 'Dernière visite', 'type' => 'date']);
        $vip = $this->champ(['libelle' => 'VIP', 'type' => 'case', 'valeur_defaut' => true]);
        $categorie = $this->champ(['libelle' => 'Type', 'type' => 'liste', 'options' => ['Particulier', 'Entreprise']]);
        $services = $this->champ(['libelle' => 'Services', 'type' => 'liste_multiple', 'options' => ['Maintenance', 'Formation', 'Audit']]);
        $courriel = $this->champ(['libelle' => 'Courriel compta', 'type' => 'courriel']);
        $site = $this->champ(['libelle' => 'Site', 'type' => 'url']);
        $telephone = $this->champ(['libelle' => 'Standard', 'type' => 'telephone']);

        // Rien de saisi : la valeur par défaut est proposée.
        $champs = collect($this->getJson("/api/champs-personnalises/valeurs?entite=client&entite_id={$this->clientA}")->assertOk()->json())->keyBy('id');
        $this->assertTrue($champs[$vip]['valeur']);
        $this->assertFalse($champs[$vip]['saisie']);
        $this->assertSame([], $champs[$services]['valeur']);

        $this->enregistrer($this->clientA, [
            $plafond => 'abc', $categorie => 'Organisme', $services => ['Maintenance', 'Inconnu'],
            $courriel => 'pas-une-adresse', $site => 'ftp://exemple.com', $telephone => 'appelez-moi',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                "valeurs.{$contrat}" => 'Le champ « No contrat » est obligatoire.',
                "valeurs.{$plafond}" => 'Le champ « Plafond » doit être un nombre.',
            ])
            ->assertJsonValidationErrors(["valeurs.{$categorie}", "valeurs.{$services}.1", "valeurs.{$courriel}", "valeurs.{$site}", "valeurs.{$telephone}"]);

        $this->enregistrer($this->clientA, [
            $contrat => 'CT-2026-118', $plafond => 1500.5, $visite => '2026-10-15', $vip => false, $categorie => 'Entreprise',
            $services => ['Maintenance', 'Audit'], $courriel => 'compta@client-a.cm', $site => 'https://client-a.cm', $telephone => '+237 6 99 00 00 00',
        ])->assertOk();

        $champs = collect($this->getJson("/api/champs-personnalises/valeurs?entite=client&entite_id={$this->clientA}")->json())->keyBy('id');
        $this->assertSame('CT-2026-118', $champs[$contrat]['valeur']);
        $this->assertEquals(1500.5, $champs[$plafond]['valeur']);
        $this->assertSame('2026-10-15', $champs[$visite]['valeur']);
        $this->assertFalse($champs[$vip]['valeur']);
        $this->assertTrue($champs[$vip]['saisie']);
        $this->assertSame(['Maintenance', 'Audit'], $champs[$services]['valeur']);

        // Vider une valeur la retire.
        $this->enregistrer($this->clientA, [$contrat => 'CT-2026-118', $site => ''])->assertOk();
        $this->assertNull(collect($this->getJson("/api/champs-personnalises/valeurs?entite=client&entite_id={$this->clientA}")->json())->keyBy('id')[$site]['valeur']);
    }

    public function test_une_valeur_sans_doublon_ne_sert_qu_une_fiche(): void
    {
        $siret = $this->champ(['libelle' => 'N° SIRET', 'sans_doublon' => true]);

        $this->enregistrer($this->clientA, [$siret => 'ABC123'])->assertOk();
        $this->enregistrer($this->clientA, [$siret => 'ABC123'])->assertOk();
        $this->enregistrer($this->clientB, [$siret => 'abc123'])->assertUnprocessable()
            ->assertJsonValidationErrors(["valeurs.{$siret}" => '« N° SIRET » : cette valeur est déjà utilisée par une autre fiche.']);
    }

    public function test_recherche_des_fiches_par_valeur(): void
    {
        $contrat = $this->champ(['libelle' => 'No contrat']);
        $services = $this->champ(['libelle' => 'Services', 'type' => 'liste_multiple', 'options' => ['Maintenance', 'Formation']]);
        $plafond = $this->champ(['libelle' => 'Plafond', 'type' => 'nombre']);
        $this->enregistrer($this->clientA, [$contrat => 'CT-2026-118', $services => ['Maintenance'], $plafond => 1500])->assertOk();
        $this->enregistrer($this->clientB, [$contrat => 'CT-2025-001', $services => ['Formation'], $plafond => 900])->assertOk();

        $this->getJson("/api/champs-personnalises/recherche?champ_id={$contrat}&valeur=ct-2026")->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.entite_id', $this->clientA)->assertJsonPath('0.libelle_entite', 'Client A');
        $this->getJson("/api/champs-personnalises/recherche?champ_id={$services}&valeur=Formation")->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.libelle_entite', 'Client B');
        $this->getJson("/api/champs-personnalises/recherche?champ_id={$plafond}&valeur=1500.00")->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.valeur', 1500);
    }

    public function test_suppression_d_un_champ_et_champ_inactif(): void
    {
        $contrat = $this->champ(['libelle' => 'No contrat', 'obligatoire' => true]);
        $note = $this->champ(['libelle' => 'Note']);
        $this->enregistrer($this->clientA, [$contrat => 'CT-1', $note => 'À rappeler'])->assertOk();

        // Inactif : masqué des fiches et plus exigé.
        $this->putJson("/api/champs-personnalises/{$contrat}", ['libelle' => 'No contrat', 'obligatoire' => true, 'actif' => false])->assertOk();
        $this->getJson("/api/champs-personnalises/valeurs?entite=client&entite_id={$this->clientB}")->assertOk()->assertJsonCount(1);
        $this->enregistrer($this->clientB, [$note => 'Rien'])->assertOk();

        $this->deleteJson("/api/champs-personnalises/{$note}")->assertOk();
        $this->assertSame(1, ChampPersonnaliseValeur::withoutTenantScope()->count());
    }

    public function test_fiche_d_une_autre_entreprise_et_module_requis(): void
    {
        $id = $this->champ(['libelle' => 'No contrat']);
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $clientAutre = app(TenantContext::class)->runAsTenant($autre->id, fn () => $this->client('CLI-Z', 'Autre')->id);

        $this->getJson("/api/champs-personnalises/valeurs?entite=client&entite_id={$clientAutre}")->assertNotFound();
        // Les fournisseurs dépendent du module Achats, absent ici.
        $this->postJson('/api/champs-personnalises', ['entite' => 'fournisseur', 'type' => 'texte', 'libelle' => 'Code'])->assertForbidden();

        $this->connecter($autre);
        $this->getJson('/api/champs-personnalises')->assertOk()->assertJsonCount(0);
        $this->putJson("/api/champs-personnalises/{$id}", ['libelle' => 'Piraté'])->assertNotFound();
    }
}
