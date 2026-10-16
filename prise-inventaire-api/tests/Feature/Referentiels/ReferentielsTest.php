<?php

namespace Tests\Feature\Referentiels;

use App\Models\AdminUser;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\ConditionPaiement;
use App\Models\Devise;
use App\Models\DeviseTaux;
use App\Models\Entrepot;
use App\Models\Fabricant;
use App\Models\Itineraire;
use App\Models\ModePaiement;
use App\Models\Raison;
use App\Models\Representant;
use App\Models\Scopes\TenantScope;
use App\Models\StatutPersonnalise;
use App\Models\Tenant;
use App\Models\Territoire;
use App\Models\Transporteur;
use App\Models\Unite;
use App\Models\UniteConversion;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Référentiels de base (plan de parité fripv2, phase 0.1) : API générique
 * /api/referentiels/{type}, isolée par tenant.
 */
class ReferentielsTest extends TestCase
{
    use RefreshDatabase;

    private function connecter(Tenant $tenant): static
    {
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(), ['*']);

        return $this->withHeader('X-Tenant-Slug', $tenant->slug);
    }

    /** Données de création valides ; crée au besoin les fiches dont le type dépend. */
    private function donnees(string $type): array
    {
        return match ($type) {
            'unites' => ['code' => 'KG', 'libelle' => 'Kilogramme', 'symbole' => 'kg', 'decimales' => 3],
            'unite_conversions' => [
                'unite_source_id' => $this->postJson('/api/referentiels/unites', ['code' => 'KG', 'libelle' => 'Kilogramme'])->json('id'),
                'unite_cible_id' => $this->postJson('/api/referentiels/unites', ['code' => 'G', 'libelle' => 'Gramme'])->json('id'),
                'facteur' => 1000,
            ],
            'categories' => ['code' => 'BOIS', 'nom' => 'Boissons', 'description' => 'Toutes les boissons'],
            'fabricants' => ['nom' => 'Acme', 'site_web' => 'https://acme.example', 'email' => 'contact@acme.example'],
            'modes_paiement' => ['code' => 'MOMO', 'libelle' => 'Mobile money', 'type' => 'mobile_money'],
            'conditions_paiement' => ['code' => 'NET30', 'libelle' => 'Net 30 jours', 'delai_jours' => 30],
            'comptes_bancaires' => ['nom' => 'Compte principal', 'banque' => 'Banque Test', 'iban' => 'cm21 1000 1234', 'devise_code' => 'xaf'],
            'devises' => ['code' => 'XAF', 'libelle' => 'Franc CFA', 'symbole' => 'FCFA', 'decimales' => 0, 'taux' => 0.00152449],
            'entrepots' => ['code' => 'E1', 'nom' => 'Entrepôt central', 'type' => 'entrepot', 'ville' => 'Douala'],
            'territoires' => ['code' => 'LIT', 'nom' => 'Littoral'],
            'itineraires' => [
                'code' => 'R01',
                'nom' => 'Tournée du lundi',
                'territoire_id' => $this->postJson('/api/referentiels/territoires', ['code' => 'LIT', 'nom' => 'Littoral'])->json('id'),
            ],
            'representants' => ['code' => 'REP1', 'nom' => 'Awa Ndiaye', 'email' => 'awa@example.test', 'taux_commission' => 5],
            'transporteurs' => ['nom' => 'Express Cargo', 'url_suivi' => 'https://suivi.example/colis/{numero}'],
            'raisons' => ['type' => 'ajustement', 'code' => 'CASSE', 'libelle' => 'Casse'],
            'statuts_personnalises' => ['document' => 'commande_client', 'code' => 'ATT_PAIE', 'libelle' => 'En attente de paiement', 'couleur' => '#f59e0b', 'ordre' => 10],
        };
    }

    public static function referentiels(): array
    {
        return [
            'unités' => ['unites', 'libelle', 'Kilogrammes'],
            'conversions' => ['unite_conversions', 'facteur', 999.5],
            'catégories' => ['categories', 'nom', 'Boissons fraîches'],
            'fabricants' => ['fabricants', 'nom', 'Acme Industries'],
            'modes de paiement' => ['modes_paiement', 'libelle', 'Orange Money'],
            'conditions de paiement' => ['conditions_paiement', 'libelle', 'Net 30 jours fin de mois'],
            'comptes bancaires' => ['comptes_bancaires', 'banque', 'Banque Atlantique'],
            'devises' => ['devises', 'libelle', 'Franc CFA (BEAC)'],
            'entrepôts' => ['entrepots', 'nom', 'Entrepôt nord'],
            'territoires' => ['territoires', 'nom', 'Littoral et Ouest'],
            'itinéraires' => ['itineraires', 'nom', 'Tournée du mardi'],
            'représentants' => ['representants', 'taux_commission', 7.5],
            'transporteurs' => ['transporteurs', 'nom', 'Express Cargo SA'],
            'raisons' => ['raisons', 'libelle', 'Casse en entrepôt'],
            'statuts personnalisés' => ['statuts_personnalises', 'libelle', 'Attente du paiement client'],
        ];
    }

    #[DataProvider('referentiels')]
    public function test_cycle_complet_creer_lister_modifier_supprimer(string $type, string $champ, mixed $nouvelleValeur): void
    {
        $this->connecter(Tenant::factory()->create());
        $donnees = $this->donnees($type);

        $id = $this->postJson("/api/referentiels/{$type}", $donnees)->assertCreated()->json('id');

        $this->assertContains($id, $this->getJson("/api/referentiels/{$type}")->assertOk()->json('*.id'));

        $modifiee = $this->putJson("/api/referentiels/{$type}/{$id}", array_merge($donnees, [$champ => $nouvelleValeur]))
            ->assertOk()
            ->json($champ);
        is_numeric($nouvelleValeur)
            ? $this->assertEquals((float) $nouvelleValeur, (float) $modifiee)
            : $this->assertSame($nouvelleValeur, $modifiee);

        $this->deleteJson("/api/referentiels/{$type}/{$id}")->assertOk();
        $this->assertNotContains($id, $this->getJson("/api/referentiels/{$type}")->json('*.id'));
    }

    public static function champsObligatoires(): array
    {
        return [
            'unités' => ['unites', ['code', 'libelle']],
            'conversions' => ['unite_conversions', ['unite_source_id', 'unite_cible_id', 'facteur']],
            'catégories' => ['categories', ['nom']],
            'fabricants' => ['fabricants', ['nom']],
            'modes de paiement' => ['modes_paiement', ['code', 'libelle', 'type']],
            'conditions de paiement' => ['conditions_paiement', ['code', 'libelle', 'delai_jours']],
            'comptes bancaires' => ['comptes_bancaires', ['nom', 'banque']],
            'devises' => ['devises', ['code', 'libelle', 'taux']],
            'entrepôts' => ['entrepots', ['code', 'nom', 'type']],
            'territoires' => ['territoires', ['code', 'nom']],
            'itinéraires' => ['itineraires', ['code', 'nom']],
            'représentants' => ['representants', ['nom']],
            'transporteurs' => ['transporteurs', ['nom']],
            'raisons' => ['raisons', ['type', 'code', 'libelle']],
            'statuts personnalisés' => ['statuts_personnalises', ['document', 'code', 'libelle']],
        ];
    }

    #[DataProvider('champsObligatoires')]
    public function test_champs_obligatoires(string $type, array $champs): void
    {
        $this->connecter(Tenant::factory()->create())
            ->postJson("/api/referentiels/{$type}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors($champs);
    }

    public function test_type_inconnu_renvoie_404(): void
    {
        $this->connecter(Tenant::factory()->create())
            ->getJson('/api/referentiels/inconnu')
            ->assertNotFound();
    }

    public function test_code_unique_dans_un_tenant_mais_reutilisable_ailleurs(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        app(TenantContext::class)->runAsTenant($a->id, fn () => Unite::create(['code' => 'KG', 'libelle' => 'Kilogramme']));

        $this->connecter($a)
            ->postJson('/api/referentiels/unites', ['code' => 'KG', 'libelle' => 'Doublon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $this->connecter($b)
            ->postJson('/api/referentiels/unites', ['code' => 'KG', 'libelle' => 'Kilogramme'])
            ->assertCreated();
    }

    public function test_un_tenant_ne_voit_ni_ne_modifie_les_referentiels_dun_autre(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $deviseA = app(TenantContext::class)->runAsTenant($a->id, fn () => Devise::create(['code' => 'EUR', 'libelle' => 'Euro', 'taux' => 655.957]));

        $this->connecter($b);
        $this->assertSame([], $this->getJson('/api/referentiels/devises')->assertOk()->json());
        $this->putJson("/api/referentiels/devises/{$deviseA->id}", ['code' => 'EUR', 'libelle' => 'Piraté', 'taux' => 1])->assertNotFound();
        $this->deleteJson("/api/referentiels/devises/{$deviseA->id}")->assertNotFound();
        $this->getJson("/api/referentiels/devises/{$deviseA->id}/historique")->assertNotFound();

        $this->assertSame('Euro', Devise::withoutTenantScope()->find($deviseA->id)->libelle);
    }

    public function test_tous_les_modeles_portent_le_tenant_scope(): void
    {
        $modeles = [
            Unite::class, UniteConversion::class, Categorie::class, Fabricant::class, ModePaiement::class,
            ConditionPaiement::class, CompteBancaire::class, Devise::class, DeviseTaux::class,
            Entrepot::class, Territoire::class, Itineraire::class, Representant::class, Transporteur::class,
            Raison::class, StatutPersonnalise::class,
        ];

        foreach ($modeles as $modele) {
            $this->assertArrayHasKey(TenantScope::class, (new $modele)->getGlobalScopes(), "{$modele} doit porter le TenantScope.");
        }
    }

    public function test_filtre_sur_les_fiches_actives(): void
    {
        $this->connecter(Tenant::factory()->create());
        $this->postJson('/api/referentiels/fabricants', ['nom' => 'Actif'])->assertCreated();
        $this->postJson('/api/referentiels/fabricants', ['nom' => 'Inactif', 'actif' => false])->assertCreated();

        $this->assertSame(['Actif'], $this->getJson('/api/referentiels/fabricants?actif=1')->json('*.nom'));
        $this->assertCount(2, $this->getJson('/api/referentiels/fabricants')->json());
    }

    // ===== Catégories : hiérarchie =====

    public function test_categorie_ne_peut_pas_devenir_sa_propre_descendante(): void
    {
        $this->connecter(Tenant::factory()->create());
        $racine = $this->postJson('/api/referentiels/categories', ['nom' => 'Racine'])->json('id');
        $enfant = $this->postJson('/api/referentiels/categories', ['nom' => 'Enfant', 'parent_id' => $racine])->json('id');
        $petitEnfant = $this->postJson('/api/referentiels/categories', ['nom' => 'Petit-enfant', 'parent_id' => $enfant])->json('id');

        $this->putJson("/api/referentiels/categories/{$racine}", ['nom' => 'Racine', 'parent_id' => $petitEnfant])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);
        $this->putJson("/api/referentiels/categories/{$racine}", ['nom' => 'Racine', 'parent_id' => $racine])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_categorie_avec_sous_categories_non_supprimable(): void
    {
        $this->connecter(Tenant::factory()->create());
        $racine = $this->postJson('/api/referentiels/categories', ['nom' => 'Racine'])->json('id');
        $this->postJson('/api/referentiels/categories', ['nom' => 'Enfant', 'parent_id' => $racine])->assertCreated();

        $this->deleteJson("/api/referentiels/categories/{$racine}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cette catégorie contient des sous-catégories.');
    }

    public function test_nom_unique_entre_categories_soeurs_seulement(): void
    {
        $this->connecter(Tenant::factory()->create());
        $boissons = $this->postJson('/api/referentiels/categories', ['nom' => 'Boissons'])->json('id');
        $epicerie = $this->postJson('/api/referentiels/categories', ['nom' => 'Épicerie'])->json('id');

        $this->postJson('/api/referentiels/categories', ['nom' => 'Boissons'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nom']);
        $this->postJson('/api/referentiels/categories', ['nom' => 'Bio', 'parent_id' => $boissons])->assertCreated();
        $this->postJson('/api/referentiels/categories', ['nom' => 'Bio', 'parent_id' => $epicerie])->assertCreated();
    }

    public function test_parent_dun_autre_tenant_refuse(): void
    {
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();
        $categorieA = app(TenantContext::class)->runAsTenant($a->id, fn () => Categorie::create(['nom' => 'Chez A']));

        $this->connecter($b)
            ->postJson('/api/referentiels/categories', ['nom' => 'Chez B', 'parent_id' => $categorieA->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);
    }

    // ===== Unités et conversions =====

    public function test_conversion_entre_deux_unites_distinctes_du_tenant(): void
    {
        $a = Tenant::factory()->create();
        $uniteA = app(TenantContext::class)->runAsTenant($a->id, fn () => Unite::create(['code' => 'L', 'libelle' => 'Litre']));

        $this->connecter(Tenant::factory()->create());
        $kg = $this->postJson('/api/referentiels/unites', ['code' => 'KG', 'libelle' => 'Kilogramme'])->json('id');
        $g = $this->postJson('/api/referentiels/unites', ['code' => 'G', 'libelle' => 'Gramme'])->json('id');

        $this->postJson('/api/referentiels/unite_conversions', ['unite_source_id' => $kg, 'unite_cible_id' => $kg, 'facteur' => 1])
            ->assertStatus(422)->assertJsonValidationErrors(['unite_cible_id']);
        $this->postJson('/api/referentiels/unite_conversions', ['unite_source_id' => $kg, 'unite_cible_id' => $g, 'facteur' => 0])
            ->assertStatus(422)->assertJsonValidationErrors(['facteur']);
        $this->postJson('/api/referentiels/unite_conversions', ['unite_source_id' => $uniteA->id, 'unite_cible_id' => $g, 'facteur' => 2])
            ->assertStatus(422)->assertJsonValidationErrors(['unite_source_id']);

        $this->postJson('/api/referentiels/unite_conversions', ['unite_source_id' => $kg, 'unite_cible_id' => $g, 'facteur' => 1000])
            ->assertCreated()
            ->assertJsonPath('source.code', 'KG')
            ->assertJsonPath('cible.code', 'G');
        $this->postJson('/api/referentiels/unite_conversions', ['unite_source_id' => $kg, 'unite_cible_id' => $g, 'facteur' => 1000])
            ->assertStatus(422)->assertJsonValidationErrors(['unite_cible_id']);
    }

    public function test_unite_utilisee_dans_une_conversion_non_supprimable(): void
    {
        $this->connecter(Tenant::factory()->create());
        $conversion = $this->postJson('/api/referentiels/unite_conversions', $this->donnees('unite_conversions'))->assertCreated();

        $this->deleteJson('/api/referentiels/unites/'.$conversion->json('unite_cible_id'))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Cette unité est utilisée dans une conversion.');
    }

    // ===== Devises : normalisation et historique des taux =====

    public function test_code_devise_normalise_et_taux_historise(): void
    {
        $this->connecter(Tenant::factory()->create());

        $devise = $this->postJson('/api/referentiels/devises', ['code' => 'xaf', 'libelle' => 'Franc CFA', 'taux' => 0.0015])
            ->assertCreated()
            ->assertJsonPath('code', 'XAF');
        $id = $devise->json('id');
        $this->assertCount(1, $this->getJson("/api/referentiels/devises/{$id}/historique")->json());

        $this->putJson("/api/referentiels/devises/{$id}", ['code' => 'XAF', 'libelle' => 'Franc CFA', 'taux' => 0.0016])->assertOk();
        $historique = $this->getJson("/api/referentiels/devises/{$id}/historique")->assertOk()->json();
        $this->assertCount(2, $historique);
        $this->assertEquals(0.0016, (float) $historique[0]['taux']);
        // Date seule, sans heure : pas de décalage d'un jour selon le fuseau du navigateur.
        $this->assertSame(now()->toDateString(), $historique[0]['date_effet']);

        // Modifier le libellé seul ne crée pas d'entrée d'historique.
        $this->putJson("/api/referentiels/devises/{$id}", ['code' => 'XAF', 'libelle' => 'Franc CFA BEAC', 'taux' => 0.0016])->assertOk();
        $this->assertCount(2, $this->getJson("/api/referentiels/devises/{$id}/historique")->json());
    }

    public function test_compte_bancaire_normalise_iban_bic_et_devise(): void
    {
        $this->connecter(Tenant::factory()->create())
            ->postJson('/api/referentiels/comptes_bancaires', ['nom' => 'Principal', 'banque' => 'BT', 'iban' => ' cm21 ab ', 'bic' => 'abcdcmcx', 'devise_code' => 'xaf'])
            ->assertCreated()
            ->assertJsonPath('iban', 'CM21 AB')
            ->assertJsonPath('bic', 'ABCDCMCX')
            ->assertJsonPath('devise_code', 'XAF');
    }

    // ===== Une seule fiche « par défaut » =====

    public static function referentielsAvecParDefaut(): array
    {
        return [
            'conditions de paiement' => ['conditions_paiement', ['libelle' => 'Comptant', 'delai_jours' => 0], 'code'],
            'comptes bancaires' => ['comptes_bancaires', ['banque' => 'Banque Test'], 'nom'],
            'entrepôts' => ['entrepots', ['nom' => 'Entrepôt', 'type' => 'entrepot'], 'code'],
        ];
    }

    #[DataProvider('referentielsAvecParDefaut')]
    public function test_une_seule_fiche_par_defaut(string $type, array $base, string $cle): void
    {
        $this->connecter(Tenant::factory()->create());
        $premiere = $this->postJson("/api/referentiels/{$type}", $base + [$cle => 'UN', 'par_defaut' => true])->json('id');
        $seconde = $this->postJson("/api/referentiels/{$type}", $base + [$cle => 'DEUX', 'par_defaut' => true])->json('id');

        $parDefaut = collect($this->getJson("/api/referentiels/{$type}")->json())->where('par_defaut', true)->pluck('id')->all();
        $this->assertSame([$seconde], $parDefaut);
        $this->assertNotSame($premiere, $seconde);
    }

    // ===== Logistique et commercial =====

    public function test_territoire_utilise_non_supprimable(): void
    {
        $this->connecter(Tenant::factory()->create());
        $itineraire = $this->postJson('/api/referentiels/itineraires', $this->donnees('itineraires'))->assertCreated();
        $territoire = $itineraire->json('territoire_id');
        $this->assertSame('LIT', $itineraire->json('territoire.code'));

        $this->deleteJson("/api/referentiels/territoires/{$territoire}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ce territoire est utilisé par un itinéraire ou un représentant.');

        $this->deleteJson('/api/referentiels/itineraires/'.$itineraire->json('id'))->assertOk();
        $this->deleteJson("/api/referentiels/territoires/{$territoire}")->assertOk();
    }

    public function test_territoire_dun_autre_tenant_refuse(): void
    {
        $a = Tenant::factory()->create();
        $territoireA = app(TenantContext::class)->runAsTenant($a->id, fn () => Territoire::create(['code' => 'A', 'nom' => 'Chez A']));

        $this->connecter(Tenant::factory()->create())
            ->postJson('/api/referentiels/representants', ['nom' => 'Rep B', 'territoire_id' => $territoireA->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['territoire_id']);
    }

    public function test_lien_de_suivi_transporteur(): void
    {
        $this->connecter(Tenant::factory()->create());

        $this->postJson('/api/referentiels/transporteurs', ['nom' => 'Sans http', 'url_suivi' => 'suivi.example/{numero}'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['url_suivi']);
        $this->postJson('/api/referentiels/transporteurs', ['nom' => 'Avec gabarit', 'url_suivi' => 'https://suivi.example/?n={numero}'])
            ->assertCreated()
            ->assertJsonPath('url_suivi', 'https://suivi.example/?n={numero}');
    }

    // ===== Raisons et statuts personnalisés =====

    public function test_code_de_raison_unique_par_type(): void
    {
        $this->connecter(Tenant::factory()->create());

        $this->postJson('/api/referentiels/raisons', ['type' => 'ajustement', 'code' => 'CASSE', 'libelle' => 'Casse'])->assertCreated();
        $this->postJson('/api/referentiels/raisons', ['type' => 'ajustement', 'code' => 'CASSE', 'libelle' => 'Doublon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
        $this->postJson('/api/referentiels/raisons', ['type' => 'retour_client', 'code' => 'CASSE', 'libelle' => 'Produit cassé'])->assertCreated();
        $this->postJson('/api/referentiels/raisons', ['type' => 'inconnu', 'code' => 'X', 'libelle' => 'X'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);

        $this->assertSame(['ajustement', 'retour_client'], $this->getJson('/api/referentiels/raisons')->json('*.type'));
    }

    public function test_statut_personnalise_couleur_et_tri(): void
    {
        $this->connecter(Tenant::factory()->create());

        foreach (['rouge', '#12ab', '#12ab3z'] as $couleur) {
            $this->postJson('/api/referentiels/statuts_personnalises', ['document' => 'facture', 'code' => 'X', 'libelle' => 'X', 'couleur' => $couleur])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['couleur']);
        }

        $this->postJson('/api/referentiels/statuts_personnalises', ['document' => 'facture', 'code' => 'RELANCE2', 'libelle' => 'Relance 2', 'ordre' => 20, 'couleur' => '#DC2626'])->assertCreated();
        $this->postJson('/api/referentiels/statuts_personnalises', ['document' => 'facture', 'code' => 'RELANCE1', 'libelle' => 'Relance 1', 'ordre' => 10])->assertCreated();
        $this->postJson('/api/referentiels/statuts_personnalises', ['document' => 'devis', 'code' => 'RELANCE1', 'libelle' => 'Devis relancé'])->assertCreated();
        $this->postJson('/api/referentiels/statuts_personnalises', ['document' => 'facture', 'code' => 'RELANCE1', 'libelle' => 'Doublon'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);

        $liste = $this->getJson('/api/referentiels/statuts_personnalises')->json();
        $this->assertSame(['Devis relancé', 'Relance 1', 'Relance 2'], array_column($liste, 'libelle'));
        $this->assertSame('#6b7280', $liste[1]['couleur']);
    }

    // ===== Permission de l'écran =====

    public function test_module_de_permission_referentiels_declare(): void
    {
        $this->connecter(Tenant::factory()->create())
            ->getJson('/api/permissions/modules')
            ->assertOk()
            ->assertJsonPath('referentiels', 'Référentiels');
    }

    public function test_migration_des_permissions_avec_des_roles_existants(): void
    {
        foreach (['admin', 'manager', 'user'] as $nom) {
            if (! DB::table('roles_custom')->whereNull('tenant_id')->where('nom', $nom)->exists()) {
                DB::table('roles_custom')->insert(['nom' => $nom, 'is_system' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $migration = require database_path('migrations/2026_10_15_100002_add_referentiels_permissions.php');

        // Ne doit pas échouer, quelle que soit la structure de role_permissions.
        $migration->up();

        if (! Schema::hasColumns('role_permissions', ['role_id', 'module'])) {
            // Structure héritée (role, permission) : rien n'est inséré.
            $this->assertSame(0, DB::table('role_permissions')->where('permission', 'referentiels')->count());

            return;
        }

        $parRole = DB::table('role_permissions')
            ->join('roles_custom', 'roles_custom.id', '=', 'role_permissions.role_id')
            ->where('module', 'referentiels')
            ->pluck('can_delete', 'roles_custom.nom');
        $this->assertTrue((bool) $parRole['admin']);
        $this->assertFalse((bool) $parRole['manager']);
    }
}
