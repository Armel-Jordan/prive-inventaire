<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\ScanTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Disponibilité des produits (phase 1.4, réservation) : stock compté, réservé par les commandes acceptées (reliquats
 * déduits), disponible, en commande chez les fournisseurs, attendu en reliquat ; manques signalés à l'acceptation
 * d'une commande, sans la bloquer.
 */
class DisponibilitesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private int $clientId;

    private int $cimentId;

    private int $sableId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        Sanctum::actingAs($this->admin, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
        [$this->clientId, $this->cimentId, $this->sableId] = $this->chez(fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
            ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 150])->id,
            ProduitTenant::create(['numero' => 'SAB', 'description' => 'Sable', 'prix_unitaire' => 20])->id,
        ]);
        // Stock compté : 10 sacs de ciment (deux comptages), 50 de sable.
        $this->chez(function () {
            foreach ([['CIM', 6], ['CIM', 4], ['SAB', 50]] as [$numero, $quantite]) {
                ScanTenant::create(['numero' => $numero, 'quantite' => $quantite, 'employe' => 'Paul', 'secteur' => 'A-01', 'date_saisie' => now()]);
            }
        });
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function commande(array $lignes): int
    {
        $id = $this->postJson('/api/commandes-client', ['client_id' => $this->clientId, 'date_commande' => '2026-10-16',
            'lignes' => array_map(fn ($l) => ['produit_id' => $l[0], 'quantite' => $l[1], 'prix_unitaire_ht' => 100, 'taux_tva' => 0], $lignes)])->assertCreated()->json('id');
        $this->postJson("/api/commandes-client/{$id}/soumettre")->assertOk();

        return $id;
    }

    private function disponibilites(string $parametres = ''): array
    {
        return collect($this->getJson('/api/disponibilites'.$parametres)->assertOk()->json('data'))->keyBy('numero')
            ->map(fn (array $d) => [$d['stock'], $d['reserve'], $d['disponible'], $d['en_commande'], $d['reliquats'], $d['manque']])->all();
    }

    public function test_reserve_disponible_en_commande_reliquats_et_manques(): void
    {
        // Rien d'engagé : la liste est vide (la liste complète montre le stock).
        $this->assertSame([], $this->disponibilites());
        $this->assertEquals([10, 0, 10, 0, 0, 0], $this->disponibilites('?tous=1')['CIM']);

        // Commande acceptée de 8 ciments : réservés, disponibles 2. Aucun manque.
        $premiere = $this->commande([[$this->cimentId, 8]]);
        $this->postJson("/api/commandes-client/{$premiere}/accepter")->assertOk()->assertJsonPath('manques', []);
        $this->assertEquals(['CIM' => [10, 8, 2, 0, 0, 0]], $this->disponibilites());

        // Une seconde de 5 ciments et 5 sables : 3 ciments manquent, signalés sans bloquer l'acceptation.
        $seconde = $this->commande([[$this->cimentId, 5], [$this->sableId, 5]]);
        $acceptee = $this->postJson("/api/commandes-client/{$seconde}/accepter")->assertOk()->assertJsonPath('statut', 'acceptee')->json('manques');
        $this->assertEquals([['produit_id' => $this->cimentId, 'numero' => 'CIM', 'description' => 'Ciment', 'commande' => 5, 'disponible' => -3, 'manque' => 3]], $acceptee);
        $this->assertEquals([10, 13, -3, 0, 0, 3], $this->disponibilites()['CIM']);
        $this->assertSame(['CIM'], array_keys($this->disponibilites('?manques=1')));

        // Les 3 manquants notés en reliquat : ils ne sont plus réservés sur le stock, mais attendus du fournisseur.
        $ligne = $this->getJson("/api/reliquats-clients/depuis-commande/{$seconde}")->json('lignes.0.ligne_id');
        $this->postJson("/api/reliquats-clients/depuis-commande/{$seconde}", ['lignes' => [['ligne_id' => $ligne, 'quantite' => 3]]])->assertCreated();
        $this->assertEquals([10, 10, 0, 0, 3, 0], $this->disponibilites()['CIM']);

        // En commande chez un fournisseur : ce qui reste à recevoir des commandes envoyées.
        $this->chez(function () {
            $fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimenterie', 'actif' => true]);
            $commande = ComFourEntete::create(['numero' => 'BC-1', 'fournisseur_id' => $fournisseur->id, 'date_commande' => '2026-10-16',
                'statut' => ComFourEntete::STATUT_ENVOYEE, 'montant_total' => 0, 'created_by' => $this->admin->id]);
            ComFourLigne::create(['com_four_entete_id' => $commande->id, 'produit_id' => $this->cimentId, 'quantite_commandee' => 20, 'quantite_recue' => 0, 'prix_unitaire' => 80, 'montant_ligne' => 1600]);
        });
        $this->assertEquals([10, 10, 0, 20, 3, 0], $this->disponibilites()['CIM']);
        $this->assertEquals([50, 5, 45, 0, 0, 0], $this->disponibilites('?recherche=sable')['SAB']);
        $this->assertSame(['SAB'], array_keys($this->disponibilites('?recherche=sable')));
    }
}
