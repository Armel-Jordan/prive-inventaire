<?php

namespace Tests\Feature\Rapports;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Devis;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\ScanTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bibliothèque des rapports (phases 5.1 et 5.2) : catalogue selon les droits et les modules de l'entreprise ; ventes par
 * période, client, produit et catégorie ; devis ; commandes en cours ; clients sans achat ; achats ; stock ; produits
 * dormants ; paiements par mode ; anglais ; CSV, Excel et PDF ; isolation.
 */
class BibliothequeRapportsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    /** @var array<string, int> */
    private array $clients = [];

    /** @var array<string, int> */
    private array $produits = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-17 09:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $this->agir($this->admin);
        $this->chez(function () {
            foreach ([['C-1', 'Bar du Port'], ['C-2', 'Épicerie Centrale'], ['C-3', 'Hôtel Dormant']] as [$code, $nom]) {
                $this->clients[$code] = Client::create(['code' => $code, 'raison_sociale' => $nom, 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'actif' => true])->id;
            }
            foreach ([['BIERE', 'Bière 33 cl', 'Boissons', 10], ['VIN', 'Vin rouge', 'Boissons', 5], ['SEL', 'Sel fin', 'Épicerie', 20]] as [$numero, $description, $categorie, $seuil]) {
                $this->produits[$numero] = ProduitTenant::create(['numero' => $numero, 'description' => $description, 'categorie' => $categorie,
                    'mesure' => 'UN', 'prix_unitaire' => 100, 'seuil_alerte' => $seuil, 'actif' => true])->id;
            }
            // Stock : 30 bières, 2 vins (sous le seuil de 5), 12 sels.
            foreach ([['BIERE', 30], ['VIN', 2], ['SEL', 12]] as [$numero, $quantite]) {
                ScanTenant::create(['numero' => $numero, 'quantite' => $quantite, 'employe' => 'Paul', 'secteur' => 'A1', 'date_saisie' => now()]);
            }
        });

        // Ventes : en septembre au Bar du Port ; en octobre au Bar du Port et à l'Épicerie (HT, sans taxe).
        $this->facture('C-1', '2026-09-20', [['BIERE', 10, 100]]);
        $octobre = $this->facture('C-1', '2026-10-05', [['BIERE', 5, 100], ['VIN', 2, 300]]);
        $this->facture('C-2', '2026-10-10', [['BIERE', 1, 100]]);
        $this->postJson("/api/factures/{$octobre}/paiement", ['montant' => 600, 'date_paiement' => '2026-10-06', 'mode_paiement' => 'especes'])->assertOk();
        $this->postJson("/api/factures/{$octobre}/paiement", ['montant' => 500, 'date_paiement' => '2026-10-07', 'mode_paiement' => 'carte'])->assertOk();
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $compte->tenant?->slug ?? $this->tenant->slug);
    }

    /** Facture émise ; lignes [produit, quantité, prix HT]. */
    private function facture(string $client, string $date, array $lignes): int
    {
        $id = $this->postJson('/api/factures', ['client_id' => $this->clients[$client], 'date_facture' => $date, 'lignes' => array_map(fn ($l) => [
            'produit_id' => $this->produits[$l[0]], 'quantite' => $l[1], 'prix_unitaire_ht' => $l[2], 'taux_tva' => 0], $lignes)])->assertCreated()->json('id');
        $this->postJson("/api/factures/{$id}/emettre")->assertOk();

        return $id;
    }

    private function rapport(string $code, array $parametres = []): array
    {
        return $this->getJson("/api/rapports/bibliotheque/{$code}?".http_build_query($parametres))->assertOk()->json();
    }

    /** Lignes d'un rapport réduites à quelques colonnes. */
    private function colonnes(array $rapport, array $cles): array
    {
        return array_map(fn (array $l) => array_map(fn ($cle) => $l[$cle], $cles), $rapport['lignes']);
    }

    public function test_catalogue_selon_les_droits_et_les_modules(): void
    {
        $catalogue = $this->getJson('/api/rapports/bibliotheque')->assertOk()->json();
        $categories = collect($catalogue['categories'])->keyBy('code');
        $this->assertSame(['ventes', 'clients', 'achats', 'inventaire', 'finance'], $categories->keys()->all());
        // Les catégories de produits, pour les rapports filtrés par catégorie.
        $this->assertSame(['Boissons', 'Épicerie'], $catalogue['categories_produits']);
        $this->assertSame(12, $categories->sum(fn ($c) => count($c['rapports'])));
        $periode = collect($categories['ventes']['rapports'])->firstWhere('code', 'ventes-par-periode');
        $this->assertSame(['Ventes par période', ['du', 'au', 'regroupement'], '2026-10-01'], [$periode['titre'], array_column($periode['parametres'], 'cle'), $periode['parametres'][0]['defaut']]);
        $this->assertSame('Sales by period', collect($this->getJson('/api/rapports/bibliotheque?langue=en')->json('categories.0.rapports'))->firstWhere('code', 'ventes-par-periode')['titre']);

        // Sans le droit de voir les factures : ni les rapports de ventes, ni leur adresse.
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => ['factures.voir' => false]])->assertOk();
        $this->agir($vendeur);
        $codes = collect($this->getJson('/api/rapports/bibliotheque')->json('categories'))->pluck('rapports')->flatten(1)->pluck('code');
        $this->assertNotContains('ventes-par-periode', $codes);
        $this->assertContains('devis-par-statut', $codes);
        $this->getJson('/api/rapports/bibliotheque/ventes-par-periode')->assertNotFound();

        // Sans le droit de voir les rapports : ni le catalogue, ni un rapport.
        $this->agir($this->admin);
        $lecteur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$lecteur->id}", ['groupes' => [], 'exceptions' => ['rapports.voir' => false]])->assertOk();
        $this->agir($lecteur);
        $this->getJson('/api/rapports/bibliotheque')->assertForbidden();
        $this->getJson('/api/rapports/bibliotheque/devis-par-statut')->assertForbidden();

        // Entreprise sans le module des achats : pas de rapports d'achats.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']));
        $codes = collect($this->getJson('/api/rapports/bibliotheque')->json('categories'))->pluck('code');
        $this->assertNotContains('achats', $codes);
        $this->getJson('/api/rapports/bibliotheque/achats-par-fournisseur')->assertNotFound();
        // Ses ventes : rien des factures de la première entreprise.
        $this->assertSame([], $this->rapport('ventes-par-periode', ['du' => '2026-01-01'])['lignes']);
    }

    public function test_rapports_de_ventes(): void
    {
        // Par période : septembre et octobre, par mois ; totaux.
        $periode = $this->rapport('ventes-par-periode', ['du' => '2026-09-01', 'au' => '2026-10-31', 'regroupement' => 'mois']);
        $this->assertEquals([['2026-09', 1, 1000], ['2026-10', 2, 1200]], $this->colonnes($periode, ['periode', 'factures', 'montant_ht']));
        $this->assertEquals(['factures' => 3, 'montant_ht' => 2200], array_intersect_key($periode['totaux'], array_flip(['factures', 'montant_ht'])));
        $this->assertSame(['periode', 'factures', 'montant_ht', 'montant_tva', 'montant_ttc', 'montant_credite'], array_column($periode['colonnes'], 'cle'));
        // Par semaine ISO : S en français, W en anglais.
        $semaines = ['du' => '2026-09-01', 'au' => '2026-10-31', 'regroupement' => 'semaine'];
        $this->assertEquals([['2026-S38', 1], ['2026-S41', 2]], $this->colonnes($this->rapport('ventes-par-periode', $semaines), ['periode', 'factures']));
        $this->assertSame(['2026-W38', '2026-W41'], array_column($this->rapport('ventes-par-periode', $semaines + ['langue' => 'en'])['lignes'], 'periode'));
        // Par jour (défaut), la période par défaut : le mois en cours.
        $this->assertEquals([['2026-10-05', 1], ['2026-10-10', 1]], $this->colonnes($this->rapport('ventes-par-periode'), ['periode', 'factures']));
        $this->getJson('/api/rapports/bibliotheque/ventes-par-periode?regroupement=annee')->assertUnprocessable()->assertJsonValidationErrors(['regroupement']);

        // Par client : le plus gros d'abord ; payé et reste.
        $clients = $this->rapport('ventes-par-client', ['du' => '2026-09-01']);
        $this->assertEquals([['C-1', 2, 2100, 1100, 1000], ['C-2', 1, 100, 0, 100]], $this->colonnes($clients, ['code', 'factures', 'montant_ht', 'montant_paye', 'reste_a_payer']));
        $this->assertCount(1, $this->rapport('ventes-par-client', ['du' => '2026-09-01', 'limite' => 1])['lignes']);

        // Par produit et par catégorie.
        $produits = $this->rapport('ventes-par-produit', ['du' => '2026-09-01']);
        $this->assertEquals([['BIERE', 16, 1600, 100], ['VIN', 2, 600, 300]], $this->colonnes($produits, ['numero', 'quantite', 'chiffre_affaires', 'prix_moyen']));
        $this->assertSame(['BIERE'], array_column($this->rapport('ventes-par-produit', ['du' => '2026-09-01', 'categorie' => 'Boissons', 'limite' => 1])['lignes'], 'numero'));
        $this->assertSame([], $this->rapport('ventes-par-produit', ['du' => '2026-09-01', 'categorie' => 'Épicerie'])['lignes']);
        $categories = $this->rapport('ventes-par-categorie', ['du' => '2026-09-01']);
        $this->assertEquals([['Boissons', 2, 2200, 100]], $this->colonnes($categories, ['categorie', 'produits', 'chiffre_affaires', 'part']));

        // Paiements par mode.
        $modes = $this->rapport('encaissements-par-mode');
        $this->assertEquals([['Espèces', 1, 600], ['Carte', 1, 500]], $this->colonnes($modes, ['mode', 'paiements', 'montant']));
        $this->assertSame('Cash', $this->rapport('encaissements-par-mode', ['langue' => 'en'])['lignes'][0]['mode']);
    }

    public function test_devis_commandes_clients_achats_et_stock(): void
    {
        $this->chez(function () {
            foreach ([['envoye', 1000], ['accepte', 3000], ['refuse', 500], ['brouillon', 200]] as $i => [$statut, $montant]) {
                Devis::create(['numero' => "DV-{$i}", 'client_id' => $this->clients['C-1'], 'date_devis' => '2026-10-02', 'date_validite' => '2026-11-02', 'statut' => $statut, 'montant_total' => $montant, 'taux_change' => 1]);
            }
            $fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Brasserie du Nord', 'actif' => true]);
            $commande = ComFourEntete::create(['numero' => 'BC-1', 'fournisseur_id' => $fournisseur->id, 'date_commande' => '2026-10-01', 'date_livraison_prevue' => '2026-10-10',
                'statut' => ComFourEntete::STATUT_PARTIELLE, 'montant_total' => 1000, 'taux_change' => 1, 'created_by' => $this->admin->id]);
            ComFourLigne::create(['com_four_entete_id' => $commande->id, 'produit_id' => $this->produits['BIERE'], 'quantite_commandee' => 10, 'quantite_recue' => 4, 'prix_unitaire' => 100, 'montant_ligne' => 1000]);
        });

        // Devis : nombre, montant, part des devis sortis (brouillon à part).
        $devis = $this->rapport('devis-par-statut');
        $this->assertEquals([['Brouillon', 1, 200, null], ['Envoyé', 1, 1000, 33.3], ['Accepté', 1, 3000, 33.3], ['Refusé', 1, 500, 33.3], ['Expiré', 0, 0, 0]],
            $this->colonnes($devis, ['statut', 'devis', 'montant', 'part']));
        $this->assertSame('Accepted', $this->rapport('devis-par-statut', ['langue' => 'en'])['lignes'][2]['statut']);

        // Clients sans achat depuis 30 jours : l'Hôtel (jamais facturé) ; depuis 10 jours : aussi le Bar du Port (5 octobre).
        $this->assertSame(['Hôtel Dormant'], array_column($this->rapport('clients-inactifs', ['jours' => 30])['lignes'], 'client'));
        $this->assertEqualsCanonicalizing(['Hôtel Dormant', 'Bar du Port'], array_column($this->rapport('clients-inactifs', ['jours' => 10])['lignes'], 'client'));

        // Achats : commandé, reçu ; commande en cours en retard de 7 jours, reçue à 40 %.
        $this->assertEquals([['Brasserie du Nord', 1, 1000, 400, 40]], $this->colonnes($this->rapport('achats-par-fournisseur'), ['fournisseur', 'commandes', 'commande', 'recu', 'part_recue']));
        $this->assertEquals([['BC-1', 7, 40, 1000]], $this->colonnes($this->rapport('commandes-fournisseur-en-cours'), ['numero', 'retard', 'part_recue', 'montant']));

        // Stock : sous le seuil ; produits dormants (le sel n'a jamais été vendu).
        $this->assertEquals([['BIERE', 30, ''], ['SEL', 12, 'Sous le seuil'], ['VIN', 2, 'Sous le seuil']], $this->colonnes($this->rapport('stock-produits'), ['numero', 'stock', 'etat']));
        $this->assertSame(['SEL', 'VIN'], array_column($this->rapport('stock-produits', ['etat' => 'sous_seuil'])['lignes'], 'numero'));
        // Sans vente depuis 10 jours : le sel (jamais vendu) puis le vin (5 octobre) ; la bière s'est vendue le 10.
        $this->assertEquals([['SEL', 12, null, null], ['VIN', 2, '2026-10-05', 12]],
            $this->colonnes($this->rapport('produits-dormants', ['jours' => 10]), ['numero', 'stock', 'derniere_vente', 'jours']));
    }

    public function test_csv_excel_et_pdf(): void
    {
        $csv = $this->get('/api/rapports/bibliotheque/ventes-par-client?du=2026-09-01&format=csv')->assertOk()->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFCode;Client;Factures;\"Hors taxes\";\"Toutes taxes\";Payé;\"Reste à payer\"", $csv);
        $this->assertStringContainsString('C-1;"Bar du Port";2;2100,00;2100,00;1100,00;1000,00', $csv);
        $this->assertStringContainsString('Total;;3;2200,00;2200,00;1100,00;1100,00', $csv);

        $xlsx = $this->get('/api/rapports/bibliotheque/ventes-par-client?du=2026-09-01&format=xlsx&langue=en')->assertOk();
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsx->headers->get('Content-Type'));
        $contenu = $xlsx->getContent();
        $this->assertStringStartsWith("PK\x03\x04", $contenu);
        foreach (['xl/worksheets/sheet1.xml', 'Sales by customer', '<t xml:space="preserve">Bar du Port</t>', '<v>2100</v>', '<t xml:space="preserve">Balance due</t>'] as $attendu) {
            $this->assertStringContainsString($attendu, $contenu);
        }
        if (class_exists(\ZipArchive::class)) {
            $chemin = tempnam(sys_get_temp_dir(), 'xlsx');
            file_put_contents($chemin, $contenu);
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($chemin) === true);
            $this->assertStringContainsString('Bar du Port', $zip->getFromName('xl/worksheets/sheet1.xml'));
            $zip->close();
            unlink($chemin);
        }

        $pdf = $this->get('/api/rapports/bibliotheque/stock-produits?format=pdf')->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }
}
