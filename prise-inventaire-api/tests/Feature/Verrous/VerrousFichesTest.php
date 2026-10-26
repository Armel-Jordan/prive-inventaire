<?php

namespace Tests\Feature\Verrous;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\ComFourEntete;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\Verrou;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Verrous des fiches en cours de modification (phase 6.3) : le compte qui modifie une fiche la verrouille ; un autre voit
 * qui la modifie et ne peut ni l'enregistrer ni la supprimer ; le verrou tombe s'il n'est plus renouvelé ; prendre la
 * main et libérer une fiche (droit de libérer) ; l'écran qui renouvelle un verrou perdu l'apprend ; liste des fiches en
 * cours de modification selon les droits ; validation et isolation des entreprises.
 */
class VerrousFichesTest extends TestCase
{
    use RefreshDatabase;

    /** Route de mise à jour (et de suppression) de chaque fiche verrouillable. */
    private const ADRESSES = [
        'client' => '/api/clients/%d',
        'fournisseur' => '/api/fournisseurs/%d',
        'produit' => '/api/produits/%d',
        'devis' => '/api/devis/%d',
        'commande_client' => '/api/commandes-client/%d',
        'commande_fournisseur' => '/api/commandes-fournisseur/%d',
        'facture' => '/api/factures/%d',
    ];

    private Tenant $tenant;

    private AdminUser $paul;

    private AdminUser $awa;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Les renouvellements d'un écran ne doivent pas buter sur la limite de débit gardée d'un test à l'autre.
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(Carbon::parse('2026-10-17 09:00:00'));
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->paul = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Paul Vendeur']);
        $this->awa = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Awa Acheteuse']);
        $this->ids = $this->chez(function () {
            $client = Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => '1 rue du Marché', 'ville' => 'Douala', 'code_postal' => '00000']);
            $fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimenterie du Sud', 'actif' => true]);

