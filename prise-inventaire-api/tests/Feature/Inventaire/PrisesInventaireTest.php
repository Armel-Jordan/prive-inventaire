<?php

namespace Tests\Feature\Inventaire;

use App\Models\AdminUser;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Fournisseur;
use App\Models\MouvementTenant;
use App\Models\ProduitTenant;
use App\Models\ScanTenant;
use App\Models\Secteur;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Prise d'inventaire formelle (plan de parité fripv2, phase 2.7) : sessions par-dessus les scans (secteur
 * saisi par code ou par nom), écart avec le dernier inventaire validé, mouvements connus depuis, corrections,
 * validation figée (inventaire officiel), droits, annulation, export, isolation.
 */
class PrisesInventaireTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private Secteur $a;

    private Secteur $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->admin = $this->connecter($this->tenant, 'admin');
        [$this->a, $this->b] = $this->chez(fn () => [
            Secteur::create(['code' => 'A-01', 'nom' => 'Allée A', 'actif' => true]),
            Secteur::create(['code' => 'B-02', 'nom' => 'Allée B', 'actif' => true]),
        ]);
        $this->chez(function () {
            ProduitTenant::create(['numero' => 'CIM-50', 'description' => 'Ciment 50 kg']);
            ProduitTenant::create(['numero' => 'FER-10', 'description' => 'Fer à béton 10']);
            ProduitTenant::create(['numero' => 'SAC-01', 'description' => 'Sable (sac)']);
        });
    }

    private function connecter(Tenant $tenant, string $role): AdminUser
    {
        $utilisateur = AdminUser::factory()->for($tenant)->create(['role' => $role]);
        Sanctum::actingAs($utilisateur, ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);

        return $utilisateur;
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function scan(string $numero, float $quantite, string $secteur, string $date): void
    {
        $this->chez(fn () => ScanTenant::create(['numero' => $numero, 'quantite' => $quantite, 'employe' => 'Paul', 'secteur' => $secteur, 'date_saisie' => $date]));
    }

    private function nouvellePrise(array $secteurs, string $libelle = 'Inventaire'): int
    {
        return $this->postJson('/api/prises-inventaire', ['libelle' => $libelle, 'secteur_ids' => array_map(fn (Secteur $s) => $s->id, $secteurs)])->assertCreated()->json('id');
    }

    /** @return array<string, array> lignes indexées par « secteur|produit » */
    private function lignes(int $prise): array
    {
        return collect($this->getJson("/api/prises-inventaire/{$prise}/lignes")->assertOk()->json('data'))
            ->keyBy(fn ($l) => $l['secteur'].'|'.$l['produit_numero'])->all();
    }

    public function test_creation_numerotation_et_sessions_concurrentes(): void
    {
        $this->travelTo('2026-10-16 08:00:00');
        $this->postJson('/api/prises-inventaire', ['libelle' => 'Allée A', 'secteur_ids' => [$this->a->id]])->assertCreated()
            ->assertJsonPath('numero', 'INV-2026-0001')->assertJsonPath('statut', 'en_cours');

        // Un secteur déjà en cours de comptage ne peut pas entrer dans une autre session.
        $this->postJson('/api/prises-inventaire', ['libelle' => 'A et B', 'secteur_ids' => [$this->a->id, $this->b->id]])->assertUnprocessable()
            ->assertJson(['message' => 'La prise INV-2026-0001 est déjà en cours sur ces secteurs : validez-la ou annulez-la d\'abord.']);
        $this->postJson('/api/prises-inventaire', ['libelle' => 'Tout', 'tous_secteurs' => true])->assertUnprocessable();
        $this->postJson('/api/prises-inventaire', ['libelle' => 'Sans secteur'])->assertUnprocessable();

        $this->postJson('/api/prises-inventaire', ['libelle' => 'Allée B', 'secteur_ids' => [$this->b->id]])->assertCreated()->assertJsonPath('numero', 'INV-2026-0002');
        $this->assertSame(['INV-2026-0002', 'INV-2026-0001'], array_column($this->getJson('/api/prises-inventaire')->json(), 'numero'));
    }

    public function test_le_comptage_reprend_les_scans_de_la_periode_et_des_secteurs(): void
    {
        $this->travelTo('2026-10-16 08:00:00');
        $prise = $this->nouvellePrise([$this->a]);
        $this->scan('CIM-50', 10, 'A-01', '2026-10-16 09:00:00');
        // Saisi depuis l'application Android : le secteur est désigné par son nom.
        $this->scan('CIM-50', 5, 'Allée A', '2026-10-16 10:00:00');
        // Hors comptage : avant le début, et dans un autre secteur.
        $this->scan('FER-10', 3, 'A-01', '2026-10-16 07:00:00');
        $this->scan('CIM-50', 7, 'B-02', '2026-10-16 09:30:00');
        $this->travelTo('2026-10-16 11:00:00');

        $lignes = $this->lignes($prise);
        $this->assertSame(['A-01|CIM-50'], array_keys($lignes));
        $this->assertEquals(
            ['quantite_comptee' => 15, 'nb_scans' => 2, 'quantite_reference' => null, 'ecart' => null, 'statut' => 'nouveau', 'description' => 'Ciment 50 kg'],
            array_intersect_key($lignes['A-01|CIM-50'], array_flip(['quantite_comptee', 'nb_scans', 'quantite_reference', 'ecart', 'statut', 'description'])),
        );

        $session = $this->getJson("/api/prises-inventaire/{$prise}")->assertOk()->json();
        $this->assertSame([['A-01', 2, 1, 1]], array_map(fn ($s) => [$s['code'], $s['nb_scans'], $s['nb_produits'], $s['nb_employes']], $session['avancement']));
        $this->assertSame(1, $session['resume']['nouveau']);
    }

    public function test_validation_ecarts_avec_le_dernier_inventaire_et_corrections(): void
    {
        $this->travelTo('2026-10-16 08:00:00');
        $premiere = $this->nouvellePrise([$this->a]);
        $this->scan('CIM-50', 15, 'A-01', '2026-10-16 09:00:00');
        $this->scan('FER-10', 4, 'A-01', '2026-10-16 09:10:00');
        $this->travelTo('2026-10-16 12:00:00');
        $this->postJson("/api/prises-inventaire/{$premiere}/valider")->assertOk()->assertJsonPath('statut', 'validee')->assertJsonPath('lignes_count', 2);
        $this->postJson("/api/prises-inventaire/{$premiere}/valider")->assertUnprocessable();

        // Les lignes validées sont figées : un scan ajouté après coup dans la période ne les change pas.
        $this->scan('CIM-50', 100, 'A-01', '2026-10-16 11:00:00');
        $this->assertEquals(15, $this->lignes($premiere)['A-01|CIM-50']['quantite_retenue']);

        // Le lendemain : écarts avec l'inventaire validé.
        $this->travelTo('2026-10-17 08:00:00');
        $seconde = $this->nouvellePrise([$this->a], 'Contrôle');
        $this->scan('CIM-50', 12, 'A-01', '2026-10-17 09:00:00');
        $this->scan('SAC-01', 6, 'Allée A', '2026-10-17 09:05:00');
        $this->travelTo('2026-10-17 10:00:00');

        $lignes = $this->lignes($seconde);
        $resume = fn (array $l) => [$l['quantite_comptee'], $l['quantite_reference'], $l['quantite_retenue'], $l['ecart'], $l['statut']];
        $this->assertEquals([12, 15, 12, -3, 'ecart'], $resume($lignes['A-01|CIM-50']));
        $this->assertEquals([0, 4, 0, -4, 'non_compte'], $resume($lignes['A-01|FER-10']));
        // Produit absent du dernier inventaire du secteur : référence 0.
        $this->assertEquals([6, 0, 6, 6, 'ecart'], $resume($lignes['A-01|SAC-01']));

        // Correction d'un responsable : recompté à la main.
        $ligne = $this->putJson("/api/prises-inventaire/{$seconde}/corrections", ['secteur' => 'A-01', 'produit_numero' => 'FER-10', 'quantite_retenue' => 4, 'raison' => 'Recompté à la main'])->assertOk()->json();
        $this->assertEquals([0, 4, 4, 0, 'conforme', true, 'Recompté à la main'], [...$resume($ligne), $ligne['corrigee'], $ligne['raison']]);

        $this->connecter($this->tenant, 'user');
        $this->putJson("/api/prises-inventaire/{$seconde}/corrections", ['secteur' => 'A-01', 'produit_numero' => 'FER-10', 'quantite_retenue' => 1])->assertForbidden();
        $this->postJson("/api/prises-inventaire/{$seconde}/valider")->assertForbidden();
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson("/api/prises-inventaire/{$seconde}/valider")->assertOk();
        $this->putJson("/api/prises-inventaire/{$seconde}/corrections", ['secteur' => 'A-01', 'produit_numero' => 'FER-10', 'quantite_retenue' => 1])->assertUnprocessable();

        // Inventaire officiel : la dernière prise validée du secteur.
        $officiel = collect($this->getJson('/api/prises-inventaire/officiel')->assertOk()->json('data'))->keyBy('produit_numero');
        $this->assertEquals(['CIM-50' => 12, 'FER-10' => 4, 'SAC-01' => 6], $officiel->map(fn ($l) => $l['quantite'])->all());
        $this->assertSame('INV-2026-0002', $officiel['CIM-50']['prise']['numero']);
    }

    public function test_mouvements_connus_depuis_le_dernier_inventaire(): void
    {
        $this->travelTo('2026-10-16 08:00:00');
        $premiere = $this->nouvellePrise([$this->a]);
        $this->scan('CIM-50', 15, 'A-01', '2026-10-16 09:00:00');
        $this->travelTo('2026-10-16 12:00:00');
        $this->postJson("/api/prises-inventaire/{$premiere}/valider")->assertOk();

        $mouvement = fn (string $type, float $quantite, ?string $source, ?string $destination, string $date) => $this->chez(fn () => MouvementTenant::create([
            'type' => $type, 'produit_numero' => 'CIM-50', 'quantite' => $quantite, 'employe' => 'Paul',
            'secteur_source' => $source, 'secteur_destination' => $destination, 'date_mouvement' => $date,
        ]));
        $mouvement(MouvementTenant::TYPE_TRANSFERT, 9, 'A-01', 'B-02', '2026-10-16 10:00:00'); // avant l'inventaire : ignoré
        $mouvement(MouvementTenant::TYPE_TRANSFERT, 5, 'A-01', 'B-02', '2026-10-16 14:00:00');
        $mouvement(MouvementTenant::TYPE_ARRIVAGE, 2, null, 'Allée A', '2026-10-16 15:00:00');
        $this->travelTo('2026-10-16 16:00:00');
        $this->recevoir('CIM-50', 3, $this->a);

        $this->travelTo('2026-10-17 08:00:00');
        $seconde = $this->nouvellePrise([$this->a]);
        $this->scan('CIM-50', 15, 'A-01', '2026-10-17 09:00:00');
        $this->travelTo('2026-10-17 10:00:00');

        $ligne = $this->lignes($seconde)['A-01|CIM-50'];
        $this->assertEquals([15, 15, 0, 5, 5, 'conforme'], [$ligne['quantite_comptee'], $ligne['quantite_reference'], $ligne['ecart'], $ligne['entrees_depuis'], $ligne['sorties_depuis'], $ligne['statut']]);
    }

    public function test_les_receptions_comptent_en_unites_de_stock_sans_les_annulees(): void
    {
        $this->travelTo('2026-10-16 08:00:00');
        $premiere = $this->nouvellePrise([$this->a]);
        $this->scan('CIM-50', 15, 'A-01', '2026-10-16 09:00:00');
        $this->travelTo('2026-10-16 12:00:00');
        $this->postJson("/api/prises-inventaire/{$premiere}/valider")->assertOk();

        // 2 cartons de 12 rangés dans le secteur, et une réception de 5 cartons annulée ensuite.
        $this->travelTo('2026-10-16 16:00:00');
        $this->chez(function () {
            $fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Fournisseur']);
            $commande = ComFourEntete::create([
                'numero' => 'BC-0001', 'fournisseur_id' => $fournisseur->id, 'date_commande' => now()->toDateString(),
                'statut' => ComFourEntete::STATUT_ENVOYEE, 'montant_total' => 0, 'created_by' => $this->admin->id,
            ]);
            $ligne = ComFourLigne::create([
                'com_four_entete_id' => $commande->id, 'produit_id' => ProduitTenant::where('numero', 'CIM-50')->value('id'),
                'quantite_commandee' => 10, 'quantite_recue' => 0, 'unite_achat' => 'Carton', 'qte_par_unite_achat' => 12, 'prix_unitaire' => 12,
            ]);
            foreach ([[2, false], [5, true]] as [$quantite, $annulee]) {
                DB::table('reception_arrivages_ligne')->insert([
                    'com_four_ligne_id' => $ligne->id, 'date_reception' => now()->toDateString(), 'quantite_recue' => $quantite, 'annulee' => $annulee,
                    'secteur_id' => $this->a->id, 'received_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        $this->travelTo('2026-10-17 08:00:00');
        $seconde = $this->nouvellePrise([$this->a]);
        $this->scan('CIM-50', 39, 'A-01', '2026-10-17 09:00:00');
        $this->travelTo('2026-10-17 10:00:00');

        // L'écart de 24 avec le dernier inventaire s'explique par les entrées connues : 2 cartons de 12, sans l'annulée.
        $ligne = $this->lignes($seconde)['A-01|CIM-50'];
        $this->assertEquals([39, 15, 24, 24], [$ligne['quantite_comptee'], $ligne['quantite_reference'], $ligne['ecart'], $ligne['entrees_depuis']]);
    }

    public function test_annulation_export_et_isolation(): void
    {
        $this->travelTo('2026-10-16 08:00:00');
        $prise = $this->nouvellePrise([$this->a]);
        $this->scan('CIM-50', 10, 'A-01', '2026-10-16 09:00:00');
        $this->travelTo('2026-10-16 10:00:00');

        $csv = $this->get("/api/prises-inventaire/{$prise}/export")->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8')->streamedContent();
        $this->assertStringContainsString('Secteur;Produit;Description;Compté', $csv);
        $this->assertStringContainsString('A-01;CIM-50;"Ciment 50 kg";10;1;;;;;10;;nouveau', $csv);

        $this->postJson("/api/prises-inventaire/{$prise}/annuler")->assertOk()->assertJsonPath('statut', 'annulee');
        $this->postJson("/api/prises-inventaire/{$prise}/annuler")->assertUnprocessable();
        $this->postJson("/api/prises-inventaire/{$prise}/valider")->assertUnprocessable();
        // Le secteur est libéré pour une nouvelle session.
        $this->nouvellePrise([$this->a]);

        $autre = Tenant::factory()->create();
        $this->connecter($autre, 'admin');
        $this->getJson("/api/prises-inventaire/{$prise}")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/prises-inventaire')->json());
        $this->postJson('/api/prises-inventaire', ['libelle' => 'X', 'secteur_ids' => [$this->a->id]])->assertUnprocessable();
    }

    /** Réception rangée dans un secteur (insertion directe, comme l'enregistrerait l'écran des réceptions). */
    private function recevoir(string $numero, int $quantite, Secteur $secteur): void
    {
        $this->chez(function () use ($numero, $quantite, $secteur) {
            $fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Fournisseur']);
            $commande = ComFourEntete::create([
                'numero' => 'BC-0001', 'fournisseur_id' => $fournisseur->id, 'date_commande' => now()->toDateString(),
                'statut' => ComFourEntete::STATUT_ENVOYEE, 'montant_total' => 0, 'created_by' => $this->admin->id,
            ]);
            $ligne = ComFourLigne::create([
                'com_four_entete_id' => $commande->id, 'produit_id' => ProduitTenant::where('numero', $numero)->value('id'),
                'quantite_commandee' => $quantite, 'quantite_recue' => 0, 'prix_unitaire' => 1, 'montant_ligne' => $quantite,
            ]);
            DB::table('reception_arrivages_ligne')->insert([
                'com_four_ligne_id' => $ligne->id, 'date_reception' => now()->toDateString(), 'quantite_recue' => $quantite,
                'secteur_id' => $secteur->id, 'received_by' => $this->admin->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }
}
