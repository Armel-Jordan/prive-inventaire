<?php

namespace Tests\Feature\Courriels;

use App\Mail\CourrielSimple;
use App\Models\AdminUser;
use App\Models\Client;
use App\Models\ComFourEntete;
use App\Models\Configuration;
use App\Models\CourrielEnvoi;
use App\Models\Fournisseur;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Envoi des documents par courriel (plan de parité fripv2, phase 0.6b) : pré-remplissage depuis
 * le modèle, PDF joint, copies, accusé de lecture, échec enregistré, relances, historique.
 */
class CourrielsEnvoisTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $clientId;

    private int $produitId;

    protected function setUp(): void
    {
        parent::setUp();

        // Ces tests vérifient l'envoi pendant la requête, comme en production (QUEUE_CONNECTION=sync,
        // cf. .env.production.example), et lisent les courriels du transport « array ». La CI part de
        // .env.example (file « database », courriels « log ») : on fixe donc la file et le transport ici.
        config(['queue.default' => 'sync', 'mail.default' => 'array']);

        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        [$this->clientId, $this->produitId] = $this->chezLeTenant(function () {
            Configuration::create(['entite' => 'facture', 'prefixe' => 'FAC', 'separateur' => '-', 'longueur' => 4, 'auto_increment' => true, 'prochain_numero' => 1]);
            TenantParametres::create(['nom_entreprise' => 'Ma Société SARL', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF', 'email' => 'contact@masociete.cm']);
            $client = Client::create([
                'code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue du Test', 'ville' => 'Douala',
                'code_postal' => '00000', 'email' => 'achats@client.cm', 'contact_nom' => 'Awa Ndiaye',
            ]);
            $produit = ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Article', 'prix_unitaire' => 100]);

            return [$client->id, $produit->id];
        });
        $this->connecter($this->tenant);
    }

    private function connecter(Tenant $tenant): AdminUser
    {
        $utilisateur = AdminUser::factory()->for($tenant)->create(['nom' => 'Paul Mbarga']);
        Sanctum::actingAs($utilisateur, ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);

        return $utilisateur;
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    /** Facture de 10 × 100 HT (TVA par défaut 20 % : 1 200 TTC). */
    private function facture(): int
    {
        return $this->postJson('/api/factures', [
            'client_id' => $this->clientId, 'date_facture' => '2026-10-15',
            'lignes' => [['produit_id' => $this->produitId, 'quantite' => 10, 'prix_unitaire_ht' => 100]],
        ])->assertCreated()->json('id');
    }

    private function courriel(int $documentId, array $donnees = []): array
    {
        return $donnees + [
            'type' => 'facture', 'document_id' => $documentId, 'langue' => 'fr', 'destinataires' => ['achats@client.cm'],
            'objet' => 'Facture FAC-0001', 'contenu' => "Bonjour,\n\nVoici votre facture **FAC-0001**.",
        ];
    }

    private function smtpInjoignable(): void
    {
        $this->putJson('/api/courriels/parametres', [
            'transport' => 'smtp', 'smtp_hote' => '127.0.0.1', 'smtp_port' => 1, 'smtp_chiffrement' => 'aucun',
            'expediteur_adresse' => 'facturation@masociete.cm',
        ])->assertOk();
    }

    public function test_preparer_avec_le_modele_par_defaut_et_l_adresse_du_client(): void
    {
        $id = $this->facture();

        $reponse = $this->getJson("/api/courriels/preparer?type=facture&document_id={$id}")->assertOk()
            ->assertJsonPath('destinataires', ['achats@client.cm'])
            ->assertJsonPath('objet', 'Facture FAC-0001 — Ma Société SARL')
            ->assertJsonPath('piece_jointe', 'facture-FAC-0001.pdf')
            ->assertJsonPath('document_numero', 'FAC-0001')
            ->assertJsonPath('modele_personnalise', false)
            ->assertJsonPath('langue', 'fr');
        $this->assertStringContainsString("1\u{00A0}200,00\u{00A0}FCFA", $reponse->json('contenu'));
    }

    public function test_preparer_avec_le_modele_de_l_entreprise_dans_la_langue_choisie(): void
    {
        $id = $this->facture();
        $this->postJson('/api/courriels/modeles', [
            'type' => 'facture', 'langue' => 'en', 'objet' => 'Your invoice {numero}',
            'contenu' => 'Dear {contact_nom}, amount: {montant_ttc}.', 'nom_fichier' => 'Invoice {numero}',
        ])->assertCreated();

        $this->getJson("/api/courriels/preparer?type=facture&document_id={$id}&langue=en")->assertOk()
            ->assertJsonPath('objet', 'Your invoice FAC-0001')
            ->assertJsonPath('contenu', "Dear Awa Ndiaye, amount: 1,200.00\u{00A0}FCFA.")
            ->assertJsonPath('piece_jointe', 'Invoice FAC-0001.pdf')
            ->assertJsonPath('modele_personnalise', true);
    }

    public function test_envoi_avec_pdf_joint_copies_et_accuse_de_lecture(): void
    {
        $this->putJson('/api/courriels/parametres', ['transport' => 'plateforme', 'copie_cachee' => 'archives@masociete.cm'])->assertOk();
        $this->postJson('/api/courriels/modeles', [
            'type' => 'facture', 'langue' => 'fr', 'objet' => 'Facture {numero}', 'contenu' => 'Bonjour',
            'repondre_a' => 'facturation@masociete.cm', 'accuse_lecture' => true,
        ])->assertCreated();
        $id = $this->facture();

        $envoi = $this->postJson('/api/courriels/envois', $this->courriel($id, ['copies' => ['compta@client.cm'], 'copies_cachees' => ['ARCHIVES@masociete.cm']]))
            ->assertCreated()
            ->assertJsonPath('statut', 'envoye')
            ->assertJsonPath('tentatives', 1)
            ->assertJsonPath('piece_jointe', 'facture-FAC-0001.pdf')
            // La copie cachée des paramètres n'est pas doublée par celle saisie (casse ignorée).
            ->assertJsonPath('copies_cachees', ['ARCHIVES@masociete.cm'])
            ->assertJsonPath('auteur', 'Paul Mbarga')
            ->json();

        // Courriel réellement construit (transport « array » des tests).
        $messages = Mail::mailer()->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $courriel = $messages->first()->getOriginalMessage();
        $this->assertSame('achats@client.cm', $courriel->getTo()[0]->getAddress());
        $this->assertSame('compta@client.cm', $courriel->getCc()[0]->getAddress());
        $this->assertSame('ARCHIVES@masociete.cm', $courriel->getBcc()[0]->getAddress());
        $this->assertSame('facturation@masociete.cm', $courriel->getReplyTo()[0]->getAddress());
        $this->assertSame('Ma Société SARL', $courriel->getFrom()[0]->getName());
        $this->assertSame('facturation@masociete.cm', $courriel->getHeaders()->get('Disposition-Notification-To')->getAddress()->getAddress());
        $this->assertStringContainsString('<strong>FAC-0001</strong>', $courriel->getHtmlBody());
        $piece = $courriel->getAttachments()[0];
        $this->assertSame('facture-FAC-0001.pdf', $piece->getFilename());
        $this->assertStringStartsWith('%PDF', $piece->getBody());

        // Historique du document, puis détail avec le corps tel qu'envoyé.
        $this->getJson("/api/courriels/envois?type=facture&document_id={$id}")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.statut', 'envoye')
            ->assertJsonMissingPath('data.0.corps_html');
        $this->assertStringContainsString('<strong>FAC-0001</strong>', $this->getJson("/api/courriels/envois/{$envoi['id']}")->assertOk()->json('corps_html'));
    }

    public function test_sans_piece_jointe_si_le_pdf_n_est_pas_joint(): void
    {
        Mail::fake();
        $id = $this->facture();

        $this->postJson('/api/courriels/envois', $this->courriel($id, ['joindre_pdf' => false]))->assertCreated()
            ->assertJsonPath('statut', 'envoye')->assertJsonPath('piece_jointe', null);

        Mail::assertSent(CourrielSimple::class, fn (CourrielSimple $c) => $c->fichiers === [] && $c->hasTo('achats@client.cm'));
    }

    public function test_echec_enregistre_puis_relance_manuelle(): void
    {
        $this->smtpInjoignable();
        $id = $this->facture();

        $envoi = $this->postJson('/api/courriels/envois', $this->courriel($id))->assertCreated()
            ->assertJsonPath('statut', 'echec')->assertJsonPath('tentatives', 1)->json();
        $this->assertStringContainsString('Connection could not be established', $envoi['erreur']);

        // Serveur corrigé : la relance manuelle part, une seule fois.
        $this->putJson('/api/courriels/parametres', ['transport' => 'plateforme'])->assertOk();
        Mail::fake();
        $this->postJson("/api/courriels/envois/{$envoi['id']}/reessayer")->assertOk()
            ->assertJsonPath('statut', 'envoye')->assertJsonPath('tentatives', 2)->assertJsonPath('erreur', null);
        Mail::assertSentCount(1);
        $this->postJson("/api/courriels/envois/{$envoi['id']}/reessayer")->assertUnprocessable();
    }

    public function test_relance_automatique_des_courriels_en_attente_en_echec_ou_interrompus(): void
    {
        $id = $this->facture();
        $creer = fn (array $etat) => $this->chezLeTenant(fn () => CourrielEnvoi::create($etat + [
            'type' => 'facture', 'document_id' => $id, 'document_numero' => 'FAC-0001', 'destinataires' => ['achats@client.cm'],
            'expediteur_adresse' => 'noreply@exemple.com', 'objet' => 'Facture', 'corps_html' => '<p>Bonjour</p>', 'corps_texte' => 'Bonjour',
        ])->id);

        $enAttente = $creer(['statut' => 'en_attente']);
        $echec = $creer(['statut' => 'echec', 'tentatives' => 1, 'dernier_essai' => now()]);
        $epuise = $creer(['statut' => 'echec', 'tentatives' => CourrielEnvoi::TENTATIVES_MAX, 'dernier_essai' => now()]);
        $interrompu = $creer(['statut' => 'en_cours', 'tentatives' => 1, 'dernier_essai' => now()]);

        $this->travel(20)->minutes();
        $recent = $creer(['statut' => 'en_attente']);
        Mail::fake();

        $this->artisan('courriels:relancer')->expectsOutput('3 courriel(s) traité(s).')->assertSuccessful();

        $statuts = $this->chezLeTenant(fn () => CourrielEnvoi::pluck('statut', 'id'));
        $this->assertSame('envoye', $statuts[$enAttente]);
        $this->assertSame('envoye', $statuts[$echec]);
        $this->assertSame('envoye', $statuts[$interrompu]);
        $this->assertSame('echec', $statuts[$epuise]);
        $this->assertSame('en_attente', $statuts[$recent]);
        Mail::assertSentCount(3);
    }

    public function test_relance_planifiee_toutes_les_cinq_minutes(): void
    {
        $taches = collect(app(Schedule::class)->events())->filter(fn ($tache) => str_contains((string) $tache->command, 'courriels:relancer'));

        $this->assertCount(1, $taches);
        $this->assertSame('*/5 * * * *', $taches->first()->expression);
    }

    public function test_documents_et_historique_d_une_autre_entreprise_invisibles(): void
    {
        Mail::fake();
        $id = $this->facture();
        $envoi = $this->postJson('/api/courriels/envois', $this->courriel($id))->assertCreated()->json('id');

        $this->connecter(Tenant::factory()->create(['modules' => ['ventes']]));
        $this->getJson("/api/courriels/preparer?type=facture&document_id={$id}")->assertNotFound();
        $this->postJson('/api/courriels/envois', $this->courriel($id))->assertNotFound();
        $this->getJson('/api/courriels/envois')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/courriels/envois/{$envoi}")->assertNotFound();
        $this->postJson("/api/courriels/envois/{$envoi}/reessayer")->assertNotFound();
    }

    public function test_bon_de_commande_fournisseur_envoye_avec_son_pdf(): void
    {
        Mail::fake();
        $acheteur = $this->connecter($this->tenant);
        $commandeId = $this->chezLeTenant(function () use ($acheteur) {
            $fournisseur = Fournisseur::create(['code' => 'F1', 'raison_sociale' => 'Fournisseur Test', 'email' => 'ventes@fournisseur.cm', 'contact_nom' => 'M. Ndi']);

            return ComFourEntete::create([
                'numero' => 'BC-0001', 'fournisseur_id' => $fournisseur->id, 'date_commande' => '2026-10-15',
                'statut' => 'brouillon', 'montant_total' => 500, 'created_by' => $acheteur->id,
            ])->id;
        });

        $prepare = $this->getJson("/api/courriels/preparer?type=commande_fournisseur&document_id={$commandeId}")->assertOk()
            ->assertJsonPath('destinataires', ['ventes@fournisseur.cm'])
            ->assertJsonPath('piece_jointe', 'bon-commande-BC-0001.pdf')
            ->json();

        $this->postJson('/api/courriels/envois', [
            'type' => 'commande_fournisseur', 'document_id' => $commandeId, 'langue' => 'fr',
            'destinataires' => $prepare['destinataires'], 'objet' => $prepare['objet'], 'contenu' => $prepare['contenu'],
        ])->assertCreated()->assertJsonPath('statut', 'envoye');

        Mail::assertSent(CourrielSimple::class, fn (CourrielSimple $c) => $c->fichiers[0]['nom'] === 'bon-commande-BC-0001.pdf'
            && str_starts_with($c->fichiers[0]['contenu'], '%PDF'));
    }

    public function test_les_envois_ne_consomment_pas_la_limite_du_courriel_de_test(): void
    {
        // Chaque route limitée a son propre compteur : sans préfixe, toutes les limites « throttle:N,M »
        // partageaient celui de l'utilisateur. Cache vidé : la CI garde un cache fichier d'un test à l'autre.
        Cache::flush();
        Mail::fake();
        $id = $this->facture();
        foreach (range(1, 5) as $envoi) {
            $this->postJson('/api/courriels/envois', $this->courriel($id))->assertCreated();
        }

        $this->postJson('/api/courriels/parametres/test', ['destinataire' => 'moi@exemple.com'])->assertOk();
    }

    public function test_module_requis_et_adresses_validees(): void
    {
        $this->connecter(Tenant::factory()->create(['modules' => ['ventes']]));
        $this->getJson('/api/courriels/preparer?type=commande_fournisseur&document_id=1')->assertForbidden();

        $this->connecter($this->tenant);
        $id = $this->facture();
        $this->postJson('/api/courriels/envois', $this->courriel($id, ['destinataires' => ['pas-une-adresse']]))
            ->assertUnprocessable()->assertJsonValidationErrors('destinataires.0');
        $this->postJson('/api/courriels/envois', $this->courriel($id, ['type' => 'general']))
            ->assertUnprocessable()->assertJsonValidationErrors('type');
    }
}
