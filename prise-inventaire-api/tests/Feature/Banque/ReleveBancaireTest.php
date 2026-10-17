<?php

namespace Tests\Feature\Banque;

use App\Models\AdminUser;
use App\Models\CompteComptable;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Relevés bancaires importés (phase 4.6) : CSV (débit et crédit de la banque, colonnes mémorisées, lignes illisibles,
 * opérations identiques, réimport sans doublon) et OFX ; meilleures correspondances (5 jours, montant au centime, sans
 * ambiguïté), candidats, relier, délier, exclure, rétablir, classer (transaction créée, passée au journal et reliée),
 * pointage du rapprochement depuis le relevé ; droits et isolation.
 */
class ReleveBancaireTest extends TestCase
{
    use RefreshDatabase;

    private const CSV = "Date;Libellé;Débit;Crédit;Référence\n"
        ."06/10/2026;VIR RECU INTERETS;;500,00;\n"
        ."11/10/2026;CHQ 1234;200,00;;1234\n"
        ."12/10/2026;CHQ 1235;200,00;;1235\n"
        ."15/10/2026;REMISE;;75,00;\n"
        ."16/10/2026;FRAIS TENUE COMPTE;12,50;;\n"
        ."16/10/2026;FRAIS TENUE COMPTE;12,50;;\n"
        ."Solde au 16/10;;;;\n";

    private Tenant $tenant;

    private AdminUser $admin;

    private int $banque;

