<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Devise;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use App\Taches\FacturerAbonnements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Abonnements clients (phase 1.8) : calendrier des échéances (mensuel, annuel), période couverte, aperçu, contrôles ;
 * facturation des échéances dues (une facture par client et par date, tarif du client ou prix fixe, devise du client,
 * description de la période, brouillon ou émise, erreur rapportée sans arrêter les autres) ; calendrier recalculé sans
 * toucher aux échéances facturées ; échéance reliée à une facture ; tâche planifiée ; droits et isolation.
 */
class AbonnementsClientsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    /** @var array<string, int> */
    private array $clients;

    /** @var array<string, int> */
    private array $produits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-17 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clients, $this->produits] = $this->chezLeTenant(function () {
            TenantParametres::create(['nom_entreprise' => 'Ma Société', 'devise_code' => 'XAF', 'devise_symbole' => 'FCFA']);
            Devise::create(['code' => 'USD', 'libelle' => 'Dollar américain', 'symbole' => '$', 'decimales' => 2, 'taux' => 600, 'actif' => true]);
            $client = fn (string $code, array $autres = []) => Client::create($autres + ['code' => $code, 'raison_sociale' => "Client {$code}", 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id;

            return [
                // A : remise de 5 % ; B : facturé en dollars ; C : une devise sans taux de change.
                ['A' => $client('A', ['taux_remise_global' => 5]), 'B' => $client('B', ['devise' => 'USD']), 'C' => $client('C', ['devise' => 'GBP'])],
                ['HEB' => ProduitTenant::create(['numero' => 'HEB', 'description' => 'Hébergement', 'prix_unitaire' => 100])->id,
                    'LIC' => ProduitTenant::create(['numero' => 'LIC', 'description' => 'Licence', 'prix_unitaire' => 1200])->id],
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

    private function abonnement(array $donnees): array
    {
        return $this->postJson('/api/abonnements-clients', $donnees + [
            'client_id' => $this->clients['A'], 'produit_id' => $this->produits['HEB'], 'quantite' => 1, 'frequence' => 'mensuelle',
            'jour_facturation' => 1, 'date_debut' => '2026-10-17', 'date_fin' => '2027-03-31',
        ])->assertCreated()->json();
    }

    public function test_calendrier_periodes_apercu_et_controles(): void
    {
        // Mensuel le 1er : la première échéance suit le début ; période du mois ; tarif du client (remise de 5 %).
        $mensuel = $this->abonnement(['quantite' => 2, 'description' => 'Hébergement {mois} {annee}, du {debut_periode} au {fin_periode}']);
        $this->assertSame(['2026-11-01', '2026-12-01', '2027-01-01', '2027-02-01', '2027-03-01'], array_column($mensuel['echeances'], 'date_prevue'));
        $this->assertSame(['2026-11-01', '2026-11-30', 'a_venir'], [$mensuel['echeances'][0]['periode_debut'], $mensuel['echeances'][0]['periode_fin'], $mensuel['echeances'][0]['statut']]);
        $this->assertEquals(['actif', 95, 190, 2280], [$mensuel['statut'], $mensuel['prix_courant'], $mensuel['total_mensuel'], $mensuel['total_annuel']]);

        // Aperçu : le 5 pour le mois écoulé ; la description reprend la période.
        $this->postJson('/api/abonnements-clients/apercu', [
            'client_id' => $this->clients['A'], 'produit_id' => $this->produits['HEB'], 'quantite' => 1, 'frequence' => 'mensuelle', 'jour_facturation' => 5,
            'periode_precedente' => true, 'date_debut' => '2026-10-17', 'date_fin' => '2027-10-16', 'description' => '{mois} {annee} ({debut_periode} – {fin_periode})',
        ])->assertOk()->assertJsonPath('premiere_echeance', '2026-11-05')->assertJsonPath('echeances', 12)
            ->assertJsonPath('periode', ['2026-10-01', '2026-10-31'])->assertJsonPath('description', 'octobre 2026 (01/10/2026 – 31/10/2026)')->assertJsonPath('prix', 95);

        // Annuel en mars, période de l'année civile, prix fixe.
        $annuel = $this->abonnement(['produit_id' => $this->produits['LIC'], 'frequence' => 'annuelle', 'mois_facturation' => 3, 'mois_debut_periode' => 1,
            'date_fin' => '2029-12-31', 'prix_fixe' => true, 'prix_unitaire' => 1000]);
        $this->assertSame(['2027-03-01', '2028-03-01', '2029-03-01'], array_column($annuel['echeances'], 'date_prevue'));
        $this->assertSame(['2027-01-01', '2027-12-31'], [$annuel['echeances'][0]['periode_debut'], $annuel['echeances'][0]['periode_fin']]);
        $this->assertEquals([1000, 83.33, 1000], [$annuel['prix_courant'], $annuel['total_mensuel'], $annuel['total_annuel']]);

        // Bientôt échu, échu ; filtres de la liste.
        $this->abonnement(['date_debut' => '2026-01-01', 'date_fin' => '2026-11-10']);
        $this->abonnement(['date_debut' => '2025-01-01', 'date_fin' => '2026-09-30']);
        $this->assertSame(3, $this->getJson('/api/abonnements-clients')->assertOk()->json('total'));
        $this->assertSame(['bientot_echu'], array_column($this->getJson('/api/abonnements-clients?statut=bientot_echu')->json('data'), 'statut'));
        $this->assertSame(['echu'], array_column($this->getJson('/api/abonnements-clients?statut=echu')->json('data'), 'statut'));
        $this->assertSame(4, $this->getJson('/api/abonnements-clients?statut=tous')->json('total'));

        // Contrôles : jour au-delà de 28, fin avant le début, prix fixe sans prix, plus de dix ans.
        $base = ['client_id' => $this->clients['A'], 'produit_id' => $this->produits['HEB'], 'quantite' => 1, 'frequence' => 'mensuelle', 'jour_facturation' => 1, 'date_debut' => '2026-10-17', 'date_fin' => '2027-10-16'];
        $this->postJson('/api/abonnements-clients', ['jour_facturation' => 29] + $base)->assertUnprocessable()
            ->assertJsonValidationErrors(['jour_facturation' => 'Le jour de facturation va de 1 à 28 (le même jour tous les mois).']);
        $this->postJson('/api/abonnements-clients', ['date_fin' => '2026-10-01'] + $base)->assertUnprocessable()->assertJsonValidationErrors('date_fin');
        $this->postJson('/api/abonnements-clients', ['prix_fixe' => true] + $base)->assertUnprocessable()->assertJsonValidationErrors(['prix_unitaire' => 'Indiquez le prix fixe.']);
        $this->postJson('/api/abonnements-clients', ['date_fin' => '2037-10-18'] + $base)->assertUnprocessable()->assertJsonValidationErrors(['date_fin' => 'Un abonnement dure au plus 10 ans.']);
    }

    public function test_facturation_des_echeances_dues(): void
    {
        // A : hébergement depuis septembre (deux échéances passées) et licence à prix fixe depuis octobre ; B en dollars.
        $hebergement = $this->abonnement(['quantite' => 2, 'date_debut' => '2026-09-01', 'description' => '{mois} {annee}']);
        // Échéances en retard en tête (à facturer), puis à venir.
        $this->assertSame([['2026-09-01', 'en_retard'], ['2026-10-01', 'en_retard'], ['2026-11-01', 'a_venir']],
            array_map(fn ($e) => [$e['date_prevue'], $e['statut']], array_slice($hebergement['echeances'], 0, 3)));
        $this->abonnement(['produit_id' => $this->produits['LIC'], 'prix_fixe' => true, 'prix_unitaire' => 50, 'date_debut' => '2026-10-01']);
        $this->abonnement(['client_id' => $this->clients['B'], 'jour_facturation' => 15, 'date_debut' => '2026-10-01']);
        // C : devise sans taux de change, rapporté sans empêcher les autres factures.
        $this->abonnement(['client_id' => $this->clients['C'], 'jour_facturation' => 10, 'date_debut' => '2026-10-01']);

        $bilan = $this->postJson('/api/abonnements-clients/facturer', ['jusquau' => '2026-10-17'])->assertOk()->json();
        $this->assertSame(['3 facture(s) créée(s) pour 4 échéance(s), 1 erreur(s).', 4], [$bilan['message'], $bilan['echeances']]);
        $this->assertSame([['client' => 'Client C', 'date' => '2026-10-10', 'message' => 'Indiquez le taux de change GBP → XAF.']], $bilan['erreurs']);
        $factures = collect($bilan['factures']);
        $this->assertSame(['2026-09-01', '2026-10-01', '2026-10-15'], $factures->pluck('date_facture')->map(fn ($d) => substr($d, 0, 10))->all());
        $this->assertSame(['brouillon'], $factures->pluck('statut')->unique()->values()->all());

        // Septembre : 2 × 95 (tarif du client), période en description ; octobre : les deux abonnements de A sur une facture.
        $septembre = $this->getJson("/api/factures/{$factures[0]['id']}")->assertOk()->json();
        $this->assertSame([[2, 95.0, 'septembre 2026']], array_map(fn ($l) => [$l['quantite'], (float) $l['prix_unitaire_ht'], $l['description']], $septembre['lignes']));
        $octobre = $this->getJson("/api/factures/{$factures[1]['id']}")->assertOk()->json();
        $this->assertEqualsCanonicalizing([95.0, 50.0], array_map(fn ($l) => (float) $l['prix_unitaire_ht'], $octobre['lignes']));
        // B : en dollars, 100 FCFA / 600.
        $dollars = $this->getJson("/api/factures/{$factures[2]['id']}")->assertOk()->json();
        $this->assertSame(['USD', 0.17], [$dollars['devise'], (float) $dollars['lignes'][0]['prix_unitaire_ht']]);
        $this->assertStringStartsWith('%PDF', $this->get("/api/factures/{$factures[0]['id']}/pdf")->assertOk()->getContent());

        // Rien de plus à facturer pour A (C reste en erreur tant que son taux manque) ; les échéances portent leur
        // facture, la prochaine en tête ; recherche par numéro.
        $this->postJson('/api/abonnements-clients/facturer', ['jusquau' => '2026-10-17', 'client_id' => $this->clients['A']])->assertOk()
            ->assertJsonPath('message', 'Aucune échéance à facturer pour cette date.')->assertJsonPath('factures', []);
        $this->postJson('/api/abonnements-clients/facturer', ['jusquau' => '2026-10-17'])->assertOk()
            ->assertJsonPath('message', '0 facture(s) créée(s) pour 0 échéance(s), 1 erreur(s).');
        $detail = $this->getJson("/api/abonnements-clients/{$hebergement['id']}")->assertOk()->json('echeances');
        $this->assertSame(['2026-11-01', 'a_venir'], [$detail[0]['date_prevue'], $detail[0]['statut']]);
        $this->assertSame(['2026-10-01', 'brouillon', $factures[1]['numero']], [$detail[count($detail) - 2]['date_prevue'], $detail[count($detail) - 2]['statut'], $detail[count($detail) - 2]['facture']['numero']]);
        $this->assertSame([$hebergement['id']], array_column($this->getJson('/api/abonnements-clients?recherche='.$factures[0]['numero'])->json('data'), 'id'));

        // Factures émises d'office, sauf quand un abonnement de la facture est marqué brouillon.
        $this->abonnement(['jour_facturation' => 17, 'date_debut' => '2026-10-17', 'prix_fixe' => true, 'prix_unitaire' => 30]);
        $this->abonnement(['client_id' => $this->clients['B'], 'jour_facturation' => 17, 'date_debut' => '2026-10-17', 'brouillon' => true]);
        $emises = collect($this->postJson('/api/abonnements-clients/facturer', ['jusquau' => '2026-10-17', 'emettre' => true])->assertOk()->json('factures'));
        $this->assertSame(['emise', 'brouillon'], $emises->sortBy('client_id')->pluck('statut')->values()->all());
        // Émise : portée à l'encours du client.
        $this->assertSame((float) $emises->firstWhere('client_id', $this->clients['A'])['montant_ttc'], (float) $this->chezLeTenant(fn () => Client::findOrFail($this->clients['A'])->encours_actuel));
    }

    public function test_calendrier_recalcule_relier_supprimer_et_tache(): void
    {
        $abonnement = $this->abonnement(['date_debut' => '2026-09-01']);
        $this->postJson('/api/abonnements-clients/facturer', ['jusquau' => '2026-09-30'])->assertOk()->assertJsonPath('echeances', 1);

        // Facturé : le début et le client ne changent plus ; la fréquence, si (le calendrier reprend après la facturée).
        $donnees = ['client_id' => $this->clients['A'], 'produit_id' => $this->produits['HEB'], 'quantite' => 1, 'frequence' => 'mensuelle', 'jour_facturation' => 1, 'date_debut' => '2026-09-01', 'date_fin' => '2027-08-31'];
        $this->putJson("/api/abonnements-clients/{$abonnement['id']}", ['date_debut' => '2026-08-01'] + $donnees)->assertUnprocessable()
            ->assertJsonValidationErrors(['date_debut' => 'La date de début ne peut pas être modifiée : des factures existent déjà pour cet abonnement.']);
        $this->putJson("/api/abonnements-clients/{$abonnement['id']}", ['client_id' => $this->clients['B']] + $donnees)->assertUnprocessable()->assertJsonValidationErrors('client_id');
        $trimestriel = $this->putJson("/api/abonnements-clients/{$abonnement['id']}", ['frequence' => 'trimestrielle', 'mois_facturation' => 9] + $donnees)->assertOk()->json('echeances');
        $this->assertSame(['2026-12-01', '2027-03-01', '2027-06-01', '2026-09-01'], array_column($trimestriel, 'date_prevue'));
        $this->assertSame(['2026-12-01', '2027-02-28'], [$trimestriel[0]['periode_debut'], $trimestriel[0]['periode_fin']]);

        // Relier une échéance à une facture faite à la main : une fois, et seulement une facture du même client.
        $manuelle = $this->postJson('/api/factures', ['client_id' => $this->clients['A'], 'date_facture' => '2026-10-17',
            'lignes' => [['produit_id' => $this->produits['HEB'], 'quantite' => 1, 'prix_unitaire_ht' => 95]]])->assertCreated()->json('id');
        $autre = $this->postJson('/api/factures', ['client_id' => $this->clients['B'], 'date_facture' => '2026-10-17', 'devise' => 'USD',
            'lignes' => [['produit_id' => $this->produits['HEB'], 'quantite' => 1, 'prix_unitaire_ht' => 1]]])->assertCreated()->json('id');
        $echeance = $trimestriel[0]['id'];
        $this->postJson("/api/abonnements-clients/{$abonnement['id']}/echeances/{$echeance}/relier", ['facture_id' => $autre])->assertUnprocessable()->assertJsonValidationErrors('facture_id');
        $this->postJson("/api/abonnements-clients/{$abonnement['id']}/echeances/{$echeance}/relier", ['facture_id' => $manuelle])->assertOk()
            ->assertJsonPath('echeances.2.facture.id', $manuelle);
        $this->postJson("/api/abonnements-clients/{$abonnement['id']}/echeances/{$echeance}/relier", ['facture_id' => $manuelle])->assertUnprocessable();

        // Tâche planifiée : factures émises si le paramètre le demande.
        $this->abonnement(['client_id' => $this->clients['B'], 'jour_facturation' => 17, 'date_debut' => '2026-10-17', 'prix_fixe' => true, 'prix_unitaire' => 2]);
        $tache = collect($this->getJson('/api/taches-planifiees')->assertOk()->json('taches'))->firstWhere('code', 'facturation_abonnements');
        $this->assertSame(['emettre' => false], $tache['parametres']);
        $this->putJson("/api/taches-planifiees/{$tache['id']}", ['actif' => true, 'recurrence' => 'quotidienne', 'heure' => '06:00', 'parametres' => ['emettre' => true]])->assertOk();
        $this->assertSame("1 facture(s) d'abonnement pour 1 échéance(s).", $this->chezLeTenant(fn () => app(FacturerAbonnements::class)->executer()));
        $this->assertSame('Aucune échéance à facturer.', $this->chezLeTenant(fn () => app(FacturerAbonnements::class)->executer()));
        $this->assertContains('emise', collect($this->getJson('/api/factures')->json('data'))->where('client_id', $this->clients['B'])->pluck('statut')->all());

        // Supprimer : l'abonnement et ses échéances à facturer partent, ses factures restent.
        $premiere = $this->getJson("/api/abonnements-clients/{$abonnement['id']}")->json('echeances.3.facture.id');
        $this->deleteJson("/api/abonnements-clients/{$abonnement['id']}")->assertOk();
        $this->getJson("/api/abonnements-clients/{$abonnement['id']}")->assertNotFound();
        $this->getJson("/api/factures/{$premiere}")->assertOk();
    }

    public function test_droits_et_isolation(): void
    {
        $abonnement = $this->abonnement(['date_debut' => '2026-10-01', 'jour_facturation' => 17]);

        // Facturer demande l'action nommée, puis le droit de créer (et d'émettre) des factures.
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $refuser = function (array $exceptions) use ($vendeur) {
            $this->agir($this->admin);
            $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => $exceptions])->assertOk();
            $this->agir($vendeur->fresh());
        };
        $refuser(['abonnements_clients.facturer' => false]);
        $this->postJson('/api/abonnements-clients/facturer', ['jusquau' => '2026-10-17'])->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Facturer les abonnements » (Abonnements clients).');
        $this->getJson('/api/abonnements-clients')->assertOk();
        $refuser(['factures.creer' => false]);
        $this->postJson('/api/abonnements-clients/facturer', ['jusquau' => '2026-10-17'])->assertForbidden()->assertJsonPath('action', 'creer');
        $refuser(['factures.emettre' => false]);
        $this->postJson('/api/abonnements-clients/facturer', ['jusquau' => '2026-10-17', 'emettre' => true])->assertForbidden()->assertJsonPath('action', 'emettre');
        $this->postJson('/api/abonnements-clients/facturer', ['jusquau' => '2026-10-17'])->assertOk()->assertJsonPath('echeances', 1);
        $refuser(['abonnements_clients.creer' => false]);
        $this->postJson('/api/abonnements-clients', ['client_id' => $this->clients['A'], 'produit_id' => $this->produits['HEB'], 'quantite' => 1, 'frequence' => 'mensuelle',
            'jour_facturation' => 1, 'date_debut' => '2026-10-17', 'date_fin' => '2027-03-31'])->assertForbidden();

        // Catalogue des droits : le module et son action nommée.
        $this->agir($this->admin);
        $modules = collect(collect($this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets'))->firstWhere('cle', 'ventes')['modules'])->keyBy('cle');
        $this->assertSame(['abonnements_clients.facturer'], array_slice(array_column($modules['abonnements_clients']['permissions'], 'code'), 4));

        // Une autre entreprise ne voit ni ne facture ces abonnements.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->assertSame(0, $this->getJson('/api/abonnements-clients?statut=tous')->assertOk()->json('total'));
        $this->getJson("/api/abonnements-clients/{$abonnement['id']}")->assertNotFound();
        $this->deleteJson("/api/abonnements-clients/{$abonnement['id']}")->assertNotFound();
    }
}
