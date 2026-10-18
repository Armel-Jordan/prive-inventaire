<?php

namespace Tests\Feature\Rapports;

use App\Mail\CourrielSimple;
use App\Models\AdminUser;
use App\Models\Client;
use App\Models\CourrielEnvoi;
use App\Models\ProduitTenant;
use App\Models\QuestionIa;
use App\Models\RapportProgramme;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\TenantContext;
use App\Taches\EnvoyerRapportsProgrammes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Rapports IA périodiques (phase 5.4) : un envoi programmé qui porte une question à l'IA ; à l'envoi, l'analyse de
 * l'IA (tirée des données du rapport joint) entre dans le courriel, à la place de {analyse_ia} ou à la fin ; sans IA
 * (échec, module, droit), le courriel part sans elle et le motif est noté ; la question n'est acceptée que si l'IA
 * est disponible pour le compte.
 */
class EnvoisProgrammesIaTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://api.anthropic.com/v1/messages';

    private Tenant $tenant;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'sync', 'mail.default' => 'array', 'ia.cle' => 'cle-de-test', 'ia.url' => self::URL, 'ia.modele' => 'claude-opus-5-5']);
        $this->travelTo('2026-10-17 09:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats', 'ia']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin']);
        $this->agir($this->admin);
        [$client, $produit] = $this->chez(function () {
            TenantParametres::create(['nom_entreprise' => 'Entreprise Test', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF']);

            return [
                Client::create(['code' => 'C-1', 'raison_sociale' => 'Bar du Port', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'actif' => true])->id,
                ProduitTenant::create(['numero' => 'BIERE', 'description' => 'Bière', 'categorie' => 'Boissons', 'mesure' => 'UN', 'prix_unitaire' => 100, 'actif' => true])->id,
            ];
        });
        // Vente d'octobre : le rapport du mois précédent, envoyé le 1er novembre, la contient.
        $id = $this->postJson('/api/factures', ['client_id' => $client, 'date_facture' => '2026-10-05', 'lignes' => [
            ['produit_id' => $produit, 'quantite' => 10, 'prix_unitaire_ht' => 100, 'taux_tva' => 0],
        ]])->assertCreated()->json('id');
        $this->postJson("/api/factures/{$id}/emettre")->assertOk();
    }

    private function chez(callable $action): mixed
    {
        return app(TenantContext::class)->runAsTenant($this->tenant->id, $action);
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $compte->tenant->slug);
    }

    private function programme(array $donnees = []): array
    {
        return $donnees + [
            'code' => 'ventes-par-client', 'nom' => 'Clients du mois', 'periode' => 'mois_precedent', 'format' => 'pdf', 'langue' => 'fr',
            'destinataires' => ['direction@entreprise.test'], 'frequence' => 'mensuelle', 'jour' => 1,
            'question_ia' => 'Quel client a le plus acheté, et quelle part des ventes représente-t-il ?',
        ];
    }

    private function texte(string $texte): array
    {
        return [
            'id' => 'msg_a', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => $texte]], 'usage' => ['input_tokens' => 900, 'output_tokens' => 60],
        ];
    }

    /** Exécute la tâche des envois programmés au 1er novembre ; rend son message (celui de l'erreur s'il y en a une). */
    private function tache(): string
    {
        $this->travelTo('2026-11-01 06:30:00');

        return $this->chez(function () {
            try {
                return app(EnvoyerRapportsProgrammes::class)->executer();
            } catch (RuntimeException $erreur) {
                return 'ERREUR '.$erreur->getMessage();
            }
        });
    }

    public function test_l_analyse_de_l_ia_entre_dans_le_courriel(): void
    {
        $cree = $this->postJson('/api/rapports/programmes', $this->programme())->assertCreated()->json();
        $this->assertSame('Quel client a le plus acheté, et quelle part des ventes représente-t-il ?', $cree['question_ia']);
        $this->assertTrue($this->getJson('/api/rapports/programmes')->json('ia_disponible'));

        Mail::fake();
        Http::fake([self::URL => Http::response($this->texte("**Bar du Port** représente 100 % des ventes d'octobre : 1 000,00 XAF hors taxes."))]);
        $this->assertSame('1 rapport(s) envoyé(s).', $this->tache());

        // L'analyse, avec son titre, à la place de {analyse_ia} du modèle par défaut ; le courriel part avec le PDF.
        $envoi = $this->chez(fn () => CourrielEnvoi::where('type', 'rapport')->firstOrFail());
        $this->assertStringContainsString("**Analyse de l'IA**\n\n**Bar du Port** représente 100 % des ventes d'octobre", $envoi->corps_texte);
        $this->assertStringContainsString('<strong>Bar du Port</strong> représente 100 %', $envoi->corps_html);
        $this->assertLessThan(strpos($envoi->corps_texte, 'Cordialement'), strpos($envoi->corps_texte, "Analyse de l'IA"));
        Mail::assertSent(CourrielSimple::class, fn (CourrielSimple $c) => str_starts_with($c->fichiers[0]['contenu'], '%PDF'));

        // L'IA a reçu la question et les données du rapport (octobre) ; la question est gardée avec ses jetons.
        $requete = Http::recorded()[0][0];
        $this->assertArrayNotHasKey('tools', $requete->data());
        $message = $requete['messages'][0]['content'];
        $this->assertStringContainsString('Question : Quel client a le plus acheté', $message);
        $this->assertStringContainsString('"client":"Bar du Port"', $message);
        $this->assertStringContainsString('"du":"2026-10-01"', $message);
        $question = $this->chez(fn () => QuestionIa::firstOrFail());
        $this->assertSame([QuestionIa::REUSSIE, 900, 60, 'ventes-par-client'], [$question->statut, $question->jetons_entree, $question->jetons_sortie, $question->rapport['code']]);
        $this->assertNull($this->chez(fn () => RapportProgramme::firstOrFail())->derniere_erreur);
    }

    public function test_modele_personnalise_sans_la_variable_et_ia_en_echec(): void
    {
        // Modèle de l'entreprise sans {analyse_ia} : l'analyse vient à la fin.
        $this->postJson('/api/courriels/modeles', [
            'type' => 'rapport', 'langue' => 'fr', 'objet' => 'Rapport {rapport}', 'contenu' => "Bonjour,\n\nLe rapport {rapport} est joint.",
        ])->assertSuccessful();
        $this->postJson('/api/rapports/programmes', $this->programme())->assertCreated();
        $this->postJson('/api/rapports/programmes', $this->programme(['nom' => 'Second', 'code' => 'stock-produits', 'periode' => null, 'question_ia' => 'Que faut-il commander ?']))->assertCreated();

        Mail::fake();
        Http::fake([self::URL => Http::sequence()
            ->push($this->texte('Le **Bar du Port** fait toutes les ventes.'))
            ->push(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)]);
        $this->assertSame('2 rapport(s) envoyé(s).', $this->tache());
        Mail::assertSentCount(2);

        $envois = $this->chez(fn () => CourrielEnvoi::where('type', 'rapport')->orderBy('id')->get());
        $this->assertStringEndsWith("**Analyse de l'IA**\n\nLe **Bar du Port** fait toutes les ventes.", trim($envois[0]->corps_texte));
        // Échec de l'IA : le courriel part sans analyse, le motif est noté sur l'envoi programmé.
        $this->assertStringNotContainsString("Analyse de l'IA", $envois[1]->corps_texte);
        $second = $this->chez(fn () => RapportProgramme::where('nom', 'Second')->firstOrFail());
        $this->assertSame("Envoyé sans l'analyse de l'IA : Le service d'IA est surchargé ; réessayez dans un moment.", $second->derniere_erreur);
        $this->assertSame(QuestionIa::ECHEC, $this->chez(fn () => QuestionIa::orderByDesc('id')->firstOrFail())->statut);
    }

    public function test_question_refusee_sans_ia_et_envoi_sans_analyse(): void
    {
        // Sans le droit de questionner l'IA, la question est refusée (le reste de l'envoi est accepté).
        $vendeur = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager']);
        $this->putJson("/api/acces-comptes/{$vendeur->id}", ['groupes' => [], 'exceptions' => ['rapports.questionner' => false]])->assertOk();
        $this->agir($vendeur);
        $this->assertFalse($this->getJson('/api/rapports/programmes')->json('ia_disponible'));
        $this->postJson('/api/rapports/programmes', $this->programme())->assertUnprocessable()
            ->assertJsonValidationErrors(['question_ia' => "Les questions à l'IA ne sont pas disponibles pour votre compte ou votre entreprise."]);
        $this->postJson('/api/rapports/programmes', $this->programme(['question_ia' => null]))->assertCreated();

        // L'envoi réglé avec une question, puis le module retiré à l'entreprise : le courriel part sans analyse.
        $this->agir($this->admin);
        $this->postJson('/api/rapports/programmes', $this->programme(['nom' => 'Avec question']))->assertCreated();
        $this->tenant->update(['modules' => ['ventes', 'achats']]);
        Mail::fake();
        Http::fake();
        $this->assertSame('2 rapport(s) envoyé(s).', $this->tache());
        Http::assertNothingSent();
        $avec = $this->chez(fn () => RapportProgramme::where('nom', 'Avec question')->firstOrFail());
        $this->assertSame("Envoyé sans l'analyse de l'IA : le module « Intelligence artificielle » n'est pas activé.", $avec->derniere_erreur);
    }
}
