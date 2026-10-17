<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\CarteCadeauMouvement;
use App\Models\Client;
use App\Models\Configuration;
use App\Models\Devise;
use App\Models\FacturePaiement;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cartes cadeaux (phase 1.10c) : vente (numéro automatique ou saisi, jamais réutilisé ; devise), paiement au point de
 * vente et à l'encaissement d'une facture jusqu'au solde (carte annulée, expirée, d'une autre devise ou inconnue
 * refusée), recharge, remboursement qui annule la carte, passage dans la caisse (vente, recharge et remboursement oui,
 * paiement par carte non), liste, certificat ; droits et isolation.
 */
class CartesCadeauxTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-17 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        [$this->clientId, $this->produitId] = $this->chezLeTenant(function () {
            TenantParametres::create(['nom_entreprise' => 'Boutique Cadeaux', 'devise_code' => 'XAF', 'devise_symbole' => 'FCFA', 'tva_taux' => 19.25]);

            return [
                Client::create(['code' => 'CLI-A', 'raison_sociale' => 'Marie Ebongue', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
                ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment 50 kg', 'prix_unitaire' => 1000])->id,
            ];
        });
        $this->agir($this->admin);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte, ?Tenant $tenant = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', ($tenant ?? $this->tenant)->slug);
    }

    private function vendreCarte(array $donnees = [])
    {
        return $this->postJson('/api/cartes-cadeaux', $donnees + ['montant' => 10000, 'mode' => 'especes', 'date' => '2026-10-17']);
    }

    private function venteComptoir(array $paiements, int $quantite = 2, ?int $caisse = null)
    {
        return $this->postJson('/api/point-de-vente/ventes', array_filter([
            'date' => '2026-10-17', 'lignes' => [['produit_id' => $this->produitId, 'quantite' => $quantite]], 'paiements' => $paiements, 'caisse_id' => $caisse,
        ], fn ($v) => $v !== null));
    }

    private function factureEmise(int $quantite): int
    {
        $id = $this->postJson('/api/factures', ['client_id' => $this->clientId, 'date_facture' => '2026-10-17',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => $quantite, 'prix_unitaire_ht' => 1000]]])->assertCreated()->json('id');
        $this->postJson("/api/factures/{$id}/emettre")->assertOk();

        return $id;
    }

    public function test_vente_paiements_recharge_remboursement_et_caisse(): void
    {
        $caisse = $this->postJson('/api/referentiels/caisses', ['code' => 'C1', 'nom' => 'Comptoir', 'actif' => true])->assertCreated()->json('id');
        $session = $this->postJson("/api/caisses/{$caisse}/ouvrir", ['fond' => 0])->assertCreated()->json('id');

        // Vente : numéro automatique, montant encaissé dans la caisse du poste.
        $carte = $this->vendreCarte(['client_id' => $this->clientId, 'caisse_id' => $caisse, 'expire_le' => '2027-10-17'])->assertCreated()->json();
        $this->assertSame(['CC-000001', 'active', 'Marie Ebongue'], [$carte['numero'], $carte['etat'], $carte['client']['raison_sociale']]);
        $this->assertEquals(10000, $carte['solde']);
        $this->vendreCarte(['expire_le' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors(['expire_le' => "L'expiration ne peut pas précéder la vente."]);

        // Numérotation manuelle (cartes pré-imprimées) : numéro exigé, jamais réutilisé ; le compteur saute un numéro déjà pris.
        $this->chezLeTenant(fn () => Configuration::pourEntite('carte_cadeau', $this->tenant->id)->update(['auto_increment' => false]));
        $this->vendreCarte()->assertUnprocessable()->assertJsonValidationErrors(['numero' => 'Saisissez ou scannez le numéro de la carte.']);
        $this->assertSame('PRE-001', $this->vendreCarte(['numero' => 'pre-001'])->assertCreated()->json('numero'));
        $this->vendreCarte(['numero' => 'PRE-001'])->assertUnprocessable()->assertJsonValidationErrors(['numero' => 'Le numéro PRE-001 est déjà utilisé : une carte ne réutilise jamais un numéro.']);
        $this->vendreCarte(['numero' => 'CC-000002'])->assertCreated();
        $this->chezLeTenant(fn () => Configuration::pourEntite('carte_cadeau', $this->tenant->id)->update(['auto_increment' => true]));
        $this->assertSame('CC-000003', $this->vendreCarte(['numero' => 'IGNORE'])->assertCreated()->json('numero'));
        // Devise : celle du référentiel.
        $this->vendreCarte(['devise' => 'USD'])->assertUnprocessable()->assertJsonValidationErrors(['devise' => "La devise USD n'est pas dans le référentiel des devises."]);
        $this->chezLeTenant(fn () => Devise::create(['code' => 'USD', 'libelle' => 'Dollar US', 'taux' => 600]));
        $dollars = $this->vendreCarte(['devise' => 'usd', 'montant' => 50])->assertCreated()->json();
        $this->assertSame('USD', $dollars['devise']);

        // Point de vente : la carte paie 2 385 ; le paiement ne passe pas par la caisse.
        $consultee = $this->getJson('/api/point-de-vente/carte-cadeau?numero=cc-000001')->assertOk()->json();
        $this->assertEquals(['CC-000001', 10000, 'active'], [$consultee['numero'], $consultee['solde'], $consultee['etat']]);
        $this->getJson('/api/point-de-vente/carte-cadeau?numero=INCONNUE')->assertNotFound()->assertJsonPath('message', 'Carte cadeau INCONNUE introuvable.');
        $vente = $this->venteComptoir([['mode' => 'carte_cadeau', 'carte_cadeau' => 'cc-000001', 'montant' => 2385]], 2, $caisse)->assertCreated()->json('facture');
        $this->assertSame('payee', $vente['statut']);
        $paiement = FacturePaiement::where('facture_id', $vente['id'])->first();
        $this->assertSame(['autre', 'CC-000001', $carte['id'], null], [$paiement->mode_paiement, $paiement->reference, $paiement->carte_cadeau_id, $paiement->caisse_session_id]);
        $this->assertEquals([7615, 'utilisation', $vente['id']], [(float) $this->getJson("/api/cartes-cadeaux/{$carte['id']}")->json('solde'),
            CarteCadeauMouvement::latest('id')->value('type'), CarteCadeauMouvement::latest('id')->value('facture_id')]);
        $this->venteComptoir([['mode' => 'carte_cadeau', 'montant' => 100]])->assertUnprocessable()
            ->assertJsonValidationErrors(['paiements.0.carte_cadeau' => 'Saisissez ou scannez le numéro de la carte cadeau.']);
        $this->assertStringStartsWith('%PDF', $this->get("/api/point-de-vente/recus/{$vente['id']}")->assertOk()->getContent());

        // Encaissement d'une facture : pas plus que le solde, pas une autre devise, pas une carte inconnue.
        $facture = $this->factureEmise(10);
        $payer = fn (string $numero, float $montant) => $this->postJson("/api/factures/{$facture}/paiement", [
            'montant' => $montant, 'date_paiement' => '2026-10-17', 'mode_paiement' => 'carte_cadeau', 'carte_cadeau' => $numero,
        ]);
        $payer('CC-000001', 11925)->assertUnprocessable()->assertJsonValidationErrors(['carte_cadeau' => 'Solde insuffisant sur la carte CC-000001 (solde disponible : 7 615,00 FCFA).']);
        $payer($dollars['numero'], 10)->assertUnprocessable()->assertJsonValidationErrors(['carte_cadeau' => "La carte cadeau {$dollars['numero']} n'est pas dans la devise de la facture."]);
        $payer('XYZ', 10)->assertUnprocessable()->assertJsonValidationErrors(['carte_cadeau' => 'Carte cadeau XYZ introuvable.']);
        $this->postJson("/api/factures/{$facture}/paiement", ['montant' => 10, 'date_paiement' => '2026-10-17', 'mode_paiement' => 'carte_cadeau'])
            ->assertUnprocessable()->assertJsonValidationErrors('carte_cadeau');
        $payer('CC-000001', 7615)->assertOk()->assertJsonPath('statut', 'partiellement_payee');
        $this->assertSame('epuisee', $this->getJson("/api/cartes-cadeaux/{$carte['id']}")->json('etat'));

        // Recharge par carte bancaire au comptoir, puis remboursement du solde en espèces : la carte est annulée.
        $this->postJson("/api/cartes-cadeaux/{$carte['id']}/recharger", ['montant' => 5000, 'mode' => 'carte', 'caisse_id' => $caisse])
            ->assertOk()->assertJsonPath('etat', 'active');
        $rembourse = $this->postJson("/api/cartes-cadeaux/{$carte['id']}/rembourser", ['mode' => 'especes', 'caisse_id' => $caisse])->assertOk()->json();
        $this->assertSame(['annulee', true], [$rembourse['etat'], $rembourse['annulee']]);
        $this->assertEquals(0, $rembourse['solde']);
        $this->postJson("/api/cartes-cadeaux/{$carte['id']}/recharger", ['montant' => 100, 'mode' => 'especes'])
            ->assertUnprocessable()->assertJsonPath('message', 'La carte CC-000001 est annulée : elle ne se recharge plus.');
        $this->postJson("/api/cartes-cadeaux/{$carte['id']}/rembourser", ['mode' => 'especes'])->assertUnprocessable()->assertJsonPath('message', 'La carte CC-000001 est déjà annulée.');
        $this->putJson("/api/cartes-cadeaux/{$carte['id']}", ['notes' => 'x'])->assertUnprocessable();
        $this->venteComptoir([['mode' => 'carte_cadeau', 'carte_cadeau' => 'CC-000001', 'montant' => 2385]])->assertUnprocessable()
            ->assertJsonValidationErrors(['carte_cadeau' => 'La carte cadeau CC-000001 est annulée.']);

        // Historique de la carte : vente, utilisations, recharge, remboursement ; le solde suit chaque mouvement.
        $mouvements = $this->getJson("/api/cartes-cadeaux/{$carte['id']}")->assertOk()->json('mouvements');
        $this->assertEquals([['vente', 10000, 10000], ['utilisation', -2385, 7615], ['utilisation', -7615, 0], ['recharge', 5000, 5000], ['remboursement', -5000, 0]],
            array_map(fn ($m) => [$m['type'], $m['montant'], $m['solde_apres']], $mouvements));

        // Caisse : vente et recharge encaissées, remboursement sorti ; le paiement par carte n'y entre pas.
        $detail = $this->getJson("/api/caisses/sessions/{$session}")->assertOk()->json();
        $lignes = collect($detail['lignes'])->where('devise', null)->keyBy('mode');
        $this->assertEquals([10000, 5000, 5000, 5000], [$lignes['especes']['encaissements'], $lignes['especes']['remboursements'], $lignes['especes']['attendu'], $lignes['carte']['encaissements']]);
        $this->assertEqualsCanonicalizing(['carte_cadeau', 'recharge_carte', 'remboursement_carte'], array_column($detail['operations'], 'type'));
        $this->assertSame('Carte cadeau CC-000001', $detail['operations'][0]['document']);

        // Une vente payée seulement par carte cadeau n'a pas besoin de la caisse ouverte.
        $this->postJson("/api/caisses/sessions/{$session}/fermer", ['comptes' => []])->assertOk();
        $this->venteComptoir([['mode' => 'carte_cadeau', 'carte_cadeau' => 'PRE-001', 'montant' => 1192.5]], 1, $caisse)->assertCreated();
        $this->venteComptoir([['mode' => 'especes', 'montant' => 2000]], 1, $caisse)->assertUnprocessable()->assertJsonValidationErrors('caisse_id');

        // Carte expirée : refusée en paiement.
        $expiree = $this->vendreCarte(['expire_le' => '2026-10-20'])->assertCreated()->json('numero');
        $this->travelTo('2026-10-25 10:00:00');
        $this->postJson('/api/point-de-vente/ventes', ['date' => '2026-10-25', 'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1]],
            'paiements' => [['mode' => 'carte_cadeau', 'carte_cadeau' => $expiree, 'montant' => 1192.5]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['carte_cadeau' => "La carte cadeau {$expiree} a expiré le 20/10/2026."]);

        // Liste : recherche, états, solde à honorer (cartes valables dans la devise de l'entreprise) ; certificat.
        $liste = $this->getJson('/api/cartes-cadeaux')->assertOk()->json();
        $this->assertSame(6, $liste['total']);
        $this->assertEquals(10000 * 3 - 1192.5, $liste['totaux']['a_honorer']);
        $this->assertSame([$expiree], array_column($this->getJson('/api/cartes-cadeaux?etat=expiree')->json('data'), 'numero'));
        $this->assertSame(['CC-000001'], array_column($this->getJson('/api/cartes-cadeaux?etat=annulee')->json('data'), 'numero'));
        $this->assertSame(['CC-000001'], array_column($this->getJson('/api/cartes-cadeaux?recherche=Ebongue')->json('data'), 'numero'));
        $this->assertStringStartsWith('%PDF', $this->get("/api/cartes-cadeaux/{$carte['id']}/certificat")->assertOk()->getContent());
    }

    public function test_droits_et_isolation(): void
    {
        $carte = $this->vendreCarte()->assertCreated()->json();
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $regler = function (array $exceptions) use ($vendeur) {
            $this->agir($this->admin);
            $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => $exceptions])->assertOk();
            $this->agir($vendeur->fresh());
        };

        $regler(['cartes_cadeaux.creer' => false]);
        $this->vendreCarte()->assertForbidden();
        $regler(['cartes_cadeaux.voir' => false]);
        $this->getJson('/api/cartes-cadeaux')->assertForbidden();
        $this->getJson("/api/cartes-cadeaux/{$carte['id']}/certificat")->assertForbidden();
        // La consultation au moment de payer reste ouverte.
        $this->getJson("/api/point-de-vente/carte-cadeau?numero={$carte['numero']}")->assertOk();
        $regler(['cartes_cadeaux.rembourser' => false]);
        $this->postJson("/api/cartes-cadeaux/{$carte['id']}/rembourser", ['mode' => 'especes'])->assertForbidden()
            ->assertJsonPath('message', "Droit insuffisant : votre rôle ne permet pas de faire « Rembourser le solde d'une carte cadeau » (Cartes cadeaux).");
        $regler(['cartes_cadeaux.modifier' => false, 'cartes_cadeaux.rembourser' => true]);
        $this->postJson("/api/cartes-cadeaux/{$carte['id']}/recharger", ['montant' => 10, 'mode' => 'especes'])->assertForbidden();
        $this->postJson("/api/cartes-cadeaux/{$carte['id']}/rembourser", ['mode' => 'especes'])->assertForbidden()->assertJsonPath('action', 'modifier');
        $regler([]);
        $this->postJson("/api/cartes-cadeaux/{$carte['id']}/recharger", ['montant' => 10, 'mode' => 'especes'])->assertOk();

        $this->agir($this->admin);
        $modules = collect(collect($this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets'))->firstWhere('cle', 'ventes')['modules'])->keyBy('cle');
        $this->assertSame(['cartes_cadeaux.voir', 'cartes_cadeaux.creer', 'cartes_cadeaux.modifier', 'cartes_cadeaux.rembourser'],
            array_column($modules['cartes_cadeaux']['permissions'], 'code'));

        // Une autre entreprise ne voit pas la carte et ne paie pas avec son numéro.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $produit = app(TenantContext::class)->runAsTenant($autre->id, fn () => ProduitTenant::create(['numero' => 'X', 'description' => 'Article', 'prix_unitaire' => 10])->id);
        $this->assertSame(0, $this->getJson('/api/cartes-cadeaux')->assertOk()->json('total'));
        $this->getJson("/api/cartes-cadeaux/{$carte['id']}")->assertNotFound();
        $this->getJson("/api/point-de-vente/carte-cadeau?numero={$carte['numero']}")->assertNotFound();
        $this->postJson('/api/point-de-vente/ventes', ['date' => '2026-10-17', 'lignes' => [['produit_id' => $produit, 'quantite' => 1]],
            'paiements' => [['mode' => 'carte_cadeau', 'carte_cadeau' => $carte['numero'], 'montant' => 12]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['carte_cadeau' => "Carte cadeau {$carte['numero']} introuvable."]);
    }
}