            return [
                'client' => $client->id,
                'fournisseur' => $fournisseur->id,
                'produit' => ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment 50 kg', 'prix_unitaire' => 100])->id,
                'devis' => Devis::create(['numero' => 'DEV-0001', 'client_id' => $client->id, 'date_devis' => '2026-10-15', 'date_validite' => '2026-11-15', 'statut' => Devis::STATUT_BROUILLON])->id,
                'commande_client' => ComClientEntete::create(['numero' => 'CMD-0001', 'client_id' => $client->id, 'date_commande' => '2026-10-15', 'statut' => 'brouillon'])->id,
                'commande_fournisseur' => ComFourEntete::create([
                    'numero' => 'BC-0001', 'fournisseur_id' => $fournisseur->id, 'date_commande' => '2026-10-15',
                    'statut' => ComFourEntete::STATUT_BROUILLON, 'taux_change' => 1, 'created_by' => $this->paul->id,
                ])->id,
                'facture' => Facture::create([
                    'numero' => 'FAC-0001', 'client_id' => $client->id, 'date_facture' => '2026-10-15', 'statut' => 'brouillon',
                    'montant_ht' => 0, 'montant_tva' => 0, 'montant_ttc' => 0, 'reste_a_payer' => 0,
                ])->id,
            ];
        });
        $this->agir($this->paul);
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
        $this->withHeader('X-Tenant-Slug', $compte->tenant->slug);
    }

    private function poser(string $entite, array $options = []): TestResponse
    {
        return $this->postJson('/api/verrous', ['entite' => $entite, 'entite_id' => $this->ids[$entite]] + $options);
    }

    private function lever(string $entite): TestResponse
    {
        return $this->deleteJson('/api/verrous', ['entite' => $entite, 'entite_id' => $this->ids[$entite]]);
    }

    private function adresse(string $entite): string
    {
        return sprintf(self::ADRESSES[$entite], $this->ids[$entite]);
    }

    /** Compte « user » avec ses exceptions de droits (réglées par un administrateur). */
    private function usager(string $nom, array $exceptions): AdminUser
    {
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'user', 'nom' => $nom]);
        $this->regler($compte, $exceptions);

        return $compte;
    }

    private function regler(AdminUser $compte, array $exceptions): void
    {
        $this->agir($this->paul);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [], 'exceptions' => $exceptions])->assertOk();
    }

    public function test_le_compte_qui_modifie_une_fiche_la_verrouille_et_l_autre_voit_qui_la_modifie(): void
    {
        $verrou = $this->poser('client')->assertOk()->json('verrou');
        $this->assertSame(['client', $this->ids['client'], true, 'Paul Vendeur', '2026-10-17T09:00:00+00:00', null],
            [$verrou['entite'], $verrou['entite_id'], $verrou['actif'], $verrou['par'], $verrou['depuis'], $verrou['libere_par']]);

        // Awa ouvre la même fiche : elle voit qui la modifie depuis quand, et ne peut ni l'enregistrer ni la supprimer.
        $this->travel(30)->seconds();
        $this->agir($this->awa);
        $refus = $this->poser('client')->assertStatus(409)->json();
        $this->assertSame(['Paul Vendeur', '2026-10-17T09:00:00+00:00', true], [$refus['verrou']['par'], $refus['verrou']['depuis'], $refus['verrou']['actif']]);
        $this->assertSame('Cette fiche est en cours de modification par Paul Vendeur : réessayez quand la modification sera terminée.', $refus['message']);
        $this->putJson($this->adresse('client'), ['raison_sociale' => 'Autre nom'])->assertStatus(409)->assertJsonPath('verrou.par', 'Paul Vendeur');
        $this->deleteJson($this->adresse('client'))->assertStatus(409);

        // Paul renouvelle (la modification garde son début) et enregistre.
        $this->travel(60)->seconds();
        $this->agir($this->paul);
        $this->poser('client', ['renouveler' => true])->assertOk()->assertJsonPath('verrou.depuis', '2026-10-17T09:00:00+00:00');
        $this->putJson($this->adresse('client'), ['raison_sociale' => 'Quincaillerie Mballa et fils'])->assertOk();

        // Il ferme la fiche : Awa la modifie à son tour.
        $this->lever('client')->assertNoContent();
        $this->agir($this->awa);
        $this->poser('client')->assertOk()->assertJsonPath('verrou.par', 'Awa Acheteuse')->assertJsonPath('verrou.depuis', '2026-10-17T09:01:30+00:00');
        $this->putJson($this->adresse('client'), ['raison_sociale' => 'Quincaillerie Mballa SARL'])->assertOk();
        $this->assertSame('Quincaillerie Mballa SARL', $this->chez(fn () => Client::findOrFail($this->ids['client'])->raison_sociale));
    }

    public function test_les_fiches_verrouillables_refusent_l_enregistrement_et_la_suppression_d_un_autre_compte(): void
    {
        foreach (array_keys(self::ADRESSES) as $entite) {
            $this->agir($this->paul);
            $this->poser($entite)->assertOk();

            $this->agir($this->awa);
            $this->putJson($this->adresse($entite), [])->assertStatus(409)->assertJsonPath('verrou.entite', $entite);
            $this->deleteJson($this->adresse($entite))->assertStatus(409);

            // Celui qui la modifie passe (la suite dépend de la fiche et des données envoyées).
            $this->agir($this->paul);
            $statut = $this->putJson($this->adresse($entite), [])->status();
            $this->assertTrue($statut !== 409 && $statut < 500, "{$entite} : {$statut}");
        }
    }

    public function test_un_verrou_non_renouvele_tombe_et_l_ecran_qui_l_a_perdu_l_apprend(): void
    {
        // Écran de Paul en veille plus de trois minutes, personne n'a touché la fiche : il reprend sa modification.
        $this->poser('produit')->assertOk();
        $this->travel(200)->seconds();
        $this->poser('produit', ['renouveler' => true])->assertOk()->assertJsonPath('verrou.depuis', '2026-10-17T09:00:00+00:00');

        // De nouveau en veille : Awa ouvre la fiche entre-temps, et l'enregistre.
        $this->travel(200)->seconds();
        $this->agir($this->awa);
        $this->poser('produit')->assertOk()->assertJsonPath('verrou.depuis', '2026-10-17T09:06:40+00:00');
        $this->putJson($this->adresse('produit'), ['description' => 'Ciment 50 kg CPJ 45'])->assertOk();

        // L'écran de Paul se réveille : la fiche est à Awa, l'enregistrement de Paul est refusé.
        $this->agir($this->paul);
        $this->poser('produit', ['renouveler' => true])->assertStatus(409)->assertJsonPath('verrou.actif', true)->assertJsonPath('verrou.par', 'Awa Acheteuse');
        $this->putJson($this->adresse('produit'), ['description' => 'Ancienne version'])->assertStatus(409);

        // Awa a fermé la fiche : l'écran de Paul ne reprend pas sa modification (la fiche a changé) ; rouverte, oui.
        $this->agir($this->awa);
        $this->lever('produit')->assertNoContent();
        $this->agir($this->paul);
        $perdu = $this->poser('produit', ['renouveler' => true])->assertStatus(409)->json();
        $this->assertSame([false, 'Awa Acheteuse', null], [$perdu['verrou']['actif'], $perdu['verrou']['par'], $perdu['verrou']['libere_par']]);
        $this->assertSame('Votre modification a été interrompue : Awa Acheteuse a modifié la fiche entre-temps. Rouvrez-la pour repartir de sa version enregistrée.', $perdu['message']);
        $this->poser('produit')->assertOk()->assertJsonPath('verrou.par', 'Paul Vendeur');

        // Un même compte dans deux onglets : la fermeture de l'un ne fait pas perdre la main à l'autre.
        $this->lever('produit')->assertNoContent();
        $this->poser('produit', ['renouveler' => true])->assertOk();

        // Les verrous levés depuis plus d'un jour sont effacés.
        $this->lever('produit')->assertNoContent();
        $this->travel(2)->days();
        $this->poser('client')->assertOk();
        $this->lever('client')->assertNoContent();
        $this->assertSame(['client'], $this->chez(fn () => Verrou::pluck('entite')->all()));
    }

    public function test_prendre_la_main_et_liberer_une_fiche_demandent_le_droit_de_liberer(): void
    {
        $lea = $this->usager('Léa Responsable', ['clients.voir' => true, 'clients.modifier' => true, 'fournisseurs.voir' => true, 'verrous.voir' => false, 'verrous.liberer' => false]);
        $this->agir($this->paul);
        $this->poser('client')->assertOk();

        // Sans le droit : ni prendre la main, ni voir la liste, ni libérer.
        $this->agir($lea);
        $this->poser('client', ['prendre_la_main' => true])->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Prendre la main sur une fiche en cours de modification » (Fiches en cours de modification).');
        $this->getJson('/api/verrous')->assertForbidden();
        $id = $this->chez(fn () => Verrou::where('entite', 'client')->value('id'));
        $this->deleteJson("/api/verrous/{$id}")->assertForbidden();

        // Avec le droit : Léa prend la main, Paul ne peut plus enregistrer et son écran l'apprend.
        $this->regler($lea, ['clients.voir' => true, 'clients.modifier' => true, 'fournisseurs.voir' => true, 'verrous.voir' => true, 'verrous.liberer' => true]);
        $this->travel(10)->seconds();
        $this->agir($lea);
        $this->poser('client', ['prendre_la_main' => true])->assertOk()->assertJsonPath('verrou.par', 'Léa Responsable')->assertJsonPath('verrou.depuis', '2026-10-17T09:00:10+00:00');
        $this->agir($this->paul);
        $this->putJson($this->adresse('client'), ['raison_sociale' => 'Version de Paul'])->assertStatus(409)->assertJsonPath('verrou.par', 'Léa Responsable');
        $this->poser('client', ['renouveler' => true])->assertStatus(409)->assertJsonPath('verrou.actif', true);

        // Awa modifie le fournisseur ; Léa le voit dans la liste et le libère : l'écran d'Awa apprend qui l'a libéré.
        $this->agir($this->awa);
        $this->poser('fournisseur')->assertOk();
        $this->agir($lea);
        $liste = $this->getJson('/api/verrous')->assertOk()->json();
        $this->assertSame([
            ['client', 'Quincaillerie Mballa', "/clients/{$this->ids['client']}", 'Léa Responsable'],
            ['fournisseur', 'Cimenterie du Sud', "/fournisseurs/{$this->ids['fournisseur']}", 'Awa Acheteuse'],
        ], array_map(fn ($v) => [$v['entite'], $v['libelle'], $v['lien'], $v['par']], $liste));
        $this->deleteJson("/api/verrous/{$liste[1]['id']}")->assertNoContent();
        $this->deleteJson("/api/verrous/{$liste[1]['id']}")->assertNotFound();
        $this->assertSame(['client'], array_column($this->getJson('/api/verrous')->assertOk()->json(), 'entite'));

        $this->agir($this->awa);
        $libere = $this->poser('fournisseur', ['renouveler' => true])->assertStatus(409)->json();
        $this->assertSame([false, 'Léa Responsable'], [$libere['verrou']['actif'], $libere['verrou']['libere_par']]);
        $this->assertSame('Votre modification a été interrompue : Léa Responsable a libéré la fiche. Rouvrez-la pour repartir de sa version enregistrée.', $libere['message']);
        // Rouverte, la fiche est de nouveau à elle.
        $this->poser('fournisseur')->assertOk()->assertJsonPath('verrou.libere_par', null);
    }

    public function test_la_liste_ne_montre_que_les_fiches_des_modules_que_le_compte_consulte(): void
    {
        foreach (['produit', 'devis', 'commande_client', 'commande_fournisseur'] as $entite) {
            $this->travel(1)->minutes();
            $this->poser($entite)->assertOk();
        }
        // Verrou tombé : absent de la liste.
        $this->poser('client')->assertOk();
        $this->travel(4)->minutes();
        $this->poser('fournisseur')->assertOk();
        $this->chez(fn () => Verrou::whereIn('entite', ['produit', 'devis', 'commande_client', 'commande_fournisseur'])->update(['expire_le' => now()->addMinute()]));

        $this->assertSame([
            ['produit', 'CIM — Ciment 50 kg', '/produits?recherche=CIM'],
            ['devis', 'DEV-0001', '/devis?recherche=DEV-0001'],
            ['commande_client', 'CMD-0001', '/commandes-client?recherche=CMD-0001'],
            ['commande_fournisseur', 'BC-0001', '/commandes-fournisseur?recherche=BC-0001'],
            ['fournisseur', 'Cimenterie du Sud', "/fournisseurs/{$this->ids['fournisseur']}"],
        ], array_map(fn ($v) => [$v['entite'], $v['libelle'], $v['lien']], $this->getJson('/api/verrous')->assertOk()->json()));

        $lecteur = $this->usager('Marc Lecteur', ['verrous.voir' => true, 'produits.voir' => true, 'devis.voir' => false, 'commandes_client.voir' => false, 'commandes_fournisseur.voir' => false, 'fournisseurs.voir' => false]);
        $this->agir($lecteur);
        $this->assertSame(['produit'], array_column($this->getJson('/api/verrous')->assertOk()->json(), 'entite'));
    }

    public function test_droits_validation_et_isolation_des_entreprises(): void
    {
        $vendeur = $this->usager('Nina Vendeuse', ['produits.voir' => true, 'produits.modifier' => false, 'clients.voir' => true, 'clients.modifier' => true]);
        $this->agir($vendeur);
        $this->poser('produit')->assertForbidden()->assertJsonPath('message', "Vous n'avez pas le droit de modifier cette fiche.");
        $this->poser('client')->assertOk();

        $this->postJson('/api/verrous', ['entite' => 'bon_livraison', 'entite_id' => 1])->assertUnprocessable()->assertJsonValidationErrors(['entite']);
        $this->postJson('/api/verrous', ['entite' => 'client'])->assertUnprocessable()->assertJsonValidationErrors(['entite_id']);
        $this->postJson('/api/verrous', ['entite' => 'client', 'entite_id' => 999999])->assertNotFound();

        // Une autre entreprise ne voit ni ne touche les fiches et les verrous de celle-ci.
        $autre = AdminUser::factory()->for(Tenant::factory()->create(['modules' => ['ventes', 'achats']]))->create(['role' => 'admin', 'nom' => 'Admin Bêta']);
        $this->agir($autre);
        $this->postJson('/api/verrous', ['entite' => 'client', 'entite_id' => $this->ids['client']])->assertNotFound();
        $this->assertSame([], $this->getJson('/api/verrous')->assertOk()->json());
        $id = $this->chez(fn () => Verrou::where('entite', 'client')->value('id'));
        $this->deleteJson("/api/verrous/{$id}")->assertNotFound();
        $this->agir($vendeur);
        $this->poser('client', ['renouveler' => true])->assertOk()->assertJsonPath('verrou.par', 'Nina Vendeuse');
    }
}
