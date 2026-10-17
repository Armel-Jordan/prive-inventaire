<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Facturation à l'usage (phase 1.8) : abonnement facturé à la consommation relevée sur la période (après coup), au-delà
 * d'une quantité incluse ; échéance close sans facture quand la consommation est couverte ; relevé manquant rapporté ;
 * relevés figés une fois facturés ; import CSV (tout ou rien) ; droits.
 */
class FacturationUsageTest extends TestCase
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
            TenantParametres::create(['nom_entreprise' => 'Télécom Littoral', 'devise_code' => 'XAF', 'devise_symbole' => 'FCFA']);
            $client = fn (string $code) => Client::create(['code' => $code, 'raison_sociale' => "Client {$code}", 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id;

            return [
                ['A' => $client('A'), 'B' => $client('B')],
                ['SMS' => ProduitTenant::create(['numero' => 'SMS', 'description' => 'Texto', 'prix_unitaire' => 10])->id,
                    'COP' => ProduitTenant::create(['numero' => 'COP', 'description' => 'Copie', 'prix_unitaire' => 2])->id],
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

    /** Abonnement à l'usage : facturé le 5 pour le mois écoulé, 100 unités incluses, 10 l'unité. */
    private function aLUsage(array $donnees = []): array
    {
        return $this->postJson('/api/abonnements-clients', $donnees + [
            'client_id' => $this->clients['A'], 'produit_id' => $this->produits['SMS'], 'mode_quantite' => 'releve', 'quantite_incluse' => 100,
            'frequence' => 'mensuelle', 'jour_facturation' => 5, 'periode_precedente' => true, 'prix_fixe' => true, 'prix_unitaire' => 10,
            'date_debut' => '2026-09-01', 'date_fin' => '2027-08-31',
        ])->assertCreated()->json();
    }

    public function test_consommation_quantite_incluse_et_releve_manquant(): void
    {
        // Facturé après coup : pas d'échéance pour août, d'avant le début ; la première couvre septembre.
        $abonnement = $this->aLUsage();
        $premiere = collect($abonnement['echeances'])->sortBy('date_prevue')->first();
        $this->assertSame(['2026-10-05', '2026-09-01', '2026-09-30'], [$premiere['date_prevue'], $premiere['periode_debut'], $premiere['periode_fin']]);
        $this->assertSame(['releve', 100, null, null], [$abonnement['mode_quantite'], $abonnement['quantite_incluse'], $abonnement['total_mensuel'], $abonnement['total_annuel']]);

        // Septembre : 150 relevés, 100 inclus, 50 facturés.
        foreach ([['2026-09-15', 80], ['2026-09-28', 70]] as [$date, $quantite]) {
            $this->postJson("/api/abonnements-clients/{$abonnement['id']}/releves", ['date_releve' => $date, 'quantite' => $quantite])->assertCreated();
        }
        $bilan = $this->postJson('/api/abonnements-clients/facturer', ['jusquau' => '2026-10-17'])->assertOk()->json();
        $this->assertSame('1 facture(s) créée(s) pour 1 échéance(s).', $bilan['message']);
        $lignes = $this->getJson("/api/factures/{$bilan['factures'][0]['id']}")->json('lignes');
        $this->assertSame([[$this->produits['SMS'], 50, 10.0]], array_map(fn ($l) => [$l['produit_id'], $l['quantite'], (float) $l['prix_unitaire_ht']], $lignes));

        // Un relevé facturé est figé ; une période facturée ne reçoit plus de relevé.
        $releves = $this->getJson("/api/abonnements-clients/{$abonnement['id']}/releves")->assertOk()->json();
        $this->assertSame([true, true], array_column($releves, 'facture'));
        $this->deleteJson("/api/abonnements-clients/{$abonnement['id']}/releves/{$releves[0]['id']}")->assertUnprocessable()
            ->assertJsonValidationErrors(['releve' => 'Ce relevé est déjà facturé.']);
        $this->postJson("/api/abonnements-clients/{$abonnement['id']}/releves", ['date_releve' => '2026-09-20', 'quantite' => 5])->assertUnprocessable()
            ->assertJsonValidationErrors(['date_releve' => 'Cette période est déjà facturée : le relevé ne peut plus y entrer.']);

        // Octobre : un relevé pas encore facturé se retire ; 60 relevés, couverts par les 100 inclus : échéance close
        // sans facture.
        $this->postJson("/api/abonnements-clients/{$abonnement['id']}/releves", ['date_releve' => '2026-10-10', 'quantite' => 60])->assertCreated();
        $erreur = $this->postJson("/api/abonnements-clients/{$abonnement['id']}/releves", ['date_releve' => '2026-10-12', 'quantite' => 500])->assertCreated()->json('0.id');
        $this->deleteJson("/api/abonnements-clients/{$abonnement['id']}/releves/{$erreur}")->assertOk();
        $this->postJson('/api/abonnements-clients/facturer', ['jusquau' => '2026-11-05'])->assertOk()
            ->assertJsonPath('message', '0 facture(s) créée(s) pour 0 échéance(s), 1 sans rien à facturer.')->assertJsonPath('sans_facture', 1);
        $echeances = collect($this->getJson("/api/abonnements-clients/{$abonnement['id']}")->json('echeances'));
        $this->assertSame('rien_a_facturer', $echeances->firstWhere('date_prevue', '2026-11-05')['statut']);

        // Novembre sans relevé : la facture attend, l'erreur est rapportée.
        $this->postJson('/api/abonnements-clients/facturer', ['jusquau' => '2026-12-05'])->assertOk()
            ->assertJsonPath('erreurs.0.message', 'Relevé manquant pour SMS : période du 01/11/2026 au 30/11/2026.');

        // Un abonnement à quantité fixe ne reçoit pas de relevé.
        $fixe = $this->postJson('/api/abonnements-clients', ['client_id' => $this->clients['B'], 'produit_id' => $this->produits['COP'], 'quantite' => 3,
            'frequence' => 'mensuelle', 'jour_facturation' => 1, 'date_debut' => '2026-10-17', 'date_fin' => '2027-10-16'])->assertCreated()->json('id');
        $this->postJson("/api/abonnements-clients/{$fixe}/releves", ['date_releve' => '2026-10-17', 'quantite' => 5])->assertUnprocessable()
            ->assertJsonValidationErrors(['abonnement' => "Cet abonnement n'est pas facturé à l'usage."]);
    }

    public function test_import_des_releves_et_droits(): void
    {
        $sms = $this->aLUsage();
        $copies = $this->aLUsage(['client_id' => $this->clients['B'], 'produit_id' => $this->produits['COP'], 'quantite_incluse' => 0]);
        $fichier = fn (string $contenu) => UploadedFile::fake()->createWithContent('releves.csv', $contenu);

        // Tout ou rien : une ligne en erreur et rien n'est importé.
        $this->post('/api/abonnements-clients/releves/import', ['fichier' => $fichier(
            "client;produit;date;quantite;note\nA;SMS;2026-10-01;40;premier\nZ;SMS;2026-10-01;1;\nA;COP;2026-10-01;1;\nB;COP;31/02/2026;1;\nB;COP;2026-10-02;-4;\n"
        )], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonPath('erreurs', [
            'Ligne 3 : client « Z » inconnu.',
            "Ligne 4 : aucun abonnement à l'usage en cours à cette date pour ce client et ce produit.",
            'Ligne 5 : date « 31/02/2026 » illisible (AAAA-MM-JJ ou JJ/MM/AAAA).',
            'Ligne 6 : quantité « -4 » invalide (nombre entier).',
        ]);
        $this->assertSame([], $this->getJson("/api/abonnements-clients/{$sms['id']}/releves")->json());

        // Fichier correct (séparateur virgule, dates des deux formats, en-têtes au choix).
        $this->post('/api/abonnements-clients/releves/import', ['fichier' => $fichier(
            "Code client,No produit,Date relevé,Consommation,Commentaire\nA,SMS,2026-10-01,40,premier\nB,COP,02/10/2026,1200,\n"
        )], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('importes', 2)->assertJsonPath('message', '2 relevé(s) importé(s).');
        $this->assertSame([40], array_column($this->getJson("/api/abonnements-clients/{$sms['id']}/releves")->json(), 'quantite'));
        $this->assertSame(['premier'], array_column($this->getJson("/api/abonnements-clients/{$sms['id']}/releves")->json(), 'note'));
        $this->assertSame([1200], array_column($this->getJson("/api/abonnements-clients/{$copies['id']}/releves")->json(), 'quantite'));

        // Saisir ou importer des relevés demande le droit de modifier les abonnements.
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => ['abonnements_clients.modifier' => false]])->assertOk();
        $this->agir($vendeur->fresh());
        $this->postJson("/api/abonnements-clients/{$sms['id']}/releves", ['date_releve' => '2026-10-17', 'quantite' => 5])->assertForbidden();
        $this->post('/api/abonnements-clients/releves/import', ['fichier' => $fichier("client;produit;date;quantite\nA;SMS;2026-10-03;1\n")], ['Accept' => 'application/json'])->assertForbidden();
        $this->getJson("/api/abonnements-clients/{$sms['id']}/releves")->assertOk();
    }
}
