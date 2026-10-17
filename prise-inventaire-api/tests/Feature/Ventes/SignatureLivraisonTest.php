<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\BonLivraison;
use App\Models\Client;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Services\DocumentsVentePdf;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Signature électronique à la livraison (phase 1.6) : image et nom du signataire enregistrés avec la livraison,
 * omis des listes, donnés par le détail, imprimés sur le bon de livraison ; image refusée si ce n'en est pas une.
 */
class SignatureLivraisonTest extends TestCase
{
    use RefreshDatabase;

    /** Image PNG de 1 × 1 pixel, encodée comme l'envoie le bloc de signature. */
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->produitId] = $this->chezLeTenant(fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
            ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    /** Bon de livraison d'une facture émise de 3 sacs, préparé puis prêt à partir. */
    private function bonPret(): array
    {
        $facture = $this->postJson('/api/factures', ['client_id' => $this->clientId, 'date_facture' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 3, 'prix_unitaire_ht' => 100]]])->assertCreated()->json('id');
        $this->postJson("/api/factures/{$facture}/emettre")->assertOk();
        $bon = $this->postJson("/api/factures/{$facture}/creer-bl")->assertCreated()->json();
        $this->postJson("/api/bons-livraison/{$bon['id']}/preparer")->assertOk();
        $this->postJson("/api/bons-livraison/{$bon['id']}/pret")->assertOk();

        return $bon;
    }

    public function test_signature_enregistree_affichee_et_imprimee(): void
    {
        $bon = $this->bonPret();
        $this->postJson("/api/bons-livraison/{$bon['id']}/livrer", [
            'lignes' => [['id' => $bon['lignes'][0]['id'], 'quantite_livree' => 3]],
            'signature_client' => self::SIGNATURE, 'signataire_nom' => ' Awa Ndiaye ',
        ])->assertOk()->assertJsonPath('statut', 'livre_complet')->assertJsonPath('a_signature', true)->assertJsonMissingPath('signature_client');

        // Liste : signé ou non, sans l'image ; détail : l'image, le signataire, le moment.
        $liste = collect($this->getJson('/api/bons-livraison')->assertOk()->json('data'))->firstWhere('id', $bon['id']);
        $this->assertTrue($liste['a_signature']);
        $this->assertArrayNotHasKey('signature_client', $liste);
        $detail = $this->getJson("/api/bons-livraison/{$bon['id']}")->assertOk()->json();
        $this->assertSame([self::SIGNATURE, 'Awa Ndiaye'], [$detail['signature_client'], $detail['signataire_nom']]);
        $this->assertStringStartsWith('2026-10-16', $detail['signe_le']);

        // Bon de livraison imprimé : la signature et le signataire dans la case du client ; statut lisible.
        $donnees = $this->chezLeTenant(fn () => app(DocumentsVentePdf::class)->bonLivraison(BonLivraison::findOrFail($bon['id'])));
        $this->assertSame(['libelle' => 'Reçu par le client', 'image' => self::SIGNATURE, 'detail' => 'Awa Ndiaye — 16/10/2026'], $donnees['signatures'][1]);
        $html = view('pdf.document-vente', $donnees)->render();
        $this->assertStringContainsString('src="'.self::SIGNATURE.'"', $html);
        $this->assertStringContainsString('Livré', $html);
        $this->assertStringStartsWith('%PDF', $this->get("/api/bons-livraison/{$bon['id']}/pdf")->assertOk()->getContent());
    }

    public function test_sans_signature_ou_image_invalide(): void
    {
        // Sans signature : la case reste à remplir à la main.
        $bon = $this->bonPret();
        $this->postJson("/api/bons-livraison/{$bon['id']}/livrer", ['lignes' => [['id' => $bon['lignes'][0]['id'], 'quantite_livree' => 3]], 'signataire_nom' => 'Awa'])
            ->assertOk()->assertJsonPath('a_signature', false)->assertJsonPath('signataire_nom', null);
        $donnees = $this->chezLeTenant(fn () => app(DocumentsVentePdf::class)->bonLivraison(BonLivraison::findOrFail($bon['id'])));
        $this->assertSame('Reçu par le client (date et signature)', $donnees['signatures'][1]);

        // Ce qui n'est pas une image est refusé.
        $autre = $this->bonPret();
        $this->postJson("/api/bons-livraison/{$autre['id']}/livrer", [
            'lignes' => [['id' => $autre['lignes'][0]['id'], 'quantite_livree' => 3]],
            'signature_client' => 'javascript:alert(1)',
        ])->assertUnprocessable()->assertJsonValidationErrors(['signature_client' => 'La signature doit être une image (PNG ou JPEG).']);
    }
}
