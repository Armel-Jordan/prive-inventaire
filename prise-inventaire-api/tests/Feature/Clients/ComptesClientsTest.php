<?php

namespace Tests\Feature\Clients;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Facture;
use App\Models\FacturePaiement;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Comptes clients (phase 4.4) : analyse chronologique des sommes dues à une date (paiements datés), relevé de
 * compte avec solde progressif, exports, module et isolation.
 */
class ComptesClientsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientA;

    private int $clientB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);

        [$this->clientA, $this->clientB] = app(TenantContext::class)->runAsTenant($this->tenant->id, function () {
            $client = fn (string $code, string $nom) => Client::create(['code' => $code, 'raison_sociale' => $nom, 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id;
            $a = $client('C-A', 'Quincaillerie du Port');
            $b = $client('C-B', 'Brasserie du Nord');
            $facture = fn (string $numero, int $client, string $date, ?string $echeance, string $statut, float $ttc) => Facture::create([
                'numero' => $numero, 'client_id' => $client, 'date_facture' => $date, 'date_echeance' => $echeance, 'statut' => $statut, 'montant_ttc' => $ttc,
            ])->id;
            $paiement = fn (int $facture, string $date, float $montant) => FacturePaiement::create(['facture_id' => $facture, 'date_paiement' => $date, 'montant' => $montant, 'mode_paiement' => 'virement']);

            $f1 = $facture('F-1', $a, '2026-06-01', '2026-07-01', 'partiellement_payee', 1000);
            $paiement($f1, '2026-07-15', 300);
            $paiement($f1, '2026-10-10', 200);
            $facture('F-2', $a, '2026-09-20', '2026-10-20', 'emise', 500);
            $f3 = $facture('F-3', $b, '2026-08-01', null, 'payee', 800);
            $paiement($f3, '2026-08-20', 800);
            $facture('F-4', $b, '2026-05-01', '2026-05-31', 'emise', 1200);
            $facture('F-5', $b, '2026-10-01', null, 'brouillon', 999);
            $facture('F-6', $a, '2026-10-01', null, 'annulee', 777);

            return [$a, $b];
        });
    }

    /** [code client => [total, non échu, 1-30, 31-60, 61-90, +90]] */
    private function tranches(array $analyse): array
    {
        return collect($analyse['clients'])->mapWithKeys(fn ($c) => [$c['code'] => [$c['total'], $c['non_echu'], $c['jours_1_30'], $c['jours_31_60'], $c['jours_61_90'], $c['plus_90']]])->all();
    }

    public function test_analyse_chronologique_a_une_date(): void
    {
        $aujourdhui = $this->getJson('/api/comptes-clients')->assertOk()->assertJsonPath('date', '2026-10-16')->json();
        $this->assertEquals(['C-B' => [1200, 0, 0, 0, 0, 1200], 'C-A' => [1000, 500, 0, 0, 0, 500]], $this->tranches($aujourdhui));
        $this->assertSame(['C-B', 'C-A'], array_column($aujourdhui['clients'], 'code'));
        $this->assertEquals([2, 107], [$aujourdhui['clients'][1]['nb_factures'], $aujourdhui['clients'][1]['retard_max']]);
        $this->assertEquals(['total' => 2200, 'non_echu' => 500, 'jours_1_30' => 0, 'jours_31_60' => 0, 'jours_61_90' => 0, 'plus_90' => 1700], $aujourdhui['totaux']);

        // Au 10 août : F-3 n'était pas payée, F-2 pas encore émise, F-1 n'avait reçu que 300.
        $aout = $this->getJson('/api/comptes-clients?date=2026-08-10')->assertOk()->json();
        $this->assertEquals(['C-B' => [2000, 0, 800, 0, 1200, 0], 'C-A' => [700, 0, 0, 700, 0, 0]], $this->tranches($aout));

        $csv = $this->get('/api/comptes-clients?format=csv')->assertOk()->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFcode;client;factures;retard_max_jours;total;non_echu;1_30_jours;31_60_jours;61_90_jours;plus_90_jours", $csv);
        $this->assertStringContainsString('C-A;"Quincaillerie du Port";2;107;1000,00;500,00;0,00;0,00;0,00;500,00', $csv);
        $this->assertStringContainsString(';Totaux;;;2200,00;500,00;0,00;0,00;0,00;1700,00', $csv);
    }

    public function test_releve_de_compte(): void
    {
        $releve = $this->getJson("/api/comptes-clients/{$this->clientA}/releve?du=2026-07-01&au=2026-10-16")->assertOk()->json();
        $this->assertEquals([1000, 500, 500, 1000], [$releve['solde_ouverture'], $releve['total_debit'], $releve['total_credit'], $releve['solde_final']]);
        $this->assertEquals([
            ['2026-07-15', 'F-1', 0, 300, 700],
            ['2026-09-20', 'F-2', 500, 0, 1200],
            ['2026-10-10', 'F-1', 0, 200, 1000],
        ], array_map(fn ($l) => [$l['date'], $l['piece'], $l['debit'], $l['credit'], $l['solde']], $releve['lignes']));
        $this->assertSame('Paiement virement', $releve['lignes'][0]['libelle']);
        $this->assertEquals([['F-1', 500, 107, 'plus_90'], ['F-2', 500, 0, 'non_echu']],
            array_map(fn ($f) => [$f['numero'], $f['reste'], $f['retard'], $f['tranche']], $releve['factures_dues']));

        // Sans dates : du 1er janvier à aujourd'hui.
        $this->getJson("/api/comptes-clients/{$this->clientA}/releve")->assertOk()->assertJsonPath('du', '2026-01-01')->assertJsonPath('solde_ouverture', 0);

        $csv = $this->get("/api/comptes-clients/{$this->clientA}/releve?du=2026-07-01&au=2026-10-16&format=csv")->assertOk()->getContent();
        $this->assertStringContainsString(';;"Solde d\'ouverture";0,00;0,00;1000,00', $csv);
        $this->assertStringContainsString('2026-09-20;F-2;"Facture F-2";500,00;0,00;1200,00', $csv);
    }

    public function test_module_et_isolation(): void
    {
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->getJson('/api/comptes-clients')->assertOk()->assertJsonCount(0, 'clients');
        $this->getJson("/api/comptes-clients/{$this->clientA}/releve")->assertNotFound();

        $sansVentes = Tenant::factory()->create(['modules' => []]);
        Sanctum::actingAs(AdminUser::factory()->for($sansVentes)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $sansVentes->slug);
        $this->getJson('/api/comptes-clients')->assertForbidden();
    }
}
