<?php

namespace Tests\Feature\Rapports;

use App\Mail\CourrielSimple;
use App\Models\AdminUser;
use App\Models\Client;
use App\Models\CourrielEnvoi;
use App\Models\ProduitTenant;
use App\Models\RapportProgramme;
use App\Models\TachePlanifiee;
use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Rapports\Programmation;
use App\Support\TenantContext;
use App\Taches\EnvoyerRapportsProgrammes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * Envoi et programmation des rapports (phase 5.2) : envoi par courriel (modèle « rapport », fichier joint refait à
 * chaque tentative, droits), envois programmés (validation, période relative, prochaine date, responsable), tâche
 * planifiée qui les fait partir, refus quand le responsable n'a plus les droits, isolation des entreprises.
 */
class EnvoiRapportsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Envoi pendant la requête et transport « array », comme les tests des courriels.
        config(['queue.default' => 'sync', 'mail.default' => 'array']);
        $this->travelTo('2026-10-17 09:00:00');
        $this->tenant = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->admin = AdminUser::factory()->for($this->tenant)->create(['role' => 'admin', 'nom' => 'Awa Admin', 'email' => 'awa@entreprise.test']);
        $this->agir($this->admin);
        [$client, $produit] = $this->chez(function () {
            TenantParametres::create(['nom_entreprise' => 'Entreprise Test', 'devise_symbole' => 'FCFA', 'devise_code' => 'XAF']);
            $client = Client::create(['code' => 'C-1', 'raison_sociale' => 'Bar du Port', 'adresse_facturation' => '1 rue', 'ville' => 'Douala', 'code_postal' => '0', 'actif' => true]);
            $produit = ProduitTenant::create(['numero' => 'BIERE', 'description' => 'Bière', 'categorie' => 'Boissons', 'mesure' => 'UN', 'prix_unitaire' => 100, 'actif' => true]);

            return [$client->id, $produit->id];
        });
        foreach ([['2026-09-20', 10], ['2026-10-05', 5]] as [$date, $quantite]) {
            $id = $this->postJson('/api/factures', ['client_id' => $client, 'date_facture' => $date, 'lignes' => [
                ['produit_id' => $produit, 'quantite' => $quantite, 'prix_unitaire_ht' => 100, 'taux_tva' => 0],
            ]])->assertCreated()->json('id');
            $this->postJson("/api/factures/{$id}/emettre")->assertOk();
        }
    }

    private function chez(callable $action, ?Tenant $tenant = null): mixed
    {
        return app(TenantContext::class)->runAsTenant(($tenant ?? $this->tenant)->id, $action);
    }

    private function agir(AdminUser $compte): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($compte, ['*']);
        $this->withHeader('X-Tenant-Slug', $compte->tenant->slug);
    }

    /** Compte « manager » de l'entreprise, avec des droits retirés. */
    private function manager(array $refus = [], string $nom = 'Max Manager'): AdminUser
    {
        $this->agir($this->admin);
        $compte = AdminUser::factory()->for($this->tenant)->create(['role' => 'manager', 'nom' => $nom]);
        $this->putJson("/api/acces-comptes/{$compte->id}", ['groupes' => [], 'exceptions' => array_fill_keys($refus, false)])->assertOk();

        return $compte;
    }

    private function courriel(array $donnees = []): array
    {
        return $donnees + [
            'langue' => 'fr', 'format' => 'xlsx', 'destinataires' => ['compta@cabinet.test'],
            'objet' => 'Ventes de septembre et octobre', 'contenu' => "Bonjour,\n\nLe rapport des ventes.",
            'parametres' => ['du' => '2026-09-01', 'au' => '2026-10-31', 'regroupement' => 'mois'],
        ];
    }

    private function programme(array $donnees = []): array
    {
        return $donnees + [
            'code' => 'ventes-par-periode', 'nom' => 'Ventes du mois', 'parametres' => ['regroupement' => 'mois'], 'periode' => 'mois_precedent',
            'format' => 'pdf', 'langue' => 'fr', 'destinataires' => ['direction@entreprise.test'], 'frequence' => 'mensuelle', 'jour' => 1,
        ];
    }

    /** Exécute la tâche des envois programmés dans l'entreprise ; rend son message (celui de l'erreur s'il y en a une). */
    private function tache(): string
    {
        return $this->chez(function () {
            try {
                return app(EnvoyerRapportsProgrammes::class)->executer();
            } catch (RuntimeException $erreur) {
                return 'ERREUR '.$erreur->getMessage();
            }
        });
    }

    public function test_envoi_d_un_rapport_par_courriel(): void
    {
        // Pré-remplissage : modèle « rapport » par défaut, paramètres lisibles, adresse du compte proposée.
        $preparation = $this->getJson('/api/rapports/bibliotheque/ventes-par-periode/courriel?'.http_build_query(['langue' => 'fr', 'parametres' => ['du' => '2026-09-01', 'au' => '2026-10-31', 'regroupement' => 'mois']]))
            ->assertOk()->json();
        $this->assertSame(['Rapport « Ventes par période » — Entreprise Test', ['awa@entreprise.test'], false], [$preparation['objet'], $preparation['destinataires'], $preparation['modele_personnalise']]);
        $this->assertStringContainsString('du 17/10/2026', $preparation['contenu']);
        $this->assertStringContainsString("Du : 01/09/2026\nAu : 31/10/2026\nRegroupement : Mois", $preparation['contenu']);
        $anglais = $this->getJson('/api/rapports/bibliotheque/ventes-par-periode/courriel?'.http_build_query(['langue' => 'en', 'parametres' => ['du' => '2026-09-01']]))->json();
        $this->assertSame('Report "Sales by period" — Entreprise Test', $anglais['objet']);
        $this->assertStringContainsString('From: 2026-09-01', $anglais['contenu']);

        // Envoi : le classeur Excel du rapport joint, l'envoi dans l'historique des courriels.
        Mail::fake();
        $envoi = $this->postJson('/api/rapports/bibliotheque/ventes-par-periode/courriel', $this->courriel())->assertCreated()->json();
        $this->assertSame(['rapport', 'Ventes par période', 'ventes-par-periode-2026-10-17.xlsx', 'envoye', 'Awa Admin'],
            [$envoi['type'], $envoi['document_numero'], $envoi['piece_jointe'], $envoi['statut'], $envoi['auteur']]);
        Mail::assertSent(CourrielSimple::class, fn (CourrielSimple $c) => $c->hasTo('compta@cabinet.test')
            && $c->fichiers[0]['nom'] === 'ventes-par-periode-2026-10-17.xlsx'
            && $c->fichiers[0]['mime'] === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            && str_starts_with($c->fichiers[0]['contenu'], 'PK'));
        $garde = $this->chez(fn () => CourrielEnvoi::findOrFail($envoi['id'])->rapport);
        $this->assertSame(['ventes-par-periode', '2026-09-01', '2026-10-31', 'mois', 'xlsx', 'fr'],
            [$garde['code'], $garde['valeurs']['du'], $garde['valeurs']['au'], $garde['valeurs']['regroupement'], $garde['format'], $garde['langue']]);
        $this->assertSame([$envoi['id']], array_column($this->getJson('/api/courriels/envois?type=rapport&rapport=ventes-par-periode')->json('data'), 'id'));
        $this->assertSame([], $this->getJson('/api/courriels/envois?type=rapport&rapport=stock-produits')->json('data'));

        // Contrôles : format, destinataires, paramètres du rapport.
        $this->postJson('/api/rapports/bibliotheque/ventes-par-periode/courriel', $this->courriel(['format' => 'docx', 'destinataires' => []]))
            ->assertUnprocessable()->assertJsonValidationErrors(['format', 'destinataires']);
        $this->postJson('/api/rapports/bibliotheque/ventes-par-periode/courriel', $this->courriel(['parametres' => ['regroupement' => 'annee']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['parametres.regroupement']);

        // Droits : sans « envoyer », refus ; sans le droit de voir les factures, le rapport est introuvable.
        $this->agir($this->manager(['rapports.envoyer']));
        $this->getJson('/api/rapports/bibliotheque/ventes-par-periode/courriel')->assertForbidden();
        $this->postJson('/api/rapports/bibliotheque/ventes-par-periode/courriel', $this->courriel())->assertForbidden();
        $this->agir($this->manager(['factures.voir'], 'Léa Lecture'));
        $this->postJson('/api/rapports/bibliotheque/ventes-par-periode/courriel', $this->courriel())->assertNotFound();
        Mail::assertSentCount(1);
    }

    public function test_un_envoi_en_echec_refait_le_rapport_a_la_nouvelle_tentative(): void
    {
        $this->putJson('/api/courriels/parametres', [
            'transport' => 'smtp', 'smtp_hote' => '127.0.0.1', 'smtp_port' => 1, 'smtp_chiffrement' => 'aucun', 'expediteur_adresse' => 'rapports@entreprise.test',
        ])->assertOk();
        $envoi = $this->postJson('/api/rapports/bibliotheque/ventes-par-periode/courriel', $this->courriel(['format' => 'pdf']))->assertCreated()->json();
        $this->assertSame('echec', $envoi['statut']);

        // Le serveur de l'entreprise retiré, la nouvelle tentative part avec le PDF refait.
        $this->putJson('/api/courriels/parametres', ['transport' => 'plateforme', 'expediteur_adresse' => 'rapports@entreprise.test'])->assertOk();
        $this->postJson("/api/courriels/envois/{$envoi['id']}/reessayer")->assertOk()->assertJsonPath('statut', 'envoye');
        $piece = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getAttachments()[0];
        $this->assertSame(['ventes-par-periode-2026-10-17.pdf', 'application/pdf'], [$piece->getFilename(), $piece->getMediaType().'/'.$piece->getMediaSubtype()]);
        $this->assertStringStartsWith('%PDF', $piece->getBody());
    }

    public function test_periodes_relatives_et_dates_d_envoi(): void
    {
        $samedi = CarbonImmutable::parse('2026-10-17');
        $periodes = collect(array_keys(Programmation::PERIODES))->mapWithKeys(fn (string $p) => [$p => implode(' → ', Programmation::periode($p, $samedi))])->all();
        $this->assertSame([
            'veille' => '2026-10-16 → 2026-10-16',
            'sept_jours' => '2026-10-10 → 2026-10-16',
            'semaine_precedente' => '2026-10-05 → 2026-10-11',
            'mois_en_cours' => '2026-10-01 → 2026-10-17',
            'mois_precedent' => '2026-09-01 → 2026-09-30',
            'trimestre_precedent' => '2026-07-01 → 2026-09-30',
            'annee_en_cours' => '2026-01-01 → 2026-10-17',
            'annee_precedente' => '2025-01-01 → 2025-12-31',
        ], $periodes);
        // Le 31 mars : le mois précédent est février, entier.
        $this->assertSame(['du' => '2026-02-01', 'au' => '2026-02-28'], Programmation::periode('mois_precedent', CarbonImmutable::parse('2026-03-31')));

        $prochain = fn (string $frequence, ?int $jour, string $apres) => Programmation::prochainEnvoi($frequence, $jour, CarbonImmutable::parse($apres))->toDateString();
        $this->assertSame('2026-10-18', $prochain('quotidienne', null, '2026-10-17'));
        // Toujours strictement après : le samedi suivant, pas le jour même.
        $this->assertSame(['2026-10-19', '2026-10-24'], [$prochain('hebdomadaire', 1, '2026-10-17'), $prochain('hebdomadaire', 6, '2026-10-17')]);
        $this->assertSame(['2026-10-18', '2026-11-17'], [$prochain('mensuelle', 18, '2026-10-17'), $prochain('mensuelle', 17, '2026-10-17')]);
        // Jour 31 (ou 30) : le dernier jour d'un mois plus court.
        $this->assertSame(['2026-02-28', '2026-03-31', '2026-02-28'], [$prochain('mensuelle', 31, '2026-02-10'), $prochain('mensuelle', 31, '2026-02-28'), $prochain('mensuelle', 30, '2026-01-30')]);
    }

    public function test_programmation_controles_et_droits(): void
    {
        // Création : la période du rapport est relative (les dates saisies sont ignorées), les adresses dédoublonnées.
        $cree = $this->postJson('/api/rapports/programmes?langue=fr', $this->programme([
            'parametres' => ['regroupement' => 'mois', 'du' => '2026-01-01'], 'destinataires' => ['direction@entreprise.test', 'DIRECTION@entreprise.test'],
        ]))->assertCreated()->json();
        $this->assertSame(['Ventes par période', ['regroupement' => 'mois'], 'mois_precedent', ['direction@entreprise.test'], '2026-11-01', 'Awa Admin', true],
            [$cree['rapport'], $cree['parametres'], $cree['periode'], $cree['destinataires'], $cree['prochain_envoi'], $cree['compte'], $cree['actif']]);
        // Le premier envoi programmé active la tâche planifiée, jamais réglée jusque-là.
        $tache = $this->chez(fn () => TachePlanifiee::where('code', 'rapports_programmes')->firstOrFail());
        $this->assertTrue($tache->actif);
        $this->assertNotNull($tache->prochaine_execution);

        $liste = $this->getJson('/api/rapports/programmes?langue=en')->assertOk()->json();
        $this->assertSame([[$cree['id'], 'Sales by period']], array_map(fn ($p) => [$p['id'], $p['rapport']], $liste['programmes']));
        $this->assertSame(['veille', 'sept_jours', 'semaine_precedente', 'mois_en_cours', 'mois_precedent', 'trimestre_precedent', 'annee_en_cours', 'annee_precedente'],
            array_column($liste['periodes'], 'code'));
        $this->assertSame('Previous month', $liste['periodes'][4]['libelle']);
        $this->assertTrue($liste['tache_active']);

        // Contrôles.
        $this->postJson('/api/rapports/programmes', $this->programme(['frequence' => 'hebdomadaire', 'jour' => null, 'periode' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['jour', 'periode']);
        $this->postJson('/api/rapports/programmes', $this->programme(['frequence' => 'hebdomadaire', 'jour' => 9, 'destinataires' => ['pas-une-adresse']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['jour', 'destinataires.0' => 'Adresse de courriel invalide : « pas-une-adresse ».']);
        $this->postJson('/api/rapports/programmes', $this->programme(['code' => 'inconnu']))->assertUnprocessable()->assertJsonValidationErrors(['code']);
        // Rapport sans période : la période relative n'est pas gardée.
        $stock = $this->postJson('/api/rapports/programmes', $this->programme(['code' => 'stock-produits', 'nom' => 'Stock', 'parametres' => ['etat' => 'sous_seuil'], 'frequence' => 'quotidienne']))
            ->assertCreated()->json();
        $this->assertSame([null, ['etat' => 'sous_seuil'], null, '2026-10-18'], [$stock['periode'], $stock['parametres'], $stock['jour'], $stock['prochain_envoi']]);

        // Modification par un autre compte : il devient le responsable ; même fréquence, même date d'envoi.
        $manager = $this->manager();
        $this->agir($manager);
        $modifie = $this->putJson("/api/rapports/programmes/{$cree['id']}", $this->programme(['nom' => 'Ventes mensuelles', 'format' => 'xlsx']))->assertOk()->json();
        $this->assertSame(['Ventes mensuelles', 'xlsx', 'Max Manager', '2026-11-01'], [$modifie['nom'], $modifie['format'], $modifie['compte'], $modifie['prochain_envoi']]);
        $hebdo = $this->putJson("/api/rapports/programmes/{$cree['id']}", $this->programme(['frequence' => 'hebdomadaire', 'jour' => 1]))->assertOk()->json();
        $this->assertSame('2026-10-19', $hebdo['prochain_envoi']);

        // Sans le droit de programmer : consulter oui, régler non.
        $this->agir($this->manager(['rapports.programmer'], 'Lou Lecteur'));
        $this->getJson('/api/rapports/programmes')->assertOk();
        $this->postJson('/api/rapports/programmes', $this->programme())->assertForbidden();
        $this->deleteJson("/api/rapports/programmes/{$cree['id']}")->assertForbidden();
        // Sans le droit de voir les factures : l'envoi des ventes n'apparaît pas et reste introuvable.
        $this->agir($this->manager(['factures.voir'], 'Sam Stock'));
        $this->assertSame([$stock['id']], array_column($this->getJson('/api/rapports/programmes')->json('programmes'), 'id'));
        $this->putJson("/api/rapports/programmes/{$cree['id']}", $this->programme())->assertNotFound();
        $this->deleteJson("/api/rapports/programmes/{$cree['id']}")->assertNotFound();

        // Une autre entreprise ne voit rien.
        $autre = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']));
        $this->assertSame([], $this->getJson('/api/rapports/programmes')->json('programmes'));
        $this->deleteJson("/api/rapports/programmes/{$cree['id']}")->assertNotFound();

        $this->agir($this->admin);
        $this->deleteJson("/api/rapports/programmes/{$stock['id']}")->assertNoContent();
        $this->assertSame(1, $this->chez(fn () => RapportProgramme::count()));
    }

    public function test_la_tache_envoie_les_rapports_dus_sur_leur_periode(): void
    {
        $manager = $this->manager();
        $this->agir($manager);
        $mensuel = $this->postJson('/api/rapports/programmes', $this->programme())->assertCreated()->json('id');
        $hebdo = $this->postJson('/api/rapports/programmes', $this->programme(['nom' => 'Semaine', 'periode' => 'semaine_precedente', 'frequence' => 'hebdomadaire', 'jour' => 1, 'format' => 'csv']))
            ->assertCreated()->json('id');
        $inactif = $this->postJson('/api/rapports/programmes', $this->programme(['nom' => 'En pause', 'frequence' => 'quotidienne', 'actif' => false]))->assertCreated()->json('id');
        // Une autre entreprise a son envoi programmé du jour : il ne part pas avec ceux-ci.
        $autre = Tenant::factory()->create(['modules' => ['ventes', 'achats']]);
        $this->agir(AdminUser::factory()->for($autre)->create(['role' => 'admin']));
        $this->postJson('/api/rapports/programmes', $this->programme(['frequence' => 'quotidienne']))->assertCreated();

        Mail::fake();
        // Rien n'est dû aujourd'hui (les premiers envois sont pour plus tard).
        $this->assertSame('Aucun rapport à envoyer.', $this->tache());

        // Le 1er novembre (le serveur arrêté le lundi 19 octobre) : le mensuel, et l'hebdomadaire manqué, une seule fois.
        $this->travelTo('2026-11-01 06:30:00');
        $this->assertSame('2 rapport(s) envoyé(s).', $this->tache());
        $envois = $this->chez(fn () => CourrielEnvoi::where('type', 'rapport')->orderBy('id')->get());
        $this->assertSame([[$mensuel, $manager->id, 'ventes-par-periode-2026-11-01.pdf'], [$hebdo, $manager->id, 'ventes-par-periode-2026-11-01.csv']],
            $envois->map(fn ($e) => [$e->document_id, $e->envoye_par, $e->piece_jointe])->all());
        // Périodes calculées au jour de l'envoi : octobre entier, la semaine du 19 au 25 octobre.
        $this->assertSame([['2026-10-01', '2026-10-31'], ['2026-10-19', '2026-10-25']], $envois->map(fn ($e) => [$e->rapport['valeurs']['du'], $e->rapport['valeurs']['au']])->all());
        $this->assertStringContainsString('Du : 01/10/2026', $envois[0]->corps_texte);
        Mail::assertSent(CourrielSimple::class, fn (CourrielSimple $c) => $c->hasTo('direction@entreprise.test') && str_starts_with($c->fichiers[0]['contenu'], '%PDF'));
        Mail::assertSentCount(2);
        $programmes = $this->chez(fn () => RapportProgramme::orderBy('id')->get()->keyBy('id'));
        $this->assertSame(['2026-12-01', '2026-11-02', '2026-10-18'],
            [$programmes[$mensuel]->prochain_envoi->toDateString(), $programmes[$hebdo]->prochain_envoi->toDateString(), $programmes[$inactif]->prochain_envoi->toDateString()]);
        $this->assertNotNull($programmes[$mensuel]->dernier_envoi_le);
        $this->assertSame('Aucun rapport à envoyer.', $this->tache());

        // Le responsable perd le droit de voir les factures : l'envoi est refusé, noté, et reporté à la semaine suivante.
        $this->agir($this->admin);
        $this->putJson("/api/acces-comptes/{$manager->id}", ['groupes' => [], 'exceptions' => ['factures.voir' => false]])->assertOk();
        $this->travelTo('2026-11-02 06:30:00');
        $this->assertSame('ERREUR 0 rapport(s) envoyé(s), 1 refusé(s) : « Semaine » : Max Manager ne peut plus ouvrir ce rapport : l\'envoi est suspendu.', $this->tache());
        $refuse = $this->chez(fn () => RapportProgramme::find($hebdo));
        $this->assertSame(['2026-11-09', 'Max Manager ne peut plus ouvrir ce rapport : l\'envoi est suspendu.'], [$refuse->prochain_envoi->toDateString(), $refuse->derniere_erreur]);
        Mail::assertSentCount(2);
        $this->assertSame('Max Manager ne peut plus ouvrir ce rapport : l\'envoi est suspendu.',
            collect($this->getJson('/api/rapports/programmes')->json('programmes'))->firstWhere('id', $hebdo)['derniere_erreur']);
    }

    public function test_envoyer_maintenant(): void
    {
        $id = $this->postJson('/api/rapports/programmes', $this->programme(['format' => 'csv']))->assertCreated()->json('id');
        Mail::fake();
        $reponse = $this->postJson("/api/rapports/programmes/{$id}/envoyer?langue=fr")->assertOk()->json();
        $this->assertSame(['envoye', 'ventes-par-periode-2026-10-17.csv', $id], [$reponse['envoi']['statut'], $reponse['envoi']['piece_jointe'], $reponse['envoi']['document_id']]);
        // La date du prochain envoi ne bouge pas ; le dernier envoi est noté.
        $this->assertSame(['2026-11-01', 'envoye'], [$reponse['programme']['prochain_envoi'], $reponse['programme']['dernier_envoi']['statut']]);
        // Envoyé à la main le 17 octobre, sur le mois précédent : septembre.
        Mail::assertSent(CourrielSimple::class, fn (CourrielSimple $c) => str_contains($c->fichiers[0]['contenu'], '2026-09'));

        // Le compte qui envoie à la main doit pouvoir ouvrir le rapport.
        $this->agir($this->manager(['factures.voir']));
        $this->postJson("/api/rapports/programmes/{$id}/envoyer")->assertNotFound();
    }
}
