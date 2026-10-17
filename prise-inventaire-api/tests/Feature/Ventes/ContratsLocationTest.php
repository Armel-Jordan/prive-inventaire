<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use App\Taches\FacturerLocations;
use App\Taches\RenouvelerContrats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contrats de location (phase 1.8) : type de location (référentiel), contrat numéroté et première facturation des
 * loyers ; facture des prix de contrat (une fois par ligne, complémentaire pour les lignes ajoutées) ; lignes facturées
 * figées ; loyers par période (unités exemptées et rendues sans loyer) ; retours ; renouvellement à la main et
 * automatique (hausses, facture du contrat suivant) ; tâches planifiées ; impression ; droits et isolation.
 */
class ContratsLocationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private int $clientId;

    /** @var array<string, int> */
    private array $produits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-17 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->produits] = $this->chezLeTenant(function () {
            TenantParametres::create(['nom_entreprise' => 'Gaz du Littoral', 'devise_code' => 'XAF', 'devise_symbole' => 'FCFA']);

            return [
                Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Restaurant Le Wouri', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
                ['BOU' => ProduitTenant::create(['numero' => 'BOU', 'description' => 'Bouteille de gaz 12,5 kg', 'prix_unitaire' => 5000])->id,
                    'ECH' => ProduitTenant::create(['numero' => 'ECH', 'description' => 'Échafaudage', 'prix_unitaire' => 20000])->id],
            ];
        });
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

    private function contrat(array $donnees = []): array
    {
        return $this->postJson('/api/contrats-location', $donnees + [
            'client_id' => $this->clientId, 'date_debut' => '2026-10-17', 'date_fin' => '2027-10-16', 'frequence' => 'mensuelle', 'jour_facturation' => 1,
            'lignes' => [['produit_id' => $this->produits['BOU'], 'quantite' => 3, 'prix_contrat' => 5000, 'prix_location' => 1000]],
        ])->assertCreated()->json();
    }

    private function quantites(int $facture): array
    {
        return array_map(fn ($l) => [$l['produit_id'], $l['quantite'], (float) $l['prix_unitaire_ht'], $l['description']], $this->getJson("/api/factures/{$facture}")->assertOk()->json('lignes'));
    }

    public function test_contrat_facture_du_contrat_loyers_et_retours(): void
    {
        // Type de location (référentiel) puis contrat : numéro, première facturation, loyer par période.
        $type = $this->postJson('/api/referentiels/types_location', ['code' => 'cyl', 'nom' => 'Cylindres mensuels', 'duree_mois' => 12,
            'frequence' => 'mensuelle', 'jour_facturation' => 1, 'a_echoir' => true, 'actif' => true])->assertCreated()->assertJsonPath('code', 'CYL')->json('id');
        $contrat = $this->contrat(['type_location_id' => $type, 'lignes' => [
            ['produit_id' => $this->produits['BOU'], 'quantite' => 10, 'quantite_exemptee' => 2, 'prix_contrat' => 5000, 'prix_location' => 1000],
            ['produit_id' => $this->produits['ECH'], 'quantite' => 1, 'prix_location' => 15000],
        ]]);
        $this->assertSame(['CT-00001', 23000, 1, 11, 'CYL'], [$contrat['numero'], (int) $contrat['loyer_periode'], $contrat['a_facturer'], $contrat['unites_louees'], $contrat['type']['code']]);
        $this->assertStringStartsWith('2026-11-01', $contrat['prochaine_facturation']);
        [$bouteilles] = array_column($contrat['lignes'], 'id');

        // Facture des prix de contrat : une fois.
        $premiere = $this->postJson("/api/contrats-location/{$contrat['id']}/facturer")->assertCreated()->json('facture.id');
        $this->assertSame([[$this->produits['BOU'], 10, 5000.0, "Contrat {$contrat['numero']}"]], $this->quantites($premiere));
        $this->postJson("/api/contrats-location/{$contrat['id']}/facturer")->assertUnprocessable()
            ->assertJsonValidationErrors(['contrat' => 'Tous les produits de ce contrat sont déjà facturés.']);

        // Ligne facturée figée ; une ligne ajoutée se facture à part.
        $saisie = fn (array $lignes) => ['client_id' => $this->clientId, 'type_location_id' => $type, 'date_debut' => '2026-10-17', 'date_fin' => '2027-10-16',
            'frequence' => 'mensuelle', 'jour_facturation' => 1, 'lignes' => $lignes];
        $figee = ['id' => $bouteilles, 'produit_id' => $this->produits['BOU'], 'quantite' => 10, 'quantite_exemptee' => 2, 'prix_contrat' => 5000, 'prix_location' => 1000];
        $echafaudage = ['id' => $contrat['lignes'][1]['id'], 'produit_id' => $this->produits['ECH'], 'quantite' => 1, 'prix_location' => 15000];
        $this->putJson("/api/contrats-location/{$contrat['id']}", $saisie([['quantite' => 12] + $figee, $echafaudage]))->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes' => 'Une ligne facturée ne change plus : ajoutez une ligne pour louer davantage.']);
        $this->putJson("/api/contrats-location/{$contrat['id']}", $saisie([$echafaudage]))->assertUnprocessable()->assertJsonValidationErrors(['lignes' => 'Une ligne facturée ne se retire pas.']);
        $this->putJson("/api/contrats-location/{$contrat['id']}", $saisie([$figee, $echafaudage, ['produit_id' => $this->produits['BOU'], 'quantite' => 5, 'prix_contrat' => 5000, 'prix_location' => 1000]]))
            ->assertOk()->assertJsonPath('a_facturer', 1)->assertJsonPath('unites_louees', 16);
        $complementaire = $this->postJson("/api/contrats-location/{$contrat['id']}/facturer")->assertCreated()->json('facture.id');
        $this->assertSame([[$this->produits['BOU'], 5, 5000.0, "Contrat {$contrat['numero']}"]], $this->quantites($complementaire));

        // Loyers de novembre et décembre : unités moins les exemptées, période en description.
        $bilan = $this->postJson('/api/contrats-location/facturer-loyers', ['jusquau' => '2026-12-15'])->assertOk()->json();
        $this->assertSame('2 facture(s) de loyer créée(s).', $bilan['message']);
        $this->assertEqualsCanonicalizing([
            [$this->produits['BOU'], 8, 1000.0, 'Location du 01/11/2026 au 30/11/2026 — contrat CT-00001'],
            [$this->produits['ECH'], 1, 15000.0, 'Location du 01/11/2026 au 30/11/2026 — contrat CT-00001'],
            [$this->produits['BOU'], 5, 1000.0, 'Location du 01/11/2026 au 30/11/2026 — contrat CT-00001'],
        ], $this->quantites($bilan['factures'][0]['id']));
        $this->postJson('/api/contrats-location/facturer-loyers', ['jusquau' => '2026-12-15'])->assertOk()->assertJsonPath('message', 'Aucun loyer à facturer pour cette date.');
        $detail = $this->getJson("/api/contrats-location/{$contrat['id']}")->assertOk()->json();
        $this->assertSame(['2027-01-01', '2026-12-01'], [substr($detail['prochaine_facturation'], 0, 10), substr($detail['derniere_facturation'], 0, 10)]);
        $this->assertCount(4, $detail['factures']);

        // Retour de 4 bouteilles : plus de loyer pour elles ; pas plus qu'il n'en reste chez le client.
        $this->postJson("/api/contrats-location/{$contrat['id']}/lignes/{$bouteilles}/retour", ['quantite' => 11, 'date_retour' => '2026-10-17'])->assertUnprocessable()
            ->assertJsonValidationErrors(['quantite' => 'Quantité rendue trop grande : il reste 10 unité(s) chez le client.']);
        $this->postJson("/api/contrats-location/{$contrat['id']}/lignes/{$bouteilles}/retour", ['quantite' => 4, 'date_retour' => '2026-10-17'])->assertOk()
            ->assertJsonPath('lignes.0.quantite_retournee', 4)->assertJsonPath('unites_louees', 12);
        $janvier = $this->postJson('/api/contrats-location/facturer-loyers', ['jusquau' => '2027-01-01'])->assertOk()->json('factures.0.id');
        $this->assertSame(9, array_sum(array_map(fn ($l) => $l[0] === $this->produits['BOU'] ? $l[1] : 0, $this->quantites($janvier))));

        // Impression ; un contrat facturé ne se supprime pas.
        $this->assertStringStartsWith('%PDF', $this->get("/api/contrats-location/{$contrat['id']}/pdf")->assertOk()->getContent());
        $this->deleteJson("/api/contrats-location/{$contrat['id']}")->assertUnprocessable()
            ->assertJsonValidationErrors(['contrat' => 'Ce contrat a des factures : désactivez-le plutôt.']);
    }

    public function test_renouvellement_a_la_main_automatique_et_taches(): void
    {
        // À la main : mêmes prix, du lendemain de la fin pour la même durée ; une seule fois.
        $annuel = $this->contrat(['date_debut' => '2025-11-01', 'date_fin' => '2026-10-31', 'prochaine_facturation' => '2026-11-01',
            'renouvellement_auto' => true, 'taux_contrat' => 10, 'taux_location' => 5]);
        $suivant = $this->postJson("/api/contrats-location/{$annuel['id']}/renouveler")->assertCreated()->json();
        $this->assertSame(['2026-11-01', '2027-10-31', 5000.0, 1000.0, $annuel['numero']],
            [substr($suivant['date_debut'], 0, 10), substr($suivant['date_fin'], 0, 10), (float) $suivant['lignes'][0]['prix_contrat'], (float) $suivant['lignes'][0]['prix_location'], $suivant['precedent']['numero']]);
        $this->postJson("/api/contrats-location/{$annuel['id']}/renouveler")->assertUnprocessable()->assertJsonValidationErrors(['contrat' => 'Ce contrat est déjà renouvelé.']);

        // Automatique, à la fin : prix augmentés des taux, facture brouillon des prix de contrat.
        $echu = $this->contrat(['date_debut' => '2025-10-17', 'date_fin' => '2026-10-16', 'prochaine_facturation' => '2026-11-01',
            'renouvellement_auto' => true, 'taux_contrat' => 10, 'taux_location' => 5]);
        $bilan = $this->chezLeTenant(fn () => app(RenouvelerContrats::class)->executer());
        $this->assertSame('1 contrat(s) renouvelé(s) : CT-00003 → CT-00004.', $bilan);
        $renouvele = $this->getJson('/api/contrats-location/'.$this->getJson("/api/contrats-location/{$echu['id']}")->json('renouvele_par.id'))->assertOk()->json();
        $this->assertSame([5500.0, 1050.0, '2026-10-17', 1], [(float) $renouvele['lignes'][0]['prix_contrat'], (float) $renouvele['lignes'][0]['prix_location'],
            substr($renouvele['date_debut'], 0, 10), count($renouvele['factures'])]);
        $this->assertSame('Aucun contrat à renouveler.', $this->chezLeTenant(fn () => app(RenouvelerContrats::class)->executer()));

        // Facturation des loyers par la tâche : émises si le paramètre le demande.
        $this->contrat(['date_debut' => '2026-10-01', 'date_fin' => '2027-09-30']);
        $tache = collect($this->getJson('/api/taches-planifiees')->json('taches'))->firstWhere('code', 'facturation_locations');
        $this->putJson("/api/taches-planifiees/{$tache['id']}", ['actif' => true, 'recurrence' => 'quotidienne', 'heure' => '06:10', 'parametres' => ['emettre' => true]])->assertOk();
        $this->assertSame('1 facture(s) de loyer.', $this->chezLeTenant(fn () => app(FacturerLocations::class)->executer()));
        $this->assertSame('Aucun loyer à facturer.', $this->chezLeTenant(fn () => app(FacturerLocations::class)->executer()));
    }

    public function test_controles_droits_et_isolation(): void
    {
        $base = ['client_id' => $this->clientId, 'date_debut' => '2026-10-17', 'date_fin' => '2027-10-16', 'frequence' => 'mensuelle', 'jour_facturation' => 1,
            'lignes' => [['produit_id' => $this->produits['BOU'], 'quantite' => 3, 'prix_location' => 1000]]];
        $this->postJson('/api/contrats-location', ['date_fin' => '2026-10-01'] + $base)->assertUnprocessable()
            ->assertJsonValidationErrors(['date_fin' => 'La date de fin doit être postérieure à la date de début.']);
        $this->postJson('/api/contrats-location', ['jour_facturation' => 31] + $base)->assertUnprocessable()->assertJsonValidationErrors('jour_facturation');
        $this->postJson('/api/contrats-location', ['lignes' => []] + $base)->assertUnprocessable()->assertJsonValidationErrors(['lignes' => 'Ajoutez au moins un produit loué.']);
        $this->postJson('/api/contrats-location', ['lignes' => [['produit_id' => $this->produits['BOU'], 'quantite' => 3, 'quantite_exemptee' => 4]]] + $base)
            ->assertUnprocessable()->assertJsonValidationErrors(['lignes.0.quantite_exemptee' => 'Les unités exemptées ne dépassent pas la quantité louée.']);
        $contrat = $this->contrat();
        // Jamais facturé : il se supprime.
        $brouillon = $this->contrat();
        $this->deleteJson("/api/contrats-location/{$brouillon['id']}")->assertOk();

        // Droits : actions nommées, puis droit de créer des factures.
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $refuser = function (array $exceptions) use ($vendeur) {
            $this->agir($this->admin);
            $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => $exceptions])->assertOk();
            $this->agir($vendeur->fresh());
        };
        $refuser(['contrats_location.facturer' => false, 'contrats_location.renouveler' => false]);
        $this->postJson("/api/contrats-location/{$contrat['id']}/facturer")->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Facturer les contrats de location » (Contrats de location).');
        $this->postJson('/api/contrats-location/facturer-loyers', ['jusquau' => '2026-12-01'])->assertForbidden();
        $this->postJson("/api/contrats-location/{$contrat['id']}/renouveler")->assertForbidden();
        $this->getJson("/api/contrats-location/{$contrat['id']}")->assertOk();
        $refuser(['factures.creer' => false]);
        $this->postJson("/api/contrats-location/{$contrat['id']}/facturer")->assertForbidden()->assertJsonPath('action', 'creer');
        $refuser([]);
        $this->postJson("/api/contrats-location/{$contrat['id']}/facturer")->assertCreated();

        // Catalogue : le module et ses actions nommées.
        $this->agir($this->admin);
        $modules = collect(collect($this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets'))->firstWhere('cle', 'ventes')['modules'])->keyBy('cle');
        $this->assertSame(['contrats_location.facturer', 'contrats_location.renouveler'], array_slice(array_column($modules['contrats_location']['permissions'], 'code'), 4));

        // Une autre entreprise ne voit ni ne facture ces contrats.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->assertSame(0, $this->getJson('/api/contrats-location?statut=tous')->assertOk()->json('total'));
        $this->getJson("/api/contrats-location/{$contrat['id']}")->assertNotFound();
        $this->postJson("/api/contrats-location/{$contrat['id']}/facturer")->assertNotFound();
        $this->get("/api/contrats-location/{$contrat['id']}/pdf")->assertNotFound();
    }
}
