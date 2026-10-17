<?php

namespace Tests\Feature\Courriels;

use App\Mail\CourrielSimple;
use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Configuration;
use App\Models\CourrielModele;
use App\Models\CourrielParametres;
use App\Models\ProduitTenant;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Services\Courriels\MailerTenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Courriels, paramètres et modèles (plan de parité fripv2, phase 0.6a) : un modèle par type et
 * par langue, aperçu avec un vrai document, serveur d'envoi propre à l'entreprise, courriel de test.
 */
class CourrielsConfigurationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['modules' => ['ventes']]);
        $this->chezLeTenant(fn () => TenantParametres::create([
            'nom_entreprise' => 'Ma Société SARL', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF', 'email' => 'contact@masociete.cm',
        ]));
        $this->connecter($this->tenant);
    }

    private function connecter(Tenant $tenant, string $role = 'admin'): void
    {
        Sanctum::actingAs(AdminUser::factory()->for($tenant)->create(['role' => $role]), ['*']);
        $this->withHeader('X-Tenant-Slug', $tenant->slug);
    }

    private function chezLeTenant(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function modele(array $donnees = []): array
    {
        return $donnees + ['type' => 'facture', 'langue' => 'fr', 'objet' => 'Facture {numero}', 'contenu' => "Bonjour,\n\nVoici la facture {numero}."];
    }

    private function parametresSmtp(array $donnees = []): array
    {
        return $donnees + [
            'transport' => 'smtp', 'smtp_hote' => 'smtp.masociete.cm', 'smtp_port' => 587, 'smtp_chiffrement' => 'tls',
            'smtp_utilisateur' => 'facturation@masociete.cm', 'smtp_mot_de_passe' => 'secret-123', 'expediteur_adresse' => 'facturation@masociete.cm',
        ];
    }

    public function test_catalogue_des_types_variables_et_modeles_par_defaut(): void
    {
        $types = collect($this->getJson('/api/courriels/catalogue')->assertOk()->assertJsonPath('langues', ['fr', 'en'])->json('types'))->keyBy('type');

        $this->assertEqualsCanonicalizing(['devis', 'commande_client', 'facture', 'avoir', 'demande_acompte', 'relance', 'relance_devis', 'bon_livraison', 'commande_fournisseur', 'retour_fournisseur', 'general'], $types->keys()->all());
        $this->assertContains('credit_disponible', $types['avoir']['variables']);
        $this->assertTrue($types['avoir']['piece_jointe']);
        $this->assertContains('montant_ttc', $types['facture']['variables']);
        $this->assertContains('entreprise_nom', $types['general']['variables']);
        $this->assertTrue($types['facture']['piece_jointe']);
        $this->assertFalse($types['general']['piece_jointe']);
        $this->assertStringContainsString('{numero}', $types['facture']['defauts']['fr']['objet']);
        $this->assertStringStartsWith('Invoice', $types['facture']['defauts']['en']['objet']);
    }

    public function test_un_seul_modele_par_type_et_langue_et_par_entreprise(): void
    {
        $this->postJson('/api/courriels/modeles', $this->modele())->assertCreated()->assertJsonPath('type', 'facture');
        $this->postJson('/api/courriels/modeles', $this->modele())->assertUnprocessable()
            ->assertJsonValidationErrors(['langue' => 'Un modèle existe déjà pour ce type et cette langue.']);
        $this->postJson('/api/courriels/modeles', $this->modele(['langue' => 'en']))->assertCreated();
        $this->postJson('/api/courriels/modeles', $this->modele(['type' => 'inconnu']))->assertUnprocessable()->assertJsonValidationErrors('type');

        // Une autre entreprise a ses propres modèles.
        $this->connecter(Tenant::factory()->create());
        $this->getJson('/api/courriels/modeles')->assertOk()->assertJsonCount(0);
        $this->postJson('/api/courriels/modeles', $this->modele())->assertCreated();
    }

    public function test_la_modification_garde_le_type_et_la_langue(): void
    {
        $id = $this->postJson('/api/courriels/modeles', $this->modele())->json('id');

        $this->putJson("/api/courriels/modeles/{$id}", $this->modele(['type' => 'devis', 'langue' => 'en', 'objet' => 'Votre facture {numero}', 'accuse_lecture' => true]))
            ->assertOk()->assertJsonPath('objet', 'Votre facture {numero}')->assertJsonPath('accuse_lecture', true);

        $modele = $this->chezLeTenant(fn () => CourrielModele::findOrFail($id));
        $this->assertSame(['facture', 'fr'], [$modele->type, $modele->langue]);
    }

    public function test_le_modele_d_une_autre_entreprise_est_introuvable(): void
    {
        $id = $this->postJson('/api/courriels/modeles', $this->modele())->json('id');

        $this->connecter(Tenant::factory()->create());
        $this->putJson("/api/courriels/modeles/{$id}", $this->modele(['objet' => 'Piraté']))->assertNotFound();
        $this->deleteJson("/api/courriels/modeles/{$id}")->assertNotFound();

        $this->connecter($this->tenant);
        $this->deleteJson("/api/courriels/modeles/{$id}")->assertOk();
        $this->assertSame(0, $this->chezLeTenant(fn () => CourrielModele::count()));
    }

    public function test_apercu_avec_la_derniere_facture_et_variables_inconnues_signalees(): void
    {
        [$clientId, $produitId] = $this->chezLeTenant(function () {
            Configuration::create(['entite' => 'facture', 'prefixe' => 'FAC', 'separateur' => '-', 'longueur' => 4, 'auto_increment' => true, 'prochain_numero' => 1]);
            $client = Client::create(['code' => 'CLI-1', 'raison_sociale' => 'Client Test', 'adresse_facturation' => '1 rue du Test', 'ville' => 'Douala', 'code_postal' => '00000', 'contact_nom' => 'Awa Ndiaye']);
            $produit = ProduitTenant::create(['numero' => 'PRD-1', 'description' => 'Article', 'prix_unitaire' => 100]);

            return [$client->id, $produit->id];
        });
        $this->postJson('/api/factures', [
            'client_id' => $clientId, 'date_facture' => '2026-10-15',
            'lignes' => [['produit_id' => $produitId, 'quantite' => 10, 'prix_unitaire_ht' => 100]],
        ])->assertCreated();

        $apercu = $this->postJson('/api/courriels/modeles/apercu', [
            'type' => 'facture', 'langue' => 'fr', 'objet' => 'Facture {numero} — {entreprise_nom}',
            'contenu' => "Bonjour {contact_nom},\n\nMontant : **{montant_ttc}**, échéance le {date_echeance}. {nom_clent}",
        ])->assertOk();

        $apercu->assertJsonPath('objet', 'Facture FAC-0001 — Ma Société SARL')
            ->assertJsonPath('document', 'FAC-0001')
            ->assertJsonPath('nom_fichier', 'facture-FAC-0001.pdf')
            ->assertJsonPath('inconnues', ['nom_clent']);
        $html = $apercu->json('html');
        $this->assertStringContainsString('Bonjour Awa Ndiaye,', $html);
        // 10 × 100 HT avec la TVA par défaut (20 %).
        $this->assertStringContainsString("<strong>1\u{00A0}200,00\u{00A0}FCFA</strong>", $html);
        $this->assertStringContainsString('<mark>{nom_clent}</mark>', $html);
        $this->assertStringContainsString('Ma Société SARL', $html);
    }

    public function test_apercu_sans_document_utilise_des_exemples_et_echappe_le_html(): void
    {
        $this->chezLeTenant(fn () => TenantParametres::first()->update(['nom_entreprise' => 'A & B <Co>']));

        $apercu = $this->postJson('/api/courriels/modeles/apercu', [
            'type' => 'devis', 'langue' => 'en', 'objet' => 'Quote {numero}',
            'contenu' => '<script>alert(1)</script> {client_nom} — {entreprise_nom} — {montant_total}',
            'nom_fichier' => 'Quote {numero} / {client_nom}.pdf',
        ])->assertOk()->assertJsonPath('document', null)->assertJsonPath('inconnues', []);

        $html = $apercu->json('html');
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Sample customer', $html);
        $this->assertStringContainsString('A &amp; B &lt;Co&gt;', $html);
        $this->assertStringContainsString("1,000.00\u{00A0}FCFA", $html);
        // Caractères interdits retirés du nom de fichier, extension ajoutée une seule fois.
        $apercu->assertJsonPath('nom_fichier', 'Quote DEV-00001 Sample customer.pdf');
    }

    public function test_mot_de_passe_smtp_chiffre_et_jamais_renvoye(): void
    {
        $this->putJson('/api/courriels/parametres', $this->parametresSmtp())->assertOk()
            ->assertJsonPath('transport', 'smtp')
            ->assertJsonPath('smtp_mot_de_passe_defini', true)
            ->assertJsonMissingPath('smtp_mot_de_passe');

        $brut = DB::table('courriel_parametres')->value('smtp_mot_de_passe');
        $this->assertNotSame('secret-123', $brut);
        $this->assertSame('secret-123', Crypt::decryptString($brut));

        // Laissé vide, le mot de passe enregistré est conservé.
        $this->putJson('/api/courriels/parametres', $this->parametresSmtp(['smtp_mot_de_passe' => '', 'smtp_port' => 2525]))->assertOk();
        $this->assertSame('secret-123', $this->chezLeTenant(fn () => CourrielParametres::first()->smtp_mot_de_passe));
        $this->getJson('/api/courriels/parametres')->assertOk()->assertJsonPath('smtp_port', 2525)->assertJsonMissingPath('smtp_mot_de_passe');
    }

    public function test_le_smtp_exige_un_serveur_et_une_adresse_d_expedition(): void
    {
        $this->putJson('/api/courriels/parametres', ['transport' => 'smtp'])->assertUnprocessable()
            ->assertJsonValidationErrors(['smtp_hote', 'expediteur_adresse']);
        $this->putJson('/api/courriels/parametres', ['transport' => 'plateforme'])->assertOk()
            ->assertJsonPath('smtp_mot_de_passe_defini', false);
    }

    public function test_seul_un_administrateur_modifie_et_teste_le_serveur_d_envoi(): void
    {
        $this->connecter($this->tenant, 'user');

        $this->getJson('/api/courriels/parametres')->assertOk()->assertJsonPath('transport', 'plateforme');
        $this->putJson('/api/courriels/parametres', $this->parametresSmtp())->assertForbidden();
        $this->postJson('/api/courriels/parametres/test', ['destinataire' => 'moi@exemple.com'])->assertForbidden();
    }

    public function test_courriel_de_test_par_la_plateforme_au_nom_de_l_entreprise(): void
    {
        Mail::fake();
        $this->putJson('/api/courriels/parametres', [
            'transport' => 'plateforme', 'expediteur_nom' => 'Service facturation', 'repondre_a' => 'compta@masociete.cm',
            'signature' => 'Service client {entreprise_nom}',
        ])->assertOk();

        $this->postJson('/api/courriels/parametres/test', ['destinataire' => 'moi@exemple.com'])->assertOk()
            ->assertJsonPath('message', 'Courriel de test envoyé à moi@exemple.com.');

        Mail::assertSent(CourrielSimple::class, function (CourrielSimple $courriel) {
            return $courriel->hasTo('moi@exemple.com')
                && $courriel->hasFrom(config('mail.from.address'), 'Service facturation')
                && $courriel->hasReplyTo('compta@masociete.cm')
                && $courriel->hasSubject('Test d\'envoi — Ma Société SARL')
                && str_contains($courriel->corpsHtml, 'Service client Ma Société SARL')
                && str_contains($courriel->corpsTexte, 'Service client Ma Société SARL');
        });
    }

    public function test_l_echec_d_envoi_est_signale_sans_erreur_serveur(): void
    {
        // Port fermé en local : la connexion est refusée tout de suite.
        $this->putJson('/api/courriels/parametres', $this->parametresSmtp(['smtp_hote' => '127.0.0.1', 'smtp_port' => 1]))->assertOk();

        $message = $this->postJson('/api/courriels/parametres/test', ['destinataire' => 'moi@exemple.com'])
            ->assertUnprocessable()->json('message');
        $this->assertStringStartsWith('Échec de l\'envoi : ', $message);
    }

    public function test_transport_smtp_selon_le_chiffrement(): void
    {
        $mailer = new MailerTenant;
        $config = fn (array $valeurs) => $mailer->configSmtp(new CourrielParametres($valeurs + ['transport' => 'smtp', 'smtp_hote' => 'smtp.exemple.com']));

        $this->assertSame(['smtp', 587, true, true], array_values(array_intersect_key($config([]), array_flip(['scheme', 'port', 'require_tls', 'auto_tls']))));
        $this->assertSame(['smtps', 465, false, true], array_values(array_intersect_key($config(['smtp_chiffrement' => 'ssl']), array_flip(['scheme', 'port', 'require_tls', 'auto_tls']))));
        $this->assertSame(['smtp', 25, false, false], array_values(array_intersect_key($config(['smtp_chiffrement' => 'aucun', 'smtp_port' => 25]), array_flip(['scheme', 'port', 'require_tls', 'auto_tls']))));
    }
}
