<?php

namespace App\Services\Courriels;

use App\Mail\CourrielSimple;
use App\Models\CourrielEnvoi;
use App\Models\CourrielModele;
use App\Models\CourrielParametres;
use App\Services\BonCommandePdf;
use App\Services\DemandesAcompte;
use App\Services\DocumentsVentePdf;
use App\Services\Relances;
use App\Services\RetourFournisseurPdf;
use App\Support\Courriels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Envoi d'un document par courriel : pré-remplissage depuis le modèle de l'entreprise (ou le
 * modèle par défaut), mise en file avec le courriel rendu tel qu'il partira, puis envoi avec le
 * PDF du document régénéré à chaque tentative. Un échec est enregistré, jamais propagé.
 */
class EnvoiCourriel
{
    public function __construct(
        private readonly VariablesCourriel $variables,
        private readonly RenduCourriel $rendu,
        private readonly MailerTenant $mailer,
        private readonly DocumentsVentePdf $documents,
        private readonly BonCommandePdf $bonCommande,
        private readonly RetourFournisseurPdf $bonRetour,
    ) {}

    /** Document source d'un envoi, dans l'entreprise courante. */
    public function document(string $type, int $id): Model
    {
        $classe = Courriels::DOCUMENTS[$type] ?? throw new InvalidArgumentException("Type de courriel sans document : {$type}");

        return $classe::query()->findOrFail($id);
    }

    /** Champs de la fenêtre de rédaction, variables déjà remplacées. */
    public function preparer(string $type, Model $document, string $langue): array
    {
        $modele = $this->modele($type, $langue);
        $valeurs = $this->variables->pour($type, $document, $langue);

        return [
            'type' => $type,
            'document_id' => $document->getKey(),
            'document_numero' => $document->numero,
            'langue' => $langue,
            'modele_personnalise' => $modele['personnalise'],
            'destinataires' => array_values(array_filter([$this->adresseDestinataire($type, $document)])),
            'objet' => $this->variables->remplacer($modele['objet'], $valeurs),
            'contenu' => $this->variables->remplacer($modele['contenu'], $valeurs),
            'piece_jointe' => $this->rendu->nomFichier($type, $modele['nom_fichier'], $valeurs),
        ];
    }

    /** Met le courriel en file d'envoi, rendu tel qu'il partira (signature et coordonnées comprises). */
    public function creer(string $type, Model $document, array $donnees, ?int $auteur): CourrielEnvoi
    {
        $langue = $donnees['langue'];
        $modele = $this->modele($type, $langue);
        $valeurs = $this->variables->pour($type, $document, $langue);
        $rendu = $this->rendu->rendre($type, [
            'objet' => $donnees['objet'],
            'contenu' => $donnees['contenu'],
            'nom_fichier' => $modele['nom_fichier'],
        ], $valeurs, $langue);

        $parametres = CourrielParametres::first();
        $expediteur = $this->mailer->expediteur($parametres);
        // La copie cachée systématique des paramètres s'ajoute à celles saisies.
        $copiesCachees = $this->adresses([...($donnees['copies_cachees'] ?? []), $parametres?->copie_cachee]);

        return CourrielEnvoi::create([
            'type' => $type,
            'document_id' => $document->getKey(),
            'document_numero' => $document->numero,
            'destinataires' => $this->adresses($donnees['destinataires']),
            'copies' => $this->adresses($donnees['copies'] ?? []) ?: null,
            'copies_cachees' => $copiesCachees ?: null,
            'expediteur_adresse' => $expediteur['adresse'],
            'expediteur_nom' => $expediteur['nom'],
            'repondre_a' => $this->mailer->repondreA($parametres, $modele['repondre_a']),
            'objet' => $rendu['objet'],
            'corps_html' => $rendu['html'],
            'corps_texte' => $rendu['texte'],
            'piece_jointe' => ($donnees['joindre_pdf'] ?? true) ? $rendu['nom_fichier'] : null,
            'accuse_lecture' => (bool) $modele['accuse_lecture'],
            'statut' => CourrielEnvoi::EN_ATTENTE,
            'envoye_par' => $auteur,
        ]);
    }

