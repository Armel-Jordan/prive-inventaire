<?php

namespace Tests\Feature\Achats;

use App\Models\AdminUser;
use App\Models\ConditionPaiement;
use App\Models\Configuration;
use App\Models\FactureFournisseurPaiement;
use App\Models\Fournisseur;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Déboursés aux fournisseurs (phase 4.5) : un paiement réparti sur plusieurs factures fournisseurs, escompte proposé
 * selon la condition de paiement (« 2/10 Net 30 »), réglé des factures, avis de paiement, correction, annulation
 * (factures de nouveau dues), contrôles de la répartition, droits et isolation.
 */
class DeboursesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private Fournisseur $fournisseur;

    private int $deuxDix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['achats']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Awa Comptable']);
        $this->chez(function () {
            TenantParametres::create(['nom_entreprise' => 'Quincaillerie Test', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF', 'tva_taux' => 19.25]);
            $this->fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimenterie du Sud', 'actif' => true, 'devise' => 'XAF']);
            $this->deuxDix = ConditionPaiement::create([
                'code' => '2-10-30', 'libelle' => '2/10 Net 30', 'delai_jours' => 30, 'escompte_pourcentage' => 2, 'escompte_jours' => 10, 'actif' => true,
            ])->id;
        });
        $this->agir($this->admin);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte, ?Tenant $tenant = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', ($tenant ?? $this->tenant)->slug);
    }

    /** Facture fournisseur d'un montant TTC (sans taxe), à une date, avec sa condition de paiement éventuelle. */
    private function facture(float $montant, string $date, ?string $numero = null, ?int $condition = null, ?Fournisseur $fournisseur = null): array
    {
        return $this->postJson('/api/factures-fournisseurs', array_filter([
            'fournisseur_id' => ($fournisseur ?? $this->fournisseur)->id, 'date_facture' => $date, 'numero_fournisseur' => $numero,
            'condition_paiement_id' => $condition,
            'lignes' => [['description' => 'Ciment', 'quantite' => 1, 'prix_unitaire' => $montant, 'taux_tva' => 0]],
        ], fn ($v) => $v !== null))->assertCreated()->json();
    }

    private function debourser(array $lignes, array $autres = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/debourses', $autres + [
            'fournisseur_id' => $this->fournisseur->id, 'date_debourse' => '2026-10-16', 'mode' => 'cheque', 'reference' => 'CHQ-1001',
            'lignes' => array_map(fn ($id, $l) => ['facture_fournisseur_id' => $id, 'montant' => $l[0], 'escompte' => $l[1] ?? 0], array_keys($lignes), $lignes),
        ]);
    }

    private function etat(int $facture): array
    {
        $f = $this->getJson("/api/factures-fournisseurs/{$facture}")->assertOk()->json();

        return [$f['etat'], (float) $f['montant_paye'], (float) $f['reste_a_payer']];
    }

    public function test_debourse_reparti_avec_escompte_avis_et_annulation(): void
    {
        $f1 = $this->facture(1000, '2026-10-01', 'FX-1');
        $f2 = $this->facture(500, '2026-10-10', 'FX-2', $this->deuxDix);
        $f3 = $this->facture(2000, '2026-10-12', 'FX-3');

        // À payer : l'échéance la plus ancienne d'abord ; l'escompte de 2 % court jusqu'au 20/10 sur la deuxième.
        $du = $this->getJson("/api/debourses/a-payer?fournisseur_id={$this->fournisseur->id}&date=2026-10-16")->assertOk()->json();
        $this->assertSame([$f1['numero'], $f3['numero'], $f2['numero']], array_column($du['factures'], 'numero'));
        $this->assertEquals([3500, 15], [$du['total_du'], $du['factures'][0]['jours_retard']]);
        $this->assertNull($du['factures'][0]['escompte']);
        $this->assertEquals([true, 10, '2026-10-20', 2], [$du['factures'][2]['escompte']['applicable'], $du['factures'][2]['escompte']['escompte'],
            $du['factures'][2]['escompte']['date_limite'], $du['factures'][2]['escompte']['pourcentage']]);
        $this->assertFalse($this->getJson("/api/debourses/a-payer?fournisseur_id={$this->fournisseur->id}&date=2026-10-21")->json('factures.2.escompte.applicable'));

        // Un chèque : la première en entier, la deuxième avec son escompte (490 + 10), 600 sur la troisième.
        $debourse = $this->debourser([$f1['id'] => [1000], $f2['id'] => [490, 10], $f3['id'] => [600]])->assertCreated()->json();
        $this->assertSame(['DEB-00001', 'valide', 'Cimenterie du Sud'], [$debourse['numero'], $debourse['statut'], $debourse['fournisseur']['raison_sociale']]);
        $this->assertEquals([2090, 2100, 10], [$debourse['montant'], $debourse['montant_applique'], $debourse['escompte']]);
        $this->assertEquals(['payee', 1000, 0], $this->etat($f1['id']));
        $this->assertEquals(['payee', 500, 0], $this->etat($f2['id']));
        $this->assertEquals(['en_retard', 600, 1400], $this->etat($f3['id']));
        $paiement = $this->getJson("/api/factures-fournisseurs/{$f2['id']}")->json('paiements.0');
        $this->assertEquals([490, 10, 'CHQ-1001', 'Déboursé DEB-00001', 'DEB-00001'], [$paiement['montant'], $paiement['escompte'], $paiement['reference'], $paiement['notes'], $paiement['debourse']['numero']]);
        // Une part de déboursé ne s'annule pas seule.
        $this->deleteJson("/api/factures-fournisseurs/{$f2['id']}/paiements/{$paiement['id']}")->assertUnprocessable()
            ->assertJsonPath('message', 'Ce paiement fait partie du déboursé DEB-00001 : annulez le déboursé.');
        $this->assertSame([$f3['numero']], array_column($this->getJson("/api/debourses/a-payer?fournisseur_id={$this->fournisseur->id}")->json('factures'), 'numero'));

        // Référence corrigée : les paiements la suivent.
        $this->putJson("/api/debourses/{$debourse['id']}", ['reference' => 'CHQ-1002', 'notes' => 'Chèque remplacé'])->assertOk()->assertJsonPath('reference', 'CHQ-1002');
        $this->assertSame(['CHQ-1002'], $this->chez(fn () => FactureFournisseurPaiement::where('debourse_id', $debourse['id'])->distinct()->pluck('reference')->all()));

        // Liste, recherche par numéro du fournisseur, totaux, avis de paiement.
        $this->assertSame(['DEB-00001'], array_column($this->getJson('/api/debourses?recherche=FX-2')->assertOk()->json('data'), 'numero'));
        $this->assertEquals([1, 2090, 10], array_values($this->getJson('/api/debourses')->json('resume')));
        $this->assertStringStartsWith('%PDF', $this->get("/api/debourses/{$debourse['id']}/pdf")->assertOk()->getContent());

        // Annulé : paiements et escompte retirés, factures de nouveau dues.
        $annule = $this->postJson("/api/debourses/{$debourse['id']}/annuler", ['motif' => 'Chèque perdu'])->assertOk()->json();
        $this->assertSame(['annule', 'Chèque perdu'], [$annule['statut'], $annule['motif_annulation']]);
        $this->assertEquals(['en_retard', 0, 1000], $this->etat($f1['id']));
        $this->assertEquals(['impayee', 0, 500], $this->etat($f2['id']));
        $this->assertEquals(['en_retard', 0, 2000], $this->etat($f3['id']));
        $this->postJson("/api/debourses/{$debourse['id']}/annuler")->assertUnprocessable()->assertJsonPath('message', 'Le déboursé DEB-00001 est déjà annulé.');
        $this->putJson("/api/debourses/{$debourse['id']}", ['notes' => 'x'])->assertUnprocessable();
        $this->assertEquals([0, 0, 0], array_values($this->getJson('/api/debourses')->json('resume')));

        // L'escompte se reprend tant qu'il court ; une fois pris, il ne l'est plus.
        $this->debourser([$f2['id'] => [245, 10]], ['date_debourse' => '2026-10-17'])->assertCreated();
        $this->assertFalse($this->getJson("/api/debourses/a-payer?fournisseur_id={$this->fournisseur->id}&date=2026-10-17")
            ->json('factures.2.escompte.applicable'));
        // Le paiement simple d'une facture fournisseur reste possible et s'annule seul.
        $simple = $this->postJson("/api/factures-fournisseurs/{$f1['id']}/paiements", ['date_paiement' => '2026-10-16', 'montant' => 100, 'mode' => 'virement'])
            ->assertOk()->json('paiements.0.id');
        $this->deleteJson("/api/factures-fournisseurs/{$f1['id']}/paiements/{$simple}")->assertOk();
        $this->assertEquals(['en_retard', 0, 1000], $this->etat($f1['id']));
    }

    public function test_controles_de_la_repartition(): void
    {
        $facture = $this->facture(1000, '2026-10-12', 'FX-1', $this->deuxDix);
        $autre = $this->chez(fn () => Fournisseur::create(['code' => 'F2', 'raison_sociale' => 'Aciérie', 'actif' => true, 'devise' => 'XAF']));
        $etrangere = $this->facture(300, '2026-10-12', 'AC-1', null, $autre);

        $this->debourser([$facture['id'] => [990, 20]])->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes' => "Le paiement et l'escompte dépassent le reste à payer de la facture {$facture['numero']} (1 000,00)."]);
        $this->debourser([$etrangere['id'] => [300]])->assertUnprocessable()->assertJsonValidationErrors(['lignes' => 'Choisissez des factures du fournisseur.']);
        $this->postJson('/api/debourses', [
            'fournisseur_id' => $this->fournisseur->id, 'date_debourse' => '2026-10-16', 'mode' => 'virement',
            'lignes' => [['facture_fournisseur_id' => $facture['id'], 'montant' => 100], ['facture_fournisseur_id' => $facture['id'], 'montant' => 100]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lignes');
        $this->debourser([$facture['id'] => [0, 20]])->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes' => "Le déboursé doit verser de l'argent : un escompte seul ne fait pas un paiement."]);
        $this->debourser([$facture['id'] => [0]])->assertUnprocessable()->assertJsonValidationErrors(['lignes' => 'Indiquez ce qui est payé sur au moins une facture.']);
        $this->debourser([$facture['id'] => [-5]])->assertUnprocessable()->assertJsonValidationErrors('lignes.0.montant');
        $this->debourser([$facture['id'] => [100]], ['date_debourse' => '2026-10-11'])->assertUnprocessable()->assertJsonValidationErrors('date_debourse');
        $this->debourser([$facture['id'] => [100]], ['date_debourse' => '2026-10-25'])->assertUnprocessable()->assertJsonValidationErrors('date_debourse');
        $this->debourser([$facture['id'] => [100]], ['devise' => 'EUR'])->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes' => "La facture {$facture['numero']} n'est pas dans la devise du déboursé."]);
        $this->postJson("/api/factures-fournisseurs/{$facture['id']}/annuler")->assertOk();
        $this->debourser([$facture['id'] => [100]])->assertUnprocessable()->assertJsonValidationErrors(['lignes' => "La facture {$facture['numero']} est annulée."]);
        $this->assertSame(0, $this->getJson('/api/debourses')->json('total'));

        // Sans numérotation automatique, le déboursé est refusé (aucun paiement n'est créé).
        $nouvelle = $this->facture(400, '2026-10-12', 'FX-2');
        $this->chez(fn () => Configuration::pourEntite('debourse', $this->tenant->id)->update(['auto_increment' => false]));
        $this->debourser([$nouvelle['id'] => [400]])->assertUnprocessable()
            ->assertJsonPath('message', 'Numéro requis : activez la numérotation automatique des déboursés (Configuration → Numérotation).');
        $this->assertEquals(['en_retard', 0, 400], $this->etat($nouvelle['id']));
    }

    public function test_droits_et_isolation(): void
    {
        $facture = $this->facture(1000, '2026-10-12', 'FX-1');
        $debourse = $this->debourser([$facture['id'] => [100]])->assertCreated()->json();

        // Rôles système : comme le paiement des factures fournisseurs (« user » consulte seulement).
        $this->agir(AdminUser::factory()->for($this->tenant)->create(['role' => 'user']));
        $this->getJson('/api/debourses')->assertOk();
        $this->debourser([$facture['id'] => [10]])->assertForbidden();

        // Un acheteur sans le droit d'annuler : il paie, mais n'annule pas.
        $this->agir($this->admin);
        $acheteur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$acheteur->id}", ['groupes' => [], 'exceptions' => ['debourses.annuler' => false]])->assertOk();
        $this->agir($acheteur);
        $this->debourser([$facture['id'] => [10]])->assertCreated();
        $this->postJson("/api/debourses/{$debourse['id']}/annuler")->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Annuler un déboursé » (Déboursés).');

        // Catalogue : le module et ses actions.
        $this->agir($this->admin);
        $modules = collect(collect($this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets'))->firstWhere('cle', 'achats')['modules'])->keyBy('cle');
        $this->assertSame(['debourses.voir', 'debourses.creer', 'debourses.modifier', 'debourses.annuler', 'debourses.cheques', 'debourses.virements'], array_column($modules['debourses']['permissions'], 'code'));

        // Une autre entreprise ne voit ni n'annule ces déboursés.
        $autre = Tenant::factory()->create(['modules' => ['achats']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->assertSame(0, $this->getJson('/api/debourses')->assertOk()->json('total'));
        $this->getJson("/api/debourses/{$debourse['id']}")->assertNotFound();
        $this->postJson("/api/debourses/{$debourse['id']}/annuler")->assertNotFound();
        $this->getJson("/api/debourses/a-payer?fournisseur_id={$this->fournisseur->id}")->assertUnprocessable()->assertJsonValidationErrors('fournisseur_id');
    }
}
