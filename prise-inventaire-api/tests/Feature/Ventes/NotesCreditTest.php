<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Notes de crédit (phase 1.5) : crédit partiel puis complet d'une facture due, crédit d'une facture payée remboursé
 * ou imputé sur une autre facture, contrôles (quantités, prix, dates, brouillons, émissions concurrentes), taxes au
 * prorata, PDF, courriel, recherche, comptes clients et droits.
 */
class NotesCreditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->produitId] = app(TenantContext::class)->runAsTenant($this->tenant->id, fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue du Test', 'ville' => 'Douala', 'code_postal' => '0', 'email' => 'achats@client.test'])->id,
            ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        $this->agir('admin');
    }

    private function agir(string $role, array $attributs = []): AdminUser
    {
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => $role] + $attributs);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);

        return $compte;
    }

    /** Facture émise ; renvoie son id et l'id de sa première ligne. */
    private function factureEmise(array $lignes, string $date = '2026-10-16'): array
    {
        $facture = $this->postJson('/api/factures', [
            'client_id' => $this->clientId, 'date_facture' => $date,
            'lignes' => array_map(fn (array $l) => ['produit_id' => $this->produitId] + $l, $lignes),
        ])->assertCreated()->json();
        $this->postJson("/api/factures/{$facture['id']}/emettre")->assertOk();

        return [$facture['id'], $facture['lignes'][0]['id']];
    }

    private function noter(int $facture, array $lignes, array $autres = []): TestResponse
    {
        return $this->postJson('/api/avoirs', $autres + ['facture_id' => $facture, 'date_avoir' => '2026-10-16', 'lignes' => $lignes]);
    }

    private function montants(array $document, array $champs): array
    {
        return array_map(fn (string $champ) => (float) $document[$champ], $champs);
    }

    public function test_credit_partiel_puis_complet_d_une_facture_due(): void
    {
        [$facture, $ligne] = $this->factureEmise([['quantite' => 3, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]]);
        $proposition = $this->getJson("/api/factures/{$facture}/avoir")->assertOk()->assertJsonPath('creditable', true)->json('lignes.0');
        $this->assertEquals([3, 300, 3, 0], [$proposition['quantite_facturee'], $proposition['reste_ht'], $proposition['quantite_proposee'], $proposition['credite_ht']]);

        // Brouillon (retour d'une unité) : pas encore de numéro, la facture ne change pas.
        $brouillon = $this->noter($facture, [['facture_ligne_id' => $ligne, 'quantite' => 1]], ['motif' => 'Retour'])
            ->assertCreated()->assertJsonPath('statut', 'brouillon')->assertJsonPath('numero', null)->json();
        $this->assertEquals([100, 20, 120], $this->montants($brouillon, ['montant_ht', 'montant_tva', 'montant_ttc']));
        $this->assertEquals(360, $this->getJson("/api/factures/{$facture}")->json('reste_a_payer'));

        // Émise : numérotée, entièrement imputée sur la facture (soldée).
        $emise = $this->postJson("/api/avoirs/{$brouillon['id']}/emettre")->assertOk()->json();
        $this->assertSame(['AV-00001', 'solde'], [$emise['numero'], $emise['statut']]);
        $this->assertEquals([120, 0], [$emise['montant_impute'], $emise['credit_disponible']]);
        $vue = $this->getJson("/api/factures/{$facture}")->assertOk()->assertJsonPath('statut', 'emise')->assertJsonPath('avoirs.0.numero', 'AV-00001')->json();
        $this->assertEquals([120, 240], $this->montants($vue, ['montant_credite', 'reste_a_payer']));
        $this->assertEquals(240, $vue['client']['encours_actuel']);
        $this->assertEquals([1, 200, 2], array_values(array_intersect_key(
            $this->getJson("/api/factures/{$facture}/avoir")->json('lignes.0'), array_flip(['quantite_creditee', 'reste_ht', 'quantite_proposee']))));

        // Un paiement ne dépasse plus le reste dû, crédit déduit.
        $this->postJson("/api/factures/{$facture}/paiement", ['montant' => 300, 'date_paiement' => '2026-10-16', 'mode_paiement' => 'virement'])
            ->assertUnprocessable()->assertJsonPath('message', 'Le montant dépasse le reste à payer');

        // Le reste : la note complète reprend exactement les totaux restants ; la facture est « créditée ».
        $complete = $this->noter($facture, [['facture_ligne_id' => $ligne, 'quantite' => 2]])->assertCreated()->json('id');
        $this->postJson("/api/avoirs/{$complete}/emettre")->assertOk()->assertJsonPath('numero', 'AV-00002');
        $vue = $this->getJson("/api/factures/{$facture}")->assertOk()->assertJsonPath('statut', 'creditee')->json();
        $this->assertEquals([360, 0, 0], [...$this->montants($vue, ['montant_credite', 'reste_a_payer']), $vue['client']['encours_actuel']]);

        $this->noter($facture, [['facture_ligne_id' => $ligne, 'quantite' => 1]])->assertUnprocessable()
            ->assertJsonPath('message', 'La facture FAC-00001 est déjà entièrement créditée.');
        $this->postJson("/api/factures/{$facture}/paiement", ['montant' => 1, 'date_paiement' => '2026-10-16', 'mode_paiement' => 'virement'])->assertUnprocessable();
        $this->assertSame(['AV-00002', 'AV-00001'], array_column($this->getJson("/api/avoirs?facture_id={$facture}")->assertOk()->json('data'), 'numero'));
    }

    public function test_credit_d_une_facture_payee_rembourse_ou_impute_sur_une_autre_facture(): void
    {
        [$payee, $ligne] = $this->factureEmise([['quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]]);
        $this->postJson("/api/factures/{$payee}/paiement", ['montant' => 120, 'date_paiement' => '2026-10-16', 'mode_paiement' => 'virement'])->assertOk();

        // Geste commercial de 50 HT : rien à imputer sur la facture payée, tout le crédit est disponible.
        $avoir = $this->noter($payee, [['facture_ligne_id' => $ligne, 'quantite' => 1, 'prix_unitaire_ht' => 50]])->assertCreated()->json('id');
        $emis = $this->postJson("/api/avoirs/{$avoir}/emettre")->assertOk()->assertJsonPath('statut', 'emis')->json();
        $this->assertEquals([60, 0, 60], $this->montants($emis, ['montant_ttc', 'montant_impute', 'credit_disponible']));
        $this->getJson("/api/factures/{$payee}")->assertJsonPath('statut', 'payee')->assertJsonPath('montant_credite', '0.00');

        [$due] = $this->factureEmise([['quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]]);
        $this->assertSame([$due], array_column($this->getJson("/api/avoirs/{$avoir}/factures-imputables")->assertOk()->json(), 'id'));

        $this->postJson("/api/avoirs/{$avoir}/imputer", ['facture_id' => $due, 'montant' => 40, 'date' => '2026-10-16'])
            ->assertOk()->assertJsonPath('imputations.0.facture.id', $due);
        $this->assertEquals([40, 80], $this->montants($this->getJson("/api/factures/{$due}")->json(), ['montant_credite', 'reste_a_payer']));
        $this->postJson("/api/avoirs/{$avoir}/imputer", ['facture_id' => $due, 'montant' => 30, 'date' => '2026-10-16'])
            ->assertUnprocessable()->assertJsonValidationErrors('montant');
        $this->postJson("/api/avoirs/{$avoir}/imputer", ['facture_id' => $payee, 'montant' => 10, 'date' => '2026-10-16'])
            ->assertUnprocessable()->assertJsonValidationErrors('facture_id');

        // Analyse : la facture due (80) et le crédit encore disponible (20), dû au client.
        $client = $this->getJson('/api/comptes-clients')->assertOk()->json('clients.0');
        $this->assertEquals([80, 20, 60], [$client['total'], $client['credits'], $client['solde']]);

        $this->postJson("/api/avoirs/{$avoir}/rembourser", ['montant' => 20, 'date_remboursement' => '2026-10-16', 'mode' => 'especes'])
            ->assertOk()->assertJsonPath('statut', 'solde')->assertJsonPath('remboursements.0.mode', 'especes');
        $this->postJson("/api/avoirs/{$avoir}/rembourser", ['montant' => 1, 'date_remboursement' => '2026-10-16', 'mode' => 'especes'])
            ->assertUnprocessable()->assertJsonPath('message', "La note de crédit AV-00001 n'a plus de crédit disponible.");

        // Relevé : factures et remboursement au débit, note de crédit et paiement au crédit.
        $releve = $this->getJson("/api/comptes-clients/{$this->clientId}/releve")->assertOk()->json();
        $this->assertSame(['facture', 'facture', 'avoir', 'paiement', 'remboursement'], array_column($releve['lignes'], 'type'));
        $this->assertSame('Note de crédit AV-00001 (facture FAC-00001)', $releve['lignes'][2]['libelle']);
        $this->assertEquals([0, 260, 180, 80], [$releve['solde_ouverture'], $releve['total_debit'], $releve['total_credit'], $releve['solde_final']]);
        $this->assertEquals([['FAC-00002', 80]], array_map(fn ($f) => [$f['numero'], $f['reste']], $releve['factures_dues']));
        $this->assertSame([], $releve['credits_disponibles']);
    }

    public function test_controles_des_lignes_des_dates_et_des_brouillons(): void
    {
        [$facture, $ligne] = $this->factureEmise([['quantite' => 2, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]], '2026-10-10');
        [, $ligneAutre] = $this->factureEmise([['quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]]);

        $this->noter($facture, [['facture_ligne_id' => $ligne, 'quantite' => 3]])->assertUnprocessable()->assertJsonValidationErrors('lignes.0.quantite');
        $this->noter($facture, [['facture_ligne_id' => $ligne, 'quantite' => 1, 'prix_unitaire_ht' => 150]])->assertUnprocessable()->assertJsonValidationErrors('lignes.0.prix_unitaire_ht');
        $this->noter($facture, [['facture_ligne_id' => $ligneAutre, 'quantite' => 1]])->assertUnprocessable()->assertJsonValidationErrors('lignes.0.facture_ligne_id');
        $this->noter($facture, [['facture_ligne_id' => $ligne, 'quantite' => 0]])->assertUnprocessable()->assertJsonValidationErrors('lignes');
        $this->noter($facture, [['facture_ligne_id' => $ligne, 'quantite' => 1]], ['date_avoir' => '2026-10-09'])->assertUnprocessable()->assertJsonValidationErrors('date_avoir');

        $brouillonFacture = $this->postJson('/api/factures', ['client_id' => $this->clientId, 'date_facture' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100]]])->json();
        $this->noter($brouillonFacture['id'], [['facture_ligne_id' => $brouillonFacture['lignes'][0]['id'], 'quantite' => 1]])
            ->assertUnprocessable()->assertJsonPath('message', 'Seule une facture émise peut être créditée.');

        // Choix de la facture à créditer : factures émises, par numéro ou par client (le brouillon n'y est pas).
        $aCrediter = fn (string $recherche) => array_column($this->getJson('/api/factures?statuts=emise,partiellement_payee,payee&recherche='.urlencode($recherche))->assertOk()->json('data'), 'numero');
        $this->assertEqualsCanonicalizing(['FAC-00001', 'FAC-00002'], $aCrediter('Client Test'));
        $this->assertSame(['FAC-00002'], $aCrediter('00002'));
        $this->assertSame([], $aCrediter('00003'));

        // Deux brouillons : le second (tout le reste) ne s'émet plus une fois le premier émis.
        $premier = $this->noter($facture, [['facture_ligne_id' => $ligne, 'quantite' => 2]])->assertCreated()->json('id');
        $second = $this->noter($facture, [['facture_ligne_id' => $ligne, 'quantite' => 2]])->assertCreated()->json('id');
        $this->putJson("/api/avoirs/{$premier}", ['date_avoir' => '2026-10-16', 'lignes' => [['facture_ligne_id' => $ligne, 'quantite' => 1]]])
            ->assertOk()->assertJsonPath('montant_ttc', '120.00');
        $this->postJson("/api/avoirs/{$premier}/emettre")->assertOk();
        $this->postJson("/api/avoirs/{$second}/emettre")->assertUnprocessable()->assertJsonValidationErrors('lignes.0.quantite');
        $this->postJson("/api/avoirs/{$premier}/emettre")->assertUnprocessable()->assertJsonPath('message', 'Cette note de crédit est déjà émise.');

        $this->putJson("/api/avoirs/{$premier}", ['date_avoir' => '2026-10-16', 'lignes' => [['facture_ligne_id' => $ligne, 'quantite' => 1]]])
            ->assertUnprocessable()->assertJsonPath('message', 'Une note de crédit émise ne se modifie plus.');
        $this->deleteJson("/api/avoirs/{$premier}")->assertUnprocessable();
        $this->deleteJson("/api/avoirs/{$second}")->assertOk();
        $this->getJson("/api/avoirs/{$second}")->assertNotFound();
        // Entièrement imputée sur la facture : rien à rembourser.
        $this->postJson("/api/avoirs/{$premier}/rembourser", ['montant' => 1, 'date_remboursement' => '2026-10-16', 'mode' => 'cheque'])->assertUnprocessable();
        $this->assertEquals(120, $this->getJson("/api/factures/{$facture}")->assertJsonPath('statut', 'emise')->json('reste_a_payer'));
    }

    public function test_taxes_au_prorata_pdf_courriel_et_recherche(): void
    {
        // Groupe par défaut TVA 17,5 % + CAC 10 % de la TVA.
        $tva = $this->postJson('/api/taxes', ['nom' => 'TVA', 'taux' => 17.5])->json('id');
        $cac = $this->postJson('/api/taxes', ['nom' => 'CAC', 'taux' => 10])->json('id');
        $this->postJson('/api/groupes-taxes', ['code' => 'TVA_CAC', 'nom' => 'TVA + CAC', 'par_defaut' => true, 'lignes' => [
            ['tenant_taxe_id' => $tva, 'applique_sur' => 'base'],
            ['tenant_taxe_id' => $cac, 'applique_sur' => 'taxe_precedente'],
        ]])->assertCreated();
        [$facture, $ligne] = $this->factureEmise([['quantite' => 2, 'prix_unitaire_ht' => 100]]);

        $avoir = $this->noter($facture, [['facture_ligne_id' => $ligne, 'quantite' => 1]])->assertCreated()->json('id');
        $detail = $this->getJson("/api/avoirs/{$avoir}")->assertOk()->json();
        $this->assertEquals([100, 19.25, 119.25], $this->montants($detail, ['montant_ht', 'montant_tva', 'montant_ttc']));
        $this->assertEquals([['TVA', 17.5, 100, 17.5], ['CAC', 10, 17.5, 1.75]],
            array_map(fn ($t) => [$t['nom'], $t['taux'], $t['base'], $t['montant']], $detail['recapitulatif_taxes']));

        // Le PDF d'un brouillon existe déjà ; celui de la note émise porte son numéro.
        $this->assertStringStartsWith('application/pdf', $this->get("/api/avoirs/{$avoir}/pdf")->assertOk()->headers->get('content-type'));
        $this->postJson("/api/avoirs/{$avoir}/emettre")->assertOk();
        $pdf = $this->get("/api/avoirs/{$avoir}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('note-credit-AV-00001.pdf', (string) $pdf->headers->get('content-disposition'));

        $courriel = $this->getJson("/api/courriels/preparer?type=avoir&document_id={$avoir}")->assertOk()->json();
        $this->assertSame(['achats@client.test'], $courriel['destinataires']);
        $this->assertStringStartsWith('Note de crédit AV-00001', $courriel['objet']);
        $this->assertStringContainsString('établie sur la facture FAC-00001', $courriel['contenu']);
        $this->assertSame('note-credit-AV-00001.pdf', $courriel['piece_jointe']);

        $groupes = $this->getJson('/api/recherche?q=AV-00001&types=avoirs')->assertOk()->json('groupes');
        $this->assertSame(['avoirs', 'AV-00001', '/notes-credit?recherche=AV-00001'], [$groupes[0]['type'], $groupes[0]['resultats'][0]['titre'], $groupes[0]['resultats'][0]['lien']]);
    }

    public function test_droits_des_notes_de_credit(): void
    {
        [$facture, $ligne] = $this->factureEmise([['quantite' => 2, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]]);
        $avoir = $this->noter($facture, [['facture_ligne_id' => $ligne, 'quantite' => 1]])->assertCreated()->json('id');

        // Rôles système : les droits des factures (« user » consulte seulement).
        $this->agir('user');
        $this->getJson('/api/avoirs')->assertOk();
        $this->noter($facture, [['facture_ligne_id' => $ligne, 'quantite' => 1]])->assertForbidden();
        $this->postJson("/api/avoirs/{$avoir}/emettre")->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Émettre une note de crédit » (Notes de crédit).');
        $this->agir('manager');
        $this->postJson("/api/avoirs/{$avoir}/emettre")->assertOk();

        // Catalogue : module « Notes de crédit » des ventes, avec ses deux actions nommées.
        $this->agir('admin');
        $modules = collect($this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets'))->firstWhere('cle', 'ventes')['modules'];
        $this->assertSame(['avoirs.voir', 'avoirs.creer', 'avoirs.modifier', 'avoirs.supprimer', 'avoirs.emettre', 'avoirs.regler'],
            array_column(collect($modules)->firstWhere('cle', 'avoirs')['permissions'], 'code'));

        // Un groupe qui crée et règle les notes sans pouvoir les émettre.
        $groupe = $this->postJson('/api/roles-custom', ['nom' => 'Service client',
            'permissions' => ['avoirs' => ['can_view' => true, 'can_create' => true, 'can_edit' => true]],
            'actions' => ['avoirs.emettre' => false, 'avoirs.regler' => true]])->assertCreated()->json('role_id');
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [$groupe], 'exceptions' => []])->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte->fresh(), ['*']);
        $brouillon = $this->noter($facture, [['facture_ligne_id' => $ligne, 'quantite' => 1]])->assertCreated()->json('id');
        $this->postJson("/api/avoirs/{$brouillon}/emettre")->assertForbidden();
        $this->assertNotSame(403, $this->postJson("/api/avoirs/{$avoir}/rembourser", ['montant' => 1, 'date_remboursement' => '2026-10-16', 'mode' => 'especes'])->status());
        $moi = $this->getJson('/api/permissions/me')->assertOk()->json('actions');
        $this->assertSame([false, true], [$moi['avoirs.emettre'], $moi['avoirs.regler']]);
    }
}
