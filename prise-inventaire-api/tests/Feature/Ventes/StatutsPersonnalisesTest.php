<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\ProduitTenant;
use App\Models\StatutPersonnalise;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Statuts personnalisés (plan de parité fripv2, phase 1.11c) : une étiquette par document, de son type ou « autre »,
 * posée même sur un document clos avec le droit de modifier le document ; filtre des listes ; une étiquette désactivée
 * reste sur ses documents ; suppression refusée si elle est posée ; isolation des entreprises.
 */
class StatutsPersonnalisesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        [$this->clientId, $this->produitId] = $this->chez(fn () => [
            Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
            ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        $this->agir($this->admin);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        app(TenantContext::class)->reset();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function statut(string $document, string $code, string $libelle, array $autres = []): int
    {
        return $this->postJson('/api/referentiels/statuts_personnalises', $autres + ['document' => $document, 'code' => $code, 'libelle' => $libelle,
            'couleur' => '#fde68a', 'couleur_texte' => '#92400e', 'actif' => true])->assertCreated()->json('id');
    }

    private function devis(): int
    {
        return $this->postJson('/api/devis', ['client_id' => $this->clientId, 'date_devis' => '2026-10-16', 'date_validite' => '2026-11-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire' => 100]]])->assertCreated()->json('id');
    }

    public function test_une_etiquette_par_document_de_son_type_meme_clos(): void
    {
        $relancer = $this->statut('devis', 'RELANCE', 'À relancer');
        $urgent = $this->statut('autre', 'URGENT', 'Urgent');
        $speciale = $this->statut('commande_client', 'SPEC', 'Commande spéciale');
        $devis = $this->devis();
        $autreDevis = $this->devis();

        // Une étiquette du type du document, ou « autre » ; pas celle d'un autre type.
        $this->putJson("/api/devis/{$devis}/statut-personnalise", ['statut_personnalise_id' => $relancer])->assertOk()
            ->assertJsonPath('statut_personnalise.libelle', 'À relancer')->assertJsonPath('statut_personnalise.couleur_texte', '#92400e');
        $this->putJson("/api/devis/{$autreDevis}/statut-personnalise", ['statut_personnalise_id' => $urgent])->assertOk();
        $this->putJson("/api/devis/{$devis}/statut-personnalise", ['statut_personnalise_id' => $speciale])
            ->assertUnprocessable()->assertJsonValidationErrors(['statut_personnalise_id']);
        $this->getJson("/api/devis/{$devis}")->assertOk()->assertJsonPath('statut_personnalise.libelle', 'À relancer');

        // Filtre des listes.
        $this->assertSame([$devis], array_column($this->getJson("/api/devis?statut_personnalise_id={$relancer}")->assertOk()->json('data'), 'id'));

        // Désactivée : elle reste sur le document (et s'y garde), mais ne se pose plus ailleurs.
        $this->putJson("/api/referentiels/statuts_personnalises/{$urgent}", ['document' => 'autre', 'code' => 'URGENT', 'libelle' => 'Urgent', 'actif' => false])->assertOk();
        $this->getJson("/api/devis/{$autreDevis}")->assertJsonPath('statut_personnalise.libelle', 'Urgent');
        $this->putJson("/api/devis/{$autreDevis}/statut-personnalise", ['statut_personnalise_id' => $urgent])->assertOk();
        $this->putJson("/api/devis/{$devis}/statut-personnalise", ['statut_personnalise_id' => $urgent])
            ->assertUnprocessable()->assertJsonValidationErrors(['statut_personnalise_id']);

        // Retirée avec null ; la valeur est exigée.
        $this->putJson("/api/devis/{$devis}/statut-personnalise", ['statut_personnalise_id' => null])->assertOk()->assertJsonPath('statut_personnalise', null);
        $this->putJson("/api/devis/{$devis}/statut-personnalise", [])->assertUnprocessable();

        // Un document clos (facture payée, puis annulée) s'étiquette aussi.
        $payee = $this->statut('facture', 'LITIGE', 'Litige');
        $facture = $this->chez(fn () => Facture::create(['numero' => 'FAC-1', 'client_id' => $this->clientId, 'date_facture' => '2026-10-16', 'statut' => 'payee',
            'montant_ht' => 100, 'montant_tva' => 0, 'montant_ttc' => 100, 'reste_a_payer' => 0])->id);
        $this->putJson("/api/factures/{$facture}/statut-personnalise", ['statut_personnalise_id' => $payee])->assertOk();
        $this->assertSame([$facture], array_column($this->getJson("/api/factures?statut_personnalise_id={$payee}")->json('data'), 'id'));

        // Posée sur un document, elle ne se supprime pas ; libre, si.
        $this->deleteJson("/api/referentiels/statuts_personnalises/{$relancer}")->assertOk();
        $this->deleteJson("/api/referentiels/statuts_personnalises/{$payee}")->assertUnprocessable()
            ->assertJsonPath('message', 'Ce statut est posé sur des documents : désactivez-le plutôt.');
    }

    public function test_droits_et_isolation(): void
    {
        $relancer = $this->statut('devis', 'RELANCE', 'À relancer');
        $devis = $this->devis();

        // L'étiquette d'une autre entreprise est refusée.
        $etrangere = app(TenantContext::class)->runAsTenant(Tenant::factory()->create()->id,
            fn () => StatutPersonnalise::create(['document' => 'devis', 'code' => 'X', 'libelle' => 'Ailleurs', 'actif' => true])->id);
        $this->putJson("/api/devis/{$devis}/statut-personnalise", ['statut_personnalise_id' => $etrangere])
            ->assertUnprocessable()->assertJsonValidationErrors(['statut_personnalise_id']);

        // Il faut le droit de modifier le document : consulter ne suffit pas.
        $lecteur = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->putJson("/api/acces-comptes/{$lecteur->id}", ['groupes' => [], 'exceptions' => ['devis.voir' => true, 'devis.modifier' => false]])->assertOk();
        $this->agir($lecteur);
        $this->putJson("/api/devis/{$devis}/statut-personnalise", ['statut_personnalise_id' => $relancer])->assertForbidden();
        $this->assertNull($this->chez(fn () => Devis::findOrFail($devis)->statut_personnalise_id));
    }
}
