<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\Droits;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

/**
 * Export Excel des listes (plan de parité fripv2, S5) : `?format=xlsx` renvoie toutes les lignes de la liste filtrée,
 * pas seulement la page affichée, avec les mêmes droits que sa lecture.
 */
class ExportListesTest extends TestCase
{
    use RefreshDatabase;

    private const VOIR = ['can_view' => true, 'can_create' => false, 'can_edit' => false, 'can_delete' => false];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->administrateur();
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function administrateur(): void
    {
        Sanctum::actingAs(AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    /** Lignes de la feuille (titres compris), chaque cellule en texte. */
    private function feuille(TestResponse $reponse): array
    {
        $reponse->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('.xlsx', (string) $reponse->headers->get('Content-Disposition'));
        $fichier = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($fichier, $reponse->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($fichier) === true);
        $xml = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        unlink($fichier);
        $lignes = [];
        foreach ($xml->sheetData->row as $ligne) {
            // Les cellules vides ne sont pas écrites : la colonne se lit dans la référence (« C2 » : troisième colonne).
            $cellules = [];
            foreach ($ligne->c as $cellule) {
                $lettres = preg_replace('/\d+/', '', (string) $cellule['r']);
                $colonne = 0;
                foreach (str_split($lettres) as $lettre) {
                    $colonne = $colonne * 26 + (ord($lettre) - 64);
                }
                $cellules[$colonne - 1] = isset($cellule->is) ? (string) $cellule->is->t : (string) $cellule->v;
            }
            $lignes[] = $cellules;
        }

        return $lignes;
    }

    public function test_toutes_les_pages_et_les_filtres(): void
    {
        $this->chez(function () {
            for ($i = 1; $i <= 25; $i++) {
                Client::create(['code' => sprintf('C%02d', $i), 'raison_sociale' => sprintf('Client %02d', $i), 'adresse_facturation' => 'Rue', 'ville' => $i <= 5 ? 'Yaoundé' : 'Douala', 'code_postal' => '0']);
            }
        });

        // La liste affiche 20 clients par page ; l'export les donne tous, triés comme la liste.
        $this->assertCount(20, $this->getJson('/api/clients')->json('data'));
        $lignes = $this->feuille($this->get('/api/clients?format=xlsx'));
        $this->assertCount(26, $lignes);
        $this->assertSame(['Code', 'Raison sociale'], array_slice($lignes[0], 0, 2));
        $this->assertSame(['C01', 'Client 01'], array_slice($lignes[1], 0, 2));

        // Filtres compris : la recherche.
        $this->assertCount(2, $this->feuille($this->get('/api/clients?format=xlsx&search=Client%2025')));
    }

    public function test_documents_statuts_lisibles_et_montants(): void
    {
        [$client, $produit] = $this->chez(fn () => [
            Client::create(['code' => 'C1', 'raison_sociale' => 'Quincaillerie Mballa', 'adresse_facturation' => 'Rue', 'ville' => 'Douala', 'code_postal' => '0'])->id,
            ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 100])->id,
        ]);
        foreach ([2, 3] as $quantite) {
            $this->postJson('/api/commandes-client', ['client_id' => $client, 'date_commande' => '2026-11-10',
                'lignes' => [['produit_id' => $produit, 'quantite' => $quantite, 'prix_unitaire_ht' => 100, 'taux_tva' => 0]]])->assertCreated();
        }

        $lignes = $this->feuille($this->get('/api/commandes-client?format=xlsx'));
        $this->assertCount(3, $lignes);
        $colonnes = array_flip($lignes[0]);
        $this->assertSame('Brouillon', $lignes[1][$colonnes['Statut']]);
        $this->assertSame('Quincaillerie Mballa', $lignes[1][$colonnes['Client']]);
        $this->assertEqualsCanonicalizing([200.0, 300.0], [(float) $lignes[1][$colonnes['Montant HT']], (float) $lignes[2][$colonnes['Montant HT']]]);
        $this->assertSame('10/11/2026', $lignes[1][$colonnes['Date']]);

        // Les autres listes s'exportent aussi.
        $this->chez(fn () => Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimencam', 'actif' => true]));
        $this->assertCount(2, $this->feuille($this->get('/api/fournisseurs?format=xlsx')));
        foreach (['devis', 'factures', 'bons-livraison'] as $liste) {
            $this->assertCount(1, $this->feuille($this->get("/api/{$liste}?format=xlsx")), $liste);
        }
    }

    public function test_memes_droits_et_entreprise_seulement(): void
    {
        $this->chez(fn () => Client::create(['code' => 'C1', 'raison_sociale' => 'Chez nous', 'adresse_facturation' => 'Rue', 'ville' => 'Douala', 'code_postal' => '0']));

        // Un compte qui lit les devis les exporte, comme il les lit.
        $roleId = $this->postJson('/api/roles-custom', ['nom' => 'Lecteur devis', 'permissions' => ['devis' => self::VOIR]])->assertCreated()->json('role_id');
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->postJson("/api/users/{$compte->id}/assign-role", ['role_id' => $roleId])->assertOk();
        app(Droits::class)->oublier();
        Sanctum::actingAs($compte->fresh(), ['*']);
        $this->assertSame($this->getJson('/api/devis')->status(), $this->get('/api/devis?format=xlsx')->status());

        // Une autre entreprise n'exporte que ses propres clients.
        $autre = Tenant::factory()->create(['modules' => ['ventes']]);
        Sanctum::actingAs(AdminUser::factory()->for($autre)->create(['role' => 'admin']), ['*']);
        $this->withHeader('X-Tenant-Slug', $autre->slug);
        $this->assertCount(1, $this->feuille($this->get('/api/clients?format=xlsx')));
    }
}
