<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Devis;
use App\Models\Facture;
use App\Models\Fournisseur;
use App\Models\MouvementTenant;
use App\Models\ProduitTenant;
use App\Models\ReglePrix;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Models\Unite;
use App\Models\UniteConversion;
use App\Services\DocumentsVentePdf;
use App\Support\ConversionsUnites;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Unités de vente (phase 2.2) : unités d'un produit (facteur proposé d'après les conversions, enchaînées et dans les
 * deux sens ; prix propre ; unité par défaut), prix calculés par unité, puis une caisse de 12 tout au long des ventes :
 * devis, commande (réservé en unités de stock), facture, bon de livraison, note de crédit, retour (remise en stock en
 * unités), reliquat (commandé au fournisseur en unités, reçu par caisses entières, facturé en caisses) ; droits.
 */
class UnitesVenteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $biere;

    private int $clientId;

    private int $secteurId;

    private int $fournisseurId;

    /** @var array<string, int> code => id */
    private array $unites = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-17 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->chez(function () {
            foreach (['UN' => 'Unité', 'CS' => 'Caisse', 'PAL' => 'Palette', 'KG' => 'Kilogramme'] as $code => $libelle) {
                $this->unites[$code] = Unite::create(['code' => $code, 'libelle' => $libelle, 'decimales' => 0, 'actif' => true])->id;
            }
            // 1 caisse = 12 unités ; 1 palette = 40 caisses (soit 480 unités, par enchaînement).
            UniteConversion::create(['unite_source_id' => $this->unites['CS'], 'unite_cible_id' => $this->unites['UN'], 'facteur' => 12]);
            UniteConversion::create(['unite_source_id' => $this->unites['PAL'], 'unite_cible_id' => $this->unites['CS'], 'facteur' => 40]);
            $fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Brasserie', 'actif' => true]);
            $this->fournisseurId = $fournisseur->id;
            $this->biere = ProduitTenant::create(['numero' => 'BIERE', 'description' => 'Bière 33 cl', 'mesure' => 'UN', 'prix_unitaire' => 100,
                'actif' => true, 'fournisseur_principal_id' => $fournisseur->id])->id;
            DB::table('produit_fournisseur')->insert(['produit_id' => $this->biere, 'fournisseur_id' => $fournisseur->id, 'prix_achat' => 60,
                'fournisseur_principal' => true, 'created_at' => now(), 'updated_at' => now()]);
            $this->clientId = Client::create(['code' => 'CLI', 'raison_sociale' => 'Bar du Port', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id;
            $this->secteurId = Secteur::create(['code' => 'A1', 'nom' => 'Allée 1'])->id;
        });
        $this->agir($this->compte('admin'));
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function compte(string $role, ?Tenant $tenant = null): AdminUser
    {
        return AdminUser::factory()->for($tenant ?? $this->tenant)->create(['role' => $role]);
    }

    private function agir(AdminUser $compte, ?Tenant $tenant = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', ($tenant ?? $this->tenant)->slug);
    }

    /** Ajoute la caisse (facteur proposé) et la palette (prix propre) à la bière. */
    private function unitesDeLaBiere(): void
    {
        $this->postJson("/api/produits/{$this->biere}/unites", ['unite_id' => $this->unites['CS'], 'par_defaut' => true])->assertCreated();
        $this->postJson("/api/produits/{$this->biere}/unites", ['unite_id' => $this->unites['PAL'], 'prix' => 40000])->assertCreated();
    }

    public function test_unites_d_un_produit_et_prix(): void
    {
        // Conversions enchaînées et à rebours, sans tenir compte de la casse ; rien entre des unités qu'aucune ne relie.
        $facteurs = $this->chez(fn () => array_map(fn (array $paire) => app(ConversionsUnites::class)->facteur(...$paire), [['un', 'PAL'], ['PAL', 'UN'], ['CS', 'cs'], ['KG', 'UN']]));
        $this->assertEquals([round(1 / 480, 6), 480.0, 1.0, null], $facteurs);

        // Le référentiel propose ses unités avec le facteur des conversions (enchaînées, dans les deux sens).
        $reponse = $this->getJson("/api/produits/{$this->biere}/unites")->assertOk()->assertJsonPath('mesure', 'UN')->assertJsonCount(0, 'unites');
        $this->assertEquals(['CS' => 12, 'KG' => null, 'PAL' => 480], collect($reponse->json('disponibles'))->pluck('facteur_propose', 'code')->all());

        $caisse = $this->postJson("/api/produits/{$this->biere}/unites", ['unite_id' => $this->unites['CS'], 'par_defaut' => true])->assertCreated()->json();
        $this->assertEquals(['CS', 'Caisse', 12, null, true], [$caisse['code'], $caisse['libelle'], $caisse['facteur'], $caisse['prix'], $caisse['par_defaut']]);
        $palette = $this->postJson("/api/produits/{$this->biere}/unites", ['unite_id' => $this->unites['PAL'], 'prix' => 40000])->assertCreated()->json();
        $this->assertEquals([480, 40000, false], [$palette['facteur'], $palette['prix'], $palette['par_defaut']]);
        $this->postJson("/api/produits/{$this->biere}/unites", ['unite_id' => $this->unites['KG']])->assertUnprocessable()
            ->assertJsonValidationErrors(['facteur' => 'Indiquez combien de UN contient une unité KG : aucune conversion du référentiel ne les relie.']);
        $this->postJson("/api/produits/{$this->biere}/unites", ['unite_id' => $this->unites['UN'], 'facteur' => 1])->assertUnprocessable()
            ->assertJsonValidationErrors(['unite_id' => "UN est l'unité de stock du produit : elle vaut toujours 1."]);
        $this->postJson("/api/produits/{$this->biere}/unites", ['unite_id' => $this->unites['CS'], 'facteur' => 24])->assertUnprocessable()
            ->assertJsonValidationErrors(['unite_id' => 'Le produit se vend déjà en CS.']);

        // Une seule unité par défaut ; la liste des produits porte leurs unités.
        $this->putJson("/api/produits/{$this->biere}/unites/{$palette['id']}", ['par_defaut' => true])->assertOk()->assertJsonPath('par_defaut', true);
        $produit = collect($this->getJson('/api/produits')->assertOk()->json())->firstWhere('id', $this->biere);
        $this->assertEquals([['PAL', 480, true], ['CS', 12, false]], array_map(fn ($u) => [$u['code'], $u['facteur'], $u['par_defaut']], $produit['unites']));

        // Prix par unité : celui de l'unité de stock × facteur, sauf le prix propre de la palette ; la quantité minimale
        // d'une règle se compte en unités de stock (2 caisses = 24 unités, au-delà du seuil de 20).
        $this->chez(fn () => ReglePrix::create(['libelle' => 'Dès 20 bouteilles', 'type' => 'remise_pourcentage', 'valeur' => 10, 'quantite_min' => 20, 'actif' => true]));
        $prix = $this->postJson('/api/prix/calculer', ['client_id' => $this->clientId, 'lignes' => [
            ['produit_id' => $this->biere, 'quantite' => 2, 'unite' => 'cs'],
            ['produit_id' => $this->biere, 'quantite' => 1, 'unite' => 'PAL'],
            ['produit_id' => $this->biere, 'quantite' => 3],
            ['produit_id' => $this->biere, 'quantite' => 1, 'unite' => 'CS'],
        ]])->assertOk()->json();
        $this->assertEquals([1080, 36000, 100, 1200], array_column($prix, 'prix'));
        $this->assertEquals(['CS', 12], [$prix[0]['unite'], $prix[0]['facteur']]);
        $this->assertArrayNotHasKey('unite', $prix[2]);
        $this->postJson('/api/prix/calculer', ['lignes' => [['produit_id' => $this->biere, 'quantite' => 1, 'unite' => 'KG']]])->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes.0.unite' => 'Le produit BIERE ne se vend pas en KG : ajoutez cette unité à ses unités de vente.']);

        // Retrait : les documents gardent leur unité (figée sur les lignes).
        $this->deleteJson("/api/produits/{$this->biere}/unites/{$palette['id']}")->assertOk();
        $this->getJson("/api/produits/{$this->biere}/unites")->assertJsonCount(1, 'unites');
    }

    public function test_une_caisse_tout_au_long_des_ventes(): void
    {
        $this->unitesDeLaBiere();

        // Devis : l'unité saisie (en minuscules) est celle du produit ; l'unité de stock reste sans unité.
        $devis = $this->postJson('/api/devis', ['client_id' => $this->clientId, 'date_devis' => '2026-10-17', 'date_validite' => '2026-11-17', 'lignes' => [
            ['produit_id' => $this->biere, 'quantite' => 2, 'prix_unitaire' => 1200, 'unite' => 'cs'],
            ['produit_id' => $this->biere, 'quantite' => 5, 'prix_unitaire' => 100, 'unite' => 'UN'],
        ]])->assertCreated()->json();
        $this->assertEquals([['CS', 12, 2400], [null, null, 500]], array_map(fn ($l) => [$l['unite'], $l['facteur'], (float) $l['montant_ligne']], $devis['lignes']));
        $this->postJson('/api/devis', ['client_id' => $this->clientId, 'date_devis' => '2026-10-17', 'date_validite' => '2026-11-17', 'lignes' => [
            ['produit_id' => $this->biere, 'quantite' => 1, 'prix_unitaire' => 10, 'unite' => 'KG'],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['lignes.0.unite']);
        $copie = $this->postJson("/api/devis/{$devis['id']}/copier")->assertCreated()->json('devis');
        $this->assertSame('CS', $copie['lignes'][0]['unite']);

        // Commande : 2 caisses + 5 unités réservent 29 unités de stock.
        $commande = $this->postJson('/api/commandes-client', ['client_id' => $this->clientId, 'date_commande' => '2026-10-17', 'lignes' => [
            ['produit_id' => $this->biere, 'quantite' => 2, 'prix_unitaire_ht' => 1200, 'taux_tva' => 0, 'unite' => 'CS'],
            ['produit_id' => $this->biere, 'quantite' => 5, 'prix_unitaire_ht' => 100, 'taux_tva' => 0],
        ]])->assertCreated()->json();
        $this->assertEquals(['CS', 12, 2400], [$commande['lignes'][0]['unite'], $commande['lignes'][0]['facteur'], (float) $commande['lignes'][0]['montant_ht']]);
        $this->postJson("/api/commandes-client/{$commande['id']}/soumettre")->assertOk();
        $manques = $this->postJson("/api/commandes-client/{$commande['id']}/accepter")->assertOk()->json('manques');
        $this->assertEquals([[29, 29]], array_map(fn ($m) => [$m['commande'], $m['manque']], $manques));
        $dispo = collect($this->getJson('/api/disponibilites')->assertOk()->json('data'))->firstWhere('produit_id', $this->biere);
        $this->assertSame([29, -29.0], [$dispo['reserve'], (float) $dispo['disponible']]);

        // Reliquat de la ligne en caisses : 2 caisses, au prix de la caisse ; commandé au fournisseur en unités (24).
        $lignes = $this->getJson("/api/reliquats-clients/depuis-commande/{$commande['id']}")->assertOk()->json('lignes');
        $this->assertEquals(['CS', 12, 2], [$lignes[0]['unite'], $lignes[0]['facteur'], $lignes[0]['quantite']]);
        $reliquat = $this->postJson("/api/reliquats-clients/depuis-commande/{$commande['id']}", ['lignes' => [['ligne_id' => $lignes[0]['ligne_id'], 'quantite' => 2]]])
            ->assertCreated()->json('0');
        $this->assertEquals(['CS', 12, 2, 1200], [$reliquat['unite'], $reliquat['facteur'], $reliquat['quantite'], (float) $reliquat['prix_unitaire_ht']]);
        $dispo = collect($this->getJson('/api/disponibilites')->json('data'))->firstWhere('produit_id', $this->biere);
        $this->assertSame([5, 24], [$dispo['reserve'], $dispo['reliquats']]);
        $commandeFournisseur = $this->postJson('/api/reliquats-clients/commander', ['ids' => [$reliquat['id']]])->assertCreated()->json('0');
        $this->assertSame(24, $commandeFournisseur['lignes'][0]['quantite_commandee']);
        $this->postJson("/api/commandes-fournisseur/{$commandeFournisseur['id']}/valider")->assertOk();
        $ligneFournisseur = $commandeFournisseur['lignes'][0]['id'];

        // Réception : 18 unités donnent 1 caisse entière ; 6 de plus complètent la seconde.
        $recevoir = fn (int $quantite) => $this->postJson('/api/receptions', ['com_four_ligne_id' => $ligneFournisseur, 'date_reception' => '2026-10-17', 'quantite_recue' => $quantite])->assertCreated();
        $etat = fn () => collect($this->getJson('/api/reliquats-clients?per_page=100')->json('data'))->firstWhere('id', $reliquat['id']);
        $recevoir(18);
        $this->assertSame(['recu_partiel', 1], [$etat()['statut'], $etat()['quantite_recue']]);
        $recevoir(6);
        $this->assertSame(['recu', 2], [$etat()['statut'], $etat()['quantite_recue']]);
        $facturesReliquat = $this->postJson('/api/reliquats-clients/facturer', ['ids' => [$reliquat['id']], 'date_facture' => '2026-10-17'])->assertCreated()->json();
        $ligneReliquat = $this->getJson("/api/factures/{$facturesReliquat[0]['id']}")->json('lignes.0');
        $this->assertEquals(['CS', 12, 2, 2400], [$ligneReliquat['unite'], $ligneReliquat['facteur'], $ligneReliquat['quantite'], (float) $ligneReliquat['montant_ht']]);

        // Facture de la commande, puis bon de livraison : les lignes gardent la caisse.
        $facture = $this->postJson("/api/factures/commande/{$commande['id']}")->assertCreated()->json();
        $this->assertEquals([['CS', 12], [null, null]], array_map(fn ($l) => [$l['unite'], $l['facteur']], $facture['lignes']));
        // PDF : chaque quantité dit son unité dès qu'une ligne est dans une unité de vente ; sinon rien ne change.
        $html = $this->chez(fn () => view('pdf.document-vente', app(DocumentsVentePdf::class)->facture(Facture::find($facture['id'])))->render());
        $this->assertMatchesRegularExpression('#>\s*2 CS</td>#', $html);
        $this->assertMatchesRegularExpression('#>\s*5 UN</td>#', $html);
        $simple = $this->postJson('/api/devis', ['client_id' => $this->clientId, 'date_devis' => '2026-10-17', 'date_validite' => '2026-11-17', 'lignes' => [
            ['produit_id' => $this->biere, 'quantite' => 5, 'prix_unitaire' => 100],
        ]])->assertCreated()->json('id');
        $html = $this->chez(fn () => view('pdf.document-vente', app(DocumentsVentePdf::class)->devis(Devis::find($simple)))->render());
        $this->assertStringContainsString('>5</td>', $html);
        $this->assertStringNotContainsString('>5 UN</td>', $html);
        $this->postJson("/api/factures/{$facture['id']}/emettre")->assertOk();
        $bon = $this->postJson("/api/factures/{$facture['id']}/creer-bl")->assertCreated()->json();
        $this->assertEquals([['CS', 12, 2], [null, null, 5]], array_map(fn ($l) => [$l['unite'], $l['facteur'], $l['quantite_a_livrer']], $bon['lignes']));

        // Note de crédit d'une caisse : dans l'unité facturée.
        $ligneCaisse = collect($facture['lignes'])->firstWhere('unite', 'CS');
        $avoir = $this->postJson('/api/avoirs', ['facture_id' => $facture['id'], 'date_avoir' => '2026-10-17', 'lignes' => [['facture_ligne_id' => $ligneCaisse['id'], 'quantite' => 1]]])
            ->assertCreated()->json();
        $this->assertEquals(['CS', 12, 1, 1200], [$avoir['lignes'][0]['unite'], $avoir['lignes'][0]['facteur'], $avoir['lignes'][0]['quantite'], (float) $avoir['lignes'][0]['montant_ht']]);

        // Retour d'une caisse remise en stock : 12 unités reviennent en stock.
        $retour = $this->postJson('/api/retours-clients', ['facture_id' => $facture['id'], 'date_retour' => '2026-10-17', 'lignes' => [
            ['facture_ligne_id' => $ligneCaisse['id'], 'quantite' => 1, 'traitement' => 'remise_en_stock', 'secteur_id' => $this->secteurId],
        ]])->assertCreated()->json();
        $this->assertSame('CS', $retour['lignes'][0]['unite']);
        $this->postJson("/api/retours-clients/{$retour['id']}/traiter")->assertOk();
        $mouvement = $this->chez(fn () => MouvementTenant::query()->where('motif', 'like', 'Retour client%')->first());
        $this->assertEquals([12, 'UN'], [(float) $mouvement->quantite, $mouvement->unite_mesure]);
        // Il ne repart pas chez le fournisseur relié au retour client : le retour fournisseur se compte en unités de stock.
        $this->postJson('/api/retours-fournisseurs', ['retour_client_id' => $retour['id'], 'fournisseur_id' => $this->fournisseurId, 'type' => 'remboursement', 'date_retour' => '2026-10-17', 'lignes' => [
            ['retour_client_ligne_id' => $retour['lignes'][0]['id'], 'quantite' => 1],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['lignes.0.retour_client_ligne_id' => 'BIERE a été rapporté en CS : faites ce retour au fournisseur sans le relier au retour client, en UN.']);
    }

    public function test_droits_et_isolation(): void
    {
        $this->unitesDeLaBiere();
        $caisse = collect($this->getJson("/api/produits/{$this->biere}/unites")->json('unites'))->firstWhere('code', 'CS');

        $vendeur = $this->compte('manager');
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => ['produits.voir' => true, 'produits.modifier' => false]])->assertOk();
        $this->agir($vendeur);
        $this->getJson("/api/produits/{$this->biere}/unites")->assertOk()->assertJsonCount(2, 'unites');
        $this->postJson("/api/produits/{$this->biere}/unites", ['unite_id' => $this->unites['KG'], 'facteur' => 1])->assertForbidden();
        $this->putJson("/api/produits/{$this->biere}/unites/{$caisse['id']}", ['facteur' => 24])->assertForbidden();
        $this->deleteJson("/api/produits/{$this->biere}/unites/{$caisse['id']}")->assertForbidden();

        $autre = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->agir($this->compte('admin', $autre), $autre);
        $this->getJson("/api/produits/{$this->biere}/unites")->assertNotFound();
        $this->deleteJson("/api/produits/{$this->biere}/unites/{$caisse['id']}")->assertNotFound();
    }
}
