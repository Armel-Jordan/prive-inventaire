<?php

namespace Tests\Feature\Inventaire;

use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\EtiquetteFormat;
use App\Models\GroupeTaxe;
use App\Models\GroupeTaxeLigne;
use App\Models\ListePrix;
use App\Models\ListePrixProduit;
use App\Models\ProduitCodeBarre;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Models\TenantTaxe;
use App\Services\Etiquettes;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Étiquettes des produits (phase 2.1) : formats d'origine créés au premier affichage, formats contrôlés (grille dans la
 * page, contenu dans l'étiquette, nom unique), un par défaut et un actif au moins ; impression en PDF (code choisi,
 * principal ou numéro ; prix d'une liste de prix, taxes comprises ; exemplaires ; planche entamée) ; droits et isolation.
 */
class EtiquettesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private int $ciment;

    private int $fer;

    private int $carton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $this->chez(function () {
            TenantParametres::create(['nom_entreprise' => 'Quincaillerie Test', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF']);
            $this->ciment = ProduitTenant::create(['numero' => 'CIM-50', 'description' => 'Ciment 50 kg', 'prix_unitaire' => 100, 'mesure' => 'SAC', 'actif' => true])->id;
            $this->fer = ProduitTenant::create(['numero' => 'FER-12', 'description' => 'Fer à béton 12 mm', 'prix_unitaire' => 50, 'mesure' => 'BARRE', 'actif' => true])->id;
            ProduitCodeBarre::create(['produit_id' => $this->ciment, 'code' => '4006381333931', 'type' => 'ean13', 'quantite' => 1, 'principal' => true]);
            $this->carton = ProduitCodeBarre::create(['produit_id' => $this->ciment, 'code' => 'CARTON-CIM-50', 'type' => 'code128', 'quantite' => 12])->id;
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

    private function format(string $nom): array
    {
        return collect($this->getJson('/api/etiquettes/formats')->assertOk()->json('formats'))->firstWhere('nom', $nom);
    }

    /** Lignes du journal des modifications sur les formats. */
    private function journal(): int
    {
        return $this->chez(fn () => AuditLog::query()->where('model_type', "Format d'étiquette")->count());
    }

    /** Nombre de pages d'un PDF. */
    private function pages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page\b(?!s)#', $pdf);
    }

    public function test_formats_d_origine_et_gestion(): void
    {
        // Les formats d'origine, créés une seule fois ; « Complet » par défaut.
        $reponse = $this->getJson('/api/etiquettes/formats')->assertOk()->assertJsonCount(4, 'formats');
        $this->assertSame(['Complet', 'Sans prix', 'Code-barres seul', 'Planche A4 (3 × 7)'], array_column($reponse->json('formats'), 'nom'));
        $this->assertEquals(['description', 'code_barres', 'chiffres', 'numero', 'prix'], $reponse->json('formats.0.contenu'));
        $this->assertTrue($reponse->json('formats.0.par_defaut'));
        $this->assertSame(Etiquettes::CONTENUS, $reponse->json('contenus'));
        $this->getJson('/api/etiquettes/formats')->assertJsonCount(4, 'formats');
        // Personne ne les a saisis : rien au journal des modifications.
        $this->assertSame(0, $this->journal());

        // Une grille qui dépasse la page ; un contenu qui ne tient pas dans l'étiquette ; un nom déjà pris.
        $planche = ['nom' => 'Planche 3 × 8', 'support' => 'planche', 'page' => 'A4', 'largeur_mm' => 70, 'hauteur_mm' => 37, 'colonnes' => 3, 'rangees' => 8,
            'marge_haut_mm' => 0.5, 'marge_gauche_mm' => 7.25, 'espace_colonnes_mm' => 2.5, 'contenu' => ['description', 'code_barres', 'prix_taxes']];
        $this->postJson('/api/etiquettes/formats', $planche)->assertUnprocessable()
            ->assertJsonValidationErrors(['colonnes' => 'La grille est trop large : 222,25 mm, marge comprise, pour une page de 210,00 mm.']);
        // 30 × 16 mm : 16 − 2 × 0,96 de marge − 2,65 (chiffres) − 4,41 (numéro et prix) − 3,09 (description) − 2 × 0,3 de blanc.
        $minuscule = ['nom' => 'Minuscule', 'support' => 'rouleau', 'largeur_mm' => 30, 'hauteur_mm' => 16, 'contenu' => ['description', 'code_barres', 'chiffres', 'numero', 'prix']];
        $this->postJson('/api/etiquettes/formats', $minuscule)->assertUnprocessable()
            ->assertJsonValidationErrors(['contenu' => "L'étiquette est trop petite pour tout ce qu'elle porte : il resterait 3,3 mm pour le code-barres (au moins 5 mm). Réduisez le texte ou retirez un élément."]);
        $this->postJson('/api/etiquettes/formats', ['hauteur_mm' => 12] + $minuscule)->assertUnprocessable()
            ->assertJsonValidationErrors(['contenu' => "L'étiquette est trop petite pour tout ce qu'elle porte : il ne resterait rien pour le code-barres (au moins 5 mm). Réduisez le texte ou retirez un élément."]);
        $this->postJson('/api/etiquettes/formats', ['nom' => 'Complet', 'support' => 'rouleau', 'largeur_mm' => 50, 'hauteur_mm' => 30, 'contenu' => ['code_barres']])
            ->assertUnprocessable()->assertJsonValidationErrors(['nom' => "Un format s'appelle déjà « Complet »."]);

        // Une planche qui tient : les taxes sans prix sont retirées du contenu ; elle devient le format par défaut.
        $cree = $this->postJson('/api/etiquettes/formats', ['espace_colonnes_mm' => 0, 'marge_gauche_mm' => 0, 'par_defaut' => true] + $planche)
            ->assertCreated()->assertJsonPath('ordre', 5)->json();
        $this->assertEquals(['description', 'code_barres'], $cree['contenu']);
        $this->assertSame([3, 8, 'A4'], [$cree['colonnes'], $cree['rangees'], $cree['page']]);
        $this->assertFalse($this->format('Complet')['par_defaut']);

        // Désactivé, il cède sa place de format par défaut au premier format actif.
        $this->putJson("/api/etiquettes/formats/{$cree['id']}", ['actif' => false])->assertOk()->assertJsonPath('par_defaut', false);
        $this->assertTrue($this->format('Complet')['par_defaut']);
        // Un rouleau garde une étiquette par page, sans marges.
        $rouleau = $this->putJson("/api/etiquettes/formats/{$cree['id']}", ['support' => 'rouleau', 'largeur_mm' => 50, 'hauteur_mm' => 30])->assertOk()->json();
        $this->assertEquals([1, 1, null, 0], [$rouleau['colonnes'], $rouleau['rangees'], $rouleau['page'], $rouleau['marge_gauche_mm']]);

        // Il reste toujours un format actif.
        foreach (['Sans prix', 'Code-barres seul', 'Planche A4 (3 × 7)'] as $nom) {
            $this->deleteJson('/api/etiquettes/formats/'.$this->format($nom)['id'])->assertOk();
        }
        $complet = $this->format('Complet');
        $this->deleteJson("/api/etiquettes/formats/{$complet['id']}")->assertUnprocessable()
            ->assertJsonValidationErrors(['format' => 'Gardez au moins un format actif.']);
        $this->putJson("/api/etiquettes/formats/{$complet['id']}", ['actif' => false])->assertUnprocessable()
            ->assertJsonValidationErrors(['actif' => 'Gardez au moins un format actif.']);
        $this->getJson('/api/etiquettes/formats')->assertJsonCount(2, 'formats');
        // Au journal : la création, trois modifications (dont « Complet » redevenu le format par défaut), trois retraits.
        $this->assertSame(7, $this->journal());
    }

    public function test_contenu_et_impression(): void
    {
        $this->chez(function () {
            $liste = ListePrix::create(['nom' => 'Détail', 'ajustement_pourcentage' => 10, 'precision' => 0, 'actif' => true]);
            ListePrixProduit::create(['liste_prix_id' => $liste->id, 'produit_id' => $this->fer, 'prix' => 60]);
            $tva = TenantTaxe::create(['nom' => 'TVA', 'taux' => 19.25]);
            $groupe = GroupeTaxe::create(['code' => 'TVA', 'nom' => 'TVA', 'par_defaut' => true, 'actif' => true]);
            GroupeTaxeLigne::create(['groupe_taxe_id' => $groupe->id, 'tenant_taxe_id' => $tva->id, 'ordre' => 1, 'applique_sur' => 'base']);
        });
        $listeId = $this->chez(fn () => ListePrix::query()->value('id'));
        $complet = $this->format('Complet');

        // Le code choisi, sinon le principal, sinon le numéro ; le prix de la liste (saisi ou ajusté de 10 %).
        $etiquettes = $this->chez(fn () => app(Etiquettes::class)->etiquettes(EtiquetteFormat::find($complet['id']), [
            ['produit_id' => $this->ciment, 'copies' => 2],
            ['produit_id' => $this->ciment, 'code_barre_id' => $this->carton, 'copies' => 1],
            ['produit_id' => $this->fer, 'copies' => 1],
        ], ['liste_prix_id' => $listeId]));
        $this->assertCount(4, $etiquettes);
        $this->assertSame(['4006381333931', '4006381333931', 'CARTON-CIM-50', 'FER-12'], array_column($etiquettes, 'code'));
        $this->assertSame(['110 FCFA', '110 FCFA', '110 FCFA', '60 FCFA'], array_column($etiquettes, 'prix'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $etiquettes[3]['image']);
        $this->assertSame('Fer à béton 12 mm', $etiquettes[3]['description']);
        // Textes ajustés : le numéro ne répète pas les chiffres du code (le fer n'a que son numéro) ; il reste sous un EAN.
        $this->assertSame(['Fer à béton 12 mm'], $etiquettes[3]['texte']['description']);
        $this->assertSame([null, 'CIM-50'], [$etiquettes[3]['texte']['numero'], $etiquettes[0]['texte']['numero']]);

        // Une longue description : coupée sur une ligne (rouleau 38 × 22), répartie entre les mots sur deux (planche).
        $peinture = $this->chez(fn () => ProduitTenant::create(['numero' => 'PEINT-20', 'prix_unitaire' => 25000, 'actif' => true, 'mesure' => 'SEAU',
            'description' => 'Peinture acrylique blanche mate pour murs intérieurs et plafonds, seau de 20 litres'])->id);
        $textes = fn (string $nom) => $this->chez(fn () => app(Etiquettes::class)->etiquettes(EtiquetteFormat::find($this->format($nom)['id']),
            [['produit_id' => $peinture, 'copies' => 1]]))[0]['texte']['description'];
        $uneLigne = $textes('Complet');
        $this->assertCount(1, $uneLigne);
        $this->assertMatchesRegularExpression('/^Peinture acrylique .+…$/u', $uneLigne[0]);
        $deuxLignes = $textes('Planche A4 (3 × 7)');
        $this->assertCount(2, $deuxLignes);
        $this->assertStringStartsWith($deuxLignes[0].' ', 'Peinture acrylique blanche mate pour murs intérieurs et plafonds, seau de 20 litres');
        $this->assertStringEndsWith('…', $deuxLignes[1]);
        $this->assertGreaterThan(mb_strlen($uneLigne[0]), mb_strlen($deuxLignes[0].$deuxLignes[1]));

        // Taxes comprises (groupe par défaut : 110 × 1,1925 = 131,18, sans décimales en XAF) ; prix de base sans liste.
        $ttc = $this->postJson('/api/etiquettes/formats', ['nom' => 'Rayon TTC', 'support' => 'rouleau', 'largeur_mm' => 50, 'hauteur_mm' => 30,
            'contenu' => ['description', 'code_barres', 'numero', 'prix', 'prix_taxes', 'date']])->assertCreated()->json();
        $prix = fn (array $options) => array_column($this->chez(fn () => app(Etiquettes::class)->etiquettes(EtiquetteFormat::find($ttc['id']),
            [['produit_id' => $this->ciment, 'copies' => 1], ['produit_id' => $this->fer, 'copies' => 1]], $options)), 'prix');
        $this->assertSame(['131 FCFA TTC', '72 FCFA TTC'], $prix(['liste_prix_id' => $listeId]));
        $this->assertSame(['119 FCFA incl. tax', '60 FCFA incl. tax'], $prix(['langue' => 'en']));

        // Le PDF : une page par étiquette sur un rouleau.
        $pdf = $this->postJson('/api/etiquettes/imprimer', ['format_id' => $complet['id'], 'liste_prix_id' => $listeId, 'lignes' => [
            ['produit_id' => $this->ciment, 'copies' => 3], ['produit_id' => $this->fer, 'copies' => 2],
        ]])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('etiquettes-complet-5.pdf', $pdf->headers->get('Content-Disposition'));
        $this->assertSame(5, $this->pages($pdf->getContent()));

        // Une planche entamée : de la position 20 à la 21, puis une nouvelle planche.
        $planche = $this->format('Planche A4 (3 × 7)');
        $pdf = $this->postJson('/api/etiquettes/imprimer', ['format_id' => $planche['id'], 'depart' => 20, 'contours' => true, 'lignes' => [
            ['produit_id' => $this->ciment, 'copies' => 3],
        ]])->assertOk();
        $this->assertSame(2, $this->pages($pdf->getContent()));
        $this->postJson('/api/etiquettes/imprimer', ['format_id' => $planche['id'], 'depart' => 22, 'lignes' => [['produit_id' => $this->ciment, 'copies' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['depart' => 'La position de départ va de 1 à 21.']);

        // Refus : code d'un autre produit, trop d'étiquettes, format désactivé, liste inconnue.
        $this->postJson('/api/etiquettes/imprimer', ['format_id' => $complet['id'], 'lignes' => [['produit_id' => $this->fer, 'code_barre_id' => $this->carton, 'copies' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['lignes.0.code_barre_id' => "Ce code-barres n'est pas celui du produit FER-12."]);
        $this->postJson('/api/etiquettes/imprimer', ['format_id' => $complet['id'], 'lignes' => [
            ['produit_id' => $this->ciment, 'copies' => 1000], ['produit_id' => $this->fer, 'copies' => 1001],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['lignes' => 'Au plus 2 000 étiquettes par impression (2 001 demandées).']);
        $this->putJson("/api/etiquettes/formats/{$ttc['id']}", ['actif' => false])->assertOk();
        $this->postJson('/api/etiquettes/imprimer', ['format_id' => $ttc['id'], 'lignes' => [['produit_id' => $this->fer, 'copies' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['format_id' => 'Format introuvable ou désactivé.']);
        $this->postJson('/api/etiquettes/imprimer', ['format_id' => $complet['id'], 'liste_prix_id' => 999999, 'lignes' => [['produit_id' => $this->fer, 'copies' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['liste_prix_id' => 'Liste de prix introuvable.']);
    }

    public function test_droits_et_isolation(): void
    {
        $complet = $this->format('Complet');
        $ligne = ['format_id' => $complet['id'], 'lignes' => [['produit_id' => $this->ciment, 'copies' => 1]]];

        // Imprimer sans gérer les formats.
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [], 'exceptions' => ['etiquettes.voir' => true, 'etiquettes.formats' => false]])->assertOk();
        $this->agir($compte);
        $this->getJson('/api/etiquettes/formats')->assertOk();
        $this->postJson('/api/etiquettes/imprimer', $ligne)->assertOk();
        $this->postJson('/api/etiquettes/formats', ['nom' => 'X', 'support' => 'rouleau', 'largeur_mm' => 50, 'hauteur_mm' => 30, 'contenu' => ['code_barres']])->assertForbidden();
        $this->putJson("/api/etiquettes/formats/{$complet['id']}", ['taille_police' => 8])->assertForbidden();
        $this->deleteJson("/api/etiquettes/formats/{$complet['id']}")->assertForbidden();

        // Ni voir ni imprimer.
        $this->agir($this->admin);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [], 'exceptions' => ['etiquettes.voir' => false]])->assertOk();
        $this->agir($compte);
        $this->getJson('/api/etiquettes/formats')->assertForbidden();
        $this->postJson('/api/etiquettes/imprimer', $ligne)->assertForbidden();

        // Une autre entreprise ne voit ni les formats ni les produits.
        $autre = Tenant::factory()->create();
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->putJson("/api/etiquettes/formats/{$complet['id']}", ['taille_police' => 8])->assertNotFound();
        $this->postJson('/api/etiquettes/imprimer', $ligne)->assertUnprocessable()->assertJsonValidationErrors(['format_id']);
        $sesFormats = $this->getJson('/api/etiquettes/formats')->assertOk()->json('formats');
        $this->assertNotContains($complet['id'], array_column($sesFormats, 'id'));
        $this->postJson('/api/etiquettes/imprimer', ['format_id' => $sesFormats[0]['id']] + $ligne)->assertUnprocessable()
            ->assertJsonValidationErrors(['lignes.0.produit_id' => 'Produit introuvable.']);
    }
}
