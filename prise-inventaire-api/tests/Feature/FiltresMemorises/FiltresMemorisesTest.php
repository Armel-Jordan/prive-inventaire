<?php

namespace Tests\Feature\FiltresMemorises;

use App\Http\Controllers\FiltreMemoriseController;
use App\Models\AdminUser;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Filtres mémorisés (phase 5.3) : enregistrer sous un nom (le même nom remplace), un seul filtre par défaut par écran,
 * renommer, supprimer ; valeurs admises et nombre de filtres limités ; chaque compte ne voit que les siens, dans son
 * entreprise ; aucun droit de module n'est demandé.
 */
class FiltresMemorisesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $compte;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->agir($this->compte);
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $compte->tenant->slug);
    }

    private function enregistrer(array $donnees): array
    {
        return $this->postJson('/api/filtres-memorises', $donnees + ['ecran' => 'scans'])->assertSuccessful()->json();
    }

    private function liste(string $ecran = 'scans'): array
    {
        return array_map(fn (array $f) => [$f['nom'], $f['par_defaut']], $this->getJson("/api/filtres-memorises?ecran={$ecran}")->assertOk()->json());
    }

    public function test_enregistrer_remplacer_et_filtre_par_defaut(): void
    {
        $reserve = $this->postJson('/api/filtres-memorises', ['ecran' => 'scans', 'nom' => '  Réserve du mois ', 'valeurs' => ['secteur' => 'RES', 'du' => '2026-10-01', 'au' => '']])
            ->assertCreated()->json();
        // Une valeur vide est gardée vide (null).
        $this->assertSame(['Réserve du mois', ['secteur' => 'RES', 'du' => '2026-10-01', 'au' => null], false], [$reserve['nom'], $reserve['valeurs'], $reserve['par_defaut']]);
        // Le même nom remplace les valeurs.
        $this->postJson('/api/filtres-memorises', ['ecran' => 'scans', 'nom' => 'Réserve du mois', 'valeurs' => ['secteur' => 'RES', 'employe' => 'Paul']])
            ->assertOk()->assertJsonPath('id', $reserve['id'])->assertJsonPath('valeurs.employe', 'Paul');

        // Un seul filtre par défaut par écran ; il vient en tête de la liste.
        $tout = $this->enregistrer(['nom' => 'Tout', 'valeurs' => [], 'par_defaut' => true]);
        $this->assertSame([['Tout', true], ['Réserve du mois', false]], $this->liste());
        $this->enregistrer(['nom' => 'Paul seul', 'valeurs' => ['employe' => 'Paul'], 'par_defaut' => true]);
        $this->assertSame([['Paul seul', true], ['Réserve du mois', false], ['Tout', false]], $this->liste());
        $this->putJson("/api/filtres-memorises/{$tout['id']}", ['par_defaut' => true])->assertOk();
        $this->assertSame([['Tout', true], ['Paul seul', false], ['Réserve du mois', false]], $this->liste());
        // Des valeurs vides restent un objet.
        $this->assertStringContainsString('"valeurs":{}', $this->getJson('/api/filtres-memorises?ecran=scans')->getContent());

        // Renommer : pas sur un nom déjà pris.
        $this->putJson("/api/filtres-memorises/{$tout['id']}", ['nom' => 'Réserve du mois'])
            ->assertUnprocessable()->assertJsonValidationErrors(['nom' => 'Vous avez déjà un filtre de ce nom pour cet écran.']);
        $this->putJson("/api/filtres-memorises/{$tout['id']}", ['nom' => 'Tous les scans', 'par_defaut' => false])->assertOk()->assertJsonPath('nom', 'Tous les scans');
        $this->assertSame([['Paul seul', false], ['Réserve du mois', false], ['Tous les scans', false]], $this->liste());

        // Un autre écran a ses propres filtres.
        $this->enregistrer(['ecran' => 'rapport:ventes-par-periode', 'nom' => 'Par mois', 'valeurs' => ['regroupement' => 'mois'], 'par_defaut' => true]);
        $this->assertSame([['Par mois', true]], $this->liste('rapport:ventes-par-periode'));
        $this->assertCount(3, $this->liste());

        $this->deleteJson("/api/filtres-memorises/{$reserve['id']}")->assertNoContent();
        $this->assertSame([['Paul seul', false], ['Tous les scans', false]], $this->liste());
    }

    public function test_valeurs_ecrans_et_nombre_controles(): void
    {
        $this->getJson('/api/filtres-memorises?ecran=Scans!')->assertUnprocessable()->assertJsonValidationErrors(['ecran']);
        $this->postJson('/api/filtres-memorises', ['ecran' => 'scans', 'nom' => '', 'valeurs' => []])->assertUnprocessable()->assertJsonValidationErrors(['nom']);
        $this->postJson('/api/filtres-memorises', ['ecran' => 'scans', 'nom' => 'Objet', 'valeurs' => ['secteur' => ['code' => 'RES']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['valeurs' => 'Valeur de filtre non prise en charge : « secteur ».']);
        $this->postJson('/api/filtres-memorises', ['ecran' => 'scans', 'nom' => 'Long', 'valeurs' => ['recherche' => str_repeat('a', 501)]])
            ->assertUnprocessable()->assertJsonValidationErrors(['valeurs']);
        // Listes, nombres et booléens admis.
        $this->enregistrer(['ecran' => 'clients', 'nom' => 'Favoris de la catégorie 3', 'valeurs' => ['categorie_id' => 3, 'favoris' => true, 'villes' => ['Douala', 'Yaoundé']]]);
        $this->assertSame(['categorie_id' => 3, 'favoris' => true, 'villes' => ['Douala', 'Yaoundé']], $this->getJson('/api/filtres-memorises?ecran=clients')->json('0.valeurs'));

        // Nombre limité par écran ; remplacer un filtre existant reste possible.
        for ($i = 1; $i <= FiltreMemoriseController::MAXIMUM; $i++) {
            $this->enregistrer(['nom' => "Filtre {$i}", 'valeurs' => ['page' => $i]]);
        }
        $this->postJson('/api/filtres-memorises', ['ecran' => 'scans', 'nom' => 'Un de trop', 'valeurs' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['nom' => '30 filtres au plus par écran']);
        $this->postJson('/api/filtres-memorises', ['ecran' => 'scans', 'nom' => 'Filtre 1', 'valeurs' => ['page' => 99]])->assertOk();
    }

    public function test_chacun_ses_filtres_sans_droit_de_module(): void
    {
        $filtre = $this->enregistrer(['nom' => 'Mes scans', 'valeurs' => ['employe' => 'Paul']]);

        // Un simple utilisateur enregistre ses filtres ; il ne voit ni ne touche ceux des autres.
        $collegue = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->agir($collegue);
        $this->assertSame([], $this->liste());
        $this->putJson("/api/filtres-memorises/{$filtre['id']}", ['nom' => 'Volé'])->assertNotFound();
        $this->deleteJson("/api/filtres-memorises/{$filtre['id']}")->assertNotFound();
        // Le même nom est libre pour lui.
        $this->postJson('/api/filtres-memorises', ['ecran' => 'scans', 'nom' => 'Mes scans', 'valeurs' => ['employe' => 'Awa']])->assertCreated();

        // Une autre entreprise non plus.
        $this->agir(AdminUser::factory()->for(Tenant::factory()->create())->create(['role' => 'admin']));
        $this->assertSame([], $this->liste());
        $this->deleteJson("/api/filtres-memorises/{$filtre['id']}")->assertNotFound();

        $this->agir($this->compte);
        $this->assertSame([['Mes scans', false]], $this->liste());
        $this->assertSame(['employe' => 'Paul'], $this->getJson('/api/filtres-memorises?ecran=scans')->json('0.valeurs'));
    }
}
