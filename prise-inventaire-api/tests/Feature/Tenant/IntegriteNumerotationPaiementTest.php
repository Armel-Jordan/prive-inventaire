<?php

namespace Tests\Feature\Tenant;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Configuration;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fix Phase 1a — intégrité des documents financiers :
 *  #1 numérotation atomique (pas de trou de séquence, pas de doublon)
 *  #2 enregistrerPaiement recalcule soldes/statut/encours (pas de double encaissement)
 *  #3 conversion devis→commande utilise la séquence « commande » configurée
 */
class IntegriteNumerotationPaiementTest extends TestCase
{
    use RefreshDatabase;

    private function config(string $entite, string $prefixe, int $prochain = 1): Configuration
    {
        return Configuration::create([
            'entite' => $entite,
            'prefixe' => $prefixe,
            'separateur' => '-',
            'longueur' => 4,
            'auto_increment' => true,
            'prochain_numero' => $prochain,
        ]);
    }

    private function client(): Client
    {
        return Client::create([
            'code' => 'CLI-'.uniqid(),
            'raison_sociale' => 'Client Test',
            'adresse_facturation' => '1 rue du Test',
            'ville' => 'Douala',
            'code_postal' => '00000',
        ]);
    }

    // ===== Fix #1 : numérotation atomique =====

    public function test_consommer_numero_est_sequentiel(): void
    {
        $tenant = Tenant::factory()->create();

        app(TenantContext::class)->runAsTenant($tenant->id, function () use ($tenant) {
            $this->config('facture', 'FAC');

            $n1 = DB::transaction(fn () => Configuration::consommerNumero('facture', $tenant->id));
            $n2 = DB::transaction(fn () => Configuration::consommerNumero('facture', $tenant->id));

            $this->assertSame('FAC-0001', $n1);
            $this->assertSame('FAC-0002', $n2);
            $this->assertSame(3, Configuration::pourEntite('facture', $tenant->id)->prochain_numero);
        });
    }

    public function test_consommer_numero_annule_l_increment_si_la_transaction_echoue(): void
    {
        $tenant = Tenant::factory()->create();

        app(TenantContext::class)->runAsTenant($tenant->id, function () use ($tenant) {
            $this->config('facture', 'FAC');

            try {
                DB::transaction(function () use ($tenant) {
                    Configuration::consommerNumero('facture', $tenant->id);
                    // Simule un échec de création du document après consommation du numéro.
                    throw new \RuntimeException('création échouée');
                });
                $this->fail('La transaction aurait dû lever une exception.');
            } catch (\RuntimeException $e) {
                // attendu
            }

            // Le numéro NE DOIT PAS être brûlé : pas de trou de séquence (exigence OHADA).
            $this->assertSame(1, Configuration::pourEntite('facture', $tenant->id)->prochain_numero);
        });
    }

    public function test_consommer_numero_leve_une_exception_si_config_absente(): void
    {
        $tenant = Tenant::factory()->create();

        app(TenantContext::class)->runAsTenant($tenant->id, function () use ($tenant) {
            $this->expectException(\RuntimeException::class);
            DB::transaction(fn () => Configuration::consommerNumero('facture', $tenant->id));
        });
    }

    // ===== Fix #2 : paiement recalcule les soldes =====

    public function test_paiement_recalcule_soldes_statut_et_rejette_le_sur_paiement(): void
    {
        $tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $admin = AdminUser::factory()->for($tenant)->create();

        $facture = app(TenantContext::class)->runAsTenant($tenant->id, function () {
            $client = $this->client();

            return Facture::create([
                'numero' => 'FAC-0001',
                'client_id' => $client->id,
                'date_facture' => '2026-07-01',
                'statut' => 'emise',
                'montant_ht' => 100,
                'montant_tva' => 0,
                'montant_ttc' => 100,
                'reste_a_payer' => 100,
            ]);
        });

        Sanctum::actingAs($admin, ['*']);

        // Paiement partiel de 40 → partiellement_payee, reste 60.
        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson("/api/factures/{$facture->id}/paiement", [
                'montant' => 40,
                'date_paiement' => '2026-07-02',
                'mode_paiement' => 'especes',
            ])->assertOk();

        $facture->refresh();
        $this->assertEquals(40, (float) $facture->montant_paye);
        $this->assertEquals(60, (float) $facture->reste_a_payer);
        $this->assertSame('partiellement_payee', $facture->statut);

        // Encours client mis à jour (= reste_a_payer des factures émises/partielles).
        $this->assertEquals(60, (float) $facture->client->fresh()->encours_actuel);

        // Sur-paiement (1000 > reste 60) → rejeté, aucune mutation.
        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson("/api/factures/{$facture->id}/paiement", [
                'montant' => 1000,
                'date_paiement' => '2026-07-02',
                'mode_paiement' => 'especes',
            ])->assertStatus(422);

        $facture->refresh();
        $this->assertEquals(60, (float) $facture->reste_a_payer);

        // Paiement du solde → payee, reste 0.
        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson("/api/factures/{$facture->id}/paiement", [
                'montant' => 60,
                'date_paiement' => '2026-07-03',
                'mode_paiement' => 'virement',
            ])->assertOk();

        $facture->refresh();
        $this->assertEquals(100, (float) $facture->montant_paye);
        $this->assertEquals(0, (float) $facture->reste_a_payer);
        $this->assertSame('payee', $facture->statut);
        $this->assertEquals(0, (float) $facture->client->fresh()->encours_actuel);
    }

    // ===== Fix #3 : conversion devis→commande utilise la bonne séquence =====

    public function test_conversion_devis_utilise_la_sequence_commande(): void
    {
        $tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $admin = AdminUser::factory()->for($tenant)->create();

        $devis = app(TenantContext::class)->runAsTenant($tenant->id, function () {
            $this->config('commande', 'CMD');
            $client = $this->client();

            return Devis::create([
                'numero' => 'DEV-0001',
                'client_id' => $client->id,
                'date_devis' => '2026-07-01',
                'date_validite' => '2026-07-31',
                'statut' => Devis::STATUT_ACCEPTE,
            ]);
        });

        Sanctum::actingAs($admin, ['*']);

        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson("/api/devis/{$devis->id}/convertir")
            ->assertOk()
            // Numéro issu de la séquence « commande » configurée, PAS du legacy COM-YYYY-….
            ->assertJsonPath('commande.numero', 'CMD-0001');

        $this->assertSame(2, Configuration::pourEntite('commande', $tenant->id)->prochain_numero);
    }
}
