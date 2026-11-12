<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\ProduitTenant;
use App\Models\Representant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Services\DocumentsVentePdf;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Identité figée des documents de vente (plan de parité fripv2, socle S4.5) : un document envoyé ou émis garde les
 * coordonnées de l'entreprise, son destinataire et les libellés de son en-tête du jour ; une facture émise porte
 * « Réimpression » à partir de sa deuxième copie réelle.
 */
class IdentiteFigeeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->ids = $this->chez(function () {
            TenantParametres::query()->updateOrCreate([], ['nom_entreprise' => 'Quincaillerie du Port', 'adresse' => '12 rue du Port, Douala']);
            $awa = Representant::create(['code' => 'AWA', 'nom' => 'Awa Ndiaye', 'actif' => true]);

            return [
                'client' => Client::create(['code' => 'C1', 'raison_sociale' => 'Bâtiments Mballa', 'adresse_facturation' => '1 rue Joss', 'ville' => 'Douala', 'code_postal' => '0',
                    'representant_id' => $awa->id])->id,
                'produit' => ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
                'awa' => $awa->id,
            ];
        });
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    /** Tout change après coup : le client, l'entreprise et le représentant sont renommés. */
    private function toutRenommer(): void
    {
        $this->chez(function () {
            Client::whereKey($this->ids['client'])->update(['raison_sociale' => 'Mballa Holding', 'adresse_facturation' => '99 avenue Neuve']);
            TenantParametres::query()->update(['nom_entreprise' => 'Port SA', 'adresse' => 'Nouvelle adresse']);
            Representant::whereKey($this->ids['awa'])->update(['nom' => 'Awa Ndiaye-Fotso']);
        });
    }

    public function test_facture_emise_garde_son_identite(): void
    {
        $facture = $this->postJson('/api/factures', ['client_id' => $this->ids['client'], 'date_facture' => '2026-11-10',
            'lignes' => [['produit_id' => $this->ids['produit'], 'quantite' => 1, 'prix_unitaire_ht' => 100]]])->assertCreated()->json('id');
        $this->assertNull($this->chez(fn () => Facture::findOrFail($facture)->identite_figee));

        $this->postJson("/api/factures/{$facture}/emettre")->assertOk();
        $this->toutRenommer();

        $donnees = $this->chez(fn () => app(DocumentsVentePdf::class)->facture(Facture::findOrFail($facture)));
        $this->assertSame('Bâtiments Mballa', $donnees['destinataire']['nom']);
        $this->assertContains('1 rue Joss', $donnees['destinataire']['lignes']);
        $this->assertSame(['Quincaillerie du Port', '12 rue du Port, Douala'], [$donnees['entreprise']['nom'], $donnees['entreprise']['adresse']]);
        $this->assertSame('Awa Ndiaye', $donnees['infos']['Représentant']);
        $this->assertSame('C1', $this->chez(fn () => Facture::findOrFail($facture)->identite_figee['client_code']));
    }

    public function test_brouillon_et_devis_envoye(): void
    {
        // Un brouillon suit la fiche du jour.
        $brouillon = $this->postJson('/api/factures', ['client_id' => $this->ids['client'], 'date_facture' => '2026-11-10',
            'lignes' => [['produit_id' => $this->ids['produit'], 'quantite' => 1, 'prix_unitaire_ht' => 100]]])->json('id');

        // Un devis envoyé garde son identité.
        $devis = $this->postJson('/api/devis', ['client_id' => $this->ids['client'], 'date_devis' => '2026-11-10', 'date_validite' => '2026-12-10',
            'lignes' => [['produit_id' => $this->ids['produit'], 'quantite' => 1, 'prix_unitaire' => 100]]])->json('id');
        $this->postJson("/api/devis/{$devis}/envoyer")->assertOk();
        $this->toutRenommer();

        $this->chez(function () use ($brouillon, $devis) {
            $pdf = app(DocumentsVentePdf::class);
            $this->assertSame('Mballa Holding', $pdf->facture(Facture::findOrFail($brouillon))['destinataire']['nom']);
            $this->assertSame('Port SA', $pdf->facture(Facture::findOrFail($brouillon))['entreprise']['nom']);
            $this->assertSame('Bâtiments Mballa', $pdf->devis(Devis::findOrFail($devis))['destinataire']['nom']);
            $this->assertSame('Quincaillerie du Port', $pdf->devis(Devis::findOrFail($devis))['entreprise']['nom']);
        });
    }

    public function test_reimpression_a_partir_de_la_deuxieme_copie(): void
    {
        $facture = $this->postJson('/api/factures', ['client_id' => $this->ids['client'], 'date_facture' => '2026-11-10',
            'lignes' => [['produit_id' => $this->ids['produit'], 'quantite' => 1, 'prix_unitaire_ht' => 100]]])->json('id');

        // Les PDF d'un brouillon ne comptent pas.
        $this->get("/api/factures/{$facture}/pdf")->assertOk();
        $this->assertSame(0, $this->chez(fn () => Facture::findOrFail($facture)->nb_impressions));

        $this->postJson("/api/factures/{$facture}/emettre")->assertOk();
        $this->get("/api/factures/{$facture}/pdf")->assertOk();
        $this->assertFalse($this->chez(fn () => app(DocumentsVentePdf::class)->facture(Facture::findOrFail($facture))['reimpression']));
        $this->get("/api/factures/{$facture}/pdf")->assertOk();
        $this->chez(function () use ($facture) {
            $this->assertSame(2, Facture::findOrFail($facture)->nb_impressions);
            $this->assertTrue(app(DocumentsVentePdf::class)->facture(Facture::findOrFail($facture))['reimpression']);
        });
    }
}