    /**
     * Envoie un courriel de la file. La prise en charge est atomique (en_attente ou echec →
     * en_cours) : deux traitements simultanés ne peuvent pas envoyer deux fois le même courriel.
     */
    public function envoyer(CourrielEnvoi $envoi): CourrielEnvoi
    {
        $pris = CourrielEnvoi::query()->whereKey($envoi->getKey())
            ->whereIn('statut', [CourrielEnvoi::EN_ATTENTE, CourrielEnvoi::ECHEC])
            ->update(['statut' => CourrielEnvoi::EN_COURS, 'dernier_essai' => now(), 'tentatives' => DB::raw('tentatives + 1')]);
        $envoi->refresh();
        if (! $pris) {
            return $envoi;
        }

        $parametres = CourrielParametres::first();
        $expediteur = $this->mailer->expediteur($parametres);
        $envoi->fill(['expediteur_adresse' => $expediteur['adresse'], 'expediteur_nom' => $expediteur['nom']]);

        try {
            $fichiers = $envoi->piece_jointe
                ? [['nom' => $envoi->piece_jointe, 'contenu' => $this->pdf($envoi->type, $this->document($envoi->type, (int) $envoi->document_id))]]
                : [];

            $this->mailer->mailer($parametres)
                ->to($envoi->destinataires)
                ->cc($envoi->copies ?? [])
                ->bcc($envoi->copies_cachees ?? [])
                ->send(new CourrielSimple(
                    objetCourriel: $envoi->objet,
                    corpsHtml: $envoi->corps_html,
                    corpsTexte: $envoi->corps_texte,
                    expediteur: $expediteur,
                    reponseA: $envoi->repondre_a,
                    accuseLecture: $envoi->accuse_lecture,
                    fichiers: $fichiers,
                ));

            $envoi->fill(['statut' => CourrielEnvoi::ENVOYE, 'envoye_le' => now(), 'erreur' => null]);
            // Une demande d'acompte envoyée au client passe « envoyée », comme dans fripv2.
            if ($envoi->type === 'demande_acompte') {
                app(DemandesAcompte::class)->apresEnvoiCourriel((int) $envoi->document_id);
            }
            // Une relance envoyée est notée sur sa facture.
            if ($envoi->type === 'relance') {
                app(Relances::class)->apresEnvoiCourriel($envoi);
            }
        } catch (ModelNotFoundException) {
            $envoi->fill(['statut' => CourrielEnvoi::ECHEC, 'erreur' => 'Le document à joindre est introuvable (supprimé ?).']);
        } catch (Throwable $erreur) {
            Log::warning('Échec d\'envoi du courriel '.$envoi->getKey(), ['erreur' => $erreur->getMessage()]);
            $envoi->fill(['statut' => CourrielEnvoi::ECHEC, 'erreur' => Str::limit($erreur->getMessage(), 1000)]);
        }

        $envoi->save();

        return $envoi;
    }

    /** Modèle de l'entreprise pour ce type et cette langue, sinon le modèle par défaut. */
    private function modele(string $type, string $langue): array
    {
        $modele = CourrielModele::where('type', $type)->where('langue', $langue)->first();

        return $modele
            ? $modele->only(['objet', 'contenu', 'nom_fichier', 'repondre_a', 'accuse_lecture']) + ['personnalise' => true]
            : Courriels::modeleParDefaut($type, $langue) + ['personnalise' => false];
    }

    private function adresseDestinataire(string $type, Model $document): ?string
    {
        return match ($type) {
            'bon_livraison' => $document->facture?->client?->email,
            'commande_fournisseur', 'retour_fournisseur' => $document->fournisseur?->email,
            default => $document->client?->email,
        } ?: null;
    }

    /** Adresses dédoublonnées (sans tenir compte de la casse), vides retirées. */
    private function adresses(array $adresses): array
    {
        $uniques = [];
        foreach (array_filter(array_map(fn ($a) => trim((string) $a), $adresses)) as $adresse) {
            $uniques[mb_strtolower($adresse)] ??= $adresse;
        }

        return array_values($uniques);
    }

    /** PDF du document, identique à celui téléchargé depuis l'application. */
    private function pdf(string $type, Model $document): string
    {
        if ($type === 'commande_fournisseur') {
            return $this->bonCommande->pdf($document)->output();
        }
        if ($type === 'retour_fournisseur') {
            return $this->bonRetour->pdf($document)->output();
        }

        $donnees = match ($type) {
            'devis' => $this->documents->devis($document),
            'commande_client' => $this->documents->commande($document),
            'facture', 'relance' => $this->documents->facture($document),
            'avoir' => $this->documents->avoir($document),
            'demande_acompte' => $this->documents->demandeAcompte($document),
            'bon_livraison' => $this->documents->bonLivraison($document),
        };

        return $this->documents->pdf($donnees)->output();
    }
}
