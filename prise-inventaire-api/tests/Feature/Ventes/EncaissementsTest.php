<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Configuration;
use App\Models\Facture;
use App\Models\FacturePaiement;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Encaissements des clients (phase 4.4) : un montant reçu réparti sur plusieurs factures, surplus laissé en acompte,
 * escompte accordé, avance sans facture, contrôles de la répartition, reçu, correction, annulation (factures de nouveau
 * dues, surplus supprimé ; refusée si le surplus a servi), caisse du poste, droits et isolation.
 */
class EncaissementsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-17 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->produitId] = $this->chez(fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Bâtisseurs SA', 'adresse_facturation' => '1 rue du Port', 'ville' => 'Douala', 'code_postal' => '0'])->id,
            ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        $this->agir('admin');
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(string $role, ?AdminUser $compte = null): AdminUser
    {
        $compte ??= AdminUser::factory()->for($this->tenant)->create(['role' => $role]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);

        return $compte;
    }

    /** Facture émise de $quantite × 100 HT à 20 % (TTC : 120 par unité). */
    private function facture(int $quantite, string $date = '2026-10-10', ?int $client = null): array
    {
        $id = $this->postJson('/api/factures', [
            'client_id' => $client ?? $this->clientId, 'date_facture' => $date,
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => $quantite, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]],
        ])->assertCreated()->json('id');

        return $this->postJson("/api/factures/{$id}/emettre")->assertOk()->json();
    }

    private function encaisser(float $montant, array $lignes, array $autres = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/encaissements', $autres + [
            'client_id' => $this->clientId, 'date_encaissement' => '2026-10-17', 'mode' => 'cheque', 'reference' => 'CHQ-77', 'montant' => $montant,
            'lignes' => array_map(fn ($facture, $montantLigne) => ['facture_id' => $facture, 'montant' => $montantLigne], array_keys($lignes), $lignes),
        ]);
    }

    private function etat(int $facture): array
    {
        $f = $this->getJson("/api/factures/{$facture}")->assertOk()->json();

        return [$f['statut'], (float) $f['montant_paye'], (float) $f['montant_escompte'], (float) $f['reste_a_payer']];
    }

    public function test_encaissement_reparti_surplus_escompte_avance_et_annulation(): void
    {
        $f1 = $this->facture(1, '2026-10-01');
        $f2 = $this->facture(2, '2026-10-05');
        $f3 = $this->facture(3);

        // Ce que doit le client : ses factures dues, l'échéance la plus ancienne d'abord ; sans échéance, une facture
        // est due à sa date.
        $this->chez(fn () => [Facture::whereKey($f1['id'])->update(['date_echeance' => '2026-11-30']), Facture::whereKey($f2['id'])->update(['date_echeance' => null])]);
        $du = $this->getJson("/api/encaissements/a-regler?client_id={$this->clientId}")->assertOk()->json();
        $this->assertSame([$f2['numero'], $f3['numero'], $f1['numero']], array_column($du['factures'], 'numero'));
        $this->assertSame([12, 0], [$du['factures'][0]['jours_retard'], $du['factures'][2]['jours_retard']]);
        $this->assertEquals([720, 0, 0], [$du['total_du'], $du['credits']['acomptes'], $du['credits']['avoirs']]);

        // Un chèque de 500 : 120 + 240 + 100 ; le surplus de 40 reste au crédit du client.
        $encaissement = $this->encaisser(500, [$f1['id'] => 120, $f2['id'] => 240, $f3['id'] => 100])->assertCreated()->json();
        $this->assertSame(['ENC-00001', 'valide', 'Bâtisseurs SA'], [$encaissement['numero'], $encaissement['statut'], $encaissement['client']['raison_sociale']]);
        $this->assertEquals([500, 460, 0, 40], [$encaissement['montant'], $encaissement['montant_applique'], $encaissement['escompte'], $encaissement['surplus']]);
        $this->assertSame([$f1['numero'], $f2['numero'], $f3['numero']], array_column(array_column($encaissement['lignes'], 'facture'), 'numero'));
        $this->assertEquals(['payee', 120, 0, 0], $this->etat($f1['id']));
        $this->assertEquals(['payee', 240, 0, 0], $this->etat($f2['id']));
        $this->assertEquals(['partiellement_payee', 100, 0, 260], $this->etat($f3['id']));
        // Chaque part est un paiement de sa facture, au mode et à la référence de l'encaissement.
        $paiement = $this->getJson("/api/factures/{$f3['id']}")->json('paiements.0');
        $this->assertSame([$encaissement['id'], 'cheque', 'CHQ-77', 'Encaissement ENC-00001'], [$paiement['encaissement_id'], $paiement['mode_paiement'], $paiement['reference'], $paiement['notes']]);
        $surplus = $this->getJson("/api/acomptes/{$encaissement['acompte_id']}")->assertOk()->json();
        $this->assertEquals([40, 'ENC-00001', "Surplus de l'encaissement ENC-00001 (CHQ-77)", 'cheque'], [$surplus['disponible'], $surplus['reference'], $surplus['notes'], $surplus['mode']]);
        // Relevé : les trois paiements et le surplus au crédit ; il reste 260 dû sur la dernière facture, moins le crédit.
        $releve = $this->getJson("/api/comptes-clients/{$this->clientId}/releve")->assertOk()->json();
        $this->assertEquals(220, $releve['solde_final']);
        $this->assertEquals([[$f3['numero'], 260]], array_map(fn ($f) => [$f['numero'], $f['reste']], $releve['factures_dues']));
        $this->assertEquals([260, 40], [$this->getJson("/api/encaissements/a-regler?client_id={$this->clientId}")->json('total_du'), $this->getJson("/api/encaissements/a-regler?client_id={$this->clientId}")->json('credits.acomptes')]);

        // Le surplus suit l'encaissement : ni son montant ni sa suppression ne se changent depuis les acomptes.
        $this->putJson("/api/acomptes/{$surplus['id']}", ['montant' => 50])->assertUnprocessable()
            ->assertJsonPath('message', "Cet acompte est le surplus de l'encaissement ENC-00001 : il se corrige en annulant l'encaissement.");
        $this->deleteJson("/api/acomptes/{$surplus['id']}")->assertUnprocessable();
        $this->putJson("/api/acomptes/{$surplus['id']}", ['notes' => 'Crédit à utiliser'])->assertOk();

        // 250 reçus pour solder les 260 de la dernière facture : refusé, sauf en accordant 10 d'escompte.
        $this->encaisser(250, [$f3['id'] => 260], ['mode' => 'virement', 'reference' => 'VIR-9'])->assertUnprocessable()
            ->assertJsonValidationErrors(['montant' => 'La répartition dépasse de 10,00 le montant reçu : réduisez-la, ou accordez la différence en escompte.']);
        $escompte = $this->encaisser(250, [$f3['id'] => 260], ['mode' => 'virement', 'reference' => 'VIR-9', 'escompte' => true])->assertCreated()->json();
        $this->assertEquals([250, 260, 10, 0, null], [$escompte['montant'], $escompte['montant_applique'], $escompte['escompte'], $escompte['surplus'], $escompte['acompte_id']]);
        $this->assertEquals(['payee', 350, 10, 0], $this->etat($f3['id']));

        // Une avance sans facture : tout le montant reste au crédit du client.
        $avance = $this->encaisser(75, [], ['mode' => 'especes', 'reference' => null])->assertCreated()->json();
        $this->assertEquals([75, 0, 75], [$avance['montant'], $avance['montant_applique'], $avance['surplus']]);
        $this->assertSame('ENC-00003', $this->getJson("/api/acomptes/{$avance['acompte_id']}")->json('reference'));

        // Référence corrigée : les paiements des factures la suivent.
        $this->putJson("/api/encaissements/{$encaissement['id']}", ['reference' => 'CHQ-78', 'notes' => 'Chèque remplacé'])->assertOk()
            ->assertJsonPath('reference', 'CHQ-78')->assertJsonPath('notes', 'Chèque remplacé');
        $this->assertSame(['CHQ-78'], $this->chez(fn () => FacturePaiement::where('encaissement_id', $encaissement['id'])->distinct()->pluck('reference')->all()));

        // Liste : recherche par facture, totaux des encaissements valides.
        $liste = $this->getJson("/api/encaissements?client_id={$this->clientId}")->assertOk()->json();
        $this->assertSame(['ENC-00003', 'ENC-00002', 'ENC-00001'], array_column($liste['data'], 'numero'));
        $this->assertEquals([3, 825, 720, 10, 115], [$liste['resume']['nombre'], $liste['resume']['montant'], $liste['resume']['montant_applique'], $liste['resume']['escompte'], $liste['resume']['surplus']]);
        $this->assertSame(['ENC-00001'], array_column($this->getJson('/api/encaissements?recherche='.$f1['numero'])->json('data'), 'numero'));
        $this->assertStringStartsWith('%PDF', $this->get("/api/encaissements/{$encaissement['id']}/pdf")->assertOk()->getContent());

        // Annuler le premier : ses paiements sont retirés, ses factures redeviennent dues, son surplus disparaît.
        $annule = $this->postJson("/api/encaissements/{$encaissement['id']}/annuler", ['motif' => 'Chèque sans provision'])->assertOk()->json();
        $this->assertSame(['annule', 'Chèque sans provision', null], [$annule['statut'], $annule['motif_annulation'], $annule['acompte_id']]);
        $this->assertEquals(['emise', 0, 0, 120], $this->etat($f1['id']));
        $this->assertEquals(['emise', 0, 0, 240], $this->etat($f2['id']));
        $this->assertEquals(['partiellement_payee', 250, 10, 100], $this->etat($f3['id']));
        $this->getJson("/api/acomptes/{$surplus['id']}")->assertNotFound();
        $this->assertEquals(385, $this->getJson("/api/comptes-clients/{$this->clientId}/releve")->json('solde_final'));
        $this->postJson("/api/encaissements/{$encaissement['id']}/annuler")->assertUnprocessable()->assertJsonPath('message', "L'encaissement ENC-00001 est déjà annulé.");
        $this->putJson("/api/encaissements/{$encaissement['id']}", ['notes' => 'x'])->assertUnprocessable();
        $this->assertEquals([2, 325], [$this->getJson('/api/encaissements')->json('resume.nombre'), $this->getJson('/api/encaissements')->json('resume.montant')]);
        $this->assertSame(['ENC-00001'], array_column($this->getJson('/api/encaissements?statut=annule')->json('data'), 'numero'));

        // Le surplus de l'avance appliqué sur une facture : l'avance ne s'annule plus.
        $this->postJson("/api/acomptes/{$avance['acompte_id']}/appliquer", ['facture_id' => $f1['id'], 'montant' => 75, 'date' => '2026-10-17'])->assertOk();
        $this->postJson("/api/encaissements/{$avance['id']}/annuler")->assertUnprocessable()
            ->assertJsonPath('message', "Le surplus de l'encaissement ENC-00003, laissé en acompte, a déjà été appliqué ou remboursé : l'encaissement ne s'annule plus.");
    }

    public function test_controles_de_la_repartition_et_numerotation(): void
    {
        $facture = $this->facture(1);
        $autreClient = $this->chez(fn () => Client::create(['code' => 'CLI-2', 'raison_sociale' => 'Autre', 'adresse_facturation' => '2 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id);
        $etrangere = $this->facture(1, '2026-10-10', $autreClient);

        // Plus que le reste à payer, facture d'un autre client, deux fois la même facture, avant la facture, montant nul.
        $this->encaisser(130, [$facture['id'] => 130])->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes' => "Le montant dépasse le reste à payer de la facture {$facture['numero']} (120,00)."]);
        $this->encaisser(120, [$etrangere['id'] => 120])->assertUnprocessable()->assertJsonValidationErrors(['lignes' => 'Choisissez des factures du client.']);
        $this->postJson('/api/encaissements', [
            'client_id' => $this->clientId, 'date_encaissement' => '2026-10-17', 'mode' => 'cheque', 'montant' => 120,
            'lignes' => [['facture_id' => $facture['id'], 'montant' => 60], ['facture_id' => $facture['id'], 'montant' => 60]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lignes');
        $this->encaisser(120, [$facture['id'] => 120], ['date_encaissement' => '2026-10-09'])->assertUnprocessable()->assertJsonValidationErrors('date_encaissement');
        $this->encaisser(0, [$facture['id'] => 0])->assertUnprocessable()->assertJsonValidationErrors('montant');
        $this->encaisser(120, [$facture['id'] => 120], ['mode' => 'carte_cadeau'])->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->encaisser(120, [$facture['id'] => 120], ['date_encaissement' => '2026-10-25'])->assertUnprocessable()->assertJsonValidationErrors('date_encaissement');
        // Une facture d'une autre devise n'est pas proposée et se refuse.
        $this->encaisser(120, [$facture['id'] => 120], ['devise' => 'USD', 'taux_change' => 600])->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes' => "La facture {$facture['numero']} n'est pas dans la devise de l'encaissement."]);
        $this->assertSame([], $this->getJson("/api/encaissements/a-regler?client_id={$this->clientId}&devise=USD")->assertOk()->json('factures'));
        $this->assertSame(0, $this->getJson('/api/encaissements')->json('total'));

        // Sans numérotation automatique, l'encaissement est refusé (aucun paiement n'est créé).
        $this->chez(fn () => Configuration::pourEntite('encaissement', $this->tenant->id)->update(['auto_increment' => false]));
        $this->encaisser(120, [$facture['id'] => 120])->assertUnprocessable()
            ->assertJsonPath('message', 'Numéro requis : activez la numérotation automatique des encaissements (Configuration → Numérotation).');
        $this->assertEquals(['emise', 0, 0, 120], $this->etat($facture['id']));
    }

    public function test_encaissement_a_la_caisse_du_poste(): void
    {
        $facture = $this->facture(2);
        $caisse = $this->postJson('/api/referentiels/caisses', ['code' => 'C1', 'nom' => 'Comptoir', 'actif' => true])->assertCreated()->json('id');
        $this->encaisser(300, [$facture['id'] => 240], ['mode' => 'especes', 'caisse_id' => $caisse])->assertUnprocessable()
            ->assertJsonValidationErrors(['caisse_id' => "La caisse C1 n'est pas ouverte : ouvrez-la avant d'encaisser."]);
        $session = $this->postJson("/api/caisses/{$caisse}/ouvrir", ['fond' => 0])->assertCreated()->json('id');

        // 300 en espèces : 240 sur la facture, 60 laissés en acompte ; la caisse attend les deux.
        $this->encaisser(300, [$facture['id'] => 240], ['mode' => 'especes', 'caisse_id' => $caisse])->assertCreated()->assertJsonPath('caisse_session_id', $session);
        $especes = collect($this->getJson("/api/caisses/sessions/{$session}")->assertOk()->json('lignes'))->firstWhere('mode', 'especes');
        $this->assertEquals([240, 60, 300], [$especes['encaissements'], $especes['acomptes'], $especes['attendu']]);
    }

    public function test_droits_et_isolation(): void
    {
        $facture = $this->facture(1);
        $encaissement = $this->encaisser(120, [$facture['id'] => 120])->assertCreated()->json();

        // Rôles système : comme les paiements de factures (« user » consulte seulement).
        $this->agir('user');
        $this->getJson('/api/encaissements')->assertOk();
        $this->getJson("/api/encaissements/{$encaissement['id']}")->assertOk();
        $this->encaisser(10, [])->assertForbidden();
        $this->postJson("/api/encaissements/{$encaissement['id']}/annuler")->assertForbidden();

        // Un gérant sans le droit d'annuler : il saisit, mais n'annule pas.
        $this->agir('admin');
        $gerant = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$gerant->id}", ['groupes' => [], 'exceptions' => ['encaissements.annuler' => false]])->assertOk();
        $this->agir('manager', $gerant);
        $this->encaisser(10, [])->assertCreated();
        $this->postJson("/api/encaissements/{$encaissement['id']}/annuler")->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Annuler un encaissement » (Encaissements).');

        // Catalogue : le module et son action.
        $this->agir('admin');
        $modules = collect(collect($this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets'))->firstWhere('cle', 'ventes')['modules'])->keyBy('cle');
        $this->assertSame(['encaissements.voir', 'encaissements.creer', 'encaissements.modifier', 'encaissements.annuler'], array_column($modules['encaissements']['permissions'], 'code'));

        // Une autre entreprise ne voit ni n'annule ces encaissements.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->assertSame(0, $this->getJson('/api/encaissements')->assertOk()->json('total'));
        $this->getJson("/api/encaissements/{$encaissement['id']}")->assertNotFound();
        $this->postJson("/api/encaissements/{$encaissement['id']}/annuler")->assertNotFound();
        $this->getJson("/api/encaissements/a-regler?client_id={$this->clientId}")->assertUnprocessable()->assertJsonValidationErrors('client_id');
    }
}
