<?php

namespace Tests\Feature\Achats;

use App\Models\AdminUser;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\ConditionPaiement;
use App\Models\Configuration;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\ReceptionArrivagesLigne;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Factures fournisseurs (phase 3.5) : facture proposée depuis des réceptions (qui passent facturées), rapprochement
 * avec la valeur reçue et alignement des coûts, taxes et échéance, paiements et état (impayée, partielle, payée, en
 * retard, annulée), doublons, devises, annulation ; droits et isolation.
 */
class FacturesFournisseursTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private Fournisseur $fournisseur;

    /** @var array<string, int> */
    private array $produits = [];

    private int $commandes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-16 10:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['achats', 'ventes']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Awa Comptable']);
        $this->chez(function () {
            TenantParametres::create(['nom_entreprise' => 'Quincaillerie Test', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF', 'tva_taux' => 19.25]);
            $this->fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimenterie du Sud', 'actif' => true, 'devise' => 'XAF']);
            foreach (['CIM' => 'Ciment 50 kg', 'FER' => 'Fer à béton'] as $numero => $description) {
                $this->produits[$numero] = ProduitTenant::create(['numero' => $numero, 'description' => $description, 'prix_unitaire' => 100])->id;
            }
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

    /** Commande envoyée en XAF ; lignes [produit, quantité, prix]. Renvoie les ids de ses lignes. */
    private function commande(array $lignes, ?Fournisseur $fournisseur = null): array
    {
        $numero = 'BC-'.str_pad((string) ++$this->commandes, 5, '0', STR_PAD_LEFT);

        return $this->chez(function () use ($lignes, $fournisseur, $numero) {
            $commande = ComFourEntete::create([
                'numero' => $numero, 'fournisseur_id' => ($fournisseur ?? $this->fournisseur)->id, 'date_commande' => '2026-10-15',
                'statut' => ComFourEntete::STATUT_ENVOYEE, 'devise' => 'XAF', 'taux_change' => 1, 'created_by' => $this->admin->id,
            ]);

            return array_map(fn (array $l) => ComFourLigne::create([
                'com_four_entete_id' => $commande->id, 'produit_id' => $this->produits[$l[0]], 'quantite_commandee' => $l[1], 'prix_unitaire' => $l[2],
            ])->id, $lignes);
        });
    }

    private function recevoir(array $lignes): array
    {
        return $this->postJson('/api/receptions/multiple', ['date_reception' => '2026-10-16', 'receptions' => $lignes])->assertCreated()->json('reception');
    }

    /** Facture manuelle d'une ligne, TVA 0. */
    private function factureManuelle(array $autres = []): array
    {
        return $this->postJson('/api/factures-fournisseurs', $autres + [
            'fournisseur_id' => $this->fournisseur->id, 'date_facture' => '2026-10-01',
            'lignes' => [['description' => 'Loyer entrepôt', 'quantite' => 1, 'prix_unitaire' => 1000, 'taux_tva' => 0]],
        ])->assertCreated()->json();
    }

    public function test_facture_depuis_des_receptions_rapprochee_et_alignee(): void
    {
        [$cim, $fer] = $this->commande([['CIM', 10, 50], ['FER', 5, 20]]);
        $r1 = $this->recevoir([['com_four_ligne_id' => $cim, 'quantite_recue' => 6], ['com_four_ligne_id' => $fer, 'quantite_recue' => 5, 'cout_unitaire' => 22]]);
        $r2 = $this->recevoir([['com_four_ligne_id' => $cim, 'quantite_recue' => 4]]);
        $trente = $this->chez(fn () => ConditionPaiement::create(['code' => '30J', 'libelle' => '30 jours', 'delai_jours' => 30, 'actif' => true])->id);

        // Proposition : une ligne par ligne reçue, au coût réel sinon au prix commandé.
        $proposition = $this->getJson("/api/factures-fournisseurs/proposition?receptions[]={$r1['id']}&receptions[]={$r2['id']}")->assertOk()
            ->assertJsonPath('fournisseur_id', $this->fournisseur->id)->assertJsonPath('devise', null)
            ->assertJsonCount(3, 'lignes')->assertJsonPath('lignes.0.description', 'CIM Ciment 50 kg — REC-00001')->json();
        $this->assertEquals([[6, 50], [5, 22], [4, 50]], array_map(fn (array $l) => [$l['quantite'], $l['prix_unitaire']], $proposition['lignes']));

        // Le fournisseur facture le ciment de la 1re livraison 52 et le fer 21 : écart de 6 × 2 − 5 × 1 = 7.
        $lignes = $proposition['lignes'];
        $lignes[0]['prix_unitaire'] = 52;
        $lignes[1]['prix_unitaire'] = 21;
        $facture = $this->postJson('/api/factures-fournisseurs', [
            'fournisseur_id' => $this->fournisseur->id, 'numero_fournisseur' => 'INV-778', 'date_facture' => '2026-10-16',
            'condition_paiement_id' => $trente, 'receptions' => [$r1['id'], $r2['id']],
            'lignes' => array_map(fn (array $l) => $l + ['taux_tva' => 19.25], $lignes),
        ])->assertCreated()
            ->assertJsonPath('numero', 'FF-00001')->assertJsonPath('date_echeance', '2026-11-15T00:00:00.000000Z')
            ->assertJsonPath('etat', 'impayee')->assertJsonPath('ecart', 7)
            ->assertJsonPath('lignes.0.valeur_recue', 300)->assertJsonPath('lignes.0.ecart', 12)
            ->assertJsonCount(2, 'receptions')->json();
        $this->assertEquals([617, 118.77, 735.77], array_map('floatval', [$facture['montant_ht'], $facture['montant_tva'], $facture['montant_ttc']]));
        $this->assertSame(735.77, $facture['reste_a_payer']);

        // Les réceptions sont facturées : filtre, lien, annulation refusée.
        $this->getJson('/api/receptions/documents?facturee=1')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.facture_fournisseur.numero', 'FF-00001');
        $this->getJson('/api/receptions/documents?facturee=0')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/receptions/documents/{$r1['id']}/annuler")->assertUnprocessable()
            ->assertJsonPath('message', "Cette réception est facturée (FF-00001) : annulez d'abord la facture fournisseur.");
        $this->getJson("/api/factures-fournisseurs/proposition?receptions[]={$r1['id']}")->assertUnprocessable()->assertJsonValidationErrors('receptions');

        // Même facture du fournisseur saisie deux fois : refusée.
        $this->postJson('/api/factures-fournisseurs', [
            'fournisseur_id' => $this->fournisseur->id, 'numero_fournisseur' => 'INV-778', 'date_facture' => '2026-10-16',
            'lignes' => [['description' => 'Doublon', 'quantite' => 1, 'prix_unitaire' => 1]],
        ])->assertUnprocessable()->assertJsonValidationErrors('numero_fournisseur');

        // Alignement : le coût réel des réceptions prend le prix facturé ; l'écart tombe à zéro.
        $this->postJson("/api/factures-fournisseurs/{$facture['id']}/aligner-couts")->assertOk()
            ->assertJsonPath('message', '2 coût(s) de réception aligné(s) sur la facture.')->assertJsonPath('facture.ecart', 0);
        $couts = ReceptionArrivagesLigne::where('reception_id', $r1['id'])->orderBy('id')->pluck('cout_unitaire')->map(fn ($c) => (float) $c)->all();
        $this->assertSame([52.0, 21.0], $couts);
        $this->postJson("/api/factures-fournisseurs/{$facture['id']}/aligner-couts")->assertOk()
            ->assertJsonPath('message', 'Les coûts des réceptions correspondent déjà à la facture.');

        // Annulée (sans paiement) : les réceptions redeviennent à facturer.
        $this->postJson("/api/factures-fournisseurs/{$facture['id']}/annuler", ['motif' => 'Mauvais fournisseur'])->assertOk()
            ->assertJsonPath('etat', 'annulee')->assertJsonPath('reste_a_payer', 0)->assertJsonPath('auteur_annulation.nom', 'Awa Comptable');
        $this->getJson('/api/receptions/documents?facturee=0')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_paiements_echeances_et_etats(): void
    {
        // Échue depuis 6 jours : en retard.
        $loyer = $this->factureManuelle(['numero_fournisseur' => 'LOY-10', 'date_echeance' => '2026-10-10']);
        $this->assertEquals(['en_retard', 6, 1000], [$loyer['etat'], $loyer['jours_retard'], $loyer['reste_a_payer']]);
        $payer = fn (int $id, float $montant) => $this->postJson("/api/factures-fournisseurs/{$id}/paiements", [
            'date_paiement' => '2026-10-16', 'montant' => $montant, 'mode' => 'virement', 'reference' => 'VIR-1',
        ]);
        $payer($loyer['id'], 400)->assertOk()->assertJsonPath('montant_paye', '400.00')->assertJsonPath('etat', 'en_retard')
            ->assertJsonPath('paiements.0.auteur.nom', 'Awa Comptable');
        $payer($loyer['id'], 700)->assertUnprocessable()->assertJsonValidationErrors('montant');
        $solde = $payer($loyer['id'], 600)->assertOk()->assertJsonPath('etat', 'payee')->json();
        $payer($loyer['id'], 1)->assertUnprocessable()->assertJsonPath('message', 'Cette facture est déjà payée.');
        $this->deleteJson("/api/factures-fournisseurs/{$loyer['id']}/paiements/{$solde['paiements'][1]['id']}")->assertOk()
            ->assertJsonPath('reste_a_payer', 600)->assertJsonPath('etat', 'en_retard');
        $this->putJson("/api/factures-fournisseurs/{$loyer['id']}", [
            'fournisseur_id' => $this->fournisseur->id, 'date_facture' => '2026-10-01', 'lignes' => [['description' => 'Loyer', 'quantite' => 1, 'prix_unitaire' => 900]],
        ])->assertUnprocessable()->assertJsonPath('message', 'Cette facture a déjà reçu un paiement : elle ne se modifie plus.');
        $this->postJson("/api/factures-fournisseurs/{$loyer['id']}/annuler")->assertUnprocessable();

        // Échéance d'après la condition de paiement (fin de mois) ; payée en partie et non échue : partielle.
        $fdm = $this->chez(fn () => ConditionPaiement::create(['code' => 'FDM30', 'libelle' => '30 jours fin de mois', 'delai_jours' => 30, 'fin_de_mois' => true, 'actif' => true])->id);
        $electricite = $this->factureManuelle(['date_facture' => '2026-10-16', 'condition_paiement_id' => $fdm, 'lignes' => [
            ['description' => 'Électricité', 'quantite' => 1, 'prix_unitaire' => 200],
        ]]);
        $this->assertSame(['2026-11-30T00:00:00.000000Z', 'impayee', 238.5], [$electricite['date_echeance'], $electricite['etat'], $electricite['reste_a_payer']]);
        $payer($electricite['id'], 100)->assertOk()->assertJsonPath('etat', 'partielle');

        // Devise étrangère : taux exigé ; les totaux de la liste sont dans la devise de l'entreprise.
        $this->postJson('/api/factures-fournisseurs', [
            'fournisseur_id' => $this->fournisseur->id, 'date_facture' => '2026-10-16', 'devise' => 'EUR',
            'lignes' => [['description' => 'Fret', 'quantite' => 1, 'prix_unitaire' => 10, 'taux_tva' => 0]],
        ])->assertUnprocessable()->assertJsonValidationErrors('taux_change');
        $this->factureManuelle(['date_facture' => '2026-10-16', 'date_echeance' => '2026-10-31', 'devise' => 'eur', 'taux_change' => 655.957, 'lignes' => [
            ['description' => 'Fret maritime', 'quantite' => 1, 'prix_unitaire' => 10, 'taux_tva' => 0],
        ]]);

        // Liste : à payer 600 + 138,50 + 6 559,57 ; en retard 600 ; filtres par état et recherche.
        $this->getJson('/api/factures-fournisseurs')->assertOk()
            ->assertJsonPath('resume.a_payer', 7298.07)->assertJsonPath('resume.en_retard', 600)
            ->assertJsonPath('resume.nombre_a_payer', 3)->assertJsonPath('resume.nombre_en_retard', 1);
        foreach (['etat=en_retard' => 1, 'etat=partielle' => 1, 'etat=impayee' => 1, 'etat=payee' => 0, 'recherche=LOY' => 1, 'recherche=Cimenterie' => 3] as $filtre => $nombre) {
            $this->getJson("/api/factures-fournisseurs?{$filtre}")->assertOk()->assertJsonCount($nombre, 'data');
        }

        // Annulation après le retrait de son paiement.
        $paiement = $this->getJson("/api/factures-fournisseurs/{$electricite['id']}")->json('paiements.0.id');
        $this->deleteJson("/api/factures-fournisseurs/{$electricite['id']}/paiements/{$paiement}")->assertOk();
        $this->postJson("/api/factures-fournisseurs/{$electricite['id']}/annuler")->assertOk()->assertJsonPath('etat', 'annulee');
        $this->getJson('/api/factures-fournisseurs?etat=annulee')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_controles_droits_et_isolation(): void
    {
        [$cim] = $this->commande([['CIM', 10, 50]]);
        $autre = $this->chez(fn () => Fournisseur::create(['code' => 'F2', 'raison_sociale' => 'Négoce', 'actif' => true, 'devise' => 'XAF']));
        [$ailleurs] = $this->commande([['FER', 2, 20]], $autre);
        $reception = $this->recevoir([['com_four_ligne_id' => $cim, 'quantite_recue' => 2]]);
        $etrangere = $this->recevoir([['com_four_ligne_id' => $ailleurs, 'quantite_recue' => 2]]);
        $ligne = fn (array $autres = []) => $autres + ['description' => 'Ciment', 'quantite' => 2, 'prix_unitaire' => 50];

        // Réception d'un autre fournisseur, ou ligne d'une réception non facturée : refusées.
        $this->postJson('/api/factures-fournisseurs', [
            'fournisseur_id' => $this->fournisseur->id, 'date_facture' => '2026-10-16', 'receptions' => [$etrangere['id']], 'lignes' => [$ligne()],
        ])->assertUnprocessable()->assertJsonValidationErrors('receptions');
        $this->postJson('/api/factures-fournisseurs', [
            'fournisseur_id' => $this->fournisseur->id, 'date_facture' => '2026-10-16',
            'lignes' => [$ligne(['reception_ligne_id' => $reception['lignes'][0]['id']])],
        ])->assertUnprocessable()->assertJsonValidationErrors('lignes.0.reception_ligne_id');
        $facture = $this->factureManuelle();

        // Un acheteur sans le droit de payer ni d'annuler.
        $acheteur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$acheteur->id}", ['groupes' => [], 'exceptions' => [
            'factures_fournisseurs.payer' => false, 'factures_fournisseurs.annuler' => false,
        ]])->assertOk();
        $this->agir($acheteur);
        $this->getJson("/api/factures-fournisseurs/{$facture['id']}")->assertOk();
        $this->postJson("/api/factures-fournisseurs/{$facture['id']}/paiements", ['date_paiement' => '2026-10-16', 'montant' => 10, 'mode' => 'especes'])->assertForbidden();
        $this->postJson("/api/factures-fournisseurs/{$facture['id']}/annuler")->assertForbidden();

        // Une autre entreprise ne voit ni ne paie ces factures.
        $concurrent = Tenant::factory()->create(['modules' => ['achats']]);
        $this->agir(AdminUser::factory()->for($concurrent)->create(['role' => 'admin']), $concurrent);
        $this->getJson('/api/factures-fournisseurs')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/factures-fournisseurs/{$facture['id']}")->assertNotFound();
        $this->postJson("/api/factures-fournisseurs/{$facture['id']}/paiements", ['date_paiement' => '2026-10-16', 'montant' => 10, 'mode' => 'especes'])->assertNotFound();

        // Numérotation désactivée : la facture est refusée.
        $this->agir($this->admin);
        $this->chez(fn () => Configuration::where('entite', 'facture_fournisseur')->update(['auto_increment' => false]));
        $this->postJson('/api/factures-fournisseurs', ['fournisseur_id' => $this->fournisseur->id, 'date_facture' => '2026-10-16', 'lignes' => [$ligne()]])
            ->assertUnprocessable()->assertJsonPath('message', 'Numéro requis : activez la numérotation automatique des factures fournisseurs (Configuration → Numérotation).');
    }
}
