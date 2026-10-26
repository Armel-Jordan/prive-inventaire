<?php

namespace Tests\Feature\Referentiels;

use App\Models\AdminUser;
use App\Models\Camion;
use App\Models\Client;
use App\Models\ConditionPaiement;
use App\Models\Devise;
use App\Models\EmployeTenant;
use App\Models\Entrepot;
use App\Models\Fabricant;
use App\Models\Itineraire;
use App\Models\ProduitTenant;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Models\Unite;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Référentiels utilisés (anomalie A15) : une fiche encore portée par des produits, des clients, des documents ou des
 * secteurs ne se supprime pas (la suppression douce la retirait en silence des listes) ; elle se désactive. Une fois
 * libérée, elle se supprime. Et le livreur d'une tournée est un employé de l'entreprise (anomalie A13).
 */
class ReferentielsUtilisesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function client(array $attributs = []): Client
    {
        return Client::create($attributs + ['code' => 'CLI-'.uniqid(), 'raison_sociale' => 'Client', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '00000']);
    }

    public function test_fabricant_et_unite_portes_par_un_produit(): void
    {
        [$fabricant, $unite, $produit] = $this->chez(function () {
            $fabricant = Fabricant::create(['nom' => 'Cimenterie du Sud']);
            $unite = Unite::create(['code' => 'SAC', 'libelle' => 'Sac']);

            return [$fabricant, $unite, ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100, 'fabricant_id' => $fabricant->id, 'mesure' => 'SAC'])];
        });

        $this->deleteJson("/api/referentiels/fabricants/{$fabricant->id}")->assertStatus(422)
            ->assertJsonPath('message', 'Ce fabricant est rattaché à des produits : désactivez-le plutôt.');
        $this->deleteJson("/api/referentiels/unites/{$unite->id}")->assertStatus(422)
            ->assertJsonPath('message', 'Cette unité est utilisée par des produits : désactivez-la plutôt.');

        // Le produit retiré, plus rien ne les porte.
        $this->chez(fn () => $produit->delete());
        $this->deleteJson("/api/referentiels/fabricants/{$fabricant->id}")->assertOk();
        $this->deleteJson("/api/referentiels/unites/{$unite->id}")->assertOk();
    }

    public function test_condition_devise_et_itineraire_portes_par_un_client(): void
    {
        [$condition, $devise, $itineraire] = $this->chez(function () {
            $condition = ConditionPaiement::create(['code' => 'N30', 'libelle' => '30 jours', 'delai_jours' => 30]);
            $devise = Devise::create(['code' => 'USD', 'libelle' => 'Dollar', 'taux' => 600]);
            $itineraire = Itineraire::create(['code' => 'R1', 'nom' => 'Route 1']);
            $this->client(['condition_paiement_id' => $condition->id, 'devise' => 'USD', 'itineraire_id' => $itineraire->id]);

            return [$condition, $devise, $itineraire];
        });

        $this->deleteJson("/api/referentiels/conditions_paiement/{$condition->id}")->assertStatus(422)
            ->assertJsonPath('message', 'Cette condition de paiement est utilisée par des clients ou des factures : désactivez-la plutôt.');
        $this->deleteJson("/api/referentiels/devises/{$devise->id}")->assertStatus(422);
        $this->deleteJson("/api/referentiels/itineraires/{$itineraire->id}")->assertStatus(422)
            ->assertJsonPath('message', 'Cet itinéraire est utilisé par des clients ou des tournées : désactivez-le plutôt.');

        // Désactiver reste possible.
        $this->putJson("/api/referentiels/itineraires/{$itineraire->id}", ['code' => 'R1', 'nom' => 'Route 1', 'actif' => false])->assertOk();
    }

    public function test_entrepot_porte_par_un_secteur_et_fiches_libres_supprimables(): void
    {
        [$entrepot, $libre] = $this->chez(function () {
            $entrepot = Entrepot::create(['code' => 'E1', 'nom' => 'Dépôt', 'type' => 'depot']);
            Secteur::create(['code' => 'A1', 'nom' => 'Allée 1', 'entrepot_id' => $entrepot->id]);

            return [$entrepot, Entrepot::create(['code' => 'E2', 'nom' => 'Annexe', 'type' => 'depot'])];
        });

        $this->deleteJson("/api/referentiels/entrepots/{$entrepot->id}")->assertStatus(422);
        $this->deleteJson("/api/referentiels/entrepots/{$libre->id}")->assertOk();
    }

    public function test_le_livreur_d_une_tournee_est_un_employe_de_l_entreprise(): void
    {
        $camion = $this->chez(fn () => Camion::create(['immatriculation' => 'LT-001', 'actif' => true]));
        $autre = Tenant::factory()->create();
        $etranger = app(TenantContext::class)->runAsTenant($autre->id, fn () => EmployeTenant::create(['numero' => 'E-1', 'nom' => 'Ailleurs', 'prenom' => 'Jean']));
        $employe = $this->chez(fn () => EmployeTenant::create(['numero' => 'E-1', 'nom' => 'Ndiaye', 'prenom' => 'Awa']));

        $this->postJson('/api/tournees', ['date_tournee' => '2026-10-26', 'camion_id' => $camion->id, 'livreur_id' => $etranger->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['livreur_id']);
        $this->postJson('/api/tournees', ['date_tournee' => '2026-10-26', 'camion_id' => $camion->id, 'livreur_id' => $employe->id])
            ->assertCreated();
    }
}
