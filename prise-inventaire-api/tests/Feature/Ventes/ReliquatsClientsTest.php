<?php

namespace Tests\Feature\Ventes;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ComFourEntete;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reliquats clients (phase 1.4) : reliquat d'une commande acceptée ou saisi à la main, commande au fournisseur
 * principal au dernier prix, réceptions réparties du plus ancien au plus récent, facturation des reliquats reçus,
 * commande fournisseur annulée, modifiée, supprimée ou close, contrôles et droits.
 */
class ReliquatsClientsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    private int $sansFournisseurId;

    private Fournisseur $principal;

    private Fournisseur $autre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->chez(function () {
            $this->principal = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimenterie', 'actif' => true]);
            $this->autre = Fournisseur::create(['code' => 'F2', 'raison_sociale' => 'Négoce', 'actif' => true]);
            $this->clientId = Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0'])->id;
            $this->produitId = ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment', 'prix_unitaire' => 150, 'fournisseur_principal_id' => $this->principal->id])->id;
            $this->sansFournisseurId = ProduitTenant::create(['numero' => 'SAB', 'description' => 'Sable', 'prix_unitaire' => 20])->id;
            // Prix d'achat convenu chez le fournisseur principal.
            DB::table('produit_fournisseur')->insert(['produit_id' => $this->produitId, 'fournisseur_id' => $this->principal->id, 'prix_achat' => 80, 'fournisseur_principal' => true, 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->agir('admin');
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(string $role): AdminUser
    {
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => $role]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);

        return $compte;
    }

    private function commandeAcceptee(int $quantite): array
    {
        $commande = $this->postJson('/api/commandes-client', [
            'client_id' => $this->clientId, 'date_commande' => '2026-10-16', 'date_livraison_souhaitee' => '2026-10-30',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => $quantite, 'prix_unitaire_ht' => 150, 'taux_tva' => 0]],
        ])->assertCreated()->json();
        $this->postJson("/api/commandes-client/{$commande['id']}/soumettre")->assertOk();
        $this->postJson("/api/commandes-client/{$commande['id']}/accepter")->assertOk();

        return [$commande['id'], $commande['lignes'][0]['id']];
    }

    private function reliquat(int $quantite, array $autres = []): int
    {
        return $this->postJson('/api/reliquats-clients', $autres + ['client_id' => $this->clientId, 'produit_id' => $this->produitId, 'quantite' => $quantite, 'prix_unitaire_ht' => 140])
            ->assertCreated()->assertJsonPath('statut', 'cree')->json('id');
    }

    private function commander(array $ids, array $autres = []): TestResponse
    {
        return $this->postJson('/api/reliquats-clients/commander', $autres + ['ids' => $ids]);
    }

    private function etats(): array
    {
        return collect($this->getJson('/api/reliquats-clients?per_page=100')->assertOk()->json('data'))
            ->mapWithKeys(fn (array $r) => [$r['id'] => [$r['statut'], $r['quantite_recue']]])->all();
    }

    private function recevoir(int $ligne, int $quantite): void
    {
        $this->postJson('/api/receptions', ['com_four_ligne_id' => $ligne, 'date_reception' => '2026-10-16', 'quantite_recue' => $quantite])->assertCreated();
    }

    public function test_reliquat_commande_au_fournisseur_recu_puis_facture(): void
    {
        [$commande, $ligne] = $this->commandeAcceptee(10);
        $this->getJson("/api/reliquats-clients/depuis-commande/{$commande}")->assertOk()->assertJsonPath('possible', true)
            ->assertJsonPath('lignes.0.quantite', 10)->assertJsonPath('lignes.0.deja_en_reliquat', 0);

        // Depuis la commande : au plus la quantité commandée, reliquats déjà notés déduits.
        $this->postJson("/api/reliquats-clients/depuis-commande/{$commande}", ['lignes' => [['ligne_id' => $ligne, 'quantite' => 11]]])
            ->assertUnprocessable()->assertJsonPath('errors', ['lignes.0.quantite' => ['Au plus 10 (quantité commandée, reliquats déjà notés déduits).']]);
        $depuisCommande = $this->postJson("/api/reliquats-clients/depuis-commande/{$commande}", ['lignes' => [['ligne_id' => $ligne, 'quantite' => 4]]])
            ->assertCreated()->json('0');
        $this->assertSame(['cree', 150, $commande, '2026-10-30'], [$depuisCommande['statut'], (int) $depuisCommande['prix_unitaire_ht'], $depuisCommande['commande_client_id'], substr($depuisCommande['date_requise'], 0, 10)]);
        $this->postJson("/api/reliquats-clients/depuis-commande/{$commande}", ['lignes' => [['ligne_id' => $ligne, 'quantite' => 7]]])
            ->assertUnprocessable()->assertJsonPath('errors', ['lignes.0.quantite' => ['Au plus 6 (quantité commandée, reliquats déjà notés déduits).']]);
        $aLaMain = $this->reliquat(2);

        // Commandés au fournisseur principal, au prix convenu : une commande brouillon, une ligne par produit.
        $commandes = $this->commander([$depuisCommande['id'], $aLaMain])->assertCreated()->json();
        $this->assertCount(1, $commandes);
        $this->assertSame([$this->principal->id, 'brouillon', 6, 80.0], [$commandes[0]['fournisseur_id'], $commandes[0]['statut'], $commandes[0]['lignes'][0]['quantite_commandee'], (float) $commandes[0]['lignes'][0]['prix_unitaire']]);
        $this->commander([$aLaMain])->assertUnprocessable();
        $this->postJson("/api/commandes-fournisseur/{$commandes[0]['id']}/valider")->assertOk();
        $ligneFournisseur = $commandes[0]['lignes'][0]['id'];

        // Réceptions réparties du plus ancien au plus récent.
        $this->recevoir($ligneFournisseur, 3);
        $this->assertSame([$depuisCommande['id'] => ['recu_partiel', 3], $aLaMain => ['commande', 0]], $this->etats());
        $this->postJson('/api/reliquats-clients/facturer', ['ids' => [$depuisCommande['id']], 'date_facture' => '2026-10-16'])
            ->assertUnprocessable()->assertJsonPath('message', 'Seuls les reliquats reçus se facturent : attendez la fin de la réception, ou clôturez la commande fournisseur.');
        $this->recevoir($ligneFournisseur, 3);
        $this->assertSame([$depuisCommande['id'] => ['recu', 4], $aLaMain => ['recu', 2]], $this->etats());

        // Facturés : une facture brouillon pour le client, aux quantités reçues et aux prix convenus.
        $factures = $this->postJson('/api/reliquats-clients/facturer', ['ids' => [$depuisCommande['id'], $aLaMain], 'date_facture' => '2026-10-16'])->assertCreated()->json();
        $this->assertCount(1, $factures);
        $facture = $this->getJson("/api/factures/{$factures[0]['id']}")->assertOk()->assertJsonPath('statut', 'brouillon')->json();
        $this->assertEquals([[4, 150], [2, 140]], array_map(fn ($l) => [$l['quantite'], (float) $l['prix_unitaire_ht']], $facture['lignes']));
        $this->assertSame([$depuisCommande['id'] => ['facture', 4], $aLaMain => ['facture', 2]], $this->etats());
        $liste = $this->getJson('/api/reliquats-clients?statut=ouverts')->assertOk()->json();
        $this->assertSame([], $liste['data']);
        $this->assertSame(2, $liste['resume']['facture']);
        $this->assertSame($factures[0]['numero'], $this->getJson("/api/reliquats-clients?recherche={$factures[0]['numero']}")->json('data.0.facture.numero'));
    }

    public function test_commande_fournisseur_annulee_modifiee_supprimee_ou_close(): void
    {
        $a = $this->reliquat(5);
        $b = $this->reliquat(3);

        // Annulée : les reliquats redeviennent inscrits.
        $commande = $this->commander([$a, $b])->assertCreated()->json('0');
        $this->postJson("/api/commandes-fournisseur/{$commande['id']}/annuler")->assertOk();
        $this->assertSame([$a => ['cree', 0], $b => ['cree', 0]], $this->etats());

        // Chez un autre fournisseur, puis la ligne est remplacée dans le brouillon : de nouveau inscrits.
        $autre = $this->commander([$a, $b], ['fournisseur_id' => $this->autre->id])->assertCreated()->json('0');
        $this->assertSame($this->autre->id, $autre['fournisseur_id']);
        $this->putJson("/api/commandes-fournisseur/{$autre['id']}", ['fournisseur_id' => $this->autre->id, 'date_commande' => '2026-10-16',
            'lignes' => [['produit_id' => $this->sansFournisseurId, 'quantite_commandee' => 1, 'prix_unitaire' => 5]]])->assertOk();
        $this->assertSame([$a => ['cree', 0], $b => ['cree', 0]], $this->etats());

        // Brouillon supprimé : de nouveau inscrits.
        $supprimee = $this->commander([$a])->assertCreated()->json('0');
        $this->deleteJson("/api/commandes-fournisseur/{$supprimee['id']}")->assertOk();
        $this->assertSame([$a => ['cree', 0], $b => ['cree', 0]], $this->etats());

        // Close après une réception partielle : celui qui a reçu se facture à la quantité reçue, l'autre est inscrit.
        $commande = $this->commander([$a, $b])->assertCreated()->json('0');
        $this->postJson("/api/commandes-fournisseur/{$commande['id']}/valider")->assertOk();
        $this->recevoir($commande['lignes'][0]['id'], 2);
        $this->postJson("/api/commandes-fournisseur/{$commande['id']}/cloturer")->assertOk();
        $this->assertSame([$a => ['recu', 2], $b => ['cree', 0]], $this->etats());
        $this->assertSame(ComFourEntete::STATUT_COMPLETE, $this->chez(fn () => ComFourEntete::find($commande['id'])->statut));

        // Un reliquat ne se modifie, ne s'annule et ne se supprime qu'inscrit.
        $this->putJson("/api/reliquats-clients/{$a}", ['quantite' => 9])->assertUnprocessable()->assertJsonPath('message', 'Seul un reliquat pas encore commandé au fournisseur se modifie.');
        $this->putJson("/api/reliquats-clients/{$b}", ['quantite' => 4, 'notes' => 'Urgent'])->assertOk()->assertJsonPath('quantite', 4);
        $this->postJson("/api/reliquats-clients/{$b}/annuler")->assertOk()->assertJsonPath('statut', 'annule');
        $this->deleteJson("/api/reliquats-clients/{$b}")->assertUnprocessable();
        $c = $this->reliquat(1);
        $this->deleteJson("/api/reliquats-clients/{$c}")->assertOk();

        // Produit sans fournisseur principal : il faut choisir le fournisseur ; le dernier prix qui lui a été payé
        // (5, ligne saisie plus haut dans le brouillon modifié) est repris.
        $sable = $this->reliquat(2, ['produit_id' => $this->sansFournisseurId]);
        $this->commander([$sable])->assertUnprocessable()->assertJsonPath('errors.fournisseur_id', ["Choisissez le fournisseur : Sable n'a pas de fournisseur principal."]);
        $this->commander([$sable], ['fournisseur_id' => $this->autre->id])->assertCreated()->assertJsonPath('0.lignes.0.prix_unitaire', '5.00');
    }

    public function test_droits_des_reliquats(): void
    {
        $reliquat = $this->reliquat(2);

        // Rôles système : un commercial (« user ») note un reliquat comme il saisit une commande client, mais ne
        // commande pas au fournisseur et ne facture pas (droits des commandes fournisseur et des factures).
        $this->agir('user');
        $this->getJson('/api/reliquats-clients')->assertOk();
        $this->postJson('/api/reliquats-clients', ['client_id' => $this->clientId, 'produit_id' => $this->produitId, 'quantite' => 1, 'prix_unitaire_ht' => 1])->assertCreated();
        $this->commander([$reliquat])->assertForbidden()
            ->assertJsonPath('message', 'Droit insuffisant : votre rôle ne permet pas de faire « Commander des reliquats au fournisseur » (Reliquats clients).');
        $this->postJson('/api/reliquats-clients/facturer', ['ids' => [$reliquat], 'date_facture' => '2026-10-16'])->assertForbidden();

        // Catalogue : module « Reliquats clients » des ventes, avec ses deux actions nommées.
        $this->agir('admin');
        $modules = collect($this->getJson('/api/permissions/catalogue')->assertOk()->json('onglets'))->firstWhere('cle', 'ventes')['modules'];
        $this->assertSame(['reliquats_clients.voir', 'reliquats_clients.creer', 'reliquats_clients.modifier', 'reliquats_clients.supprimer', 'reliquats_clients.commander', 'reliquats_clients.facturer'],
            array_column(collect($modules)->firstWhere('cle', 'reliquats_clients')['permissions'], 'code'));

        // Un groupe qui commande au fournisseur sans pouvoir facturer.
        $groupe = $this->postJson('/api/roles-custom', ['nom' => 'Approvisionnement',
            'permissions' => ['reliquats_clients' => ['can_view' => true, 'can_create' => true, 'can_edit' => true]],
            'actions' => ['reliquats_clients.commander' => true, 'reliquats_clients.facturer' => false]])->assertCreated()->json('role_id');
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'user']);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [$groupe], 'exceptions' => []])->assertOk();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte->fresh(), ['*']);
        $this->commander([$reliquat])->assertCreated();
        $this->postJson('/api/reliquats-clients/facturer', ['ids' => [$reliquat], 'date_facture' => '2026-10-16'])->assertForbidden();
    }
}
