<?php

namespace Tests\Feature\Inventaire;

use App\Models\AdminUser;
use App\Models\MouvementTenant;
use App\Models\ScanTenant;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Historique des scans et des mouvements sur toute leur étendue (l'API s'arrêtait aux 100 derniers) : filtres
 * par période en instants (fuseau de l'utilisateur), par produit et par secteur (code ou nom), pagination,
 * export, statistiques et comparaison calculées par l'API.
 */
class HistoriqueScansTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 12:00:00');

        $this->tenant = Tenant::factory()->create();
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
        $this->chezLeTenant(fn () => Secteur::create(['code' => 'A1', 'nom' => 'Allée 1', 'actif' => true]));
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function scan(string $date, string $numero = 'P-1', float $quantite = 1, string $secteur = 'A1', string $employe = 'E1'): void
    {
        $this->chezLeTenant(fn () => ScanTenant::create([
            'numero' => $numero, 'type' => 'produit', 'quantite' => $quantite, 'unite_mesure' => 'UN',
            'employe' => $employe, 'secteur' => $secteur, 'date_saisie' => $date,
        ]));
    }

    public function test_sans_page_la_liste_reste_celle_des_100_derniers_avec_page_tout_l_historique(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->scan('2026-10-01 08:00:00');
        }

        $this->assertCount(100, $this->getJson('/api/scan/historique')->assertOk()->json());
        $this->getJson('/api/scan/historique?page=3&per_page=50')->assertOk()
            ->assertJsonPath('total', 120)
            ->assertJsonPath('last_page', 3)
            ->assertJsonCount(20, 'data');
    }

    public function test_la_periode_suit_le_fuseau_de_l_utilisateur(): void
    {
        $this->scan('2026-10-16 03:30:00', 'SOIR');   // 15/10 à 23 h 30 à Toronto
        $this->scan('2026-10-16 05:00:00', 'MATIN');  // 16/10 à 1 h à Toronto

        $numeros = collect($this->getJson('/api/scan/historique?page=1&depuis='.urlencode('2026-10-15T00:00:00-04:00').'&jusqu_a='.urlencode('2026-10-16T00:00:00-04:00'))
            ->assertOk()->json('data'))->pluck('numero');

        $this->assertEquals(['SOIR'], $numeros->all());
    }

    public function test_un_secteur_se_retrouve_par_son_code_comme_par_son_nom_et_le_produit_par_son_numero(): void
    {
        $this->scan('2026-10-10 08:00:00', 'BIERE-33', 1, 'A1');
        $this->scan('2026-10-10 09:00:00', 'BIERE-50', 1, 'Allée 1');
        $this->scan('2026-10-10 10:00:00', 'EAU-150', 1, 'B2');

        $this->getJson('/api/scan/historique?page=1&secteur=A1')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/scan/historique?page=1&produit=BIERE')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/scan/historique?page=1&produit=%25')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_les_statistiques_portent_sur_toute_la_periode(): void
    {
        for ($i = 0; $i < 150; $i++) {
            $this->scan('2026-10-02 10:00:00', 'P-'.($i % 3), 1, $i % 2 ? 'A1' : 'Allée 1', $i % 2 ? 'E1' : 'E2');
        }
        $this->scan('2026-10-16 03:30:00', 'P-9', 1, 'B2');   // 15/10 au soir à Toronto

        $stats = $this->getJson('/api/scan/statistiques?fuseau=America/Toronto&depuis='.urlencode('2026-09-16T00:00:00-04:00').'&jusqu_a='.urlencode('2026-10-17T00:00:00-04:00'))
            ->assertOk()->json();

        $this->assertSame(151, $stats['total']);
        $this->assertSame([['date' => '2026-10-02', 'total' => 150], ['date' => '2026-10-15', 'total' => 1]], $stats['par_jour']);
        $this->assertSame(2, $stats['secteurs_distincts']);   // A1 (code ou nom) et B2
        $this->assertSame(['nom' => 'A1', 'total' => 150], $stats['par_secteur'][0]);
        $this->assertSame(2, $stats['employes_distincts']);
    }

    public function test_la_comparaison_montre_les_baisses(): void
    {
        $this->scan('2026-10-01 10:00:00', 'P-1', 120);
        $this->scan('2026-10-01 11:00:00', 'P-2', 10);
        $this->scan('2026-10-15 10:00:00', 'P-1', 100);
        $this->scan('2026-10-15 10:30:00', 'P-3', 5);

        $lignes = collect($this->getJson('/api/scan/comparaison?'.http_build_query([
            'a_depuis' => '2026-10-01T00:00:00Z', 'a_jusqu_a' => '2026-10-02T00:00:00Z',
            'b_depuis' => '2026-10-15T00:00:00Z', 'b_jusqu_a' => '2026-10-16T00:00:00Z',
        ]))->assertOk()->json('lignes'))->keyBy('numero');

        $this->assertEquals(-20, $lignes['P-1']['difference']);
        $this->assertEquals(-16.7, $lignes['P-1']['pourcentage']);
        $this->assertEquals(-10, $lignes['P-2']['difference']);
        $this->assertEquals(5, $lignes['P-3']['difference']);
    }

    public function test_l_export_contient_tous_les_scans_filtres(): void
    {
        for ($i = 0; $i < 130; $i++) {
            $this->scan('2026-10-03 08:00:00', 'P-'.$i);
        }

        $csv = $this->get('/api/scan/export?secteur=A1&fuseau=Europe/Paris', ['Accept' => 'text/csv'])->assertOk()->streamedContent();

        $this->assertStringStartsWith("\u{FEFF}Numéro;Type;Quantité", $csv);
        $this->assertCount(131, array_filter(explode("\n", trim($csv))));
        $this->assertStringContainsString('P-129;produit;1;UN;E1;A1;"2026-10-03 10:00"', $csv);
    }

    public function test_l_historique_des_mouvements_se_pagine(): void
    {
        $this->chezLeTenant(function () {
            for ($i = 0; $i < 70; $i++) {
                MouvementTenant::create([
                    'tenant_id' => $this->tenant->id, 'type' => 'transfert', 'produit_numero' => 'P-1', 'secteur_source' => 'A1',
                    'secteur_destination' => 'B2', 'quantite' => 1, 'employe' => 'E1', 'date_mouvement' => '2026-10-10 08:00:00',
                ]);
            }
        });

        $this->assertCount(70, $this->getJson('/api/relocalisation')->assertOk()->json());
        $this->getJson('/api/relocalisation?page=2&per_page=50')->assertOk()
            ->assertJsonPath('total', 70)
            ->assertJsonCount(20, 'data');
    }
}
