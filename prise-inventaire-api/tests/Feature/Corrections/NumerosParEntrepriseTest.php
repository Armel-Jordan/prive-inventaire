<?php

namespace Tests\Feature\Corrections;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Configuration;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Numéros de documents uniques par entreprise : chaque entreprise numérote avec ses préfixes. Avec un
 * index unique global, la deuxième entreprise qui émettait « FAC-0001 » échouait en erreur SQL.
 */
class NumerosParEntrepriseTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_uniques_par_entreprise(): void
    {
        $colonnes = [
            'clients' => 'code', 'devis' => 'numero', 'com_client_entete' => 'numero', 'com_four_entete' => 'numero',
            'factures' => 'numero', 'bons_livraison' => 'numero', 'tournees' => 'numero', 'alertes_stock' => 'produit_numero',
        ];

        foreach ($colonnes as $table => $colonne) {
            $uniques = collect(Schema::getIndexes($table))->where('unique', true)->pluck('columns');
            $this->assertContains(['tenant_id', $colonne], $uniques->all(), "{$table}.{$colonne} : unique par entreprise");
            $this->assertNotContains([$colonne], $uniques->all(), "{$table}.{$colonne} : plus d'unicité globale");
        }
    }

    public function test_deux_entreprises_emettent_chacune_leur_premiere_facture_et_leur_premier_devis(): void
    {
        foreach ([Tenant::factory()->create(['modules' => ['ventes']]), Tenant::factory()->create(['modules' => ['ventes']])] as $tenant) {
            [$clientId, $produitId] = app(TenantContext::class)->runAsTenant($tenant->id, function () {
                foreach (['facture' => 'FAC', 'devis' => 'DEV'] as $entite => $prefixe) {
                    Configuration::create(['entite' => $entite, 'prefixe' => $prefixe, 'separateur' => '-', 'longueur' => 4, 'auto_increment' => true, 'prochain_numero' => 1]);
                }
                TenantParametres::create(['nom_entreprise' => 'Entreprise', 'devise_symbole' => 'FCFA']);

                return [
                    Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client', 'adresse_facturation' => 'Rue', 'ville' => 'Douala', 'code_postal' => '00000'])->id,
                    ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Article', 'prix_unitaire' => 100])->id,
                ];
            });

            Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(), ['*']);
            $this->withHeader('X-Tenant-Slug', $tenant->slug);

            $this->postJson('/api/factures', [
                'client_id' => $clientId, 'date_facture' => '2026-10-16',
                'lignes' => [['produit_id' => $produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]],
            ])->assertCreated()->assertJsonPath('numero', 'FAC-0001');

            $this->postJson('/api/devis', [
                'client_id' => $clientId, 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16',
                'lignes' => [['produit_id' => $produitId, 'quantite' => 1, 'prix_unitaire' => 100]],
            ])->assertCreated()->assertJsonPath('numero', 'DEV-0001');
        }
    }
}
