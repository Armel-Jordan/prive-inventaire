<?php

namespace Tests\Feature\Fournisseurs;

use App\Models\AdminUser;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\ReceptionArrivagesLigne;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fiche fournisseur (plan de parité fripv2, phase 3.1) : synthèse des achats, historique, produits fournis,
 * contacts, adresses, coordonnées bancaires et remarques.
 */
class FicheFournisseurTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $acheteur;

    private int $fournisseurId;

    private int $ciment;

    private int $fer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');

        $this->tenant = Tenant::factory()->create(['modules' => ['achats']]);
        $this->acheteur = AdminUser::factory()->for($this->tenant)->create(['nom' => 'Paul Mbarga']);
        [$this->fournisseurId, $this->ciment, $this->fer] = $this->chezLeTenant(fn () => [
            Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimencam', 'email' => 'ventes@cimencam.cm'])->id,
            ProduitTenant::create(['numero' => 'CIM-50', 'description' => 'Ciment 50 kg', 'prix_unitaire' => 5000])->id,
            ProduitTenant::create(['numero' => 'FER-12', 'description' => 'Fer de 12', 'prix_unitaire' => 1200])->id,
        ]);
        $this->connecter($this->acheteur);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function connecter(AdminUser $utilisateur): void
    {
        Sanctum::actingAs($utilisateur, ['*']);
        $this->withHeader('X-Tenant-Slug', $utilisateur->tenant->slug);
    }

    /**
     * Commande au fournisseur : lignes [produit, quantité, prix], puis réceptions [ligne, quantité, date].
     * Par défaut dans la devise de l'entreprise (taux 1).
     */
    private function commande(string $numero, string $date, ?string $prevue, string $statut, array $lignes, array $receptions = [], string $devise = 'XAF', float $taux = 1): int
    {
        return $this->chezLeTenant(function () use ($numero, $date, $prevue, $statut, $lignes, $receptions, $devise, $taux) {
            $commande = ComFourEntete::create([
                'numero' => $numero, 'fournisseur_id' => $this->fournisseurId, 'date_commande' => $date,
                'date_livraison_prevue' => $prevue, 'statut' => $statut, 'created_by' => $this->acheteur->id,
                'devise' => $devise, 'taux_change' => $taux,
            ]);
            $ids = [];
            foreach ($lignes as [$produit, $quantite, $prix]) {
                $ids[] = ComFourLigne::create(['com_four_entete_id' => $commande->id, 'produit_id' => $produit, 'quantite_commandee' => $quantite, 'prix_unitaire' => $prix])->id;
            }
            foreach ($receptions as [$index, $quantite, $jour]) {
                ReceptionArrivagesLigne::create(['com_four_ligne_id' => $ids[$index], 'quantite_recue' => $quantite, 'date_reception' => $jour, 'received_by' => $this->acheteur->id]);
            }

            return $commande->id;
        });
    }

    private function achats(): void
    {
        // Livrée en entier, 2 jours après la date prévue : 11 jours de délai.
        $this->commande('BC-1', '2026-09-01', '2026-09-10', 'envoyee', [[$this->ciment, 10, 4200], [$this->fer, 5, 1000]], [[0, 10, '2026-09-08'], [1, 5, '2026-09-12']]);
        // Reçue en partie : 15 sacs encore attendus.
        $this->commande('BC-2', '2026-10-01', '2026-10-20', 'envoyee', [[$this->ciment, 20, 4300]], [[0, 5, '2026-10-05']]);
        // Ni les brouillons ni les commandes annulées ne comptent.
        $this->commande('BC-3', '2026-10-10', null, 'brouillon', [[$this->fer, 1, 1000]]);
        $this->commande('BC-4', '2026-10-11', null, 'annulee', [[$this->fer, 100, 1000]]);
    }

    public function test_synthese_des_achats_et_des_livraisons(): void
    {
        $this->achats();

        $this->getJson("/api/fournisseurs/{$this->fournisseurId}/synthese")->assertOk()
            ->assertJsonPath('achats.total', 133000)
            ->assertJsonPath('achats.annee_courante', 133000)
            ->assertJsonPath('achats.annee_precedente', 0)
            ->assertJsonPath('commandes.nombre', 2)
            ->assertJsonPath('commandes.brouillons', 1)
            ->assertJsonPath('commandes.montant_moyen', 66500)
            ->assertJsonPath('commandes.derniere.numero', 'BC-2')
            ->assertJsonPath('commandes.derniere.statut', 'partielle')
            ->assertJsonPath('a_recevoir.commandes', 1)
            ->assertJsonPath('a_recevoir.quantite', 15)
            ->assertJsonPath('a_recevoir.montant', 64500)
            ->assertJsonPath('livraisons.commandes_livrees', 1)
            ->assertJsonPath('livraisons.delai_moyen_jours', 11)
            ->assertJsonPath('livraisons.a_temps', 0)
            ->assertJsonPath('livraisons.taux_reception', 57.1)
            ->assertJsonCount(12, 'achats_mensuels')
            ->assertJsonPath('achats_mensuels.11', ['mois' => '2026-10', 'montant' => 86000])
            ->assertJsonPath('achats_mensuels.10', ['mois' => '2026-09', 'montant' => 47000])
            ->assertJsonPath('produits_les_plus_achetes.0.numero', 'CIM-50')
            ->assertJsonPath('produits_les_plus_achetes.0.montant', 128000)
            ->assertJsonPath('produits_les_plus_achetes.0.commandes', 2)
            ->assertJsonPath('produits_les_plus_achetes.1.numero', 'FER-12');
    }

    public function test_achats_en_devise_etrangere_convertis_au_taux_de_la_commande(): void
    {
        // 10 × 2 EUR au taux de 655,957 : 13 119,14 dans la devise de l'entreprise.
        $this->commande('BC-EUR', '2026-10-12', null, 'envoyee', [[$this->fer, 10, 2]], [], 'EUR', 655.957);
        $this->commande('BC-XAF', '2026-10-01', null, 'envoyee', [[$this->ciment, 1, 5000]]);

        $this->getJson("/api/fournisseurs/{$this->fournisseurId}/synthese")->assertOk()
            ->assertJsonPath('achats.total', 18119.14)
            ->assertJsonPath('a_recevoir.montant', 18119.14)
            ->assertJsonPath('achats_mensuels.11.montant', 18119.14)
            ->assertJsonPath('produits_les_plus_achetes.0.numero', 'FER-12')
            ->assertJsonPath('produits_les_plus_achetes.0.montant', 13119.14)
            // La dernière commande garde son montant et sa devise.
            ->assertJsonPath('commandes.derniere', fn ($derniere) => $derniere['numero'] === 'BC-EUR' && $derniere['montant'] == 20 && $derniere['devise'] === 'EUR');

        // L'historique garde chaque ligne dans sa devise ; le total est converti.
        $this->getJson("/api/fournisseurs/{$this->fournisseurId}/historique-achats")->assertOk()
            ->assertJsonPath('data.0.montant', 20)
            ->assertJsonPath('data.0.devise', 'EUR')
            ->assertJsonPath('totaux.montant', 18119.14);
    }

    public function test_historique_filtre_avec_totaux(): void
    {
        $this->achats();
        $url = "/api/fournisseurs/{$this->fournisseurId}/historique-achats";

        $this->getJson($url)->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.commande_numero', 'BC-2')
            ->assertJsonPath('data.0.quantite_recue', 5)
            ->assertJsonPath('totaux', ['quantite_commandee' => 35, 'quantite_recue' => 20, 'montant' => 133000]);

        $this->getJson("{$url}?recherche=fer")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('totaux.montant', 5000);
        $this->getJson("{$url}?du=2026-10-01")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.produit_numero', 'CIM-50');
    }

    public function test_produits_fournis_un_seul_fournisseur_principal_et_dernier_prix(): void
    {
        $this->achats();
        $autre = $this->chezLeTenant(fn () => Fournisseur::create(['code' => 'F2', 'raison_sociale' => 'Dangote'])->id);
        DB::table('produit_fournisseur')->insert(['produit_id' => $this->ciment, 'fournisseur_id' => $autre, 'fournisseur_principal' => true]);
        $url = "/api/fournisseurs/{$this->fournisseurId}/produits";

        $this->postJson($url, ['produit_id' => $this->ciment, 'reference_fournisseur' => 'CPJ-45', 'prix_achat' => 4300, 'delai_livraison' => 7, 'fournisseur_principal' => true])
            ->assertCreated();
        $this->postJson($url, ['produit_id' => $this->ciment])->assertUnprocessable()->assertJsonValidationErrors('produit_id');
        $this->assertFalse((bool) DB::table('produit_fournisseur')->where('fournisseur_id', $autre)->value('fournisseur_principal'));

        $this->getJson($url)->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.reference_fournisseur', 'CPJ-45')
            ->assertJsonPath('0.prix_achat', 4300)
            ->assertJsonPath('0.fournisseur_principal', true)
            ->assertJsonPath('0.dernier_prix', 4300)
            ->assertJsonPath('0.derniere_commande', '2026-10-01');

        $this->putJson("{$url}/{$this->ciment}", ['prix_achat' => 4400, 'fournisseur_principal' => false])->assertOk();
        $this->assertSame(4400.0, (float) DB::table('produit_fournisseur')->where('fournisseur_id', $this->fournisseurId)->value('prix_achat'));
        $this->deleteJson("{$url}/{$this->ciment}")->assertOk();
        $this->deleteJson("{$url}/{$this->ciment}")->assertNotFound();
        $this->getJson("/api/fournisseurs/{$this->fournisseurId}/fiche")->assertOk()->assertJsonPath('produits_count', 0);
    }

    public function test_contacts_et_adresses_avec_un_seul_element_principal(): void
    {
        $contacts = "/api/fournisseurs/{$this->fournisseurId}/contacts";
        $premier = $this->postJson($contacts, ['nom' => 'Awa Ndiaye', 'principal' => true, 'recoit_commandes' => true])->assertCreated()->json('id');
        $this->postJson($contacts, ['nom' => 'Jean Ekane', 'principal' => true])->assertCreated();
        $this->getJson($contacts)->assertOk()->assertJsonCount(2)->assertJsonPath('0.nom', 'Jean Ekane')->assertJsonPath('1.principal', false);
        $this->putJson("{$contacts}/{$premier}", ['nom' => 'Awa Ndiaye', 'email' => 'pas-une-adresse'])->assertUnprocessable();

        $adresses = "/api/fournisseurs/{$this->fournisseurId}/adresses";
        $this->postJson($adresses, ['libelle' => 'Dépôt de Bonabéri', 'adresse' => 'Zone industrielle', 'enlevement' => true])
            ->assertCreated()->assertJsonPath('enlevement', true);
        $this->getJson("/api/fournisseurs/{$this->fournisseurId}/fiche")->assertOk()
            ->assertJsonPath('contacts_count', 2)->assertJsonPath('adresses_count', 1);
    }

    public function test_coordonnees_bancaires_saisies_par_un_responsable_et_tracees(): void
    {
        $comptes = "/api/fournisseurs/{$this->fournisseurId}/comptes-bancaires";

        $this->postJson($comptes, ['banque' => 'Afriland First Bank'])->assertUnprocessable()->assertJsonValidationErrors(['numero_compte', 'iban']);
        $this->postJson($comptes, ['banque' => 'Afriland First Bank', 'iban' => 'pas un iban'])->assertUnprocessable()->assertJsonValidationErrors('iban');

        $compte = $this->postJson($comptes, [
            'banque' => 'Afriland First Bank', 'iban' => 'fr76 3000 6000 0112 3456 7890 189', 'bic' => 'agrifrpp', 'devise' => 'xaf', 'principal' => true,
        ])->assertCreated()
            ->assertJsonPath('iban', 'FR7630006000011234567890189')
            ->assertJsonPath('bic', 'AGRIFRPP')
            ->assertJsonPath('devise', 'XAF')
            ->assertJsonPath('modifie_par', $this->acheteur->id)
            ->assertJsonPath('auteur_modification.nom', 'Paul Mbarga')
            ->json('id');

        // Un utilisateur simple consulte, sans pouvoir modifier.
        $this->connecter(AdminUser::factory()->for($this->tenant)->create(['role' => 'user']));
        $this->getJson($comptes)->assertOk()->assertJsonCount(1);
        $this->putJson("{$comptes}/{$compte}", ['banque' => 'Autre', 'numero_compte' => '123'])->assertForbidden();
        $this->deleteJson("{$comptes}/{$compte}")->assertForbidden();

        // Un manager modifie : il devient l'auteur de la dernière modification.
        $manager = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager', 'nom' => 'Chef achats']);
        $this->connecter($manager);
        $this->putJson("{$comptes}/{$compte}", ['banque' => 'Afriland First Bank', 'numero_compte' => '10005 00001 01234567890 11'])
            ->assertOk()->assertJsonPath('auteur_modification.nom', 'Chef achats');
    }

    public function test_remarques_de_l_auteur(): void
    {
        $remarques = "/api/fournisseurs/{$this->fournisseurId}/remarques";
        $id = $this->postJson($remarques, ['texte' => 'Livraisons en retard en saison des pluies.'])->assertCreated()
            ->assertJsonPath('auteur.nom', 'Paul Mbarga')->json('id');

        $this->connecter(AdminUser::factory()->for($this->tenant)->create(['role' => 'user']));
        $this->putJson("{$remarques}/{$id}", ['epinglee' => true])->assertForbidden();
        $this->getJson($remarques)->assertOk()->assertJsonCount(1);
    }

    public function test_fiche_d_un_autre_fournisseur_ou_d_une_autre_entreprise_introuvable(): void
    {
        $this->connecter(AdminUser::factory()->for(Tenant::factory()->create(['modules' => ['achats']]))->create());

        foreach (['fiche', 'synthese', 'historique-achats', 'produits', 'contacts', 'adresses', 'comptes-bancaires', 'remarques'] as $chemin) {
            $this->getJson("/api/fournisseurs/{$this->fournisseurId}/{$chemin}")->assertNotFound();
        }
    }
}
