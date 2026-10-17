<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Configuration;
use App\Models\MouvementTenant;
use App\Models\ProduitTenant;
use App\Models\Raison;
use App\Models\ScanTenant;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Retours clients (phase 1.7) : retour d'une facture remis en stock ou mis au rebut, quantités au plus celles
 * facturées (retours traités déduits, y compris au traitement), arrivage compté par la prise d'inventaire suivante,
 * note de crédit du retour, retour sans facture, raisons, contrôles, recherche et droits.
 */
class RetoursClientsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    private Secteur $secteur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        [$this->clientId, $this->secteur] = $this->chez(fn () => [
            Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue du Test', 'ville' => 'Douala', 'code_postal' => '0', 'email' => 'achats@client.test'])->id,
            Secteur::create(['code' => 'A-01', 'nom' => 'Allée A', 'actif' => true]),
        ]);
        $this->produitId = $this->chez(fn () => ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Ciment', 'prix_unitaire' => 100, 'secteur_id' => $this->secteur->id])->id);
        $this->agir('admin');
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(string $role, array $attributs = []): AdminUser
    {
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => $role, 'nom' => 'Paul Magasin'] + $attributs);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);

        return $compte;
    }

    /** Facture émise de 3 unités à 100 HT (TVA 20 %) ; renvoie son id et l'id de sa ligne. */
    private function factureEmise(int $quantite = 3, ?int $client = null): array
    {
        $facture = $this->postJson('/api/factures', [
            'client_id' => $client ?? $this->clientId, 'date_facture' => '2026-10-16',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => $quantite, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]],
        ])->assertCreated()->json();
        $this->postJson("/api/factures/{$facture['id']}/emettre")->assertOk();

        return [$facture['id'], $facture['lignes'][0]['id']];
    }

    private function retourner(?int $facture, array $lignes, array $autres = []): TestResponse
    {
        return $this->postJson('/api/retours-clients', $autres + ['facture_id' => $facture, 'date_retour' => '2026-10-16', 'lignes' => $lignes]);
    }

    private function enStock(int $ligne, int $quantite): array
    {
        return ['facture_ligne_id' => $ligne, 'quantite' => $quantite, 'traitement' => 'remise_en_stock', 'secteur_id' => $this->secteur->id];
    }

    private function auRebut(int $ligne, int $quantite): array
    {
        return ['facture_ligne_id' => $ligne, 'quantite' => $quantite, 'traitement' => 'rebut'];
    }

    public function test_retour_d_une_facture_remis_en_stock_et_au_rebut(): void
    {
        [$facture, $ligne] = $this->factureEmise();
        $proposition = $this->getJson("/api/factures/{$facture}/retour")->assertOk()->assertJsonPath('retournable', true)->json('lignes.0');
        $this->assertEquals([3, 0, 3, $this->secteur->id], [$proposition['quantite_facturee'], $proposition['quantite_retournee'], $proposition['reste'], $proposition['secteur_id']]);

        // Plus que facturé, d'un coup ou réparti sur deux lignes : refusé.
        $this->retourner($facture, [$this->enStock($ligne, 4)])->assertUnprocessable()->assertJsonPath('errors', ['lignes.0.quantite' => ['Au plus 3 (quantité facturée).']]);
        $this->retourner($facture, [$this->enStock($ligne, 2), $this->auRebut($ligne, 2)])->assertUnprocessable()->assertJsonValidationErrors(['lignes.1.quantite']);
        // Une remise en stock exige son secteur ; les lignes sans quantité sont ignorées.
        $this->retourner($facture, [['facture_ligne_id' => $ligne, 'quantite' => 1, 'traitement' => 'remise_en_stock']])
            ->assertUnprocessable()->assertJsonPath('errors', ['lignes.0.secteur_id' => ['Choisissez le secteur où remettre la marchandise en stock.']]);
        $this->retourner($facture, [$this->enStock($ligne, 0)])->assertUnprocessable()->assertJsonPath('message', 'Indiquez au moins une quantité retournée.');

        // Brouillon : une unité en stock, une au rebut ; ni numéro, ni mouvement de stock.
        $brouillon = $this->retourner($facture, [$this->enStock($ligne, 1), $this->auRebut($ligne, 1)], ['notes' => 'Sacs abîmés'])
            ->assertCreated()->assertJsonPath('statut', 'brouillon')->assertJsonPath('numero', null)->assertJsonPath('client_id', $this->clientId)->json('id');
        $this->assertSame(0, $this->chez(fn () => MouvementTenant::count()));

        // Traité : numéroté ; la remise en stock est un arrivage dans le secteur, le rebut ne fait rien entrer.
        $traite = $this->postJson("/api/retours-clients/{$brouillon}/traiter")->assertOk()->json();
        $this->assertSame(['RC-00001', 'traite'], [$traite['numero'], $traite['statut']]);
        $mouvement = $this->chez(fn () => MouvementTenant::sole());
        $this->assertSame(['arrivage', 'PRD-1', 'A-01', 'Retour client RC-00001', 'Paul Magasin', '2026-10-16'],
            [$mouvement->type, $mouvement->produit_numero, $mouvement->secteur_destination, $mouvement->motif, $mouvement->employe, $mouvement->date_mouvement->toDateString()]);
        $this->assertEquals(1, $mouvement->quantite);
        $this->assertSame([$mouvement->id, null], array_column($traite['lignes'], 'mouvement_id'));

        // Un retour traité ne se modifie ni ne se supprime ; il ne se traite qu'une fois.
        $this->putJson("/api/retours-clients/{$brouillon}", ['date_retour' => '2026-10-16', 'lignes' => [$this->auRebut($ligne, 1)]])
            ->assertUnprocessable()->assertJsonPath('message', 'Un retour traité ne se modifie plus.');
        $this->deleteJson("/api/retours-clients/{$brouillon}")->assertUnprocessable()->assertJsonPath('message', 'Seuls les retours en brouillon peuvent être supprimés.');
        $this->postJson("/api/retours-clients/{$brouillon}/traiter")->assertUnprocessable()->assertJsonPath('message', 'Ce retour est déjà traité.');

        // Il reste une unité : deux brouillons la réclament, seul le premier traité passe.
        $this->assertEquals([2, 1], array_values(array_intersect_key($this->getJson("/api/factures/{$facture}/retour")->json('lignes.0'), array_flip(['quantite_retournee', 'reste']))));
        $premier = $this->retourner($facture, [$this->enStock($ligne, 1)])->assertCreated()->json('id');
        $second = $this->retourner($facture, [$this->auRebut($ligne, 1)])->assertCreated()->json('id');
        $this->postJson("/api/retours-clients/{$premier}/traiter")->assertOk()->assertJsonPath('numero', 'RC-00002');
        $this->postJson("/api/retours-clients/{$second}/traiter")->assertUnprocessable()
            ->assertJsonPath('errors', ['lignes.0.quantite' => ['Au plus 0 (quantité facturée, retours déjà traités déduits).']]);
        $this->deleteJson("/api/retours-clients/{$second}")->assertOk();

        // La facture liste ses retours ; la liste résume les quantités traitées.
        $this->assertSame(['RC-00001', 'RC-00002'], array_column($this->getJson("/api/factures/{$facture}")->assertOk()->json('retours'), 'numero'));
        $liste = $this->getJson("/api/retours-clients?facture_id={$facture}")->assertOk()->json();
        $this->assertSame(['RC-00002', 'RC-00001'], array_column($liste['data'], 'numero'));
        $this->assertSame([2, 1], [$liste['resume']['remis_en_stock'], $liste['resume']['rebut']]);
        $this->assertEquals([1, 2], array_column($liste['data'], 'quantite_totale'));
    }

    public function test_la_remise_en_stock_compte_comme_une_entree_a_la_prise_d_inventaire_suivante(): void
    {
        [$facture, $ligne] = $this->factureEmise();
        $this->travelTo('2026-10-16 08:00:00');
        $premiere = $this->postJson('/api/prises-inventaire', ['libelle' => 'Allée A', 'secteur_ids' => [$this->secteur->id]])->assertCreated()->json('id');
        $this->chez(fn () => ScanTenant::create(['numero' => 'PRD-1', 'quantite' => 10, 'employe' => 'Paul', 'secteur' => 'A-01', 'date_saisie' => '2026-10-16 08:30:00']));
        $this->travelTo('2026-10-16 09:00:00');
        $this->postJson("/api/prises-inventaire/{$premiere}/valider")->assertOk();

        $this->travelTo('2026-10-16 10:00:00');
        $retour = $this->retourner($facture, [$this->enStock($ligne, 2)])->assertCreated()->json('id');
        $this->postJson("/api/retours-clients/{$retour}/traiter")->assertOk();

        $this->travelTo('2026-10-17 08:00:00');
        $seconde = $this->postJson('/api/prises-inventaire', ['libelle' => 'Allée A', 'secteur_ids' => [$this->secteur->id]])->assertCreated()->json('id');
        $ligneSession = collect($this->getJson("/api/prises-inventaire/{$seconde}/lignes")->assertOk()->json('data'))->firstWhere('produit_numero', 'PRD-1');
        $this->assertEquals([10, 2, 0], [$ligneSession['quantite_reference'], $ligneSession['entrees_depuis'], $ligneSession['sorties_depuis']]);
    }

    public function test_note_de_credit_d_un_retour(): void
    {
        [$facture, $ligne] = $this->factureEmise();
        $retour = $this->retourner($facture, [$this->enStock($ligne, 1), $this->auRebut($ligne, 1)])->assertCreated()->json('id');
        $this->postJson("/api/retours-clients/{$retour}/crediter")->assertUnprocessable()->assertJsonPath('message', "Traitez d'abord le retour.");
        $this->postJson("/api/retours-clients/{$retour}/traiter")->assertOk();

        // Brouillon de note de crédit : les deux unités retournées, au prix facturé.
        $avoir = $this->postJson("/api/retours-clients/{$retour}/crediter")->assertCreated()
            ->assertJsonPath('statut', 'brouillon')->assertJsonPath('motif', 'Retour RC-00001')->assertJsonPath('facture_id', $facture)->json();
        $this->assertEquals([2, 200, 240], [$avoir['lignes'][0]['quantite'], $avoir['montant_ht'], $avoir['montant_ttc']]);
        $this->getJson("/api/retours-clients/{$retour}")->assertOk()->assertJsonPath('avoir.id', $avoir['id']);
        $this->getJson("/api/avoirs/{$avoir['id']}")->assertOk()->assertJsonPath('retour.numero', 'RC-00001');
        $this->postJson("/api/retours-clients/{$retour}/crediter")->assertUnprocessable()
            ->assertJsonPath('message', 'La note de crédit de ce retour existe déjà (brouillon).');

        // Brouillon supprimé : le retour peut en donner une autre, émise ensuite comme toute note.
        $this->deleteJson("/api/avoirs/{$avoir['id']}")->assertOk();
        $this->getJson("/api/retours-clients/{$retour}")->assertJsonPath('avoir', null);
        $nouvelle = $this->postJson("/api/retours-clients/{$retour}/crediter")->assertCreated()->json('id');
        $this->postJson("/api/avoirs/{$nouvelle}/emettre")->assertOk()->assertJsonPath('numero', 'AV-00001');
        $this->assertEquals(120, $this->getJson("/api/factures/{$facture}")->json('reste_a_payer'));

        // Facture entièrement créditée : le retour suivant n'a plus rien à créditer.
        $reste = $this->postJson('/api/avoirs', ['facture_id' => $facture, 'date_avoir' => '2026-10-16', 'lignes' => [['facture_ligne_id' => $ligne, 'quantite' => 1]]])->assertCreated()->json('id');
        $this->postJson("/api/avoirs/{$reste}/emettre")->assertOk();
        $dernier = $this->retourner($facture, [$this->enStock($ligne, 1)])->assertCreated()->json('id');
        $this->postJson("/api/retours-clients/{$dernier}/traiter")->assertOk();
        $this->postJson("/api/retours-clients/{$dernier}/crediter")->assertUnprocessable()
            ->assertJsonPath('message', 'La facture FAC-00001 est déjà entièrement créditée.');
    }

    public function test_retour_sans_facture_raisons_controles_et_recherche(): void
    {
        [$defectueux, $mouvement] = $this->chez(fn () => [
            Raison::create(['type' => 'retour_client', 'code' => 'DEF', 'libelle' => 'Produit défectueux', 'actif' => true])->id,
            Raison::create(['type' => 'mouvement', 'code' => 'AUT', 'libelle' => 'Autre mouvement', 'actif' => true])->id,
        ]);
        $sansFacture = fn (array $ligne) => $this->retourner(null, [$ligne + ['produit_id' => $this->produitId, 'quantite' => 1]], ['client_id' => $this->clientId]);

        // Raison exigée dès que l'entreprise en a défini ; seulement une raison de retour active.
        $sansFacture(['traitement' => 'rebut'])->assertUnprocessable()->assertJsonPath('errors', ['lignes.0.raison_id' => ['Choisissez la raison du retour.']]);
        $sansFacture(['traitement' => 'rebut', 'raison_id' => $mouvement])->assertUnprocessable()->assertJsonPath('errors', ['lignes.0.raison_id' => ['Choisissez une raison de retour active.']]);
        $sansFacture(['traitement' => 'remise_en_stock', 'raison_id' => $defectueux, 'produit_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors(['lignes.0.produit_id']);
        $retour = $sansFacture(['traitement' => 'remise_en_stock', 'raison_id' => $defectueux, 'secteur_id' => $this->secteur->id])->assertCreated()->json('id');
        $this->postJson("/api/retours-clients/{$retour}/traiter")->assertOk()->assertJsonPath('numero', 'RC-00001');
        $this->assertSame('Retour client RC-00001 : Produit défectueux', $this->chez(fn () => MouvementTenant::sole()->motif));
        $this->getJson("/api/retours-clients/{$retour}")->assertOk()->assertJsonPath('lignes.0.raison.libelle', 'Produit défectueux')->assertJsonPath('lignes.0.secteur.code', 'A-01');
        $this->postJson("/api/retours-clients/{$retour}/crediter")->assertUnprocessable()
            ->assertJsonPath('message', "Ce retour n'a pas de facture d'origine : il n'y a rien à créditer.");

        // Facture d'un autre client, facture brouillon, dates.
        $autre = $this->chez(fn () => Client::create(['code' => 'CLI-2', 'raison_sociale' => 'Autre client', 'adresse_facturation' => '2 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id);
        [$facture, $ligne] = $this->factureEmise(2, $autre);
        $avecRaison = fn (array $l) => $l + ['raison_id' => $defectueux];
        $this->retourner($facture, [$avecRaison($this->auRebut($ligne, 1))], ['client_id' => $this->clientId])->assertUnprocessable()->assertJsonValidationErrors(['facture_id']);
        $this->retourner($facture, [$avecRaison($this->auRebut($ligne, 1))], ['date_retour' => '2026-10-15'])->assertUnprocessable()->assertJsonValidationErrors(['date_retour']);
        $this->retourner($facture, [$avecRaison($this->auRebut($ligne, 1))], ['date_retour' => '2026-10-18'])->assertUnprocessable()
            ->assertJsonPath('errors', ['date_retour' => ['Le retour ne peut pas être daté dans le futur.']]);
        $brouillon = $this->postJson('/api/factures', ['client_id' => $this->clientId, 'date_facture' => '2026-10-16', 'lignes' => [['produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 100, 'taux_tva' => 20]]])->json();
        $this->retourner($brouillon['id'], [$avecRaison($this->auRebut($brouillon['lignes'][0]['id'], 1))])->assertUnprocessable()
            ->assertJsonPath('message', "Seule la marchandise d'une facture émise peut faire l'objet d'un retour.");

        // Numérotation manuelle : pas de traitement.
        $retourAutre = $this->retourner($facture, [$avecRaison($this->auRebut($ligne, 1))])->assertCreated()->assertJsonPath('client_id', $autre)->json('id');
        $this->chez(fn () => Configuration::where('entite', 'retour_client')->update(['auto_increment' => false]));
        $this->postJson("/api/retours-clients/{$retourAutre}/traiter")->assertUnprocessable()
            ->assertJsonPath('message', 'Numéro requis : activez la numérotation automatique des retours clients (Configuration → Numérotation).');

        // Recherche globale (retours traités) et résumé par raison.
        $groupes = $this->getJson('/api/recherche?q=RC-00001&types=retours_clients')->assertOk()->json('groupes');
        $this->assertSame(['retours_clients', 'RC-00001', '/retours-clients?recherche=RC-00001', 1], [$groupes[0]['type'], $groupes[0]['resultats'][0]['titre'], $groupes[0]['resultats'][0]['lien'], $groupes[0]['resultats'][0]['details']['quantite']]);
        $this->assertSame([['raison_id' => $defectueux, 'libelle' => 'Produit défectueux', 'quantite' => 1]], $this->getJson('/api/retours-clients?recherche=PRD-1')->assertOk()->json('resume.par_raison'));
    }

    public function test_droits_des_retours_clients(): void
    {
        [$facture, $ligne] = $this->factureEmise();
        $retour = $this->retourner($facture, [$this->enStock($ligne, 1)])->assertCreated()->json('id');

        // Rôles système : les droits des factures (« user » consulte seulement).
        $this->agir('user');
        $this->getJson('/api/retours-clients')->assertOk();
        $this->retourner($facture, [$this->enStock($ligne, 1)])->assertForbidden();
        $this->postJson("/api/retours-clients/{$retour}/traiter")->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Traiter un retour client » (Retours clients).');
        $this->agir('manager');
        $this->postJson("/api/retours-clients/{$retour}/traiter")->assertOk();

        // Catalogue : module « Retours clients » des ventes, avec son action nommée.
        $this->agir('admin');
        $modules = collect($this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets'))->firstWhere('cle', 'ventes')['modules'];
        $this->assertSame(['retours_clients.voir', 'retours_clients.creer', 'retours_clients.modifier', 'retours_clients.supprimer', 'retours_clients.traiter'],
            array_column(collect($modules)->firstWhere('cle', 'retours_clients')['permissions'], 'code'));

        // Un groupe qui saisit les retours sans les traiter ni créer de note de crédit.
        $groupe = $this->postJson('/api/roles-custom', ['nom' => 'Comptoir',
            'permissions' => ['retours_clients' => ['can_view' => true, 'can_create' => true, 'can_edit' => true], 'avoirs' => ['can_view' => true]],
            'actions' => ['retours_clients.traiter' => false]])->assertCreated()->json('role_id');
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [$groupe], 'exceptions' => []])->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte->fresh(), ['*']);
        $brouillon = $this->retourner($facture, [$this->auRebut($ligne, 1)])->assertCreated()->json('id');
        $this->postJson("/api/retours-clients/{$brouillon}/traiter")->assertForbidden();
        $this->postJson("/api/retours-clients/{$retour}/crediter")->assertForbidden();
        $this->assertFalse($this->getJson('/api/permissions/me')->assertOk()->json('actions')['retours_clients.traiter']);
    }
}
