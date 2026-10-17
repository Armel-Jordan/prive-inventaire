<?php

namespace Tests\Feature\Achats;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\ReliquatClient;
use App\Models\ScanTenant;
use App\Models\SuggestionAchat;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Suggestions d'achat (phase 3.2) : stock sous le minimum remonté jusqu'au maximum (avec ou sans ce qui est déjà en
 * commande), reliquats clients pas encore commandés, suggestion manuelle signalée depuis un produit, acceptation,
 * fournisseur et quantité changés, commandes fournisseur par fournisseur (ajoutées à une commande en préparation),
 * reliquat rattaché à sa ligne puis libéré par l'annulation ; seuil maximum ; droits et isolation.
 */
class SuggestionsAchatTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    /** @var array<string, int> */
    private array $produits;

    private int $f1;

    private int $f2;

    private int $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-17 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['achats', 'ventes']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Joseph Acheteur']);
        $this->chez(function () {
            $this->f1 = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimenterie du Sud', 'actif' => true])->id;
            $this->f2 = Fournisseur::create(['code' => 'F2', 'raison_sociale' => 'Sablière', 'actif' => true])->id;
            $this->clientId = Client::create(['code' => 'CLI-A', 'raison_sociale' => 'Chantier Bonapriso', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id;
            $produit = fn (string $numero, float $seuil, ?float $maximum, ?int $fournisseur) => ProduitTenant::create([
                'numero' => $numero, 'description' => "Produit {$numero}", 'prix_unitaire' => 100, 'seuil_alerte' => $seuil,
                'stock_maximum' => $maximum, 'fournisseur_principal_id' => $fournisseur,
            ])->id;
            $this->produits = [
                'CIM' => $produit('CIM', 10, 50, $this->f1),
                'FER' => $produit('FER', 20, null, $this->f1),
                'SAB' => $produit('SAB', 5, null, $this->f2),
                'GRA' => $produit('GRA', 3, null, null),
                'VIS' => $produit('VIS', 0, null, $this->f1),
            ];
            DB::table('produit_fournisseur')->insert(['produit_id' => $this->produits['CIM'], 'fournisseur_id' => $this->f1, 'prix_achat' => 80, 'fournisseur_principal' => true, 'created_at' => now(), 'updated_at' => now()]);
            foreach (['CIM' => 4, 'FER' => 5, 'SAB' => 10, 'VIS' => 0] as $numero => $quantite) {
                ScanTenant::create(['numero' => $numero, 'quantite' => $quantite, 'employe' => 'Paul', 'secteur' => 'A-01', 'date_saisie' => now()]);
            }
            // 8 fers déjà commandés (envoyés) au dernier prix de 900.
            $envoyee = ComFourEntete::create(['numero' => 'BC-EXT-1', 'fournisseur_id' => $this->f1, 'date_commande' => '2026-10-01', 'statut' => ComFourEntete::STATUT_ENVOYEE, 'taux_change' => 1, 'created_by' => $this->admin->id]);
            ComFourLigne::create(['com_four_entete_id' => $envoyee->id, 'produit_id' => $this->produits['FER'], 'quantite_commandee' => 8, 'quantite_recue' => 0, 'qte_par_unite_achat' => 1, 'prix_unitaire' => 900, 'montant_ligne' => 7200]);
            // Le client attend 4 sacs de sable (reliquat inscrit).
            ReliquatClient::create(['client_id' => $this->clientId, 'produit_id' => $this->produits['SAB'], 'quantite' => 4, 'prix_unitaire_ht' => 50, 'statut' => ReliquatClient::CREE]);
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

    /** Suggestions ouvertes : numéro du produit => [source, quantité suggérée, fournisseur]. */
    private function ouvertes(): array
    {
        $liste = [];
        foreach ($this->getJson('/api/suggestions-achat')->assertOk()->json() as $s) {
            $liste[$s['produit']['numero'].'|'.$s['source']] = [$s['quantite_suggeree'], $s['fournisseur']['code'] ?? null, $s['statut']];
        }
        ksort($liste);

        return $liste;
    }

    public function test_du_calcul_aux_commandes_fournisseur(): void
    {
        // Stock sous le minimum (en tenant compte de ce qui est déjà commandé) et reliquat client.
        $this->postJson('/api/suggestions-achat/generer', ['inventaire' => true, 'reliquats' => true, 'avec_commandes' => true])
            ->assertOk()->assertJsonPath('message', '4 suggestion(s) proposée(s).');
        $this->assertSame([
            'CIM|inventaire' => [46, 'F1', 'en_attente'], 'FER|inventaire' => [7, 'F1', 'en_attente'],
            'GRA|inventaire' => [3, null, 'en_attente'], 'SAB|reliquat' => [4, 'F2', 'en_attente'],
        ], $this->ouvertes());
        $liste = collect($this->getJson('/api/suggestions-achat')->json())->keyBy(fn ($s) => $s['produit']['numero']);
        $this->assertEquals([4, 0, 80], [$liste['CIM']['disponible'], $liste['CIM']['en_commande'], $liste['CIM']['dernier_cout']]);
        $this->assertEquals([8, 900], [$liste['FER']['en_commande'], $liste['FER']['dernier_cout']]);
        $this->assertSame('Chantier Bonapriso', $liste['SAB']['reliquat']['client']['raison_sociale']);
        // Sans compter les commandes en cours : 15 fers.
        $this->postJson('/api/suggestions-achat/generer', ['inventaire' => true, 'reliquats' => true, 'avec_commandes' => false])->assertOk();
        $this->assertSame([15, 'F1', 'en_attente'], $this->ouvertes()['FER|inventaire']);

        // Suggestion manuelle depuis un produit : une suggestion ouverte existe déjà, la confirmer en crée une seconde.
        $this->postJson('/api/suggestions-achat', ['produit_id' => $this->produits['SAB'], 'quantite' => 6])->assertUnprocessable()
            ->assertJsonValidationErrors(['deja' => 'Une suggestion existe déjà pour ce produit (4 unité(s), en attente).']);
        $manuelle = $this->postJson('/api/suggestions-achat', ['produit_id' => $this->produits['SAB'], 'quantite' => 6, 'forcer' => true])->assertCreated()->json();
        $this->assertSame(['manuelle', 'Suggestion manuelle', 'Joseph Acheteur', 'en_attente'], [$manuelle['source'], $manuelle['remarque'], $manuelle['commis_nom'], $manuelle['statut']]);

        // Acceptation (quantité suggérée retenue, nom de qui accepte), quantité changée ; pas de commande sans fournisseur.
        $ids = collect($this->getJson('/api/suggestions-achat')->json())->keyBy(fn ($s) => $s['produit']['numero'].'|'.$s['source'])->map(fn ($s) => $s['id']);
        $this->postJson('/api/suggestions-achat/accepter', ['ids' => $ids->values()->all(), 'accepte' => true])->assertOk()->assertJsonPath('nombre', 5);
        $this->putJson("/api/suggestions-achat/{$ids['CIM|inventaire']}", ['quantite' => 40])->assertOk()->assertJsonPath('quantite', 40);
        $this->postJson('/api/suggestions-achat/commander')->assertUnprocessable()
            ->assertJsonValidationErrors(['fournisseur_id' => "Choisissez le fournisseur de GRA : il n'a pas de fournisseur principal."]);
        $this->putJson("/api/suggestions-achat/{$ids['GRA|inventaire']}", ['fournisseur_id' => $this->f2])->assertOk();

        // Commandes : une par fournisseur ; chez F2, ajoutées à la commande en préparation.
        $brouillon = $this->chez(function () {
            $commande = ComFourEntete::create(['numero' => 'BC-EXT-2', 'fournisseur_id' => $this->f2, 'date_commande' => '2026-10-15', 'statut' => ComFourEntete::STATUT_BROUILLON, 'taux_change' => 1, 'created_by' => $this->admin->id]);
            ComFourLigne::create(['com_four_entete_id' => $commande->id, 'produit_id' => $this->produits['SAB'], 'quantite_commandee' => 2, 'quantite_recue' => 0, 'qte_par_unite_achat' => 1, 'prix_unitaire' => 30, 'montant_ligne' => 60]);

            return $commande->id;
        });
        $resultat = $this->postJson('/api/suggestions-achat/commander', ['fusionner' => true])->assertCreated()->json();
        $this->assertSame(['BC-00001', 'BC-EXT-2'], collect($resultat['commandes'])->pluck('numero')->sort()->values()->all());
        $this->assertSame('2 commande(s) fournisseur en préparation : BC-00001, BC-EXT-2.', $resultat['message']);
        $lignes = fn (string $numero) => $this->chez(fn () => ComFourEntete::where('numero', $numero)->first()->lignes()->orderBy('id')->get()
            ->map(fn ($l) => [ProduitTenant::find($l->produit_id)->numero, $l->quantite_commandee, (float) $l->prix_unitaire])->all());
        $this->assertSame([['CIM', 40, 80.0], ['FER', 15, 900.0]], $lignes('BC-00001'));
        $this->assertSame([['SAB', 12, 30.0], ['GRA', 3, 0.0]], $lignes('BC-EXT-2'));
        $reliquat = $this->chez(fn () => ReliquatClient::first());
        $this->assertSame([ReliquatClient::COMMANDE, 4], [$reliquat->statut, $reliquat->quantite_commandee]);
        $this->assertSame([], $this->getJson('/api/suggestions-achat')->json());
        $commandees = $this->getJson('/api/suggestions-achat?statut=commandees')->assertOk()->json();
        $this->assertCount(5, $commandees);
        $this->assertEqualsCanonicalizing(['BC-00001', 'BC-00001', 'BC-EXT-2', 'BC-EXT-2', 'BC-EXT-2'], array_map(fn ($s) => $s['ligne_commande']['commande']['numero'], $commandees));
        $this->postJson('/api/suggestions-achat/commander')->assertUnprocessable()->assertJsonPath('message', 'Aucune suggestion acceptée : cochez celles à commander.');

        // Tout est couvert par les commandes en préparation : plus rien à suggérer ; la commande annulée libère le reliquat.
        $this->postJson('/api/suggestions-achat/generer', ['inventaire' => true, 'reliquats' => true, 'avec_commandes' => true])->assertJsonPath('nombre', 0);
        $this->postJson("/api/commandes-fournisseur/{$brouillon}/annuler")->assertOk();
        $this->postJson('/api/suggestions-achat/generer', ['inventaire' => false, 'reliquats' => true])->assertJsonPath('nombre', 1);
        $this->assertSame(['SAB|reliquat' => [4, 'F2', 'en_attente']], $this->ouvertes());
        // Une suggestion en attente se retire ; une commandée, non.
        $this->deleteJson('/api/suggestions-achat/'.$this->getJson('/api/suggestions-achat')->json('0.id'))->assertOk();
        $this->deleteJson("/api/suggestions-achat/{$ids['CIM|inventaire']}")->assertUnprocessable()->assertJsonPath('message', 'Cette suggestion est déjà commandée.');

        // Seuil maximum (écran des alertes) : pas sous le seuil.
        $this->putJson("/api/alertes/produit/{$this->produits['VIS']}/seuil", ['seuil_alerte' => 10, 'stock_maximum' => 5])->assertUnprocessable()
            ->assertJsonValidationErrors(['stock_maximum' => "Le maximum ne peut pas être inférieur au seuil d'alerte."]);
        $this->putJson("/api/alertes/produit/{$this->produits['VIS']}/seuil", ['seuil_alerte' => 10, 'stock_maximum' => 60])->assertOk();
        $this->postJson('/api/suggestions-achat/generer', ['inventaire' => true, 'reliquats' => false, 'produit_id' => $this->produits['VIS']])->assertJsonPath('nombre', 1);
        $this->assertSame([60, 'F1', 'en_attente'], $this->ouvertes()['VIS|inventaire']);
    }

    public function test_droits_et_isolation(): void
    {
        $this->postJson('/api/suggestions-achat/generer', ['inventaire' => true, 'reliquats' => true])->assertOk();
        $suggestion = $this->getJson('/api/suggestions-achat')->json('0.id');
        $commis = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $regler = function (array $exceptions) use ($commis) {
            $this->agir($this->admin);
            $this->putJson("/api/acces-comptes/{$commis->id}", ['groupes' => [], 'exceptions' => $exceptions])->assertOk();
            $this->agir($commis->fresh());
        };

        // Signaler un besoin sans pouvoir gérer les suggestions ni commander.
        $regler(['suggestions_achat.gerer' => false, 'suggestions_achat.suggerer' => true]);
        $this->postJson('/api/suggestions-achat/generer', ['inventaire' => true])->assertForbidden()
            ->assertJsonPath('message', "Droit insuffisant : votre rôle ne permet pas de faire « Gérer les suggestions d'achat » (Suggestions d'achat).");
        $this->postJson('/api/suggestions-achat/accepter', ['ids' => [$suggestion], 'accepte' => true])->assertForbidden();
        $this->postJson('/api/suggestions-achat', ['produit_id' => $this->produits['VIS'], 'quantite' => 2])->assertCreated();
        $regler(['suggestions_achat.suggerer' => false]);
        $this->postJson('/api/suggestions-achat', ['produit_id' => $this->produits['VIS'], 'quantite' => 2, 'forcer' => true])->assertForbidden()
            ->assertJsonPath('message', "Droit insuffisant : votre rôle ne permet pas de faire « Créer une suggestion d'achat » (Suggestions d'achat).");
        // Commander demande aussi le droit de créer des commandes fournisseur.
        $regler(['suggestions_achat.gerer' => true, 'commandes_fournisseur.creer' => false]);
        $this->postJson('/api/suggestions-achat/accepter', ['ids' => [$suggestion], 'accepte' => true])->assertOk();
        $this->postJson('/api/suggestions-achat/commander')->assertForbidden()->assertJsonPath('action', 'creer');

        $this->agir($this->admin);
        $modules = collect(collect($this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets'))->firstWhere('cle', 'achats')['modules'])->keyBy('cle');
        $this->assertSame(['suggestions_achat.voir', 'suggestions_achat.gerer', 'suggestions_achat.suggerer'], array_column($modules['suggestions_achat']['permissions'], 'code'));

        // Une autre entreprise ne voit ni ne touche les suggestions.
        $autre = Tenant::factory()->create(['modules' => ['achats']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']), $autre);
        $this->assertSame([], $this->getJson('/api/suggestions-achat')->assertOk()->json());
        $this->putJson("/api/suggestions-achat/{$suggestion}", ['quantite' => 1])->assertNotFound();
        $this->deleteJson("/api/suggestions-achat/{$suggestion}")->assertNotFound();
        $this->postJson('/api/suggestions-achat', ['produit_id' => $this->produits['CIM'], 'quantite' => 1])->assertUnprocessable()->assertJsonValidationErrors('produit_id');
        $this->assertSame(0, $this->postJson('/api/suggestions-achat/generer', ['inventaire' => true, 'reliquats' => true])->assertOk()->json('nombre'));
        $this->assertSame(1, $this->chez(fn () => SuggestionAchat::where('statut', 'acceptee')->count()));
    }
}
