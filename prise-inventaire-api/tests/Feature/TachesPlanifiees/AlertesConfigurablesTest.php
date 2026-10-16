<?php

namespace Tests\Feature\TachesPlanifiees;

use App\Models\AdminUser;
use App\Models\BonLivraison;
use App\Models\Camion;
use App\Models\Client;
use App\Models\ComFourEntete;
use App\Models\Facture;
use App\Models\Fournisseur;
use App\Models\Notification;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Alertes réglables (écran Alertes) : tâches planifiées avec un délai en jours, qui notifient les factures
 * impayées, les commandes fournisseur et les livraisons en retard, et les contrôles techniques à renouveler.
 * L'écran était factice (sauvegarde simulée, valeurs codées en dur).
 */
class AlertesConfigurablesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create();
        Sanctum::actingAs($this->admin, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function tache(string $code): array
    {
        return collect($this->getJson('/api/taches-planifiees')->assertOk()->json('taches'))->firstWhere('code', $code);
    }

    private function executer(string $code): string
    {
        return $this->postJson('/api/taches-planifiees/'.$this->tache($code)['id'].'/executer')->assertOk()->json('execution.message');
    }

    private function notifications(string $type): \Illuminate\Support\Collection
    {
        return $this->chez(fn () => Notification::where('type', $type)->get());
    }

    public function test_les_alertes_exposent_et_reglent_leur_delai(): void
    {
        $tache = $this->tache('factures_impayees');
        $this->assertSame(['jours' => 30], $tache['parametres']);
        $this->assertSame('ventes', $tache['module']);

        $reglage = ['actif' => true, 'recurrence' => 'quotidienne', 'heure' => '07:00'];
        $this->putJson("/api/taches-planifiees/{$tache['id']}", $reglage + ['parametres' => ['jours' => 10, 'inconnu' => 5]])
            ->assertOk()->assertJsonPath('parametres', ['jours' => 10]);
        $this->putJson("/api/taches-planifiees/{$tache['id']}", $reglage + ['parametres' => ['jours' => -1]])
            ->assertUnprocessable()->assertJsonValidationErrors('parametres.jours');
    }

    public function test_les_factures_impayees_au_dela_du_delai_sont_signalees_une_fois(): void
    {
        $this->chez(function () {
            $clientId = Client::create(['code' => 'C-1', 'raison_sociale' => 'Quincaillerie du Port', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id;
            foreach ([['F-1', '2026-09-01', 'emise', 50], ['F-2', '2026-10-10', 'emise', 20], ['F-3', '2026-08-01', 'payee', 0]] as [$numero, $echeance, $statut, $reste]) {
                Facture::create(['numero' => $numero, 'client_id' => $clientId, 'date_facture' => '2026-08-01', 'date_echeance' => $echeance, 'statut' => $statut, 'montant_ttc' => 100, 'reste_a_payer' => $reste]);
            }
        });

        $this->assertSame('1 facture(s) impayée(s) signalée(s).', $this->executer('factures_impayees'));
        $this->assertSame('Aucune nouvelle facture impayée.', $this->executer('factures_impayees'));
        $this->assertStringContainsString('F-1 (Quincaillerie du Port)', $this->notifications('facture_impayee')->first()->message);

        // Délai à 0 : toute échéance dépassée.
        $tache = $this->tache('factures_impayees');
        $this->putJson("/api/taches-planifiees/{$tache['id']}", ['actif' => false, 'recurrence' => 'quotidienne', 'heure' => '07:00', 'parametres' => ['jours' => 0]])->assertOk();
        $this->assertSame('1 facture(s) impayée(s) signalée(s).', $this->executer('factures_impayees'));
    }

    public function test_commandes_livraisons_et_controles_techniques_sont_signales(): void
    {
        $this->chez(function () {
            $fournisseurId = Fournisseur::create(['code' => 'F-1', 'raison_sociale' => 'Brasserie du Nord'])->id;
            ComFourEntete::create(['numero' => 'BC-1', 'fournisseur_id' => $fournisseurId, 'date_commande' => '2026-10-01', 'date_livraison_prevue' => '2026-10-10', 'statut' => 'envoyee', 'created_by' => $this->admin->id]);
            ComFourEntete::create(['numero' => 'BC-2', 'fournisseur_id' => $fournisseurId, 'date_commande' => '2026-10-01', 'date_livraison_prevue' => '2026-10-20', 'statut' => 'envoyee', 'created_by' => $this->admin->id]);

            $clientId = Client::create(['code' => 'C-1', 'raison_sociale' => 'Client', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id;
            $factureId = Facture::create(['numero' => 'F-1', 'client_id' => $clientId, 'date_facture' => '2026-10-01', 'statut' => 'emise'])->id;
            $ancien = BonLivraison::create(['numero' => 'BL-1', 'facture_id' => $factureId, 'statut' => 'pret']);
            $ancien->forceFill(['created_at' => now()->subDays(5)])->save();
            BonLivraison::create(['numero' => 'BL-2', 'facture_id' => $factureId, 'statut' => 'cree']);

            Camion::create(['immatriculation' => 'LT-001-AA', 'date_controle_technique' => '2026-10-26', 'actif' => true]);
            Camion::create(['immatriculation' => 'LT-002-AA', 'date_controle_technique' => '2027-01-15', 'actif' => true]);
        });

        $this->assertSame('1 commande(s) fournisseur en retard signalée(s).', $this->executer('commandes_fournisseur_en_retard'));
        $this->assertSame('1 livraison(s) en retard signalée(s).', $this->executer('livraisons_en_retard'));
        $this->assertSame('1 contrôle(s) technique(s) signalé(s).', $this->executer('controles_techniques'));
        $this->assertSame('Aucun contrôle technique à signaler.', $this->executer('controles_techniques'));
        $this->assertSame('Aucune nouvelle livraison en retard.', $this->executer('livraisons_en_retard'));

        $this->assertStringContainsString('BC-1', $this->notifications('commande_retard')->first()->message);
        $this->assertStringContainsString('BL-1', $this->notifications('livraison_retard')->first()->message);
        $this->assertStringContainsString('LT-001-AA expire le 26/10/2026', $this->notifications('ct_expire')->first()->message);
    }
}
