<?php

namespace Tests\Feature\Documents;

use App\Models\AdminUser;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\EntreprisePdf;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bon de commande fournisseur : au nom et dans la devise de l'entreprise qui commande (il affichait
 * le nom de l'application, des montants en euros et une TVA de 20 % codée en dur), servi comme les
 * autres PDF avec le jeton en en-tête (plus de jeton dans l'URL).
 */
class BonCommandePdfTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $commandeId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['modules' => ['achats']]);
        $acheteur = AdminUser::factory()->for($this->tenant)->create(['nom' => 'Paul Mbarga']);
        $this->commandeId = $this->chezLeTenant(function () use ($acheteur) {
            TenantParametres::create([
                'nom_entreprise' => 'Quincaillerie Ndjock', 'devise_symbole' => 'FCFA', 'email' => 'achats@ndjock.cm',
                'telephone' => '+237 699 00 00 00', 'adresse' => 'Akwa, Douala',
            ]);
            $fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimencam']);
            $produit = ProduitTenant::create(['numero' => 'CIM-50', 'description' => 'Ciment 50 kg', 'prix_unitaire' => 5000]);
            $commande = ComFourEntete::create([
                'numero' => 'BC-0001', 'fournisseur_id' => $fournisseur->id, 'date_commande' => '2026-10-16',
                'statut' => 'brouillon', 'montant_total' => 42000, 'created_by' => $acheteur->id,
            ]);
            ComFourLigne::create([
                'com_four_entete_id' => $commande->id, 'produit_id' => $produit->id,
                'quantite_commandee' => 10, 'prix_unitaire' => 4200, 'montant_ligne' => 42000,
            ]);

            return $commande->id;
        });
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function connecter(Tenant $tenant): void
    {
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(), ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);
    }

    public function test_au_nom_et_dans_la_devise_de_l_entreprise_sans_tva_codee_en_dur(): void
    {
        $html = $this->chezLeTenant(fn () => view('pdf.bon-commande', [
            'commande' => ComFourEntete::with(['fournisseur', 'lignes.produit', 'createdBy'])->find($this->commandeId),
        ] + EntreprisePdf::entete())->render());

        $this->assertStringContainsString('Quincaillerie Ndjock', $html);
        $this->assertStringContainsString('achats@ndjock.cm', $html);
        $this->assertStringContainsString('42 000,00 FCFA', $html);
        $this->assertStringContainsString('TOTAL HT', $html);
        $this->assertStringNotContainsString('TVA (20%)', $html);
        $this->assertStringNotContainsString('€', $html);
        $this->assertStringNotContainsString('Prise Inventaire', $html);
    }

    public function test_servi_avec_le_jeton_en_en_tete_et_dans_l_entreprise_seulement(): void
    {
        $this->getJson("/api/commandes-fournisseur/{$this->commandeId}/pdf")->assertUnauthorized();

        $this->connecter($this->tenant);
        $reponse = $this->get("/api/commandes-fournisseur/{$this->commandeId}/pdf")->assertOk();
        $this->assertSame('application/pdf', $reponse->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $reponse->getContent());

        $this->connecter(Tenant::factory()->create(['modules' => ['achats']]));
        $this->get("/api/commandes-fournisseur/{$this->commandeId}/pdf")->assertNotFound();
    }
}
