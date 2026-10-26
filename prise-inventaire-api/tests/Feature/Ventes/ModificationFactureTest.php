<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\DocumentTaxe;
use App\Models\Facture;
use App\Models\FactureLigne;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Modifier une facture brouillon (anomalie A2) : en-tête et lignes remplacées, taxes et totaux recalculés, échéance
 * de la condition de paiement ; une facture émise est figée ; une facture créée par un reliquat (abonnement, contrat,
 * consignation) se corrige à sa source ; le client d'une facture tirée d'une commande ne change pas ; droits et
 * isolation.
 */
class ModificationFactureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $autreClientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        [$this->clientId, $this->autreClientId, $this->produitId] = $this->chezLeTenant(fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue du Test', 'ville' => 'Douala', 'code_postal' => '00000'])->id,
            Client::create(['code' => 'CLI-2', 'raison_sociale' => 'Autre Client', 'adresse_facturation' => '2 rue du Test', 'ville' => 'Douala', 'code_postal' => '00000'])->id,
            ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        $this->connecter('admin');
    }

    private function connecter(string $role, ?Tenant $tenant = null): void
    {
        $tenant ??= $this->tenant;
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(['role' => $role]), ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function saisie(array $lignes, array $entete = []): array
    {
        return $entete + ['client_id' => $this->clientId, 'date_facture' => '2026-10-20', 'lignes' => $lignes];
    }

    private function brouillon(): int
    {
        return $this->postJson('/api/factures', $this->saisie([['produit_id' => $this->produitId, 'quantite' => 2, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]]))
            ->assertCreated()->json('id');
    }

    public function test_un_brouillon_se_modifie_et_ses_totaux_sont_recalcules(): void
    {
        $id = $this->brouillon();
        $numero = $this->chezLeTenant(fn () => Facture::findOrFail($id)->numero);
        $anciennes = $this->chezLeTenant(fn () => FactureLigne::where('facture_id', $id)->pluck('id')->all());

        $this->putJson("/api/factures/{$id}", $this->saisie([
            ['produit_id' => $this->produitId, 'quantite' => 3, 'prix_unitaire_ht' => 50, 'taux_tva' => 10],
            ['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 200, 'taux_tva' => 10, 'remise_ligne' => 50],
        ], ['date_facture' => '2026-10-21', 'notes' => 'Corrigée']))
            ->assertOk()
            ->assertJsonPath('numero', $numero)
            ->assertJsonPath('notes', 'Corrigée')
            ->assertJsonCount(2, 'lignes');

        $facture = $this->chezLeTenant(fn () => Facture::with('lignes')->findOrFail($id));
        // 3 × 50 + 1 × 200 × 50 % = 250 HT ; TVA 10 % : 275 TTC, tout reste à payer.
        $this->assertEqualsWithDelta(250, (float) $facture->montant_ht, 0.001);
        $this->assertEqualsWithDelta(275, (float) $facture->montant_ttc, 0.001);
        $this->assertEqualsWithDelta(275, (float) $facture->reste_a_payer, 0.001);
        $this->assertSame('2026-10-21', $facture->date_facture->toDateString());
        // Les anciennes lignes et leur détail de taxes sont retirés.
        $this->assertSame(0, $this->chezLeTenant(fn () => FactureLigne::whereIn('id', $anciennes)->count()));
        $this->assertSame(0, DocumentTaxe::where('document', 'facture')->whereIn('ligne_id', $anciennes)->count());
    }

    public function test_une_facture_emise_est_figee(): void
    {
        $id = $this->brouillon();
        $this->postJson("/api/factures/{$id}/emettre")->assertOk();

        $this->putJson("/api/factures/{$id}", $this->saisie([['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 1]]))
            ->assertUnprocessable()->assertJsonPath('message', 'Seules les factures en brouillon peuvent être modifiées.');
    }

    public function test_une_facture_de_reliquats_se_corrige_a_sa_source(): void
    {
        $id = $this->brouillon();
        $this->chezLeTenant(fn () => DB::table('reliquats_clients')->insert([
            'tenant_id' => $this->tenant->id, 'client_id' => $this->clientId, 'produit_id' => $this->produitId, 'quantite' => 2,
            'statut' => 'facture', 'facture_id' => $id, 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->putJson("/api/factures/{$id}", $this->saisie([['produit_id' => $this->produitId, 'quantite' => 5, 'prix_unitaire_ht' => 100]]))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Cette facture vient de reliquats clients : corrigez-la à sa source, ou supprimez-la et refaites-la.');
    }

    public function test_le_client_d_une_facture_tiree_d_une_commande_ne_change_pas(): void
    {
        $id = $this->brouillon();
        $this->chezLeTenant(function () use ($id) {
            $commande = ComClientEntete::create(['numero' => 'CMD-0001', 'client_id' => $this->clientId, 'date_commande' => '2026-10-15', 'statut' => 'acceptee']);
            Facture::whereKey($id)->update(['commande_id' => $commande->id]);
        });

        $this->putJson("/api/factures/{$id}", $this->saisie([['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]], ['client_id' => $this->autreClientId]))
            ->assertUnprocessable();
        $this->putJson("/api/factures/{$id}", $this->saisie([['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]]))
            ->assertOk();
    }

    public function test_droits_et_isolation(): void
    {
        $id = $this->brouillon();
        $saisie = $this->saisie([['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]]);

        $this->connecter('readonly');
        $this->putJson("/api/factures/{$id}", $saisie)->assertForbidden()->assertJsonPath('module', 'factures');

        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->connecter('admin', $autre);
        $this->putJson("/api/factures/{$id}", $saisie)->assertNotFound();
    }
}
