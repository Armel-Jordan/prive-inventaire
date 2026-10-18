<?php

namespace Tests\Feature\Recherche;

use App\Models\AdminUser;
use App\Models\BonLivraison;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\Facture;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Recherche des listes ouvertes par « ?recherche= » (liens de la recherche globale et des autres écrans) : commandes
 * clients, factures et bons de livraison, par numéro, par client (raison sociale, code) et par le nom noté pour un
 * client de passage ; caractères génériques de LIKE pris tels quels ; isolation des entreprises.
 */
class RechercheListesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        app(TenantContext::class)->runAsTenant($this->tenant->id, function () {
            $client = fn (string $code, string $nom) => Client::create(['code' => $code, 'raison_sociale' => $nom, 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id;
            $mballa = $client('C-MBA', 'Quincaillerie Mballa');
            $passage = $client('PASSAGE', 'Client de passage');
            ComClientEntete::create(['numero' => 'CMD-0001', 'client_id' => $mballa, 'date_commande' => '2026-10-15', 'statut' => 'brouillon']);
            ComClientEntete::create(['numero' => 'CMD-0002', 'client_id' => $passage, 'passage_nom' => 'Jean Dupont', 'date_commande' => '2026-10-16', 'statut' => 'brouillon']);
            $facture = fn (string $numero, int $client, ?string $passageNom = null) => Facture::create([
                'numero' => $numero, 'client_id' => $client, 'passage_nom' => $passageNom, 'date_facture' => '2026-10-16', 'statut' => 'emise', 'montant_ttc' => 100, 'reste_a_payer' => 100,
            ])->id;
            BonLivraison::create(['numero' => 'BL-0001', 'facture_id' => $facture('FAC-0001', $mballa), 'mode_livraison' => 'entreprise', 'statut' => 'cree']);
            BonLivraison::create(['numero' => 'BL-0002', 'facture_id' => $facture('FAC-0002', $passage, 'Jean Dupont'), 'mode_livraison' => 'entreprise', 'statut' => 'cree']);
        });
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function numeros(string $liste, string $recherche): array
    {
        return array_column($this->getJson("/api/{$liste}?recherche=".rawurlencode($recherche))->assertOk()->json('data'), 'numero');
    }

    public function test_les_commandes_clients_se_cherchent_par_numero_client_ou_nom_de_passage(): void
    {
        $this->assertSame(['CMD-0001'], $this->numeros('commandes-client', 'cmd-0001'));
        $this->assertSame(['CMD-0001'], $this->numeros('commandes-client', 'Mballa'));
        $this->assertSame(['CMD-0001'], $this->numeros('commandes-client', 'C-MBA'));
        $this->assertSame(['CMD-0002'], $this->numeros('commandes-client', 'Dupont'));
        $this->assertCount(2, $this->getJson('/api/commandes-client?recherche=')->assertOk()->json('data'));
    }

    public function test_les_factures_et_les_bons_de_livraison_se_cherchent_aussi_par_le_nom_de_passage(): void
    {
        $this->assertSame(['FAC-0002'], $this->numeros('factures', 'Dupont'));
        $this->assertSame(['FAC-0001'], $this->numeros('factures', 'Mballa'));

        $this->assertSame(['BL-0001'], $this->numeros('bons-livraison', 'BL-0001'));
        // Numéro de la facture du bon, client, nom noté pour le client de passage.
        $this->assertSame(['BL-0002'], $this->numeros('bons-livraison', 'FAC-0002'));
        $this->assertSame(['BL-0001'], $this->numeros('bons-livraison', 'Quincaillerie'));
        $this->assertSame(['BL-0002'], $this->numeros('bons-livraison', 'Dupont'));
    }

    public function test_les_caracteres_generiques_sont_pris_tels_quels_et_les_entreprises_isolees(): void
    {
        // « % » et « _ » ne sont pas des jokers : rien ne contient ces caractères.
        $this->assertSame([], $this->numeros('commandes-client', '%'));
        $this->assertSame([], $this->numeros('bons-livraison', '_'));

        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->assertSame([], $this->numeros('commandes-client', 'CMD'));
        $this->assertSame([], $this->numeros('bons-livraison', 'BL'));
    }
}
