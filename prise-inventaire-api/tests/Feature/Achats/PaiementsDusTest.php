<?php

namespace Tests\Feature\Achats;

use App\Models\AdminUser;
use App\Models\ConditionPaiement;
use App\Models\Fournisseur;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Paiements dus (phase 4.5) : soldes arrêtés à la date du rapport, escompte encore accordé à la date « payer le »,
 * filtres (échéance au, échus, escompte qui court, factures soldées), regroupement par fournisseur et devise, CSV ;
 * paiement d'une sélection en un geste (un déboursé par fournisseur, tout ou rien) ; droits.
 */
class PaiementsDusTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private Fournisseur $ciment;

    private Fournisseur $acier;

    private int $deuxDix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['achats']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        app(TenantContext::class)->runAsTenant($this->tenant->id, function () {
            TenantParametres::create(['nom_entreprise' => 'Quincaillerie Test', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF', 'tva_taux' => 19.25]);
            $this->ciment = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimenterie du Sud', 'actif' => true, 'devise' => 'XAF']);
            $this->acier = Fournisseur::create(['code' => 'F2', 'raison_sociale' => 'Aciérie du Nord', 'actif' => true, 'devise' => 'XAF']);
            $this->deuxDix = ConditionPaiement::create([
                'code' => '2-10-30', 'libelle' => '2/10 Net 30', 'delai_jours' => 30, 'escompte_pourcentage' => 2, 'escompte_jours' => 10, 'actif' => true,
            ])->id;
        });
        $this->agir($this->admin);
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function facture(Fournisseur $fournisseur, float $montant, string $date, ?int $condition = null): array
    {
        return $this->postJson('/api/factures-fournisseurs', array_filter([
            'fournisseur_id' => $fournisseur->id, 'date_facture' => $date, 'condition_paiement_id' => $condition,
            'lignes' => [['description' => 'Marchandise', 'quantite' => 1, 'prix_unitaire' => $montant, 'taux_tva' => 0]],
        ], fn ($v) => $v !== null))->assertCreated()->json();
    }

    private function rapport(string $parametres = ''): array
    {
        return $this->getJson('/api/debourses/paiements-dus'.($parametres ? "?{$parametres}" : ''))->assertOk()->json();
    }

    /** Lignes du rapport : [numéro, jours, solde, escompte, net]. */
    private function lignes(array $rapport): array
    {
        return collect($rapport['groupes'])->flatMap(fn ($g) => $g['factures'])
            ->map(fn ($f) => [$f['numero'], $f['jours'], $f['solde'], $f['escompte'], $f['net']])->all();
    }

    public function test_rapport_des_paiements_dus_et_paiement_d_une_selection(): void
    {
        // A : sans condition, due à sa date (01/09), payée 400 le 15/09 ; B : 2/10 Net 30, échéance le 09/11 ; C : due le 05/10.
        $a = $this->facture($this->ciment, 1000, '2026-09-01');
        $this->postJson("/api/factures-fournisseurs/{$a['id']}/paiements", ['date_paiement' => '2026-09-15', 'montant' => 400, 'mode' => 'cheque'])->assertOk();
        $b = $this->facture($this->ciment, 500, '2026-10-10', $this->deuxDix);
        $c = $this->facture($this->acier, 300, '2026-10-05');
        // Un paiement daté après la date du rapport ne compte pas.
        $this->postJson("/api/factures-fournisseurs/{$c['id']}/paiements", ['date_paiement' => '2026-10-17', 'montant' => 100, 'mode' => 'virement'])->assertOk();

        // Par défaut : ce qui est déjà dû aujourd'hui, par fournisseur.
        $dus = $this->rapport();
        $this->assertSame(['Aciérie du Nord', 'Cimenterie du Sud'], array_column($dus['groupes'], 'fournisseur'));
        $this->assertEquals([[$c['numero'], 11, 300, 0, 300], [$a['numero'], 45, 600, 0, 600]], $this->lignes($dus));
        $this->assertEquals([2, 900], [$dus['totaux_par_devise'][0]['nombre'], $dus['totaux_par_devise'][0]['net']]);

        // Jusqu'au 30/11, payée le 16/10 : B entre, avec ses 2 % d'escompte jusqu'au 20/10.
        $avecB = $this->rapport('echeance_au=2026-11-30');
        $this->assertEquals([[$c['numero'], 11, 300, 0, 300], [$a['numero'], 45, 600, 0, 600], [$b['numero'], -24, 500, 10, 490]], $this->lignes($avecB));
        $this->assertSame('2026-10-20', $avecB['groupes'][1]['factures'][1]['limite_escompte']);
        $this->assertEquals([1100, 10, 1090], [$avecB['groupes'][1]['totaux']['solde'], $avecB['groupes'][1]['totaux']['escompte'], $avecB['groupes'][1]['totaux']['net']]);
        $this->assertEquals(['solde' => 1400, 'escompte' => 10, 'net' => 1390], $avecB['total_entreprise']);
        // Payée le 21/10, l'escompte est perdu ; seulement ce qui rapporte, seulement ce qui est échu.
        $this->assertEquals([0, 500], array_slice($this->lignes($this->rapport('echeance_au=2026-11-30&payer_le=2026-10-21'))[2], 3, 2));
        $this->assertSame([$b['numero']], array_column($this->lignes($this->rapport('echeance_au=2026-11-30&escompte_seulement=1')), 0));
        $this->assertSame([$c['numero'], $a['numero']], array_column($this->lignes($this->rapport('echeance_au=2026-11-30&echus=1')), 0));
        // Soldes arrêtés à une date passée : la facture B n'existait pas encore le 05/10.
        $this->assertSame([$c['numero'], $a['numero']], array_column($this->lignes($this->rapport('date=2026-10-05&echeance_au=2026-11-30')), 0));
        $this->assertSame([$a['numero']], array_column($this->lignes($this->rapport("fournisseur_id={$this->ciment->id}")), 0));
        $csv = $this->get('/api/debourses/paiements-dus?echeance_au=2026-11-30&format=csv')->assertOk()->getContent();
        $this->assertStringContainsString('Sous-total Cimenterie du Sud', $csv);
        $this->assertStringContainsString('net_a_payer', $csv);

        // Payer la sélection : un déboursé par fournisseur, escompte compris.
        $this->postJson('/api/debourses/lot', ['factures' => [], 'date_debourse' => '2026-10-16', 'mode' => 'virement'])->assertUnprocessable()->assertJsonValidationErrors('factures');
        $lot = $this->postJson('/api/debourses/lot', ['factures' => [$a['id'], $b['id'], $c['id']], 'date_debourse' => '2026-10-16', 'mode' => 'virement', 'reference' => 'VIR-LOT'])
            ->assertCreated()->assertJsonPath('message', '2 déboursé(s) enregistré(s) : DEB-00001, DEB-00002.')->json('debourses');
        $this->assertEquals([[$a['numero'], $b['numero']], 1090, 10], [array_column(array_column($lot[0]['lignes'], 'facture'), 'numero'), $lot[0]['montant'], $lot[0]['escompte']]);
        // C : son reste (300 moins le paiement du 17/10).
        $this->assertEquals([[$c['numero']], 200, 'VIR-LOT'], [array_column(array_column($lot[1]['lignes'], 'facture'), 'numero'), $lot[1]['montant'], $lot[1]['reference']]);
        $this->assertSame([], $this->rapport('echeance_au=2026-11-30&date=2026-10-17')['groupes']);
        $this->assertCount(3, $this->lignes($this->rapport('echeance_au=2026-11-30&date=2026-10-17&inclure_soldees=1')));

        // Tout ou rien : une facture déjà payée dans la sélection, aucun déboursé n'est créé.
        $d = $this->facture($this->acier, 250, '2026-10-12');
        $this->postJson('/api/debourses/lot', ['factures' => [$d['id'], $a['id']], 'date_debourse' => '2026-10-16', 'mode' => 'cheque'])->assertUnprocessable()
            ->assertJsonValidationErrors(['factures' => "La facture {$a['numero']} ne reste pas à payer."]);
        $this->assertSame(2, $this->getJson('/api/debourses')->json('total'));

        // Consulter le rapport demande de voir les déboursés ; payer, de les saisir.
        $this->agir(AdminUser::factory()->for($this->tenant)->create(['role' => 'user']));
        $this->getJson('/api/debourses/paiements-dus')->assertOk();
        $this->postJson('/api/debourses/lot', ['factures' => [$d['id']], 'date_debourse' => '2026-10-16', 'mode' => 'cheque'])->assertForbidden();
    }
}
