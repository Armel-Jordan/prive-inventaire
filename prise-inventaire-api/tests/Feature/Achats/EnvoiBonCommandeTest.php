<?php

namespace Tests\Feature\Achats;

use App\Mail\CourrielSimple;
use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ComFourEntete;
use App\Models\ComFourLigne;
use App\Models\Configuration;
use App\Models\Fournisseur;
use App\Models\FournisseurContact;
use App\Models\HistoriquePrixAchat;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Services\BonCommandePdf;
use App\Support\TenantContext;
use ArrayObject;
use Barryvdh\DomPDF\PDF as DocumentPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bon de commande passé au fournisseur (phase 3.3) : destinataires proposés d'après les contacts qui reçoivent les
 * commandes (et, côté clients, les factures), brouillon validé par l'envoi du courriel avec le jour et l'auteur de
 * l'envoi, PDF joint sans la mention « Brouillon », droit de valider exigé, envoi raté sans effet sur la commande.
 */
class EnvoiBonCommandeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    private int $fournisseur;

    private int $produit;

    private int $commandes = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // Envoi pendant la requête et courriels gardés en mémoire, comme dans les tests des envois (la CI part de .env.example).
        config(['queue.default' => 'sync', 'mail.default' => 'array']);

        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Joseph Acheteur']);
        $this->chez(function () {
            TenantParametres::create(['nom_entreprise' => 'Quincaillerie Test', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF']);
            Configuration::create(['entite' => 'facture', 'prefixe' => 'FAC', 'separateur' => '-', 'longueur' => 4, 'auto_increment' => true, 'prochain_numero' => 1]);
            $this->fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Cimenterie du Sud', 'email' => 'info@cimenterie.cm', 'actif' => true])->id;
            $this->produit = ProduitTenant::create(['numero' => 'CIM', 'description' => 'Ciment 50 kg', 'prix_unitaire' => 100])->id;
        });
        $this->agir($this->admin);
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $this->tenant->slug);
    }

    /** Commande de 10 sacs à 80, au statut voulu. */
    private function commande(string $statut = ComFourEntete::STATUT_BROUILLON): int
    {
        $numero = 'CF-2026-'.str_pad((string) ++$this->commandes, 4, '0', STR_PAD_LEFT);

        return $this->chez(function () use ($statut, $numero) {
            $commande = ComFourEntete::create([
                'numero' => $numero, 'fournisseur_id' => $this->fournisseur, 'date_commande' => '2026-10-15',
                'statut' => $statut, 'created_by' => $this->admin->id,
            ]);
            ComFourLigne::create(['com_four_entete_id' => $commande->id, 'produit_id' => $this->produit, 'quantite_commandee' => 10, 'prix_unitaire' => 80]);

            return $commande->id;
        });
    }

    private function envoyer(int $commandeId)
    {
        return $this->postJson('/api/courriels/envois', [
            'type' => 'commande_fournisseur', 'document_id' => $commandeId, 'langue' => 'fr',
            'destinataires' => ['achats@cimenterie.cm'], 'objet' => 'Notre commande', 'contenu' => 'Bonjour, voici notre commande.',
        ]);
    }

    private function etat(int $commandeId): ComFourEntete
    {
        return $this->chez(fn () => ComFourEntete::findOrFail($commandeId));
    }

    public function test_destinataires_proposes_d_apres_les_contacts(): void
    {
        $commande = $this->commande();
        // Sans contact qui reçoit les commandes : le courriel du fournisseur.
        $this->getJson("/api/courriels/preparer?type=commande_fournisseur&document_id={$commande}")->assertOk()
            ->assertJsonPath('destinataires', ['info@cimenterie.cm'])
            ->assertJsonPath('statut_document', 'brouillon');

        $this->chez(function () {
            $contact = fn (array $champs) => FournisseurContact::create($champs + ['fournisseur_id' => $this->fournisseur, 'recoit_commandes' => true, 'actif' => true]);
            $contact(['nom' => 'Awa', 'email' => 'awa@cimenterie.cm']);
            $contact(['nom' => 'Bruno', 'email' => 'bruno@cimenterie.cm', 'principal' => true]);
            $contact(['nom' => 'Chantal', 'email' => 'chantal@cimenterie.cm', 'actif' => false]);
            $contact(['nom' => 'Dieudonné', 'email' => 'dieudonne@cimenterie.cm', 'recoit_commandes' => false]);
            $contact(['nom' => 'Éric', 'email' => null]);
            $contact(['nom' => 'Fanny', 'email' => 'AWA@cimenterie.cm']);
        });
        // Les contacts actifs qui reçoivent les commandes, le principal d'abord, sans doublon ni adresse vide.
        $this->getJson("/api/courriels/preparer?type=commande_fournisseur&document_id={$commande}")->assertOk()
            ->assertJsonPath('destinataires', ['bruno@cimenterie.cm', 'awa@cimenterie.cm']);

        // Côté clients : la facture va aux contacts qui reçoivent les factures, sinon au courriel du client.
        $client = $this->chez(fn () => Client::create([
            'code' => 'CLI-1', 'raison_sociale' => 'Bâtisseurs SA', 'adresse_facturation' => '1 rue du Port', 'ville' => 'Douala',
            'code_postal' => '00000', 'email' => 'contact@batisseurs.cm',
        ])->id);
        $facture = $this->postJson('/api/factures', [
            'client_id' => $client, 'date_facture' => '2026-10-15',
            'lignes' => [['produit_id' => $this->produit, 'quantite' => 1, 'prix_unitaire_ht' => 100]],
        ])->assertCreated()->json('id');
        $this->getJson("/api/courriels/preparer?type=facture&document_id={$facture}")->assertOk()
            ->assertJsonPath('destinataires', ['contact@batisseurs.cm']);
        $this->chez(fn () => ClientContact::create([
            'client_id' => $client, 'nom' => 'Comptabilité', 'email' => 'compta@batisseurs.cm', 'recoit_factures' => true, 'actif' => true,
        ]));
        $this->getJson("/api/courriels/preparer?type=facture&document_id={$facture}")->assertOk()
            ->assertJsonPath('destinataires', ['compta@batisseurs.cm']);
    }

    public function test_envoyer_un_brouillon_le_valide_et_joint_le_bon_tel_qu_il_part(): void
    {
        Mail::fake();
        // Le PDF joint est construit avant l'envoi : il doit déjà porter le statut de la commande une fois envoyée.
        $statuts = new ArrayObject;
        $this->app->instance(BonCommandePdf::class, new class($statuts) extends BonCommandePdf
        {
            public function __construct(private ArrayObject $statuts) {}

            public function pdf(ComFourEntete $commande): DocumentPdf
            {
                $this->statuts[] = [$commande->statut, $commande->envoyee_le !== null];

                return parent::pdf($commande);
            }
        });
        $commande = $this->commande();

        $this->envoyer($commande)->assertCreated()->assertJsonPath('statut', 'envoye');

        Mail::assertSent(CourrielSimple::class, fn (CourrielSimple $c) => $c->fichiers[0]['nom'] === 'bon-commande-CF-2026-0001.pdf');
        $this->assertSame([['envoyee', true]], $statuts->getArrayCopy());
        $envoyee = $this->etat($commande);
        $this->assertSame(ComFourEntete::STATUT_ENVOYEE, $envoyee->statut);
        $this->assertNotNull($envoyee->envoyee_le);
        $this->assertSame($this->admin->id, $envoyee->envoyee_par);
        $historique = $this->chez(fn () => HistoriquePrixAchat::where('com_four_entete_id', $commande)->get());
        $this->assertCount(1, $historique);
        $this->assertEquals(80, $historique[0]->prix_achat);

        // Renvoyée plus tard : la commande ne bouge plus (jour d'envoi et prix déjà notés).
        $this->travel(2)->days();
        $this->envoyer($commande)->assertCreated()->assertJsonPath('statut', 'envoye');
        $this->assertEquals($envoyee->envoyee_le, $this->etat($commande)->envoyee_le);
        $this->assertSame(1, $this->chez(fn () => HistoriquePrixAchat::where('com_four_entete_id', $commande)->count()));

        // Le bouton « Valider et envoyer » note aussi le jour et l'auteur ; une commande envoyée ne se revalide pas.
        $autre = $this->commande();
        $this->postJson("/api/commandes-fournisseur/{$autre}/valider")->assertOk()
            ->assertJsonPath('commande.statut', 'envoyee')
            ->assertJsonPath('commande.envoyee_par', $this->admin->id);
        $this->assertNotNull($this->etat($autre)->envoyee_le);
        $this->getJson("/api/commandes-fournisseur/{$autre}")->assertOk()->assertJsonPath('auteur_envoi.nom', 'Joseph Acheteur');
        $this->postJson("/api/commandes-fournisseur/{$autre}/valider")->assertUnprocessable();
    }

    public function test_envoyer_un_brouillon_exige_le_droit_de_valider(): void
    {
        Mail::fake();
        $brouillon = $this->commande();
        $envoyee = $this->commande(ComFourEntete::STATUT_ENVOYEE);
        $acheteur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$acheteur->id}", ['groupes' => [], 'exceptions' => ['commandes_fournisseur.valider' => false]])->assertOk();
        $this->agir($acheteur);

        $this->envoyer($brouillon)->assertForbidden()
            ->assertJsonPath('module', 'commandes_fournisseur')->assertJsonPath('action', 'valider');
        $this->assertSame(ComFourEntete::STATUT_BROUILLON, $this->etat($brouillon)->statut);
        Mail::assertNothingSent();

        // Une commande déjà envoyée se renvoie sans ce droit : l'envoi ne la change pas.
        $this->envoyer($envoyee)->assertCreated()->assertJsonPath('statut', 'envoye');
    }

    public function test_un_envoi_rate_ne_valide_pas_la_commande(): void
    {
        $this->putJson('/api/courriels/parametres', [
            'transport' => 'smtp', 'smtp_hote' => '127.0.0.1', 'smtp_port' => 1, 'smtp_chiffrement' => 'aucun',
            'expediteur_adresse' => 'achats@quincaillerie.cm',
        ])->assertOk();
        $commande = $this->commande();

        $envoi = $this->envoyer($commande)->assertCreated()->assertJsonPath('statut', 'echec')->json('id');
        $this->assertSame(ComFourEntete::STATUT_BROUILLON, $this->etat($commande)->statut);
        $this->assertNull($this->etat($commande)->envoyee_le);

        // Serveur corrigé : la relance part et valide la commande, au nom de celui qui l'avait envoyée.
        $this->putJson('/api/courriels/parametres', ['transport' => 'plateforme'])->assertOk();
        Mail::fake();
        $this->postJson("/api/courriels/envois/{$envoi}/reessayer")->assertOk()->assertJsonPath('statut', 'envoye');
        $this->assertSame(ComFourEntete::STATUT_ENVOYEE, $this->etat($commande)->statut);
        $this->assertSame($this->admin->id, $this->etat($commande)->envoyee_par);
    }
}
