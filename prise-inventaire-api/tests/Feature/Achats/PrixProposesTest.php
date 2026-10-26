<?php

namespace Tests\Feature\Achats;

use App\Models\AdminUser;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Devise;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Prix proposés sur une commande fournisseur (anomalie A5) : dernier prix payé au fournisseur (commande envoyée ou
 * reçue, pas un brouillon), sinon prix convenu de son catalogue ; ramené à l'unité d'achat actuelle et à la devise de
 * la commande ; rien si aucun prix n'est connu ; fournisseur d'une autre entreprise refusé.
 */
class PrixProposesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $fournisseurId;

    private int $produitId;

    private AdminUser $acheteur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['achats', 'ventes']]);
        $this->acheteur = AdminUser::factory()->for($this->tenant)->create();
        [$this->fournisseurId, $this->produitId] = $this->chez(function () {
            TenantParametres::create(['nom_entreprise' => 'Ma Société', 'devise_code' => 'XAF']);
            Devise::create(['code' => 'USD', 'libelle' => 'Dollar', 'taux' => 600]);

            return [
                Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimenterie du Sud', 'actif' => true, 'devise' => 'XAF'])->id,
                ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
            ];
        });
        Sanctum::actingAs($this->acheteur, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function commande(string $statut, float $prix, int $qte = 1, string $devise = 'XAF', float $taux = 1, string $date = '2026-10-01'): void
    {
        $this->chez(function () use ($statut, $prix, $qte, $devise, $taux, $date) {
            $commande = ComFourEntete::create([
                'numero' => 'BC-'.uniqid(), 'fournisseur_id' => $this->fournisseurId, 'date_commande' => $date, 'statut' => $statut,
                'devise' => $devise, 'taux_change' => $taux, 'created_by' => $this->acheteur->id,
            ]);
            ComFourLigne::create([
                'com_four_entete_id' => $commande->id, 'produit_id' => $this->produitId, 'quantite_commandee' => 1,
                'qte_par_unite_achat' => $qte, 'prix_unitaire' => $prix, 'montant_ligne' => $prix,
            ]);
        });
    }

    private function catalogue(array $valeurs): void
    {
        DB::table('produit_fournisseur')->updateOrInsert(
            ['produit_id' => $this->produitId, 'fournisseur_id' => $this->fournisseurId],
            $valeurs + ['created_at' => now(), 'updated_at' => now()],
        );
    }

    private function proposer(string $devise = 'XAF', float $taux = 1): array
    {
        return $this->getJson('/api/commandes-fournisseur/prix-proposes?'.http_build_query([
            'fournisseur_id' => $this->fournisseurId, 'produits' => [$this->produitId], 'devise' => $devise, 'taux_change' => $taux,
        ]))->assertOk()->json((string) $this->produitId);
    }

    public function test_sans_prix_connu_rien_n_est_propose(): void
    {
        $this->assertSame(['prix' => null, 'origine' => null, 'document' => null], $this->proposer());
    }

    public function test_le_dernier_prix_paye_l_emporte_sur_le_prix_convenu_et_les_brouillons_ne_comptent_pas(): void
    {
        $this->catalogue(['prix_achat' => 1200]);
        $this->assertSame('convenu', $this->proposer()['origine']);
        $this->assertEquals(1200, $this->proposer()['prix']);

        $this->commande('envoyee', 1000, date: '2026-09-01');
        $this->commande('brouillon', 500, date: '2026-10-20');
        $this->commande('annulee', 400, date: '2026-10-21');

        $proposition = $this->proposer();
        $this->assertSame('dernier_paye', $proposition['origine']);
        $this->assertEquals(1000, $proposition['prix']);
        $this->assertStringStartsWith('BC-', $proposition['document']);
    }

    public function test_le_prix_suit_l_unite_d_achat_actuelle(): void
    {
        // Payé 100 l'unité ; le fournisseur livre maintenant par caisses de 12.
        $this->commande('complete', 100);
        $this->catalogue(['unite_achat' => 'Caisse', 'qte_par_unite_achat' => 12]);

        $this->assertEquals(1200, $this->proposer()['prix']);
    }

    public function test_le_prix_est_converti_dans_la_devise_de_la_commande(): void
    {
        // Prix convenu de 2 USD chez un fournisseur qui facture en dollars (1 USD = 600 XAF) : 1 200 XAF.
        $this->chez(fn () => Fournisseur::whereKey($this->fournisseurId)->update(['devise' => 'USD']));
        $this->catalogue(['prix_achat' => 2]);
        $this->assertEquals(1200, $this->proposer()['prix']);
        $this->assertEquals(2, $this->proposer('USD', 600)['prix']);

        // Dernier paiement en francs CFA, nouvelle commande en dollars au taux de 600.
        $this->commande('envoyee', 3000);
        $this->assertEquals(5, $this->proposer('USD', 600)['prix']);
    }

    public function test_fournisseur_d_une_autre_entreprise_refuse(): void
    {
        $autre = Tenant::factory()->create(['modules' => ['achats']]);
        $etranger = app(TenantContext::class)->runAsTenant($autre->id, fn () => Fournisseur::create(['code' => 'X', 'raison_sociale' => 'Ailleurs', 'actif' => true]));

        $this->getJson('/api/commandes-fournisseur/prix-proposes?'.http_build_query(['fournisseur_id' => $etranger->id, 'produits' => [$this->produitId]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['fournisseur_id']);
    }
}
