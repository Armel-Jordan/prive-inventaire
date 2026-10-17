<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Camion;
use App\Models\Client;
use App\Models\ClientAdresse;
use App\Models\Itineraire;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\Tournee;
use App\Services\FeuilleDeRoutePdf;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Séquences de livraison (phase 1.6) : route et rang de passage du client ; tournée qui suit une route et reçoit ses
 * bons prêts dans l'ordre de passage ; arrêts rangés par séquence (sans rang à la fin) ; feuille de route du livreur ;
 * droits.
 */
class SequencesLivraisonTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $produitId;

    private int $route;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->produitId, $this->route] = $this->chezLeTenant(fn () => [
            ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
            Itineraire::create(['code' => 'R1', 'nom' => 'Douala nord', 'actif' => true])->id,
        ]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
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

    private function client(string $nom, array $autres = []): int
    {
        return $this->postJson('/api/clients', $autres + ['raison_sociale' => $nom, 'adresse_facturation' => "Rue {$nom}", 'ville' => 'Douala', 'code_postal' => '0'])
            ->assertCreated()->json('id');
    }

    /** Bon de livraison prêt d'une facture émise pour ce client. */
    private function bonPret(int $client): int
    {
        $facture = $this->postJson('/api/factures', ['client_id' => $client, 'date_facture' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 2, 'prix_unitaire_ht' => 100]]])->assertCreated()->json('id');
        $this->postJson("/api/factures/{$facture}/emettre")->assertOk();
        $bon = $this->postJson("/api/factures/{$facture}/creer-bl")->assertCreated()->json('id');
        $this->postJson("/api/bons-livraison/{$bon}/preparer")->assertOk();
        $this->postJson("/api/bons-livraison/{$bon}/pret")->assertOk();

        return $bon;
    }

    private function ordre(int $tournee): array
    {
        return collect($this->getJson("/api/tournees/{$tournee}")->assertOk()->json('tournee_bons'))
            ->sortBy('ordre_livraison')->pluck('bon_livraison.facture.client.raison_sociale')->values()->all();
    }

    public function test_route_ordre_de_passage_et_feuille_de_route(): void
    {
        // Route et rang de passage des clients ; un itinéraire inconnu est refusé.
        $loin = $this->client('Quincaillerie Loin', ['itineraire_id' => $this->route, 'sequence_livraison' => 20, 'telephone' => '699 11 11 11']);
        $pres = $this->client('Boutique Près', ['itineraire_id' => $this->route, 'sequence_livraison' => 10]);
        $hors = $this->client('Client hors route');
        $this->putJson("/api/clients/{$hors}", ['itineraire_id' => 999])->assertUnprocessable()->assertJsonValidationErrors(['itineraire_id']);
        $this->chezLeTenant(fn () => ClientAdresse::create(['client_id' => $pres, 'libelle' => 'Dépôt', 'adresse' => '12 avenue du Port', 'ville' => 'Bonabéri',
            'livraison' => true, 'principale' => true, 'instructions' => 'Livrer par le portail arrière.', 'actif' => true]));

        [$bonLoin, $bonPres, $bonHors] = [$this->bonPret($loin), $this->bonPret($pres), $this->bonPret($hors)];
        $camion = $this->chezLeTenant(fn () => Camion::create(['immatriculation' => 'LT-001-AA', 'statut' => 'disponible'])->id);
        $tournee = $this->postJson('/api/tournees', ['date_tournee' => '2026-10-16', 'camion_id' => $camion, 'itineraire_id' => $this->route])->assertCreated()->json('id');

        // Les bons de la route arrivent d'un coup, dans l'ordre de passage ; pas celui du client hors route.
        $this->postJson("/api/tournees/{$tournee}/ajouter-route")->assertOk()->assertJsonPath('ajoutes', 2)->assertJsonPath('itineraire.code', 'R1');
        $this->assertSame(['Boutique Près', 'Quincaillerie Loin'], $this->ordre($tournee));
        $this->postJson("/api/tournees/{$tournee}/ajouter-route")->assertOk()->assertJsonPath('ajoutes', 0);

        // Le bon hors route s'ajoute à la fin ; déplacé en tête à la main, il revient à la fin quand on range.
        $this->postJson("/api/tournees/{$tournee}/ajouter-bon", ['bon_livraison_id' => $bonHors])->assertOk();
        $this->putJson("/api/tournees/{$tournee}/ordre", ['ordre' => [
            ['bon_livraison_id' => $bonHors, 'ordre_livraison' => 1], ['bon_livraison_id' => $bonPres, 'ordre_livraison' => 2], ['bon_livraison_id' => $bonLoin, 'ordre_livraison' => 3],
        ]])->assertOk();
        $this->assertSame(['Client hors route', 'Boutique Près', 'Quincaillerie Loin'], $this->ordre($tournee));
        $this->postJson("/api/tournees/{$tournee}/ordonner")->assertOk();
        $this->assertSame(['Boutique Près', 'Quincaillerie Loin', 'Client hors route'], $this->ordre($tournee));

        // Feuille de route : arrêts dans l'ordre, adresse de livraison principale et ses instructions, téléphone.
        $arrets = $this->chezLeTenant(fn () => app(FeuilleDeRoutePdf::class)->arrets(Tournee::findOrFail($tournee)));
        $this->assertSame([1, 'Boutique Près', 10, '12 avenue du Port, Bonabéri', 'Livrer par le portail arrière.'],
            [$arrets[0]['ordre'], $arrets[0]['client'], $arrets[0]['sequence'], $arrets[0]['adresse'], $arrets[0]['instructions']]);
        $this->assertSame(['Quincaillerie Loin', 'Rue Quincaillerie Loin, Douala', '699 11 11 11'], [$arrets[1]['client'], $arrets[1]['adresse'], $arrets[1]['telephone']]);
        $this->assertStringStartsWith('%PDF', $this->get("/api/tournees/{$tournee}/feuille-de-route")->assertOk()->getContent());
    }

    public function test_droits_et_tournee_demarree(): void
    {
        $client = $this->client('Boutique', ['itineraire_id' => $this->route, 'sequence_livraison' => 5]);
        $bon = $this->bonPret($client);
        $camion = $this->chezLeTenant(fn () => Camion::create(['immatriculation' => 'LT-002-AA', 'statut' => 'disponible'])->id);
        $tournee = $this->postJson('/api/tournees', ['date_tournee' => '2026-10-16', 'camion_id' => $camion])->assertCreated()->json('id');
        // Sans route, rien à ajouter d'un coup.
        $this->postJson("/api/tournees/{$tournee}/ajouter-route")->assertUnprocessable()->assertJsonPath('message', 'Cette tournée ne suit pas de route.');

        // Ranger et ajouter la route demandent le droit de piloter les tournées ; la feuille de route se lit.
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => ['tournees.piloter' => false]])->assertOk();
        $this->agir($vendeur->fresh());
        $this->postJson("/api/tournees/{$tournee}/ordonner")->assertForbidden();
        $this->postJson("/api/tournees/{$tournee}/ajouter-route")->assertForbidden();
        $this->get("/api/tournees/{$tournee}/feuille-de-route")->assertOk();

        // Tournée démarrée : l'ordre ne change plus.
        $this->agir($this->admin);
        $this->postJson("/api/tournees/{$tournee}/ajouter-bon", ['bon_livraison_id' => $bon])->assertOk();
        $this->postJson("/api/tournees/{$tournee}/demarrer")->assertOk();
        $this->postJson("/api/tournees/{$tournee}/ordonner")->assertUnprocessable();
    }
}
