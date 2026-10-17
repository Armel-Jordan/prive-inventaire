<?php

namespace Tests\Feature\Achats;

use App\Models\AdminUser;
use App\Models\CompteBancaire;
use App\Models\Fournisseur;
use App\Models\FournisseurCompteBancaire;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Services\Virements;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Virements aux fournisseurs (phase 4.5) : déboursés à transmettre et leurs alertes (sans compte, IBAN invalide,
 * coordonnées récemment modifiées), lot SEPA (pain.001) refusé tant qu'il manque quoi que ce soit, fichier gardé,
 * numéro de lot du compte, annulation bloquée d'un virement transmis, refus de la banque, nouveau lot CSV au numéro
 * choisi, numéro déjà pris ; clé des IBAN ; droits et isolation.
 */
class VirementsTest extends TestCase
{
    use RefreshDatabase;

    private const IBAN_CM = 'CM2110002000300277976315008';

    private const IBAN_FR = 'FR1420041010050500013M02606';

    private Tenant $tenant;

    private AdminUser $admin;

    private Fournisseur $cimenterie;

    private Fournisseur $quincaillerie;

    private int $banque;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['achats', 'finance']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Awa Comptable']);
        $this->chez(function () {
            TenantParametres::create(['nom_entreprise' => 'Quincaillerie Test', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF', 'tva_taux' => 19.25]);
            $this->cimenterie = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimenterie du Sud', 'actif' => true, 'devise' => 'XAF']);
            $this->quincaillerie = Fournisseur::create(['code' => 'F2', 'raison_sociale' => 'Fers & Métaux', 'actif' => true, 'devise' => 'XAF']);
            $compte = FournisseurCompteBancaire::create([
                'fournisseur_id' => $this->cimenterie->id, 'banque' => 'Banque Atlantique', 'titulaire' => 'Cimenterie du Sud SA', 'iban' => self::IBAN_FR,
                'bic' => 'PSSTFRPPXXX', 'principal' => true, 'actif' => true,
            ]);
            // Coordonnées anciennes : pas d'alerte.
            FournisseurCompteBancaire::query()->whereKey($compte->id)->update(['updated_at' => now()->subDays(90), 'created_at' => now()->subDays(90)]);
            $this->banque = CompteBancaire::create([
                'nom' => 'Compte courant', 'banque' => 'Afriland First Bank', 'iban' => self::IBAN_CM, 'bic' => 'CCEICMCXXXX', 'par_defaut' => true, 'actif' => true,
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

    /** Une facture et son déboursé par virement (ou un autre mode) sur le compte courant. */
    private function payer(Fournisseur $fournisseur, string $facture, float $montant, string $mode = 'virement'): array
    {
        $id = $this->postJson('/api/factures-fournisseurs', [
            'fournisseur_id' => $fournisseur->id, 'date_facture' => '2026-10-10', 'numero_fournisseur' => $facture,
            'lignes' => [['description' => 'Ciment', 'quantite' => 1, 'prix_unitaire' => $montant, 'taux_tva' => 0]],
        ])->assertCreated()->json('id');

        return $this->postJson('/api/debourses', [
            'fournisseur_id' => $fournisseur->id, 'date_debourse' => '2026-10-16', 'mode' => $mode, 'compte_bancaire_id' => $this->banque,
            'lignes' => [['facture_fournisseur_id' => $id, 'montant' => $montant, 'escompte' => 0]],
        ])->assertCreated()->json();
    }

    private function lot(array $debourses, array $autres = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/debourses/virements/lots', $autres + [
            'compte_bancaire_id' => $this->banque, 'debourses' => array_column($debourses, 'id'), 'format' => 'sepa', 'date_execution' => '2026-10-17',
        ]);
    }

    private function aTransmettre(): array
    {
        return collect($this->getJson('/api/debourses/virements')->assertOk()->json('debourses'))->keyBy('numero')->all();
    }

    public function test_lots_sepa_et_csv_refus_et_alertes(): void
    {
        $this->assertSame([true, false, true, false], [
            app(Virements::class)->ibanValide(self::IBAN_FR), app(Virements::class)->ibanValide('FR1420041010050500013M02607'),
            app(Virements::class)->ibanValide('cm21 1000 2000 3002 7797 6315 008'), app(Virements::class)->ibanValide('CM21'),
        ]);
        $d1 = $this->payer($this->cimenterie, 'FX-1', 1000000);
        $d2 = $this->payer($this->cimenterie, 'FX-2', 250000);
        $d3 = $this->payer($this->quincaillerie, 'QM-9', 75000);
        $this->payer($this->cimenterie, 'FX-3', 5000, 'cheque');

        // À transmettre : les virements seulement ; le fournisseur sans compte bancaire est signalé.
        $liste = $this->aTransmettre();
        $this->assertSame([$d1['numero'], $d2['numero'], $d3['numero']], array_keys($liste));
        $this->assertSame([[], ["Le fournisseur n'a pas de compte bancaire actif."]], [$liste[$d1['numero']]['alertes'], $liste[$d3['numero']]['alertes']]);
        $this->assertEquals([self::IBAN_FR, true, 'FX-1'], [$liste[$d1['numero']]['beneficiaire']['iban'], $liste[$d1['numero']]['beneficiaire']['iban_valide'], $liste[$d1['numero']]['factures']]);
        $courant = collect($this->getJson('/api/debourses/virements')->json('comptes'))->firstWhere('id', $this->banque);
        $this->assertEquals([1, null, true], [$courant['prochain_lot'], $courant['virement_format'], $courant['iban_valide']]);

        // Refusé tant qu'il manque quoi que ce soit : la liste des problèmes.
        $this->lot([$d1, $d3])->assertUnprocessable()->assertJsonPath('errors.debourses', [
            "Déboursé {$d3['numero']} : Fers & Métaux n'a pas de compte bancaire actif (fiche du fournisseur, Coordonnées bancaires).",
        ]);

        // Lot SEPA de la cimenterie : n° 1, le fichier pain.001 gardé ; le compte retient le format et l'émetteur.
        $lot = $this->lot([$d2, $d1], ['emetteur' => 'CM-EMET-42'])->assertCreated()->json();
        $this->assertEquals([1, 'sepa', 2, 1250000, 'virements-compte-courant-lot-1.xml', 'emis'], [$lot['numero'], $lot['format'], $lot['nombre'], $lot['total'], $lot['fichier'], $lot['statut']]);
        $this->assertArrayNotHasKey('contenu', $lot);
        $fichier = $this->get("/api/debourses/virements/lots/{$lot['id']}/fichier")->assertOk();
        $this->assertStringContainsString('application/xml', $fichier->headers->get('content-type'));
        $xml = simplexml_load_string($fichier->getContent());
        $xml->registerXPathNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.03');
        $texte = fn (string $chemin) => array_map('strval', $xml->xpath($chemin));
        $this->assertSame(['2'], $texte('//p:GrpHdr/p:NbOfTxs'));
        $this->assertSame(['1250000.00'], $texte('//p:GrpHdr/p:CtrlSum'));
        $this->assertSame(['CM-EMET-42'], $texte('//p:InitgPty/p:Id/p:OrgId/p:Othr/p:Id'));
        $this->assertSame(['2026-10-17'], $texte('//p:ReqdExctnDt'));
        $this->assertSame([self::IBAN_CM], $texte('//p:DbtrAcct/p:Id/p:IBAN'));
        $this->assertSame(['SHAR'], $texte('//p:ChrgBr'));
        $this->assertSame([], $texte('//p:SvcLvl/p:Cd'));
        $this->assertSame([$d1['numero'], $d2['numero']], $texte('//p:CdtTrfTxInf/p:PmtId/p:EndToEndId'));
        $this->assertSame(['1000000.00', '250000.00'], $texte('//p:CdtTrfTxInf/p:Amt/p:InstdAmt'));
        $this->assertSame([self::IBAN_FR, self::IBAN_FR], $texte('//p:CdtrAcct/p:Id/p:IBAN'));
        $this->assertSame(['Cimenterie du Sud SA', 'Cimenterie du Sud SA'], $texte('//p:Cdtr/p:Nm'));
        $this->assertSame(["Factures FX-1 - {$d1['numero']}", "Factures FX-2 - {$d2['numero']}"], $texte('//p:RmtInf/p:Ustrd'));
        $compte = $this->chez(fn () => CompteBancaire::find($this->banque));
        $this->assertSame([2, 'sepa', 'CM-EMET-42'], [$compte->prochain_lot, $compte->virement_format, $compte->virement_emetteur]);
        $this->assertSame([$d3['numero']], array_keys($this->aTransmettre()));
        $emis = $this->getJson('/api/debourses/virements?statut=emis')->assertOk()->json('debourses');
        $this->assertEquals([[1, self::IBAN_FR], [1, self::IBAN_FR]], array_map(fn ($d) => [$d['lot']['numero'], $d['beneficiaire']['iban']], $emis));

        // Un virement transmis ne s'annule pas tant que la banque ne l'a pas refusé.
        $this->postJson("/api/debourses/{$d1['id']}/annuler", ['motif' => 'Erreur'])->assertUnprocessable()
            ->assertJsonPath('message', "Le déboursé {$d1['numero']} est dans le lot de virements n° 1 transmis à la banque : marquez le lot refusé avant de l'annuler.");

        // Refusé par la banque : les déboursés redeviennent à transmettre ; un nouveau lot CSV au numéro choisi.
        $this->postJson("/api/debourses/virements/lots/{$lot['id']}/refuser", ['motif' => 'Fichier rejeté'])->assertOk()
            ->assertJsonPath('statut', 'refuse')->assertJsonPath('motif_refus', 'Fichier rejeté')->assertJsonPath('auteur_refus.nom', 'Awa Comptable');
        $this->postJson("/api/debourses/virements/lots/{$lot['id']}/refuser")->assertUnprocessable();
        $this->assertSame('refuse', $this->aTransmettre()[$d1['numero']]['virement_statut']);
        $csv = $this->lot([$d1, $d2], ['format' => 'csv', 'numero' => 7])->assertCreated()->assertJsonPath('numero', 7)->json();
        $contenu = $this->get("/api/debourses/virements/lots/{$csv['id']}/fichier")->assertOk()->getContent();
        $this->assertStringStartsWith("\"Date d'exécution\";Bénéficiaire;Banque;BIC;IBAN;\"Numéro de compte\";Montant;Devise;Référence;Libellé\n", $contenu);
        $this->assertStringContainsString('2026-10-17;"Cimenterie du Sud SA";"Banque Atlantique";PSSTFRPPXXX;'.self::IBAN_FR.";;1000000.00;XAF;{$d1['numero']};\"Factures FX-1 - {$d1['numero']}\"", $contenu);
        $this->assertSame(8, $this->chez(fn () => CompteBancaire::find($this->banque)->prochain_lot));

        // Le quincaillier reçoit un compte sans IBAN : pas de SEPA, le CSV passe ; ses coordonnées toutes neuves sont signalées.
        $this->chez(fn () => FournisseurCompteBancaire::create([
            'fournisseur_id' => $this->quincaillerie->id, 'banque' => 'SGC', 'titulaire' => 'Fers et Métaux SARL', 'numero_compte' => '10003-00100-05012345678-17',
            'principal' => true, 'actif' => true, 'modifie_par' => $this->admin->id,
        ]));
        $this->assertSame(['Coordonnées bancaires modifiées le 16/10/2026 par Awa Comptable : vérifiez-les auprès du fournisseur avant de payer.'],
            $this->aTransmettre()[$d3['numero']]['alertes']);
        $this->lot([$d3])->assertUnprocessable()->assertJsonPath('errors.debourses.0', "Déboursé {$d3['numero']} : l'IBAN de Fers & Métaux manque ou n'est pas valide.");
        $this->lot([$d3], ['format' => 'csv', 'numero' => 7])->assertUnprocessable()->assertJsonPath('message', 'Le lot n° 7 de ce compte existe déjà.');
        $this->lot([$d3], ['format' => 'csv'])->assertCreated()->assertJsonPath('numero', 8);
        $this->lot([$d3], ['format' => 'csv'])->assertUnprocessable();
        $this->assertSame([8, 7, 1], array_column($this->getJson('/api/debourses/virements/lots')->assertOk()->json(), 'numero'));
        $this->lot([$d1], ['date_execution' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors('date_execution');
    }

    public function test_droits_et_isolation(): void
    {
        $d1 = $this->payer($this->cimenterie, 'FX-1', 1000);
        $lot = $this->lot([$d1])->assertCreated()->json();

        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [], 'exceptions' => ['debourses.voir' => true, 'debourses.creer' => true, 'debourses.virements' => false]])
            ->assertOk();
        $this->agir($compte);
        $this->getJson('/api/debourses/virements?statut=emis')->assertOk()->assertJsonCount(1, 'debourses');
        $this->lot([$d1])->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Générer les fichiers de virement » (Déboursés).');
        $this->get("/api/debourses/virements/lots/{$lot['id']}/fichier", ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson("/api/debourses/virements/lots/{$lot['id']}/refuser")->assertForbidden();

        $autre = Tenant::factory()->create(['modules' => ['achats', 'finance']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->get("/api/debourses/virements/lots/{$lot['id']}/fichier", ['Accept' => 'application/json'])->assertNotFound();
        $this->postJson("/api/debourses/virements/lots/{$lot['id']}/refuser")->assertNotFound();
        $this->lot([$d1])->assertNotFound();
        $this->getJson('/api/debourses/virements?statut=emis')->assertOk()->assertJsonCount(0, 'debourses');
    }
}
