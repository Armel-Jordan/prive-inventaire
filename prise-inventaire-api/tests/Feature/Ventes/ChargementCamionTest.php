<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Camion;
use App\Models\Client;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Chargement du camion (phase 1.6) : arrêts dans l'ordre de chargement (dernier livré d'abord), colis et vrac,
 * étiquette scannée (inconnue, d'une autre tournée, déjà chargée), cases cochées et décochées, colis chargé figé,
 * avancement dans la tournée, fiche de chargement, bon retiré déchargé ; droits, départ et isolation.
 */
class ChargementCamionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private array $produits;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-17 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->produits] = $this->chezLeTenant(fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
            [ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id, ProduitTenant::create(['numero' => 'SAB', 'description' => 'Sable', 'prix_unitaire' => 10])->id],
        ]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Awa Magasin']);
        $this->agir($this->admin);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    /** Bon de livraison en préparation pour ces quantités (ciment, sable). */
    private function bon(int $ciment, int $sable = 0): array
    {
        $lignes = array_values(array_filter([
            $ciment ? ['produit_id' => $this->produits[0], 'quantite' => $ciment, 'prix_unitaire_ht' => 100] : null,
            $sable ? ['produit_id' => $this->produits[1], 'quantite' => $sable, 'prix_unitaire_ht' => 10] : null,
        ]));
        $facture = $this->postJson('/api/factures', ['client_id' => $this->clientId, 'date_facture' => '2026-10-17', 'lignes' => $lignes])->assertCreated()->json('id');
        $this->postJson("/api/factures/{$facture}/emettre")->assertOk();
        $bon = $this->postJson("/api/factures/{$facture}/creer-bl")->assertCreated()->json();
        $this->postJson("/api/bons-livraison/{$bon['id']}/preparer")->assertOk();

        return $bon;
    }

    /** Nouveau colis du bon ; renvoie [id, numéro]. */
    private function colis(array $bon, int $ligne, int $quantite): array
    {
        $colis = collect($this->postJson("/api/bons-livraison/{$bon['id']}/colis", ['lignes' => [['bon_livraison_ligne_id' => $bon['lignes'][$ligne]['id'], 'quantite' => $quantite]]])
            ->assertCreated()->json('colis'))->last();

        return [$colis['id'], $colis['numero']];
    }

    private function tournee(array $bons): int
    {
        $camion = $this->chezLeTenant(fn () => Camion::create(['immatriculation' => 'LT-'.count($bons).'-'.random_int(100, 999), 'statut' => 'disponible'])->id);
        $tournee = $this->postJson('/api/tournees', ['date_tournee' => '2026-10-17', 'camion_id' => $camion])->assertCreated()->json('id');
        foreach ($bons as $bon) {
            $this->postJson("/api/bons-livraison/{$bon['id']}/pret")->assertOk();
            $this->postJson("/api/tournees/{$tournee}/ajouter-bon", ['bon_livraison_id' => $bon['id']])->assertOk();
        }

        return $tournee;
    }

    public function test_ordre_de_chargement_colis_vrac_et_scan(): void
    {
        // Premier arrêt : tout en deux colis. Second : un colis de ciment, le sable hors colis.
        $premier = $this->bon(10);
        [$a1, $numeroA1] = $this->colis($premier, 0, 6);
        [$a2, $numeroA2] = $this->colis($premier, 0, 4);
        $second = $this->bon(5, 4);
        [$b1] = $this->colis($second, 0, 5);
        $tournee = $this->tournee([$premier, $second]);

        // Ordre de chargement : le dernier arrêt d'abord ; le vrac du second arrêt ; rien de coché.
        $etat = $this->getJson("/api/tournees/{$tournee}/chargement")->assertOk()->json();
        $this->assertSame([[$second['numero'], 2], [$premier['numero'], 1]], array_map(fn ($a) => [$a['bon'], $a['ordre']], $etat['arrets']));
        $this->assertSame([['Sable', 4]], array_map(fn ($v) => [$v['produit']['description'], $v['quantite']], $etat['arrets'][0]['vrac']));
        $this->assertSame([], $etat['arrets'][1]['vrac']);
        $this->assertSame(['Ciment', 6], [$etat['arrets'][1]['colis'][0]['contenu'][0]['produit']['description'], $etat['arrets'][1]['colis'][0]['contenu'][0]['quantite']]);
        $this->assertSame(['colis' => 3, 'colis_charges' => 0, 'vrac' => 1, 'vrac_charges' => 0, 'reste' => 4, 'commence' => false], $etat['resume']);

        // Étiquettes scannées : chargé, déjà chargé (premier pointage gardé), numéro avec espaces, inconnu.
        $this->postJson("/api/tournees/{$tournee}/chargement/scan", ['numero' => $numeroA1])->assertOk()
            ->assertJsonPath('message', "Colis {$numeroA1} chargé.")->assertJsonPath('deja', false)
            ->assertJsonPath('arrets.1.colis.0.charge_par', 'Awa Magasin')->assertJsonPath('resume.commence', true);
        $this->travel(5)->minutes();
        $this->postJson("/api/tournees/{$tournee}/chargement/scan", ['numero' => $numeroA1])->assertOk()
            ->assertJsonPath('message', "Colis {$numeroA1} déjà chargé.")->assertJsonPath('deja', true)
            ->assertJsonPath('arrets.1.colis.0.charge_le', '2026-10-17T10:00:00.000000Z');
        $this->postJson("/api/tournees/{$tournee}/chargement/scan", ['numero' => " {$numeroA2} "])->assertOk()->assertJsonPath('resume.colis_charges', 2);
        $this->postJson("/api/tournees/{$tournee}/chargement/scan", ['numero' => 'XYZ'])
            ->assertUnprocessable()->assertJsonValidationErrors(['numero' => 'Colis inconnu : XYZ.']);

        // Colis d'un bon hors tournée refusé ; vrac d'un bon hors tournée refusé.
        $ailleurs = $this->bon(0, 1);
        [, $numeroAilleurs] = $this->colis($ailleurs, 0, 1);
        $this->postJson("/api/tournees/{$tournee}/chargement/scan", ['numero' => $numeroAilleurs])
            ->assertUnprocessable()->assertJsonValidationErrors(['colis' => "Le colis {$numeroAilleurs} n'est pas dans cette tournée."]);
        $this->postJson("/api/tournees/{$tournee}/chargement/vrac/{$ailleurs['id']}", ['charge' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['bon_livraison_id' => "Ce bon n'est pas dans cette tournée."]);

        // Cases : colis coché puis un autre décoché ; vrac coché.
        $this->postJson("/api/tournees/{$tournee}/chargement/colis/{$b1}", ['charge' => true])->assertOk()->assertJsonPath('resume.colis_charges', 3);
        $this->postJson("/api/tournees/{$tournee}/chargement/colis/{$a1}", ['charge' => false])->assertOk()
            ->assertJsonPath('resume.colis_charges', 2)->assertJsonPath('arrets.1.colis.0.charge_le', null);
        $this->postJson("/api/tournees/{$tournee}/chargement/vrac/{$second['id']}", ['charge' => true])->assertOk()
            ->assertJsonPath('arrets.0.vrac_charge_par', 'Awa Magasin')->assertJsonPath('resume.reste', 1);

        // Un colis chargé ne se modifie plus ; décoché, si : le ciment retiré passe hors colis (un lot de vrac de plus).
        $this->putJson("/api/bons-livraison/{$premier['id']}/colis/{$a2}", ['lignes' => [['bon_livraison_ligne_id' => $premier['lignes'][0]['id'], 'quantite' => 3]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['colis' => "Le colis {$numeroA2} est chargé dans le camion : décochez-le avant de le modifier."]);
        $this->deleteJson("/api/bons-livraison/{$premier['id']}/colis/{$a2}")->assertUnprocessable();
        $this->putJson("/api/bons-livraison/{$premier['id']}/colis/{$a1}", ['lignes' => [['bon_livraison_ligne_id' => $premier['lignes'][0]['id'], 'quantite' => 5]]])->assertOk();
        $this->getJson("/api/tournees/{$tournee}/chargement")->assertJsonPath('arrets.1.vrac.0.quantite', 1);

        // La tournée donne l'avancement (le colis décoché, le nouveau vrac) ; la fiche de chargement s'imprime.
        $this->getJson("/api/tournees/{$tournee}")->assertOk()->assertJsonPath('chargement.reste', 2)->assertJsonPath('chargement.colis', 3);
        $this->assertStringStartsWith('%PDF', $this->get("/api/tournees/{$tournee}/chargement/pdf")->assertOk()->getContent());

        // Bon retiré de la tournée : ses colis ne sont plus chargés.
        $this->deleteJson("/api/tournees/{$tournee}/bon/{$second['id']}")->assertOk();
        $this->assertNull($this->getJson("/api/bons-livraison/{$second['id']}/colis")->json('colis.0.charge_le'));
        $this->assertNotNull($this->getJson("/api/bons-livraison/{$premier['id']}/colis")->json('colis.1.charge_le'));
    }

    public function test_droits_depart_et_isolation(): void
    {
        $bon = $this->bon(2);
        [$colis, $numero] = $this->colis($bon, 0, 2);
        $tournee = $this->tournee([$bon]);

        // Cocher demande le droit de charger un camion ; l'état et la fiche se consultent.
        $magasinier = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$magasinier->id}", ['groupes' => [], 'exceptions' => ['tournees.charger' => false]])->assertOk();
        $this->agir($magasinier->fresh());
        $this->postJson("/api/tournees/{$tournee}/chargement/scan", ['numero' => $numero])->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Charger un camion » (Tournées).');
        $this->postJson("/api/tournees/{$tournee}/chargement/colis/{$colis}", ['charge' => true])->assertForbidden();
        $this->postJson("/api/tournees/{$tournee}/chargement/vrac/{$bon['id']}", ['charge' => true])->assertForbidden();
        $this->getJson("/api/tournees/{$tournee}/chargement")->assertOk()->assertJsonPath('modifiable', true);
        $this->get("/api/tournees/{$tournee}/chargement/pdf")->assertOk();

        // Catalogue des droits : l'action nommée du chargement.
        $this->agir($this->admin);
        $modules = collect(collect($this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets'))->firstWhere('cle', 'ventes')['modules'])->keyBy('cle');
        $this->assertSame(['tournees.piloter', 'tournees.charger'], array_slice(array_column($modules['tournees']['permissions'], 'code'), 4));

        // Tournée partie : le chargement ne se coche plus (le départ ne dépend pas du chargement).
        $this->postJson("/api/tournees/{$tournee}/demarrer")->assertOk();
        $this->getJson("/api/tournees/{$tournee}/chargement")->assertJsonPath('modifiable', false);
        $this->postJson("/api/tournees/{$tournee}/chargement/scan", ['numero' => $numero])
            ->assertUnprocessable()->assertJsonValidationErrors(['tournee' => 'Le chargement se coche avant le départ de la tournée.']);

        // Une autre entreprise ne voit ni ne coche ce chargement.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->getJson("/api/tournees/{$tournee}/chargement")->assertNotFound();
        $this->get("/api/tournees/{$tournee}/chargement/pdf")->assertNotFound();
        $this->postJson("/api/tournees/{$tournee}/chargement/scan", ['numero' => $numero])->assertNotFound();
        $this->postJson("/api/tournees/{$tournee}/chargement/colis/{$colis}", ['charge' => true])->assertNotFound();
    }
}
