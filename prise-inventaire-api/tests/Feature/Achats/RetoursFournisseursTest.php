<?php

namespace Tests\Feature\Achats;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Configuration;
use App\Models\Fournisseur;
use App\Models\MouvementTenant;
use App\Models\ProduitTenant;
use App\Models\Raison;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Retours fournisseurs et avoirs (phase 3.4) : retour d'une commande reçue (sortie du stock, quantités au plus
 * celles reçues, contrôlées de nouveau à l'expédition), clôture avec le crédit obtenu ; retour des produits
 * défectueux d'un retour client, remplacement reçu en stock ; retour sans origine dans la devise du fournisseur,
 * raisons, contrôles, PDF, courriel, recherche, fiche fournisseur et droits.
 */
class RetoursFournisseursTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Fournisseur $fournisseur;

    private Secteur $secteur;

    private int $produitId;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['achats', 'ventes']]);
        [$this->fournisseur, $this->secteur] = $this->chez(fn () => [
            Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimenterie du Sud', 'email' => 'retours@cimenterie.test', 'actif' => true]),
            Secteur::create(['code' => 'A-01', 'nom' => 'Allée A', 'actif' => true]),
        ]);
        $this->produitId = $this->chez(fn () => ProduitTenant::create([
            'numero' => 'CIM-50', 'description' => 'Ciment 50 kg', 'prix_unitaire' => 150, 'secteur_id' => $this->secteur->id,
            'fournisseur_principal_id' => $this->fournisseur->id,
        ])->id);
        $this->admin = $this->agir('admin');
        // Courriels envoyés aussitôt (et non mis en file d'attente).
        config(['queue.default' => 'sync', 'mail.default' => 'array']);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(string $role, array $attributs = []): AdminUser
    {
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => $role, 'nom' => 'Awa Achats'] + $attributs);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);

        return $compte;
    }

    /** Commande envoyée de 5 unités à 100, entièrement reçue dans l'allée A ; renvoie son id et l'id de sa ligne. */
    private function commandeRecue(array $entete = []): array
    {
        [$commande, $ligne] = $this->chez(function () use ($entete) {
            $commande = ComFourEntete::create($entete + [
                'numero' => 'BC-00001', 'fournisseur_id' => $this->fournisseur->id, 'date_commande' => '2026-10-15',
                'statut' => ComFourEntete::STATUT_ENVOYEE, 'montant_total' => 500, 'created_by' => $this->admin->id,
            ]);
            $ligne = ComFourLigne::create([
                'com_four_entete_id' => $commande->id, 'produit_id' => $this->produitId, 'quantite_commandee' => 5,
                'quantite_recue' => 0, 'prix_unitaire' => 100, 'montant_ligne' => 500,
            ]);

            return [$commande->id, $ligne->id];
        });
        $this->postJson('/api/receptions', ['com_four_ligne_id' => $ligne, 'date_reception' => '2026-10-16', 'quantite_recue' => 5, 'secteur_id' => $this->secteur->id])->assertCreated();

        return [$commande, $ligne];
    }

    private function retourner(array $donnees): TestResponse
    {
        return $this->postJson('/api/retours-fournisseurs', $donnees + ['type' => 'remboursement', 'date_retour' => '2026-10-16']);
    }

    private function sorties(): array
    {
        return $this->chez(fn () => MouvementTenant::orderBy('id')->get()->map(fn ($m) => [$m->type, $m->secteur_source ?? $m->secteur_destination, (float) $m->quantite, $m->motif])->all());
    }

    public function test_retour_d_une_commande_expedie_puis_clos_avec_l_avoir_du_fournisseur(): void
    {
        [$commande, $ligne] = $this->commandeRecue();
        $proposition = $this->getJson("/api/retours-fournisseurs/depuis-commande/{$commande}")->assertOk()->assertJsonPath('retournable', true)->json();
        $this->assertEquals([5, 0, 5, 100, $this->secteur->id], array_values(array_intersect_key($proposition['lignes'][0], array_flip(['quantite_recue', 'quantite_retournee', 'reste', 'prix_unitaire', 'secteur_id']))));

        // Plus que reçu : refusé. Brouillon : deux sacs crevés repartent de l'allée A.
        $this->retourner(['commande_fournisseur_id' => $commande, 'lignes' => [['com_four_ligne_id' => $ligne, 'quantite' => 6]]])
            ->assertUnprocessable()->assertJsonPath('errors', ['lignes.0.quantite' => ['Au plus 5 (quantité reçue).']]);
        $autre = $this->chez(fn () => Fournisseur::create(['code' => 'F2', 'raison_sociale' => 'Autre', 'actif' => true])->id);
        $this->retourner(['commande_fournisseur_id' => $commande, 'fournisseur_id' => $autre, 'lignes' => [['com_four_ligne_id' => $ligne, 'quantite' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['fournisseur_id']);
        $brouillon = $this->retourner(['commande_fournisseur_id' => $commande, 'numero_rma' => 'RMA-77', 'lignes' => [['com_four_ligne_id' => $ligne, 'quantite' => 2, 'secteur_id' => $this->secteur->id]]])
            ->assertCreated()->assertJsonPath('statut', 'brouillon')->assertJsonPath('numero', null)->assertJsonPath('fournisseur_id', $this->fournisseur->id)->json();
        $this->assertEquals([200, 100], [$brouillon['montant'], $brouillon['lignes'][0]['prix_unitaire']]);
        $this->assertSame([], $this->sorties());

        // Expédié : numéroté, la marchandise sort de l'allée A.
        $this->postJson("/api/retours-fournisseurs/{$brouillon['id']}/expedier")->assertOk()
            ->assertJsonPath('numero', 'RF-00001')->assertJsonPath('statut', 'expedie')->assertJsonPath('date_expedition', '2026-10-16T00:00:00.000000Z');
        $this->assertSame([['sortie', 'A-01', 2.0, 'Retour fournisseur RF-00001']], $this->sorties());
        $this->putJson("/api/retours-fournisseurs/{$brouillon['id']}", ['type' => 'remboursement', 'date_retour' => '2026-10-16', 'lignes' => [['com_four_ligne_id' => $ligne, 'quantite' => 1]]])
            ->assertUnprocessable()->assertJsonPath('message', 'Un retour expédié ne se modifie plus.');
        $this->deleteJson("/api/retours-fournisseurs/{$brouillon['id']}")->assertUnprocessable();

        // Il en reste 3 : deux brouillons les réclament, le second expédié est refusé.
        $premier = $this->retourner(['commande_fournisseur_id' => $commande, 'lignes' => [['com_four_ligne_id' => $ligne, 'quantite' => 3]]])->assertCreated()->json('id');
        $second = $this->retourner(['commande_fournisseur_id' => $commande, 'lignes' => [['com_four_ligne_id' => $ligne, 'quantite' => 1]]])->assertCreated()->json('id');
        $this->postJson("/api/retours-fournisseurs/{$premier}/expedier")->assertOk()->assertJsonPath('numero', 'RF-00002');
        $this->postJson("/api/retours-fournisseurs/{$second}/expedier")->assertUnprocessable()
            ->assertJsonPath('errors', ['lignes.0.quantite' => ['Au plus 0 (quantité reçue, retours déjà expédiés déduits).']]);
        // Hors stock (sans secteur) : rien ne sort.
        $this->assertCount(1, $this->sorties());

        // Clôture : le crédit obtenu est exigé pour un remboursement.
        $this->postJson("/api/retours-fournisseurs/{$second}/cloturer", ['date_cloture' => '2026-10-16'])->assertUnprocessable()->assertJsonPath('message', "Expédiez d'abord le retour.");
        $this->postJson("/api/retours-fournisseurs/{$brouillon['id']}/cloturer", ['date_cloture' => '2026-10-16'])
            ->assertUnprocessable()->assertJsonPath('errors', ['montant_credite' => ['Indiquez le crédit obtenu du fournisseur.']]);
        $clos = $this->postJson("/api/retours-fournisseurs/{$brouillon['id']}/cloturer", ['date_cloture' => '2026-10-16', 'montant_credite' => 180, 'reference_avoir' => 'AV-F-001'])
            ->assertOk()->assertJsonPath('statut', 'clos')->assertJsonPath('reference_avoir', 'AV-F-001')->json();
        $this->assertEquals(180, $clos['montant_credite']);
        $this->postJson("/api/retours-fournisseurs/{$brouillon['id']}/cloturer", ['date_cloture' => '2026-10-16', 'montant_credite' => 1])
            ->assertUnprocessable()->assertJsonPath('message', 'Ce retour est déjà clos.');

        // Résumé : 300 attendus (RF-00002), 180 obtenus ; la fiche du fournisseur en fait autant. La commande ne bouge pas.
        $liste = $this->getJson("/api/retours-fournisseurs?commande_fournisseur_id={$commande}")->assertOk()->json();
        $this->assertSame([null, 'RF-00002', 'RF-00001'], array_column($liste['data'], 'numero'));
        $this->assertEquals(['credits_attendus' => 300, 'credits_obtenus' => 180, 'en_cours' => 1, 'brouillons' => 1], $liste['resume']);
        $this->assertEquals(['credits_attendus' => 300, 'credits_obtenus' => 180, 'en_cours' => 1, 'brouillons' => 1],
            $this->getJson("/api/fournisseurs/{$this->fournisseur->id}/synthese")->assertOk()->json('retours'));
        $this->assertSame(5, $this->chez(fn () => ComFourLigne::find($ligne)->quantite_recue));
        $this->assertEquals([5, 5, 0], array_values(array_intersect_key($this->getJson("/api/retours-fournisseurs/depuis-commande/{$commande}")->json('lignes.0'), array_flip(['quantite_recue', 'quantite_retournee', 'reste']))));
    }

    public function test_produits_defectueux_d_un_retour_client_et_remplacement_recu_en_stock(): void
    {
        // Un client rapporte deux sacs : l'un défectueux (rebut), l'autre remis en stock.
        $client = $this->chez(fn () => Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id);
        $facture = $this->postJson('/api/factures', ['client_id' => $client, 'date_facture' => '2026-10-16', 'lignes' => [['produit_id' => $this->produitId, 'quantite' => 2, 'prix_unitaire_ht' => 150, 'taux_tva' => 0]]])->assertCreated()->json();
        $this->postJson("/api/factures/{$facture['id']}/emettre")->assertOk();
        $retourClient = $this->postJson('/api/retours-clients', ['facture_id' => $facture['id'], 'date_retour' => '2026-10-16', 'lignes' => [
            ['facture_ligne_id' => $facture['lignes'][0]['id'], 'quantite' => 1, 'traitement' => 'rebut'],
            ['facture_ligne_id' => $facture['lignes'][0]['id'], 'quantite' => 1, 'traitement' => 'remise_en_stock', 'secteur_id' => $this->secteur->id],
        ]])->assertCreated()->json();
        $this->getJson("/api/retours-fournisseurs/depuis-retour-client/{$retourClient['id']}")->assertOk()->assertJsonPath('retournable', false);
        $this->postJson("/api/retours-clients/{$retourClient['id']}/traiter")->assertOk();

        // Proposé : le fournisseur principal du produit ; le sac au rebut (hors stock), pas celui remis en stock.
        $proposition = $this->getJson("/api/retours-fournisseurs/depuis-retour-client/{$retourClient['id']}")->assertOk()
            ->assertJsonPath('retournable', true)->assertJsonPath('fournisseur_propose_id', $this->fournisseur->id)->json('lignes');
        $this->assertEquals([[1, null], [0, $this->secteur->id]], array_map(fn ($l) => [$l['quantite_proposee'], $l['secteur_id']], $proposition));
        [$rebut] = $retourClient['lignes'];

        $this->retourner(['retour_client_id' => $retourClient['id'], 'fournisseur_id' => $this->fournisseur->id, 'type' => 'remplacement', 'lignes' => [['retour_client_ligne_id' => $rebut['id'], 'quantite' => 2]]])
            ->assertUnprocessable()->assertJsonPath('errors', ['lignes.0.quantite' => ['Au plus 1 (quantité rapportée par le client).']]);
        $this->retourner(['retour_client_id' => $retourClient['id'], 'commande_fournisseur_id' => 1, 'fournisseur_id' => $this->fournisseur->id, 'lignes' => [['retour_client_ligne_id' => $rebut['id'], 'quantite' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['commande_fournisseur_id']);
        $retour = $this->retourner(['retour_client_id' => $retourClient['id'], 'fournisseur_id' => $this->fournisseur->id, 'type' => 'remplacement', 'lignes' => [['retour_client_ligne_id' => $rebut['id'], 'quantite' => 1, 'prix_unitaire' => 80]]])
            ->assertCreated()->assertJsonPath('retour_client_id', $retourClient['id'])->json('id');

        // Expédié hors stock : rien ne sort. Remplacement reçu dans l'allée A : un arrivage.
        $this->postJson("/api/retours-fournisseurs/{$retour}/expedier")->assertOk();
        $avant = count($this->sorties());
        $this->postJson("/api/retours-fournisseurs/{$retour}/cloturer", ['date_cloture' => '2026-10-16', 'secteur_remplacement_id' => $this->secteur->id])
            ->assertOk()->assertJsonPath('statut', 'clos')->assertJsonPath('montant_credite', null)->assertJsonPath('secteur_remplacement_id', $this->secteur->id);
        $this->assertSame([['arrivage', 'A-01', 1.0, 'Remplacement fournisseur RF-00001']], array_slice($this->sorties(), $avant));
        // Le sac défectueux est reparti ; celui remis en stock peut encore partir.
        $this->assertSame([0, 1], array_column($this->getJson("/api/retours-fournisseurs/depuis-retour-client/{$retourClient['id']}")->json('lignes'), 'reste'));
        $this->getJson("/api/retours-fournisseurs/{$retour}")->assertOk()->assertJsonPath('retour_client.numero', 'RC-00001')
            ->assertJsonPath('secteur_remplacement.code', 'A-01')->assertJsonPath('lignes.0.secteur_id', null);
    }

    public function test_retour_sans_origine_devise_raisons_controles_pdf_courriel_et_recherche(): void
    {
        $etranger = $this->chez(fn () => Fournisseur::create(['code' => 'F9', 'raison_sociale' => 'Global Supplies', 'email' => 'rma@global.test', 'devise' => 'USD', 'actif' => true])->id);
        [$defectueux, $mouvement] = $this->chez(fn () => [
            Raison::create(['type' => 'retour_fournisseur', 'code' => 'DEF', 'libelle' => 'Produit défectueux', 'actif' => true])->id,
            Raison::create(['type' => 'mouvement', 'code' => 'AUT', 'libelle' => 'Autre', 'actif' => true])->id,
        ]);
        $ligne = fn (array $autres = []) => $autres + ['produit_id' => $this->produitId, 'quantite' => 2, 'prix_unitaire' => 10, 'secteur_id' => $this->secteur->id, 'raison_id' => $defectueux];

        // Fournisseur, devise (celle du fournisseur, taux exigé), raison exigée et de type « retour fournisseur ».
        $this->retourner(['lignes' => [$ligne()]])->assertUnprocessable()->assertJsonPath('errors', ['fournisseur_id' => ['Choisissez le fournisseur.']]);
        $this->retourner(['fournisseur_id' => $etranger, 'lignes' => [$ligne()]])->assertUnprocessable()->assertJsonPath('errors', ['taux_change' => ['Indiquez le taux de change USD → EUR.']]);
        $this->retourner(['fournisseur_id' => $etranger, 'taux_change' => 600, 'lignes' => [$ligne(['raison_id' => null])]])
            ->assertUnprocessable()->assertJsonPath('errors', ['lignes.0.raison_id' => ['Choisissez la raison du retour.']]);
        $this->retourner(['fournisseur_id' => $etranger, 'taux_change' => 600, 'lignes' => [$ligne(['raison_id' => $mouvement])]])
            ->assertUnprocessable()->assertJsonPath('errors', ['lignes.0.raison_id' => ['Choisissez une raison de retour active.']]);
        $this->retourner(['fournisseur_id' => $etranger, 'taux_change' => 600, 'date_retour' => '2026-10-18', 'lignes' => [$ligne()]])
            ->assertUnprocessable()->assertJsonPath('errors', ['date_retour' => ['Le retour ne peut pas être daté dans le futur.']]);
        $cree = $this->retourner(['fournisseur_id' => $etranger, 'taux_change' => 600, 'numero_rma' => 'RMA-555', 'lignes' => [$ligne()]])
            ->assertCreated()->assertJsonPath('devise', 'USD')->json();
        $this->assertEquals([600, 20], [$cree['taux_change'], $cree['montant']]);
        $retour = $cree['id'];

        // Numérotation manuelle : pas d'expédition. Expédition datée dans le futur : refusée.
        $this->chez(fn () => Configuration::pourEntite('retour_fournisseur', $this->tenant->id)->update(['auto_increment' => false]));
        $this->postJson("/api/retours-fournisseurs/{$retour}/expedier")->assertUnprocessable()
            ->assertJsonPath('message', 'Numéro requis : activez la numérotation automatique des retours fournisseurs (Configuration → Numérotation).');
        $this->chez(fn () => Configuration::where('entite', 'retour_fournisseur')->update(['auto_increment' => true]));
        $this->postJson("/api/retours-fournisseurs/{$retour}/expedier", ['date_expedition' => '2026-10-18'])->assertUnprocessable()->assertJsonValidationErrors(['date_expedition']);
        $this->postJson("/api/retours-fournisseurs/{$retour}/expedier")->assertOk()->assertJsonPath('numero', 'RF-00001');
        $this->assertSame([['sortie', 'A-01', 2.0, 'Retour fournisseur RF-00001 : Produit défectueux']], $this->sorties());

        // Crédits dans la devise de l'entreprise : 20 USD attendus = 12 000 ; 15 USD obtenus = 9 000.
        $this->assertEquals(12000, $this->getJson('/api/retours-fournisseurs')->json('resume.credits_attendus'));
        $this->postJson("/api/retours-fournisseurs/{$retour}/cloturer", ['date_cloture' => '2026-10-16', 'montant_credite' => 15])->assertOk();
        $this->assertEquals([0, 9000], array_values(array_intersect_key($this->getJson('/api/retours-fournisseurs')->json('resume'), array_flip(['credits_attendus', 'credits_obtenus']))));

        // Un contenant consigné se clôt sans crédit.
        $consigne = $this->retourner(['fournisseur_id' => $this->fournisseur->id, 'type' => 'consigne', 'lignes' => [$ligne(['secteur_id' => null, 'prix_unitaire' => 0])]])->assertCreated()->json('id');
        $this->postJson("/api/retours-fournisseurs/{$consigne}/expedier")->assertOk();
        $this->postJson("/api/retours-fournisseurs/{$consigne}/cloturer", ['date_cloture' => '2026-10-16'])->assertOk()->assertJsonPath('montant_credite', null);

        // Bon de retour (PDF), courriel au fournisseur, recherche par numéro RMA.
        $this->assertStringStartsWith('%PDF', $this->get("/api/retours-fournisseurs/{$retour}/pdf")->assertOk()->getContent());
        Mail::fake();
        $courriel = $this->getJson("/api/courriels/preparer?type=retour_fournisseur&document_id={$retour}")->assertOk()->json();
        $this->assertSame(["Retour de marchandise RF-00001 — {$this->tenant->nom}", ['rma@global.test'], 'retour-fournisseur-RF-00001.pdf'],
            [$courriel['objet'], $courriel['destinataires'], $courriel['piece_jointe']]);
        $this->assertStringContainsString('(remboursement), d\'une valeur de 20,00', $courriel['contenu']);
        $this->postJson('/api/courriels/envois', [
            'type' => 'retour_fournisseur', 'document_id' => $retour, 'langue' => 'fr', 'destinataires' => ['rma@global.test'],
            'objet' => $courriel['objet'], 'contenu' => $courriel['contenu'],
        ])->assertCreated()->assertJsonPath('statut', 'envoye');
        $groupes = $this->getJson('/api/recherche?q=RMA-555&types=retours_fournisseurs')->assertOk()->json('groupes');
        $this->assertSame(['retours_fournisseurs', 'RF-00001', '/retours-fournisseurs?recherche=RF-00001'], [$groupes[0]['type'], $groupes[0]['resultats'][0]['titre'], $groupes[0]['resultats'][0]['lien']]);
    }

    public function test_droits_des_retours_fournisseurs(): void
    {
        [$commande, $ligne] = $this->commandeRecue();
        $retour = $this->retourner(['commande_fournisseur_id' => $commande, 'lignes' => [['com_four_ligne_id' => $ligne, 'quantite' => 1]]])->assertCreated()->json('id');

        // Rôles système : les droits des commandes fournisseur (« user » consulte seulement).
        $this->agir('user');
        $this->getJson('/api/retours-fournisseurs')->assertOk();
        $this->retourner(['commande_fournisseur_id' => $commande, 'lignes' => [['com_four_ligne_id' => $ligne, 'quantite' => 1]]])->assertForbidden();
        $this->postJson("/api/retours-fournisseurs/{$retour}/expedier")->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Expédier un retour fournisseur » (Retours fournisseurs).');
        $this->agir('manager');
        $this->postJson("/api/retours-fournisseurs/{$retour}/expedier")->assertOk();

        // Catalogue : module « Retours fournisseurs » des achats, avec ses deux actions nommées.
        $this->agir('admin');
        $modules = collect($this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets'))->firstWhere('cle', 'achats')['modules'];
        $this->assertSame(['retours_fournisseurs.voir', 'retours_fournisseurs.creer', 'retours_fournisseurs.modifier', 'retours_fournisseurs.supprimer', 'retours_fournisseurs.expedier', 'retours_fournisseurs.cloturer'],
            array_column(collect($modules)->firstWhere('cle', 'retours_fournisseurs')['permissions'], 'code'));

        // Un groupe qui expédie les retours sans pouvoir les clôturer.
        $groupe = $this->postJson('/api/roles-custom', ['nom' => 'Magasin',
            'permissions' => ['retours_fournisseurs' => ['can_view' => true, 'can_create' => true, 'can_edit' => true]],
            'actions' => ['retours_fournisseurs.expedier' => true, 'retours_fournisseurs.cloturer' => false]])->assertCreated()->json('role_id');
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [$groupe], 'exceptions' => []])->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte->fresh(), ['*']);
        $brouillon = $this->retourner(['commande_fournisseur_id' => $commande, 'lignes' => [['com_four_ligne_id' => $ligne, 'quantite' => 1]]])->assertCreated()->json('id');
        $this->postJson("/api/retours-fournisseurs/{$brouillon}/expedier")->assertOk();
        $this->postJson("/api/retours-fournisseurs/{$retour}/cloturer", ['date_cloture' => '2026-10-16', 'montant_credite' => 100])->assertForbidden();
        $moi = $this->getJson('/api/permissions/me')->assertOk()->json('actions');
        $this->assertSame([true, false], [$moi['retours_fournisseurs.expedier'], $moi['retours_fournisseurs.cloturer']]);
    }
}
