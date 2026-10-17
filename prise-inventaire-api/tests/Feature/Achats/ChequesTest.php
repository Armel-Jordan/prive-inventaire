<?php

namespace Tests\Feature\Achats;

use App\Models\AdminUser;
use App\Models\CompteBancaire;
use App\Models\ConditionPaiement;
use App\Models\Fournisseur;
use App\Models\FournisseurAdresse;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Services\Cheques;
use App\Support\EntreprisePdf;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Chèques des déboursés (phase 4.5) : numéro pris sur le chéquier du compte bancaire à la première impression (une
 * référence saisie est gardée, un numéro déjà porté est sauté), réimpression au même numéro, chèques à imprimer ;
 * contenu du chèque (montant en lettres en français ou en anglais, adresse principale, talon par facture, totaux) ;
 * refus (pas par chèque, annulé, sans compte, sans chéquier, pas numéroté) ; droits et isolation.
 */
class ChequesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private Fournisseur $fournisseur;

    private int $banque;

    private int $deuxDix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['achats', 'finance']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $this->chez(function () {
            TenantParametres::create(['nom_entreprise' => 'Quincaillerie Test', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF', 'tva_taux' => 19.25]);
            $this->fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimenterie du Sud', 'actif' => true, 'devise' => 'XAF']);
            FournisseurAdresse::create([
                'fournisseur_id' => $this->fournisseur->id, 'libelle' => 'Siège', 'adresse' => 'Zone industrielle de Bassa', 'ville' => 'Douala',
                'pays' => 'Cameroun', 'principale' => true, 'actif' => true,
            ]);
            $this->banque = CompteBancaire::create(['nom' => 'Compte courant', 'banque' => 'Afriland First Bank', 'numero_compte' => '0123456789', 'par_defaut' => true, 'actif' => true])->id;
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

    private function facture(float $montant, string $date, string $numero, ?int $condition = null): int
    {
        return $this->postJson('/api/factures-fournisseurs', array_filter([
            'fournisseur_id' => $this->fournisseur->id, 'date_facture' => $date, 'numero_fournisseur' => $numero, 'condition_paiement_id' => $condition,
            'lignes' => [['description' => 'Ciment', 'quantite' => 1, 'prix_unitaire' => $montant, 'taux_tva' => 0]],
        ], fn ($v) => $v !== null))->assertCreated()->json('id');
    }

    /** Déboursé : lignes [facture => [argent, escompte]], par chèque sur le compte courant sans référence, sauf réglages. */
    private function debourse(array $lignes, array $autres = []): array
    {
        return $this->postJson('/api/debourses', $autres + [
            'fournisseur_id' => $this->fournisseur->id, 'date_debourse' => '2026-10-16', 'mode' => 'cheque', 'compte_bancaire_id' => $this->banque,
            'lignes' => array_map(fn ($id, $l) => ['facture_fournisseur_id' => $id, 'montant' => $l[0], 'escompte' => $l[1] ?? 0], array_keys($lignes), $lignes),
        ])->assertCreated()->json();
    }

    private function numeroter(array $ids): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/debourses/cheques/numeroter', ['debourses' => $ids]);
    }

    public function test_numerotation_reimpression_et_contenu_du_cheque(): void
    {
        $fx1 = $this->facture(1000, '2026-10-01', 'FX-1');
        $fx2 = $this->facture(500, '2026-10-10', 'FX-2', $this->deuxDix);
        $fx3 = $this->facture(3000, '2026-10-12', 'FX-3');
        $d1 = $this->debourse([$fx1 => [1000], $fx2 => [490, 10]]);
        $d2 = $this->debourse([$fx3 => [500]], ['reference' => '0045']);
        $virement = $this->debourse([$fx3 => [100]], ['mode' => 'virement']);
        $this->assertSame(2, $this->getJson('/api/debourses?cheque=a_imprimer')->assertOk()->json('total'));

        // Sans chéquier réglé sur le compte : refusé ; une fois réglé, le chèque prend son numéro et le compteur avance.
        $this->numeroter([$d1['id']])->assertUnprocessable()
            ->assertJsonPath('message', 'Indiquez le prochain numéro de chèque du compte « Compte courant » (Finance → Banque → Comptes).');
        $this->putJson("/api/banque/comptes/{$this->banque}", ['prochain_cheque' => 1234567])->assertOk();
        $this->assertSame(1234567, collect($this->getJson('/api/banque/comptes')->json('comptes'))->firstWhere('id', $this->banque)['prochain_cheque']);
        $this->numeroter([$d2['id'], $d1['id']])->assertOk()->assertJsonPath('cheques', [
            ['id' => $d1['id'], 'numero' => $d1['numero'], 'cheque' => '1234567', 'nouveau' => true],
            ['id' => $d2['id'], 'numero' => $d2['numero'], 'cheque' => '0045', 'nouveau' => false],
        ]);
        // Un numéro déjà porté par un chèque du compte est sauté ; une réimpression garde le sien.
        $manuel = $this->debourse([$fx3 => [50]], ['reference' => '1234568']);
        $d3 = $this->debourse([$fx3 => [70]]);
        $this->numeroter([$d3['id']])->assertOk()->assertJsonPath('cheques.0.cheque', '1234569');
        $this->numeroter([$d1['id']])->assertOk()->assertJsonPath('cheques.0.cheque', '1234567')->assertJsonPath('cheques.0.nouveau', false);
        $this->assertSame(1234570, $this->chez(fn () => CompteBancaire::find($this->banque)->prochain_cheque));
        $this->assertSame([$manuel['id']], array_column($this->getJson('/api/debourses?cheque=a_imprimer')->json('data'), 'id'));
        $this->assertSame(3, $this->getJson('/api/debourses?cheque=imprimes')->json('total'));

        // Le chèque : montant en lettres, adresse principale, talon par facture (date, numéro du fournisseur, escompte, net).
        $cheque = $this->chez(fn () => app(Cheques::class)->cheques([$d1['id']], 'fr')->first());
        $this->assertSame(['1234567', 1490.0, 'MILLE QUATRE CENT QUATRE-VINGT-DIX FRANCS CFA', 'Cimenterie du Sud', ['Zone industrielle de Bassa', 'Douala', 'Cameroun'], 'FCFA', 0],
            [$cheque['numero'], $cheque['montant'], $cheque['lettres'], $cheque['beneficiaire'], $cheque['adresse'], $cheque['symbole'], $cheque['decimales']]);
        $this->assertEquals([['date' => '2026-10-01', 'numero' => 'FX-1', 'escompte' => 0, 'net' => 1000], ['date' => '2026-10-10', 'numero' => 'FX-2', 'escompte' => 10, 'net' => 490]],
            $cheque['factures']);
        $this->assertEquals([10, 1490], [$cheque['total_escompte'], $cheque['total_net']]);
        $this->assertSame('ONE THOUSAND FOUR HUNDRED NINETY CFA FRANCS', $this->chez(fn () => app(Cheques::class)->cheques([$d1['id']], 'en')->first()['lettres']));
        $html = $this->chez(fn () => view('pdf.cheque', ['cheques' => app(Cheques::class)->cheques([$d1['id']], 'fr'), 'langue' => 'fr'] + EntreprisePdf::entete())->render());
        $this->assertStringContainsString('****MILLE QUATRE CENT QUATRE-VINGT-DIX FRANCS CFA****', $html);
        $this->assertStringContainsString('**1 490 FCFA**', $html);
        $this->assertSame(2, substr_count($html, 'FX-2'));
        $pdf = $this->get("/api/debourses/cheques/pdf?debourses={$d1['id']},{$d2['id']}&langue=fr")->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));

        // Refus : pas numéroté, pas par chèque, annulé, sans compte bancaire.
        $this->get("/api/debourses/cheques/pdf?debourses={$manuel['id']}", ['Accept' => 'application/json'])->assertUnprocessable()
            ->assertJsonPath('message', "Numérotez d'abord le chèque du déboursé {$manuel['numero']}.");
        $this->numeroter([$virement['id']])->assertUnprocessable()->assertJsonPath('message', "Le déboursé {$virement['numero']} n'est pas payé par chèque.");
        $this->postJson("/api/debourses/{$d3['id']}/annuler", ['motif' => 'Erreur'])->assertOk();
        $this->numeroter([$d3['id']])->assertUnprocessable()->assertJsonPath('message', "Le déboursé {$d3['numero']} est annulé.");
        $sansCompte = $this->debourse([$fx3 => [20]], ['compte_bancaire_id' => null]);
        $this->numeroter([$sansCompte['id']])->assertUnprocessable()
            ->assertJsonPath('message', "Choisissez le compte bancaire du déboursé {$sansCompte['numero']} : le chèque est tiré sur son chéquier.");
    }

    public function test_droits_et_isolation(): void
    {
        $fx1 = $this->facture(1000, '2026-10-01', 'FX-1');
        $d1 = $this->debourse([$fx1 => [1000]]);
        $this->putJson("/api/banque/comptes/{$this->banque}", ['prochain_cheque' => 100])->assertOk();

        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [], 'exceptions' => [
            'debourses.voir' => true, 'debourses.creer' => true, 'debourses.cheques' => false, 'banque.voir' => true, 'banque.configurer' => false,
        ]])->assertOk();
        $this->agir($compte);
        $this->numeroter([$d1['id']])->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Imprimer les chèques » (Déboursés).');
        $this->putJson("/api/banque/comptes/{$this->banque}", ['prochain_cheque' => 500])->assertForbidden();

        $autre = Tenant::factory()->create(['modules' => ['achats', 'finance']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->numeroter([$d1['id']])->assertUnprocessable()->assertJsonPath('message', 'Déboursé introuvable.');
        $this->get("/api/debourses/cheques/pdf?debourses={$d1['id']}", ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(100, $this->chez(fn () => CompteBancaire::find($this->banque)->prochain_cheque));
    }
}
