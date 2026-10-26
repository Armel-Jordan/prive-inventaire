<?php

namespace Tests\Feature\Ventes;

use App\Models\BonLivraison;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\Facture;
use App\Models\PortailCompte;
use App\Models\PortailParametres;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Models\Transporteur;
use App\Services\Courriels\VariablesCourriel;
use App\Services\DocumentsVentePdf;
use App\Services\Portail\AccesPortail;
use App\Support\Expedition;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Sorties des transporteurs (plan de parité fripv2, phase 1.11b) : transporteur et numéro de suivi sur les PDF
 * (« Expédié par » sur le bon de livraison), variables de courriel {transporteur}, {numero_suivi} et {lien_suivi}, et
 * expédition de la commande dans l'espace client (bon de livraison, sinon facture, sinon commande).
 */
class TransporteursSortiesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    /** Commande acceptée, sa facture émise et son bon de livraison ; le transporteur et le numéro de chacun au choix. */
    private function documents(?int $transporteur, ?string $suiviCommande, ?string $suiviFacture, ?string $suiviBon): array
    {
        return $this->chez(function () use ($transporteur, $suiviCommande, $suiviFacture, $suiviBon) {
            $client = Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'actif' => true]);
            $commande = ComClientEntete::create(['numero' => 'CMD-1', 'client_id' => $client->id, 'date_commande' => '2026-10-16', 'statut' => 'acceptee',
                'montant_ht' => 100, 'montant_tva' => 0, 'montant_ttc' => 100, 'transporteur_id' => $transporteur, 'numero_suivi' => $suiviCommande]);
            $facture = Facture::create(['numero' => 'FAC-1', 'commande_id' => $commande->id, 'client_id' => $client->id, 'date_facture' => '2026-10-16', 'statut' => 'emise',
                'montant_ht' => 100, 'montant_tva' => 0, 'montant_ttc' => 100, 'reste_a_payer' => 100, 'transporteur_id' => $transporteur, 'numero_suivi' => $suiviFacture]);
            $bon = BonLivraison::create(['numero' => 'BL-1', 'facture_id' => $facture->id, 'mode_livraison' => 'entreprise', 'statut' => 'cree',
                'transporteur_id' => $transporteur, 'numero_suivi' => $suiviBon]);

            return [$client, $commande, $facture, $bon];
        });
    }

    public function test_pdf_et_variables_de_courriel(): void
    {
        $dhl = $this->chez(fn () => Transporteur::create(['nom' => 'DHL Express', 'url_suivi' => 'https://dhl.test/suivi?id={numero}', 'actif' => true])->id);
        [, $commande, $facture, $bon] = $this->documents($dhl, 'CMD 1/2', 'FAC-9', 'BL-9');

        $this->chez(function () use ($commande, $facture, $bon) {
            $pdf = app(DocumentsVentePdf::class);
            $this->assertSame(['DHL Express', 'FAC-9'], [$pdf->facture($facture)['infos']['Transporteur'], $pdf->facture($facture)['infos']['N° de suivi']]);
            $this->assertSame('DHL Express', $pdf->bonLivraison($bon)['infos']['Expédié par']);

            // Le numéro est encodé dans le lien ; les autres variables du document restent.
            $variables = app(VariablesCourriel::class)->pour('commande_client', $commande, 'fr');
            $this->assertSame(['DHL Express', 'CMD 1/2', 'https://dhl.test/suivi?id=CMD%201%2F2', 'CMD-1'],
                [$variables['transporteur'], $variables['numero_suivi'], $variables['lien_suivi'], $variables['numero']]);
            $this->assertSame('https://dhl.test/suivi?id=BL-9', app(VariablesCourriel::class)->pour('bon_livraison', $bon, 'fr')['lien_suivi']);
            $this->assertSame('DHL Express', app(VariablesCourriel::class)->pour('facture', null, 'fr')['transporteur']);
        });
    }

    public function test_sans_transporteur_rien_ne_s_affiche(): void
    {
        [, , $facture, $bon] = $this->documents(null, null, null, null);

        $this->chez(function () use ($facture, $bon) {
            $this->assertArrayNotHasKey('Transporteur', app(DocumentsVentePdf::class)->facture($facture)['infos']);
            $this->assertArrayNotHasKey('Expédié par', app(DocumentsVentePdf::class)->bonLivraison($bon)['infos']);
            $variables = app(VariablesCourriel::class)->pour('facture', $facture, 'fr');
            $this->assertSame(['', '', ''], [$variables['transporteur'], $variables['numero_suivi'], $variables['lien_suivi']]);
        });
        $this->assertNull(Expedition::lien('https://dhl.test/{numero}', null));
        $this->assertSame('https://dhl.test/suivi', Expedition::lien('https://dhl.test/suivi', 'X1'));
    }

    public function test_l_espace_client_montre_l_expedition_de_la_commande(): void
    {
        $dhl = $this->chez(fn () => Transporteur::create(['nom' => 'DHL Express', 'url_suivi' => 'https://dhl.test/{numero}', 'actif' => true])->id);
        [$client, $commande, $facture, $bon] = $this->documents($dhl, 'CMD-SUIVI', 'FAC-SUIVI', 'BL-SUIVI');
        $jeton = $this->chez(function () use ($client) {
            TenantParametres::create(['nom_entreprise' => 'Quincaillerie du Port', 'devise_symbole' => 'FCFA']);
            PortailParametres::create(['actif' => true]);

            return app(AccesPortail::class)->ouvrirSession(PortailCompte::create(['client_id' => $client->id, 'nom' => 'Awa', 'email' => 'awa@client.test', 'password' => 'motdepasse1', 'actif' => true]));
        });
        $voir = fn () => $this->withHeaders(['Authorization' => "Bearer {$jeton}"])->getJson("/api/espace-client/commandes/{$commande->id}")->assertOk();

        // Le bon de livraison d'abord ; annulé, la facture ; sans facture émise, la commande.
        $voir()->assertJsonPath('commande.expedition', ['transporteur' => 'DHL Express', 'numero_suivi' => 'BL-SUIVI', 'lien_suivi' => 'https://dhl.test/BL-SUIVI'])
            ->assertJsonPath('facture', ['id' => $facture->id, 'numero' => 'FAC-1', 'statut' => 'emise']);
        $this->chez(fn () => $bon->update(['statut' => 'annule']));
        $voir()->assertJsonPath('commande.expedition.numero_suivi', 'FAC-SUIVI');
        $this->chez(fn () => $facture->update(['statut' => 'brouillon']));
        $voir()->assertJsonPath('commande.expedition.numero_suivi', 'CMD-SUIVI')->assertJsonPath('facture', null);
    }
}
