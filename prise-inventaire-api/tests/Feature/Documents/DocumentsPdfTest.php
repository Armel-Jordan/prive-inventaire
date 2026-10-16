<?php

namespace Tests\Feature\Documents;

use App\Models\AdminUser;
use App\Models\BonLivraison;
use App\Models\BonLivraisonLigne;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\Configuration;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Services\DocumentsVentePdf;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PDF des documents de vente (plan de parité fripv2, phase 0.5) : un vrai PDF par document,
 * en-tête et devise de l'entreprise, détail des taxes, isolation entre entreprises.
 */
class DocumentsPdfTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->produitId] = $this->chezLeTenant(function () {
            foreach (['commande' => 'CMD', 'facture' => 'FAC'] as $entite => $prefixe) {
                Configuration::create(['entite' => $entite, 'prefixe' => $prefixe, 'separateur' => '-', 'longueur' => 4, 'auto_increment' => true, 'prochain_numero' => 1]);
            }
            TenantParametres::create(['nom_entreprise' => 'Ma Société SARL', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF', 'siret' => 'M0123456789', 'telephone' => '+237 600 00 00 00']);
            $client = Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue du Test', 'ville' => 'Douala', 'code_postal' => '00000']);
            $produit = ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Ciment 50 kg', 'prix_unitaire' => 100]);

            return [$client->id, $produit->id];
        });

        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    /** HTML du gabarit pour un document : le texte du PDF lui-même est compressé. */
    private function html(string $methode, Model $document): string
    {
        return $this->chezLeTenant(fn () => view('pdf.document-vente', app(DocumentsVentePdf::class)->{$methode}($document))->render());
    }

    private function factureAvecTvaEtCac(): int
    {
        $tva = $this->postJson('/api/taxes', ['nom' => 'TVA', 'taux' => 17.5])->json('id');
        $cac = $this->postJson('/api/taxes', ['nom' => 'CAC', 'taux' => 10])->json('id');
        $this->postJson('/api/groupes-taxes', ['code' => 'TVA_CAC', 'nom' => 'TVA + CAC', 'par_defaut' => true, 'lignes' => [
            ['tenant_taxe_id' => $tva, 'applique_sur' => 'base'],
            ['tenant_taxe_id' => $cac, 'applique_sur' => 'taxe_precedente'],
        ]])->assertCreated();

        return $this->postJson('/api/factures', [
            'client_id' => $this->clientId,
            'date_facture' => '2026-10-15',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 10, 'prix_unitaire_ht' => 100]],
        ])->assertCreated()->json('id');
    }

    private function assertPdf(string $url): void
    {
        $reponse = $this->get($url)->assertOk();
        $this->assertStringStartsWith('application/pdf', $reponse->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $reponse->getContent());
    }

    public function test_facture_pdf_avec_detail_des_taxes_et_entreprise(): void
    {
        $id = $this->factureAvecTvaEtCac();
        $this->assertPdf("/api/factures/{$id}/pdf");

        $html = $this->html('facture', $this->chezLeTenant(fn () => Facture::find($id)));
        foreach (['FACTURE', 'FAC-0001', 'Ma Société SARL', 'M0123456789', 'Client Test', 'Ciment 50 kg', 'TVA (17,5 %)', 'CAC (10 %)', '1 192,50 FCFA', 'Reste à payer'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }
    }

    public function test_tva_unique_sans_groupe_de_taxes(): void
    {
        $id = $this->postJson('/api/factures', [
            'client_id' => $this->clientId,
            'date_facture' => '2026-10-15',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]],
        ])->json('id');

        $html = $this->html('facture', $this->chezLeTenant(fn () => Facture::find($id)));
        $this->assertStringContainsString('TVA (20 %)', $html);
        $this->assertStringContainsString('120,00 FCFA', $html);
    }

    public function test_commande_pdf(): void
    {
        $id = $this->postJson('/api/commandes-client', [
            'client_id' => $this->clientId,
            'date_commande' => '2026-10-15',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 2, 'prix_unitaire_ht' => 100, 'remise_ligne' => 10]],
        ])->assertCreated()->json('id');

        $this->assertPdf("/api/commandes-client/{$id}/pdf");
        $html = $this->html('commande', $this->chezLeTenant(fn () => ComClientEntete::find($id)));
        $this->assertStringContainsString('COMMANDE', $html);
        $this->assertStringContainsString('10 %', $html);
        $this->assertStringContainsString('180,00 FCFA', $html);
    }

    public function test_devis_pdf_hors_taxes(): void
    {
        $devis = $this->chezLeTenant(function () {
            $devis = Devis::create(['numero' => 'DEV-0001', 'client_id' => $this->clientId, 'date_devis' => '2026-10-15', 'date_validite' => '2026-11-15', 'statut' => Devis::STATUT_ENVOYE]);
            $devis->lignes()->create(['produit_id' => $this->produitId, 'quantite' => 3, 'prix_unitaire' => 50, 'montant_ligne' => 150]);
            $devis->recalculerTotal();

            return $devis;
        });

        $this->assertPdf("/api/devis/{$devis->id}/pdf");
        $html = $this->html('devis', $devis->fresh());
        foreach (['DEVIS', 'DEV-0001', 'Envoyé', '15/11/2026', 'Montants hors taxes.', '150,00 FCFA', 'Bon pour accord'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }
    }

    public function test_bon_de_livraison_pdf(): void
    {
        $factureId = $this->factureAvecTvaEtCac();
        $bon = $this->chezLeTenant(function () use ($factureId) {
            $bon = BonLivraison::create(['numero' => 'BL-0001', 'facture_id' => $factureId, 'mode_livraison' => 'entreprise', 'statut' => 'cree']);
            BonLivraisonLigne::create(['bon_id' => $bon->id, 'produit_id' => $this->produitId, 'quantite_a_livrer' => 10, 'quantite_preparee' => 4, 'quantite_livree' => 0]);

            return $bon;
        });

        $this->assertPdf("/api/bons-livraison/{$bon->id}/pdf");
        $html = $this->html('bonLivraison', $bon);
        foreach (['BON DE LIVRAISON', 'BL-0001', 'FAC-0001', 'Créé', 'Livraison par l&#039;entreprise', 'Livrer à', 'Préparé', 'Reçu par le client'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }
    }

    public function test_pdf_dun_autre_tenant_introuvable(): void
    {
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $factureAutre = app(TenantContext::class)->runAsTenant($autre->id, function () {
            $client = Client::create(['code' => 'X', 'raison_sociale' => 'Ailleurs', 'adresse_facturation' => '-', 'ville' => '-', 'code_postal' => '-']);

            return Facture::create(['numero' => 'FAC-9999', 'client_id' => $client->id, 'date_facture' => '2026-10-15', 'statut' => 'brouillon', 'montant_ht' => 1, 'montant_tva' => 0, 'montant_ttc' => 1, 'reste_a_payer' => 1]);
        });

        $this->get("/api/factures/{$factureAutre->id}/pdf")->assertNotFound();
    }
}