    private int $octobre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-05 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['finance']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $this->chez(fn () => TenantParametres::create(['nom_entreprise' => 'Quincaillerie Test', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF', 'tva_taux' => 19.25]));
        $this->agir($this->admin);
        $this->postJson('/api/comptabilite/comptes/importer-plan', ['plan' => 'syscohada'])->assertOk();
        $exercice = $this->postJson('/api/comptabilite/exercices', ['date_debut' => '2026-01-01', 'date_fin' => '2026-12-31', 'decoupage' => 'mensuel'])->assertCreated()->json();
        $this->octobre = $exercice['periodes'][9]['id'];
        $this->chez(fn () => CompteComptable::create([
            'numero' => '5211', 'libelle' => 'Afriland', 'parent_id' => CompteComptable::where('numero', '52')->value('id'),
            'nature' => 'actif', 'type' => 'tresorerie', 'saisie_manuelle' => true, 'actif' => true,
        ]));
        $this->banque = $this->postJson('/api/referentiels/comptes_bancaires', ['nom' => 'Afriland courant', 'banque' => 'Afriland First Bank', 'par_defaut' => true, 'actif' => true])
            ->assertCreated()->json('id');
        $this->putJson("/api/banque/comptes/{$this->banque}", ['compte_comptable_id' => $this->compte('5211')])->assertOk();
        $reglages = $this->getJson('/api/comptabilite/comptabilisation/parametres')->assertOk()->json();
        $this->putJson('/api/comptabilite/comptabilisation/parametres', $reglages['propositions'] + ['date_debut' => '2026-01-01'])->assertOk();
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

    private function compte(string $numero): int
    {
        return $this->chez(fn () => CompteComptable::where('numero', $numero)->value('id'));
    }

    private function transaction(string $type, string $date, float $montant, string $contrepartie): void
    {
        $this->postJson('/api/banque/transactions', [
            'compte_bancaire_id' => $this->banque, 'date_transaction' => $date, 'type' => $type, 'libelle' => "{$type} {$montant}",
            'lignes' => [['compte_comptable_id' => $this->compte($contrepartie), 'montant' => $montant]],
        ])->assertCreated();
    }

    private function importer(string $contenu, array $reglages, bool $simulation = false, string $nom = 'releve.csv'): \Illuminate\Testing\TestResponse
    {
        return $this->post("/api/banque/comptes/{$this->banque}/releves", $reglages + [
            'fichier' => UploadedFile::fake()->createWithContent($nom, $contenu), 'simulation' => $simulation ? '1' : '0',
        ], ['Accept' => 'application/json']);
    }

    /** Opérations : libellé (et date) => statut. */
    private function statuts(): array
    {
        $lignes = $this->getJson("/api/banque/comptes/{$this->banque}/releve?du=2026-10-01&au=2026-10-31")->assertOk()->json('lignes');

        return collect($lignes)->mapWithKeys(fn ($l) => ["{$l['date_operation']} {$l['libelle']} #{$l['id']}" => $l['statut']])->sortKeys()->all();
    }

    private function operation(string $date, string $libelle): array
    {
        return collect($this->getJson("/api/banque/comptes/{$this->banque}/releve?du=2026-10-01&au=2026-10-31")->json('lignes'))
            ->first(fn ($l) => $l['date_operation'] === $date && $l['libelle'] === $libelle);
    }

    public function test_import_correspondances_liens_classement_et_rapprochement(): void
    {
        // Mouvements du compte, passés au journal : +500 (05/10), −200 (10/10), −200 (12/10), +75 (14/10).
        $this->transaction('depot', '2026-10-05', 500, '771');
        $this->transaction('retrait', '2026-10-10', 200, '631');
        $this->transaction('retrait', '2026-10-12', 200, '631');
        $this->transaction('depot', '2026-10-14', 75, '771');
        $this->postJson('/api/comptabilite/comptabilisation')->assertOk()->assertJsonPath('creees', 4);

        // Aperçu du CSV de la banque (débit et crédit vus par la banque) : la ligne de solde est écartée.
        $colonnes = ['colonne_date' => 1, 'colonne_libelle' => 2, 'colonne_debit' => 3, 'colonne_credit' => 4, 'colonne_reference' => 5, 'ligne_debut' => 2, 'format_date' => 'jma'];
        $apercu = $this->importer(self::CSV, $colonnes, true)->assertOk()->json();
        $this->assertEquals(['csv', 6, 6, 0, 150, null], [$apercu['format'], $apercu['lues'], $apercu['nouvelles'], $apercu['doublons'], $apercu['total'], $apercu['releve_id']]);
        $this->assertSame([['ligne' => 8, 'raison' => 'Date illisible.']], $apercu['ignorees']);
        $this->assertEquals(['2026-10-11', 'CHQ 1234', '1234', -200], array_values(array_intersect_key($apercu['apercu'][1], array_flip(['date', 'libelle', 'reference', 'montant']))));
        $this->assertSame(0, $this->getJson("/api/banque/comptes/{$this->banque}/releve")->assertOk()->json('resume.non_reliee'));

        // Import : les deux frais identiques passent ; un réimport ne double rien ; les colonnes sont mémorisées.
        $this->importer(self::CSV, $colonnes)->assertOk()->assertJsonPath('nouvelles', 6);
        $this->importer(self::CSV, $colonnes)->assertOk()->assertJsonPath('nouvelles', 0)->assertJsonPath('doublons', 6);
        $releve = $this->getJson("/api/banque/comptes/{$this->banque}/releve?du=2026-10-01&au=2026-10-31")->assertOk()->json();
        // Sans l'ordre des clés : MySQL trie celles d'une colonne JSON.
        $this->assertEquals($colonnes, $releve['import_reglages']);
        $this->assertSame(6, $releve['resume']['non_reliee']);

        // Meilleures correspondances : 500 et 75 ; pas les chèques de 200 (deux mouvements possibles chacun).
        $this->assertSame(2, $releve['resume']['suggestions']);
        $this->assertSame('2026-10-05', $this->operation('2026-10-06', 'VIR RECU INTERETS')['suggestion']['date']);
        $this->assertNull($this->operation('2026-10-11', 'CHQ 1234')['suggestion']);
        $this->postJson("/api/banque/comptes/{$this->banque}/releve/relier-suggestions?du=2026-10-01&au=2026-10-31")->assertOk()->assertJsonPath('reliees', 2);

        // Un chèque relié à la main ; l'autre devient alors sans ambiguïté.
        $cheque = $this->operation('2026-10-11', 'CHQ 1234');
        $candidats = $this->getJson("/api/banque/releve-lignes/{$cheque['id']}/candidats")->assertOk()->json();
        $this->assertEquals([['2026-10-10', 200], ['2026-10-12', 200]], array_map(fn ($c) => [$c['date'], $c['montant']], $candidats));
        $this->postJson("/api/banque/releve-lignes/{$cheque['id']}/relier", ['lignes' => [$candidats[0]['id']]])->assertOk()
            ->assertJsonPath('statut', 'reliee')->assertJsonPath('mouvements.0.date', '2026-10-10');
        $autre = $this->operation('2026-10-12', 'CHQ 1235');
        $this->postJson("/api/banque/releve-lignes/{$autre['id']}/relier", ['lignes' => [$candidats[0]['id']]])->assertUnprocessable()->assertJsonValidationErrors('lignes');
        $this->postJson("/api/banque/releve-lignes/{$autre['id']}/relier", ['lignes' => [$this->operation('2026-10-06', 'VIR RECU INTERETS')['mouvements'][0]['id']]])
            ->assertUnprocessable();
        $this->assertSame('2026-10-12', $autre['suggestion']['date']);
        // Délier, puis relier de nouveau.
        $this->postJson("/api/banque/releve-lignes/{$cheque['id']}/delier")->assertOk()->assertJsonPath('statut', 'non_reliee');
        $this->postJson("/api/banque/releve-lignes/{$cheque['id']}/relier", ['lignes' => [$candidats[0]['id']]])->assertOk();
        $this->postJson("/api/banque/comptes/{$this->banque}/releve/relier-suggestions?du=2026-10-01&au=2026-10-31")->assertOk()->assertJsonPath('reliees', 1);

        // Les deux frais : l'un exclu (la banque l'a compté deux fois), l'autre classé en transaction.
        [$frais1, $frais2] = collect($this->getJson("/api/banque/comptes/{$this->banque}/releve?du=2026-10-01&au=2026-10-31")->json('lignes'))
            ->where('libelle', 'FRAIS TENUE COMPTE')->sortBy('id')->values()->all();
        $this->postJson("/api/banque/releve-lignes/{$frais1['id']}/exclure", ['motif' => 'Prélevé deux fois, la banque rembourse'])->assertOk()->assertJsonPath('statut', 'exclue');
        $this->postJson("/api/banque/releve-lignes/{$frais1['id']}/relier", ['lignes' => [1]])->assertUnprocessable();
        $classe = $this->postJson("/api/banque/releve-lignes/{$frais2['id']}/classer", ['compte_comptable_id' => $this->compte('631')])->assertCreated()->json();
        $this->assertSame('reliee', $classe['ligne']['statut']);
        $this->assertStringStartsWith("Transaction {$classe['transaction']['numero']} créée, passée au journal (BQ-", $classe['message']);
        $this->postJson("/api/banque/releve-lignes/{$frais2['id']}/classer", ['compte_comptable_id' => $this->compte('631')])->assertUnprocessable();
        $this->postJson("/api/banque/releve-lignes/{$cheque['id']}/exclure")->assertUnprocessable()
            ->assertJsonPath('message', "Une opération reliée ne s'exclut pas : déliez d'abord ses mouvements.");
        $this->assertSame(['reliee' => 5, 'exclue' => 1], array_count_values($this->statuts()));

        // Rétablie puis classée alors qu'octobre est fermé : la transaction attend de passer au journal.
        $this->postJson("/api/banque/releve-lignes/{$frais1['id']}/retablir")->assertOk()->assertJsonPath('statut', 'non_reliee');
        $this->postJson("/api/comptabilite/periodes/{$this->octobre}/fermer")->assertOk();
        $attente = $this->postJson("/api/banque/releve-lignes/{$frais1['id']}/classer", ['compte_comptable_id' => $this->compte('631')])->assertCreated()->json();
        $this->assertSame('non_reliee', $attente['ligne']['statut']);
        $this->assertStringContainsString('elle se reliera une fois passée au journal : La période du 01/10/2026 au 31/10/2026 est fermée.', $attente['message']);
        $this->postJson("/api/comptabilite/periodes/{$this->octobre}/rouvrir")->assertOk();

        // Rapprochement au 31/10 : un geste pointe ce qui est relié au relevé (500 − 200 − 200 + 75 − 12,50).
        $rapprochement = $this->postJson("/api/banque/comptes/{$this->banque}/rapprochements", ['date_releve' => '2026-10-31', 'solde_releve' => 162.5])->assertCreated()->json('id');
        $this->postJson("/api/banque/rapprochements/{$rapprochement}/pointer-releve")->assertOk()
            ->assertJsonPath('pointes', 5)->assertJsonPath('resume.solde_pointe', 162.5)->assertJsonPath('resume.ecart', 0);

        // Un relevé dont des opérations sont reliées ne se supprime pas.
        $import = $this->getJson("/api/banque/comptes/{$this->banque}/releves")->assertOk()->json('0');
        $this->assertEquals(['releve.csv', 'csv', '2026-10-06', '2026-10-16', 6, 0, 5], [$import['fichier'], $import['format'], $import['du'], $import['au'], $import['nombre_lignes'], $import['doublons'], $import['reliees']]);
        $this->deleteJson("/api/banque/releves/{$import['id']}")->assertUnprocessable()
            ->assertJsonPath('message', "Des opérations de ce relevé sont reliées à des mouvements : déliez-les d'abord.");
    }

    public function test_import_camt(): void
    {
        $camt = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt>'
            .'<Ntry><Amt Ccy="XAF">1500.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><Sts>BOOK</Sts><BookgDt><Dt>2026-10-06</Dt></BookgDt><AcctSvcrRef>R1</AcctSvcrRef>'
            .'<NtryDtls><TxDtls><Refs><EndToEndId>FAC-12</EndToEndId></Refs><RltdPties><Dbtr><Nm>Client Alpha</Nm></Dbtr><Cdtr><Nm>Quincaillerie</Nm></Cdtr></RltdPties>'
            .'<RmtInf><Ustrd>Facture 12</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>'
            .'<Ntry><Amt Ccy="XAF">250</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts><Cd>BOOK</Cd></Sts><BookgDt><DtTm>2026-10-07T09:30:00</DtTm></BookgDt>'
            .'<AcctSvcrRef>R2</AcctSvcrRef><NtryDtls><TxDtls><Refs><EndToEndId>NOTPROVIDED</EndToEndId></Refs></TxDtls></NtryDtls><AddtlNtryInf>FRAIS SMS</AddtlNtryInf></Ntry>'
            .'<Ntry><Amt Ccy="XAF">99</Amt><CdtDbtInd>DBIT</CdtDbtInd><Sts>PDNG</Sts><BookgDt><Dt>2026-10-08</Dt></BookgDt></Ntry>'
            .'</Stmt></BkToCstmrStmt></Document>';
        $resultat = $this->importer($camt, [], false, 'releve.xml')->assertOk()->json();
        $this->assertEquals(['camt', 2, 2, 1250], [$resultat['format'], $resultat['lues'], $resultat['nouvelles'], $resultat['total']]);
        $this->assertSame([['ligne' => 3, 'raison' => 'Opération pas encore passée par la banque.']], $resultat['ignorees']);
        $lignes = $this->getJson("/api/banque/comptes/{$this->banque}/releve?du=2026-10-01&au=2026-10-31")->assertOk()->json('lignes');
        $this->assertEquals([['2026-10-07', 'FRAIS SMS', null, -250], ['2026-10-06', 'Client Alpha — Facture 12', 'FAC-12', 1500]],
            array_map(fn ($l) => [$l['date_operation'], $l['libelle'], $l['reference'], $l['montant']], $lignes));
        $this->importer($camt, [], false, 'releve.xml')->assertOk()->assertJsonPath('nouvelles', 0)->assertJsonPath('doublons', 2);

        // Une déclaration de type (entité externe, expansion) est refusée sans rien lire.
        $xxe = '<?xml version="1.0"?><!DOCTYPE Document [<!ENTITY x SYSTEM "file:///etc/hosts">]>'
            .'<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"><BkToCstmrStmt><Stmt><Ntry><Amt>1</Amt><CdtDbtInd>CRDT</CdtDbtInd>'
            .'<BookgDt><Dt>2026-10-06</Dt></BookgDt><AddtlNtryInf>&x;</AddtlNtryInf></Ntry></Stmt></BkToCstmrStmt></Document>';
        $this->importer($xxe, [], false, 'releve.xml')->assertUnprocessable()
            ->assertJsonPath('errors.fichier.0', 'Ce fichier CAMT contient une déclaration de type de document : il est refusé.');
        $this->importer('<Document><BkToCstmrStmt></BkToCstmrStmt></Document>', [], false, 'releve.xml')->assertUnprocessable()
            ->assertJsonPath('errors.fichier.0', 'Aucune opération trouvée dans ce fichier CAMT.');
    }

    public function test_import_ofx_droits_et_isolation(): void
    {
        $ofx = "OFXHEADER:100\nDATA:OFXSGML\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKTRANLIST>\n"
            ."<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261020120000<TRNAMT>-45.30<FITID>A1<NAME>PRLV ENERGIE<MEMO>Facture octobre</STMTTRN>\n"
            ."<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261020<TRNAMT>-45.30<FITID>A1<NAME>PRLV ENERGIE</STMTTRN>\n"
            ."<STMTTRN><TRNTYPE>CHECK<DTPOSTED>20261021<TRNAMT>-80,00<FITID>A2<CHECKNUM>1236<NAME>CHEQUE</STMTTRN>\n"
            .'</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>';
        $resultat = $this->importer($ofx, [], false, 'releve.ofx')->assertOk()->json();
        $this->assertEquals(['ofx', 3, 2, 1, -125.3], [$resultat['format'], $resultat['lues'], $resultat['nouvelles'], $resultat['doublons'], $resultat['total']]);
        $lignes = $this->getJson("/api/banque/comptes/{$this->banque}/releve?du=2026-10-01&au=2026-10-31")->assertOk()->json('lignes');
        $this->assertEquals([['CHEQUE', '1236', -80], ['PRLV ENERGIE — Facture octobre', null, -45.3]], array_map(fn ($l) => [$l['libelle'], $l['reference'], $l['montant']], $lignes));
        $this->importer($ofx, [], false, 'releve.ofx')->assertOk()->assertJsonPath('nouvelles', 0)->assertJsonPath('doublons', 3);
        $this->importer('pas un relevé', ['colonne_date' => 1, 'colonne_libelle' => 2])->assertUnprocessable()->assertJsonValidationErrors('colonne_montant');
        // Un compte en devise : son relevé ne se compare pas au journal, tenu en XAF.
        $euros = $this->postJson('/api/referentiels/comptes_bancaires', ['nom' => 'Compte euros', 'banque' => 'Afriland First Bank', 'devise_code' => 'EUR', 'actif' => true])
            ->assertCreated()->json('id');
        $this->post("/api/banque/comptes/{$euros}/releves", ['fichier' => UploadedFile::fake()->createWithContent('releve.ofx', $ofx)], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('message', "Le compte « Compte euros » est en EUR : les relevés s'importent pour les comptes dans la devise de l'entreprise (XAF), celle du journal.");

        // Sans rapprocher : pas d'import ni de liens ; sans saisir : pas de classement.
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [], 'exceptions' => ['banque.voir' => true, 'banque.rapprocher' => false, 'banque.saisir' => false]])->assertOk();
        $this->agir($compte);
        $this->getJson("/api/banque/comptes/{$this->banque}/releve")->assertOk();
        $this->getJson("/api/banque/comptes/{$this->banque}/releves")->assertOk()->assertJsonCount(1);
        $this->importer($ofx, [], false, 'releve.ofx')->assertForbidden();
        $this->deleteJson("/api/banque/releves/{$resultat['releve_id']}")->assertForbidden();
        $this->postJson("/api/banque/releve-lignes/{$lignes[0]['id']}/exclure")->assertForbidden();
        $this->postJson("/api/banque/releve-lignes/{$lignes[0]['id']}/classer", ['compte_comptable_id' => $this->compte('631')])->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Saisir une transaction bancaire » (Banque).');

        $autre = Tenant::factory()->create(['modules' => ['finance']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->getJson("/api/banque/releve-lignes/{$lignes[0]['id']}/candidats")->assertNotFound();
        $this->postJson("/api/banque/releve-lignes/{$lignes[0]['id']}/exclure")->assertNotFound();
        $this->getJson("/api/banque/comptes/{$this->banque}/releve")->assertNotFound();
        $this->deleteJson("/api/banque/releves/{$resultat['releve_id']}")->assertNotFound();

        // Un import sans opération reliée (mauvaises colonnes, mauvais compte) se supprime avec ses opérations.
        $this->agir($this->admin);
        $this->deleteJson("/api/banque/releves/{$resultat['releve_id']}")->assertOk()
            ->assertJsonPath('message', 'Relevé « releve.ofx » supprimé avec ses opérations.');
        $this->getJson("/api/banque/comptes/{$this->banque}/releves")->assertOk()->assertExactJson([]);
        $this->getJson("/api/banque/comptes/{$this->banque}/releve?du=2026-10-01&au=2026-10-31")->assertOk()->assertJsonCount(0, 'lignes');
        $this->importer($ofx, [], false, 'releve.ofx')->assertOk()->assertJsonPath('nouvelles', 2);
    }
}
