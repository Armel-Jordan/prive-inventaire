<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\CaisseMouvement;
use App\Models\Client;
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
 * Gestion de caisse (phase 1.10a) : caisses (référentiel), ouverture avec le fond, encaissements, acomptes et
 * remboursements rattachés à la session ouverte (refusés caisse fermée), entrées et sorties d'argent, attendu par mode
 * et devise, fermeture qui fige l'attendu, le compté et l'écart, sommaire imprimé ; droits et isolation.
 */
class CaissesTest extends TestCase
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
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Awa Caissière']);
        [$this->clientId, $this->produitId] = $this->chezLeTenant(function () {
            TenantParametres::create(['nom_entreprise' => 'Boutique du Port', 'devise_code' => 'XAF', 'devise_symbole' => 'FCFA']);

            return [
                Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Comptoir', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
                ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
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

    /** Facture émise de $quantite × 100 HT à 20 % (TTC : 120 par unité), dans une devise au besoin. */
    private function factureEmise(int $quantite = 1, array $devise = []): int
    {
        $id = $this->postJson('/api/factures', $devise + [
            'client_id' => $this->clientId, 'date_facture' => '2026-10-17',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => $quantite, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]],
        ])->assertCreated()->json('id');
        $this->postJson("/api/factures/{$id}/emettre")->assertOk();

        return $id;
    }

    private function payer(int $factureId, float $montant, string $mode, ?int $caisseId)
    {
        return $this->postJson("/api/factures/{$factureId}/paiement", array_filter([
            'montant' => $montant, 'date_paiement' => '2026-10-17', 'mode_paiement' => $mode, 'caisse_id' => $caisseId,
        ], fn ($v) => $v !== null));
    }

    private function caisse(string $code = 'c1', string $nom = 'Comptoir principal'): int
    {
        return $this->postJson('/api/referentiels/caisses', ['code' => $code, 'nom' => $nom, 'actif' => true])->assertCreated()->json('id');
    }

    /** Lignes d'une session : mode|devise => [fond, encaissements, acomptes, remboursements, entrées, sorties, attendu, compté, écart]. */
    private function lignes(array $session): array
    {
        $lignes = [];
        foreach ($session['lignes'] as $l) {
            $lignes[$l['mode'].'|'.$l['devise']] = [$l['fond'], $l['encaissements'], $l['acomptes'], $l['remboursements'], $l['entrees'], $l['sorties'], $l['attendu'], $l['compte'], $l['ecart']];
        }

        return $lignes;
    }

    public function test_session_de_caisse_de_l_ouverture_au_sommaire(): void
    {
        // Caisse du référentiel : code en majuscules et unique.
        $caisse = $this->caisse();
        $this->postJson('/api/referentiels/caisses', ['code' => 'C1', 'nom' => 'Doublon'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame('C1', $this->getJson('/api/referentiels/caisses')->assertOk()->json('0.code'));

        // Caisse fermée : l'encaissement sur son poste est refusé.
        $facture = $this->factureEmise();
        $this->payer($facture, 120, 'especes', $caisse)->assertUnprocessable()
            ->assertJsonValidationErrors(['caisse_id' => "La caisse C1 n'est pas ouverte : ouvrez-la avant d'encaisser."]);
        $this->assertSame(0, FacturePaiement::count());
        $this->assertNull($this->getJson('/api/caisses')->assertOk()->json('0.session'));

        // Ouverture avec un fond de 100 ; une seule session ouverte à la fois.
        $session = $this->postJson("/api/caisses/{$caisse}/ouvrir", ['fond' => 100])->assertCreated()->assertJsonPath('statut', 'ouverte')->json();
        $this->assertEquals([100, 0, 0, 0, 0, 0, 100, null, null], $this->lignes($session)['especes|']);
        $this->postJson("/api/caisses/{$caisse}/ouvrir", ['fond' => 50])->assertUnprocessable()->assertJsonPath('message', 'La caisse C1 est déjà ouverte.');

        // Encaissements sur le poste de la caisse ; un paiement fait hors caisse n'y entre pas.
        $this->payer($facture, 120, 'especes', $caisse)->assertOk();
        $deuxieme = $this->factureEmise();
        $this->payer($deuxieme, 50, 'carte', $caisse)->assertOk();
        $this->payer($deuxieme, 70, 'virement', null)->assertOk();
        $this->assertSame([$session['id'], $session['id'], null], FacturePaiement::orderBy('id')->pluck('caisse_session_id')->all());

        // Acompte reçu, remboursé en partie, puis appliqué sur une facture : l'application n'apporte pas d'argent.
        $acompte = $this->postJson('/api/acomptes', ['client_id' => $this->clientId, 'date_acompte' => '2026-10-17', 'mode' => 'especes', 'montant' => 200, 'caisse_id' => $caisse])
            ->assertCreated()->json('id');
        $this->postJson("/api/acomptes/{$acompte}/rembourser", ['montant' => 50, 'date_remboursement' => '2026-10-17', 'caisse_id' => $caisse])->assertOk();
        $troisieme = $this->factureEmise();
        $this->postJson("/api/acomptes/{$acompte}/appliquer", ['facture_id' => $troisieme, 'montant' => 120, 'date' => '2026-10-17'])->assertOk();
        // Demande d'acompte payée au comptoir, par chèque.
        $demande = $this->postJson('/api/demandes-acompte', ['client_id' => $this->clientId, 'date_demande' => '2026-10-17', 'description' => 'Dépôt sur commande', 'montant' => 300])
            ->assertCreated()->json('id');
        $acompteDemande = $this->postJson("/api/demandes-acompte/{$demande}/encaisser", ['date' => '2026-10-17', 'mode' => 'cheque', 'caisse_id' => $caisse])
            ->assertOk()->json('acompte.id');

        // Entrée et sortie d'argent (raison obligatoire pour une sortie).
        $this->postJson("/api/caisses/sessions/{$session['id']}/mouvements", ['type' => 'entree', 'mode' => 'especes', 'montant' => 20, 'note' => 'Monnaie'])->assertCreated();
        $this->postJson("/api/caisses/sessions/{$session['id']}/mouvements", ['type' => 'sortie', 'mode' => 'especes', 'montant' => 40])
            ->assertUnprocessable()->assertJsonValidationErrors(['note' => "Indiquez la raison de la sortie d'argent."]);
        $this->postJson("/api/caisses/sessions/{$session['id']}/mouvements", ['type' => 'sortie', 'mode' => 'especes', 'montant' => 40, 'note' => 'Achat de sacs'])->assertCreated();

        // Attendu par mode : fond + encaissements + acomptes − remboursements + entrées − sorties.
        $detail = $this->getJson("/api/caisses/sessions/{$session['id']}")->assertOk()->json();
        $lignes = $this->lignes($detail);
        $this->assertEquals([100, 120, 200, 50, 20, 40, 350, null, null], $lignes['especes|']);
        $this->assertEquals([0, 50, 0, 0, 0, 0, 50, null, null], $lignes['carte|']);
        $this->assertEquals([0, 0, 300, 0, 0, 0, 300, null, null], $lignes['cheque|']);
        $this->assertEquals([0, 0, 0, 0, 0, 0, 0, null, null], $lignes['virement|']);
        $this->assertSame(['especes', 'cheque', 'virement', 'carte', 'autre'], array_column($detail['lignes'], 'mode'));
        $this->assertEquals(700, $detail['totaux'][0]['attendu']);
        // Chaque opération, avec son document ; les sorties et remboursements en négatif.
        $operations = collect($detail['operations']);
        $this->assertEqualsCanonicalizing(['fond', 'encaissement', 'encaissement', 'acompte', 'remboursement', 'acompte', 'entree', 'sortie'], $operations->pluck('type')->all());
        $this->assertEquals(-50, $operations->firstWhere('type', 'remboursement')['montant']);
        $this->assertEquals(-40, $operations->firstWhere('type', 'sortie')['montant']);
        $paiement = $operations->firstWhere('mode', 'carte');
        $this->assertSame([$deuxieme, 'Client Comptoir', 'Awa Caissière'], [$paiement['facture_id'], $paiement['client'], $paiement['commis']]);
        $this->assertSame(['Acompte du 17/10/2026', $acompteDemande], [$operations->where('type', 'acompte')->last()['document'], $operations->where('type', 'acompte')->last()['acompte_id']]);
        // Par employé : encaissements et acomptes, moins les remboursements.
        $this->assertEquals([['especes', 270, 3], ['cheque', 300, 1], ['carte', 50, 1]],
            array_map(fn ($g) => [$g['mode'], $g['montant'], $g['nombre']], $detail['par_usager']));
        // Hors caisse pendant la session : le virement.
        $this->assertSame([['encaissement', 'virement', 70.0]], array_map(fn ($o) => [$o['type'], $o['mode'], (float) $o['montant']], $detail['sans_caisse']['operations']));

        // Pas de sommaire imprimé tant que la caisse est ouverte.
        $this->getJson("/api/caisses/sessions/{$session['id']}/pdf")->assertUnprocessable()->assertJsonPath('message', "Fermez la caisse avant d'imprimer son sommaire.");

        // Fermeture : compté par mode (un mode non saisi compte pour 0), écart, note ; chiffres figés.
        $fermee = $this->postJson("/api/caisses/sessions/{$session['id']}/fermer", ['comptes' => [
            ['mode' => 'especes', 'montant' => 340], ['mode' => 'carte', 'montant' => 50], ['mode' => 'cheque', 'montant' => 300],
        ], 'note' => 'Il manque 10 en espèces.'])->assertOk()->assertJsonPath('statut', 'fermee')->json();
        $lignes = $this->lignes($fermee);
        $this->assertEquals([350, 340, -10], array_slice($lignes['especes|'], 6));
        $this->assertEquals([0, 0, 0], array_slice($lignes['virement|'], 6));
        $this->assertEquals(-10, $fermee['total_ecart']);
        $this->assertEquals([700, 690, -10], [$fermee['totaux'][0]['attendu'], $fermee['totaux'][0]['compte'], $fermee['totaux'][0]['ecart']]);

        // Session fermée : plus d'encaissement, de mouvement ni de deuxième fermeture.
        $this->payer($this->factureEmise(), 120, 'especes', $caisse)->assertUnprocessable()->assertJsonValidationErrors('caisse_id');
        $this->postJson("/api/caisses/sessions/{$session['id']}/mouvements", ['type' => 'entree', 'mode' => 'especes', 'montant' => 5])
            ->assertUnprocessable()->assertJsonPath('message', 'Cette session de caisse est fermée.');
        $this->postJson("/api/caisses/sessions/{$session['id']}/fermer", ['comptes' => []])->assertUnprocessable()->assertJsonPath('message', 'Cette session de caisse est déjà fermée.');

        // Une transaction corrigée après coup ne change pas les chiffres figés.
        $this->putJson("/api/acomptes/{$acompteDemande}", ['montant' => 999])->assertOk();
        $this->assertEquals([0, 0, 300, 0, 0, 0, 300, 300, 0], $this->lignes($this->getJson("/api/caisses/sessions/{$session['id']}")->assertOk()->json())['cheque|']);

        // Sommaire imprimé, liste des sessions, caisse qui a servi.
        $this->assertStringStartsWith('%PDF', $this->get("/api/caisses/sessions/{$session['id']}/pdf?fuseau=Africa/Douala")->assertOk()->getContent());
        $liste = $this->getJson('/api/caisses/sessions')->assertOk()->json();
        $this->assertSame(['2026-10-11', '2026-10-17'], [$liste['du'], $liste['au']]);
        $this->assertSame([[$session['id'], 'fermee', 'C1']], array_map(fn ($s) => [$s['id'], $s['statut'], $s['caisse']['code']], $liste['sessions']));
        $this->assertEquals(-10, $liste['sessions'][0]['totaux'][0]['ecart']);
        $this->getJson('/api/caisses/sessions?du=2026-10-18&au=2026-10-20')->assertOk()->assertJsonCount(0, 'sessions');
        $this->deleteJson("/api/referentiels/caisses/{$caisse}")->assertUnprocessable()
            ->assertJsonPath('message', 'Cette caisse a déjà servi : désactivez-la plutôt que de la supprimer.');
        $this->putJson("/api/referentiels/caisses/{$caisse}", ['code' => 'C1', 'nom' => 'Comptoir principal', 'actif' => false])->assertOk();
        $this->postJson("/api/caisses/{$caisse}/ouvrir", [])->assertUnprocessable()->assertJsonPath('message', 'La caisse C1 est désactivée.');
        $this->assertSame([], $this->getJson('/api/caisses')->assertOk()->json());
        // Une caisse qui n'a jamais servi se supprime.
        $this->deleteJson('/api/referentiels/caisses/'.$this->caisse('C2', 'Comptoir du fond'))->assertOk();
    }

    public function test_devises_droits_et_isolation(): void
    {
        $caisse = $this->caisse();
        $session = $this->postJson("/api/caisses/{$caisse}/ouvrir", ['fond' => 0])->assertCreated()->json('id');
        $this->assertSame(0, $this->chezLeTenant(fn () => CaisseMouvement::count()));

        // Une facture en dollars payée en espèces : une ligne à part, comptée dans sa devise.
        $this->chezLeTenant(fn () => Devise::create(['code' => 'USD', 'libelle' => 'Dollar US', 'taux' => 600]));
        $dollars = $this->factureEmise(1, ['devise' => 'USD', 'taux_change' => 600]);
        $this->payer($dollars, 120, 'especes', $caisse)->assertOk();
        $this->postJson("/api/caisses/sessions/{$session}/mouvements", ['type' => 'sortie', 'mode' => 'especes', 'devise' => 'usd', 'montant' => 20, 'note' => 'Change'])->assertCreated();
        $this->postJson("/api/caisses/sessions/{$session}/mouvements", ['type' => 'entree', 'mode' => 'especes', 'devise' => 'XAF', 'montant' => 500, 'note' => 'Appoint'])->assertCreated();
        $detail = $this->getJson("/api/caisses/sessions/{$session}")->assertOk()->json();
        $this->assertEquals([0, 120, 0, 0, 0, 20, 100, null, null], $this->lignes($detail)['especes|USD']);
        $this->assertEquals(500, $this->lignes($detail)['especes|'][6]);
        $this->assertSame([null, 'USD'], array_column($detail['totaux'], 'devise'));
        $fermee = $this->postJson("/api/caisses/sessions/{$session}/fermer", ['comptes' => [
            ['mode' => 'especes', 'montant' => 500], ['mode' => 'especes', 'devise' => 'USD', 'montant' => 95],
        ]])->assertOk()->json();
        $this->assertEquals([100, 95, -5], array_slice($this->lignes($fermee)['especes|USD'], 6));
        // Le total des écarts est celui de la devise de l'entreprise ; l'écart en dollars reste à part.
        $this->assertEquals(0, $fermee['total_ecart']);
        $this->assertEquals([0, -5], array_column($fermee['totaux'], 'ecart'));

        // Droits : gérer les sessions (ouvrir, mouvements, fermer) ; consulter (écran, sommaire).
        $caissier = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $regler = function (array $exceptions) use ($caissier) {
            $this->agir($this->admin);
            $this->putJson("/api/acces-comptes/{$caissier->id}", ['groupes' => [], 'exceptions' => $exceptions])->assertOk();
            $this->agir($caissier->fresh());
        };
        $regler(['caisses.gerer' => false]);
        $this->postJson("/api/caisses/{$caisse}/ouvrir", ['fond' => 10])->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Gérer les sessions de caisse » (Gestion de caisse).');
        $this->getJson('/api/caisses/sessions')->assertOk();
        $regler(['caisses.voir' => false]);
        $this->getJson('/api/caisses')->assertForbidden();
        $this->getJson("/api/caisses/sessions/{$session}")->assertForbidden();
        // Sans accès à la gestion de caisse, on encaisse quand même sur le poste d'une caisse ouverte.
        $regler([]);
        $nouvelle = $this->postJson("/api/caisses/{$caisse}/ouvrir", ['fond' => 10])->assertCreated()->json('id');
        $regler(['caisses.voir' => false, 'caisses.gerer' => false]);
        $this->payer($this->factureEmise(), 120, 'especes', $caisse)->assertOk();
        $this->assertSame($nouvelle, FacturePaiement::latest('id')->value('caisse_session_id'));

        $this->agir($this->admin);
        $modules = collect(collect($this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets'))->firstWhere('cle', 'ventes')['modules'])->keyBy('cle');
        $this->assertSame(['caisses.voir', 'caisses.gerer'], array_column($modules['caisses']['permissions'], 'code'));

        // Une autre entreprise ne voit ni les caisses ni les sessions, et n'encaisse pas dans une caisse étrangère.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->assertSame([], $this->getJson('/api/caisses')->assertOk()->json());
        $this->assertSame([], $this->getJson('/api/caisses/sessions')->assertOk()->json('sessions'));
        $this->getJson("/api/caisses/sessions/{$session}")->assertNotFound();
        $this->get("/api/caisses/sessions/{$session}/pdf")->assertNotFound();
        $this->postJson("/api/caisses/{$caisse}/ouvrir", [])->assertNotFound();
        $this->postJson("/api/caisses/sessions/{$nouvelle}/fermer", ['comptes' => []])->assertNotFound();
        $client = app(TenantContext::class)->runAsTenant($autre->id, fn () => Client::create(['code' => 'X', 'raison_sociale' => 'Autre', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id);
        $this->postJson('/api/acomptes', ['client_id' => $client, 'date_acompte' => '2026-10-17', 'mode' => 'especes', 'montant' => 10, 'caisse_id' => $caisse])
            ->assertUnprocessable()->assertJsonPath('message', "La caisse de ce poste n'existe plus : choisissez-en une autre dans Gestion de caisse.");
    }
}
