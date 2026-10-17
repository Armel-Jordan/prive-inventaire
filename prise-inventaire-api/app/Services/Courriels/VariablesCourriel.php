<?php

namespace App\Services\Courriels;

use App\Models\Tenant;
use App\Models\TenantParametres;
use App\Support\Courriels;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Valeurs des variables d'un courriel ({numero}, {client_nom}, {montant_ttc}…), tirées d'un
 * document, ou valeurs d'exemple quand l'entreprise n'a encore aucun document de ce type.
 * Montants et dates sont écrits selon la langue du modèle.
 */
class VariablesCourriel
{
    private const MOTIF = '/\{([a-z_]+)\}/';

    /** Toutes les variables du type, avec leur valeur (chaîne vide si le document ne la renseigne pas). */
    public function pour(string $type, ?Model $document, string $langue): array
    {
        $parametres = TenantParametres::first();
        $devise = $parametres?->devise_symbole ?: '€';

        $valeurs = $document
            ? $this->duDocument($type, $document, $langue, $devise, $parametres?->devise_code)
            : $this->exemple($type, $langue, $devise);

        return array_map(
            fn ($valeur) => (string) ($valeur ?? ''),
            array_merge(array_fill_keys(Courriels::variables($type), ''), $valeurs, $this->entreprise($parametres)),
        );
    }

    /** Document le plus récent du type (pour l'aperçu d'un modèle). */
    public function documentRecent(string $type): ?Model
    {
        $classe = Courriels::DOCUMENTS[$type] ?? null;

        return $classe ? $classe::query()->latest('id')->first() : null;
    }

    /** Remplace les variables connues ; les autres restent telles quelles. */
    public function remplacer(string $texte, array $valeurs, ?callable $echapper = null): string
    {
        return preg_replace_callback(self::MOTIF, function (array $m) use ($valeurs, $echapper) {
            if (! array_key_exists($m[1], $valeurs)) {
                return $m[0];
            }

            return $echapper ? $echapper($valeurs[$m[1]]) : $valeurs[$m[1]];
        }, $texte);
    }

    /** Variables écrites dans le texte mais inexistantes pour ce type (faute de frappe, autre type…). */
    public function inconnues(string $texte, array $valeurs): array
    {
        preg_match_all(self::MOTIF, $texte, $m);

        return array_values(array_unique(array_diff($m[1], array_keys($valeurs))));
    }

    private function duDocument(string $type, Model $d, string $langue, string $devise, ?string $codeDevise): array
    {
        // Document de vente dans une devise étrangère : ses montants dans sa devise.
        if ($type !== 'commande_fournisseur' && filled($d->getAttribute('devise')) && $d->getAttribute('devise') !== $codeDevise) {
            $devise = (string) $d->getAttribute('devise');
        }
        $montant = fn ($v) => $this->montant($v, $langue, $devise);
        $date = fn ($v) => $this->date($v, $langue);

        return match ($type) {
            'devis' => [
                'numero' => $d->numero,
                'date' => $date($d->date_devis),
                'date_validite' => $date($d->date_validite),
                'montant_total' => $montant($d->montant_total),
            ] + $this->client($d->client),
            'commande_client' => [
                'numero' => $d->numero,
                'date' => $date($d->date_commande),
                'date_livraison_souhaitee' => $date($d->date_livraison_souhaitee),
                'montant_ht' => $montant($d->montant_ht),
                'montant_ttc' => $montant($d->montant_ttc),
            ] + $this->client($d->client),
            'facture' => [
                'numero' => $d->numero,
                'date' => $date($d->date_facture),
                'date_echeance' => $date($d->date_echeance),
                'montant_ht' => $montant($d->montant_ht),
                'montant_ttc' => $montant($d->montant_ttc),
                'montant_paye' => $montant($d->montant_paye),
                'reste_a_payer' => $montant($d->reste_a_payer),
            ] + $this->client($d->client),
            'avoir' => [
                'numero' => $d->numero,
                'date' => $date($d->date_avoir),
                'numero_facture' => $d->facture?->numero,
                'motif' => $d->motif,
                'montant_ht' => $montant($d->montant_ht),
                'montant_ttc' => $montant($d->montant_ttc),
                'credit_disponible' => $montant($d->credit_disponible),
            ] + $this->client($d->client),
            'relance' => [
                'numero' => $d->numero,
                'date' => $date($d->date_facture),
                'date_echeance' => $date($d->date_echeance ?? $d->date_facture),
                'jours_retard' => (string) max(0, (int) ($d->date_echeance ?? $d->date_facture)->copy()->startOfDay()->diffInDays(Carbon::today())),
                // Niveau de la relance à envoyer : celles déjà faites, plus une.
                'niveau' => (string) ($d->relances()->count() + 1),
                'montant_ttc' => $montant($d->montant_ttc),
                'montant_paye' => $montant($d->montant_paye),
                'reste_a_payer' => $montant($d->reste_a_payer),
            ] + $this->client($d->client),
            'demande_acompte' => [
                'numero' => $d->numero,
                'date' => $date($d->date_demande),
                'montant' => $montant($d->montant),
                'description' => $d->description,
            ] + $this->client($d->client),
            'bon_livraison' => [
                'numero' => $d->numero,
                'date_livraison' => $date($d->date_livraison),
                'numero_facture' => $d->facture?->numero,
            ] + $this->client($d->facture?->client),
            'commande_fournisseur' => [
                'numero' => $d->numero,
                'date' => $date($d->date_commande),
                'date_livraison_prevue' => $date($d->date_livraison_prevue),
                'fournisseur_nom' => $d->fournisseur?->raison_sociale,
                'fournisseur_code' => $d->fournisseur?->code,
                'contact_nom' => $d->fournisseur?->contact_nom,
                // Une commande peut être passée dans la devise du fournisseur.
                'montant_total' => $this->montant($d->montant_total, $langue, $d->devise && $d->devise !== $codeDevise ? $d->devise : $devise),
            ],
            'retour_fournisseur' => [
                'numero' => $d->numero,
                'date' => $date($d->date_expedition ?? $d->date_retour),
                'type_retour' => $this->typeRetour($d->type, $langue),
                'numero_rma' => $d->numero_rma,
                'fournisseur_nom' => $d->fournisseur?->raison_sociale,
                'fournisseur_code' => $d->fournisseur?->code,
                'contact_nom' => $d->fournisseur?->contact_nom,
                'montant' => $montant($d->montant),
            ],
            default => [],
        };
    }

    private function client(?Model $client): array
    {
        return [
            'client_nom' => $client?->raison_sociale,
            'client_code' => $client?->code,
            'contact_nom' => $client?->contact_nom,
        ];
    }

    private function entreprise(?TenantParametres $parametres): array
    {
        return [
            'entreprise_nom' => $parametres?->nom_entreprise ?: (Tenant::find(app(TenantContext::class)->getTenantId())?->nom ?? ''),
            'entreprise_adresse' => $parametres?->adresse,
            'entreprise_telephone' => $parametres?->telephone,
            'entreprise_courriel' => $parametres?->email,
            'entreprise_numero' => $parametres?->siret,
            'entreprise_numero_taxe' => $parametres?->tva_numero,
        ];
    }

    private function exemple(string $type, string $langue, string $devise): array
    {
        $en = $langue === 'en';
        $jour = Carbon::today();
        $prefixes = ['devis' => 'DEV', 'commande_client' => 'CMD', 'facture' => 'FAC', 'relance' => 'FAC', 'avoir' => 'AV', 'demande_acompte' => 'DA', 'bon_livraison' => 'BL', 'commande_fournisseur' => 'BC', 'retour_fournisseur' => 'RF'];

        $valeurs = [
            'numero' => ($prefixes[$type] ?? 'DOC').'-00001',
            'date' => $this->date($jour, $langue),
            'date_validite' => $this->date($jour->copy()->addDays(30), $langue),
            'date_echeance' => $this->date($jour->copy()->addDays(30), $langue),
            'date_livraison_souhaitee' => $this->date($jour->copy()->addDays(7), $langue),
            'date_livraison_prevue' => $this->date($jour->copy()->addDays(7), $langue),
            'date_livraison' => $this->date($jour, $langue),
            'numero_facture' => 'FAC-00001',
            'client_nom' => $en ? 'Sample customer' : 'Client exemple',
            'client_code' => 'C00001',
            'fournisseur_nom' => $en ? 'Sample supplier' : 'Fournisseur exemple',
            'fournisseur_code' => 'F00001',
            'contact_nom' => $en ? 'John Smith' : 'Jean Dupont',
            'montant_total' => $this->montant(1000, $langue, $devise),
            'montant_ht' => $this->montant(1000, $langue, $devise),
            'montant_ttc' => $this->montant(1200, $langue, $devise),
            'montant_paye' => $this->montant(0, $langue, $devise),
            'reste_a_payer' => $this->montant(1200, $langue, $devise),
            'motif' => $en ? 'Returned goods' : 'Marchandise retournée',
            'credit_disponible' => $this->montant(0, $langue, $devise),
            'montant' => $this->montant(300, $langue, $devise),
            'description' => $en ? 'Deposit before starting the work' : 'Acompte avant le début des travaux',
            'jours_retard' => '15',
            'niveau' => '1',
            'type_retour' => $this->typeRetour('remboursement', $langue),
            'numero_rma' => 'RMA-12345',
        ];

        return array_intersect_key($valeurs, array_flip(Courriels::TYPES[$type] ?? []));
    }

    /** Espaces insécables : un montant n'est jamais coupé en fin de ligne. */
    private function montant(mixed $valeur, string $langue, string $devise): string
    {
        $texte = $langue === 'en'
            ? number_format((float) $valeur, 2, '.', ',')
            : number_format((float) $valeur, 2, ',', "\u{00A0}");

        return $texte."\u{00A0}".$devise;
    }

    /** Type d'un retour fournisseur, dans la langue du modèle. */
    private function typeRetour(?string $type, string $langue): string
    {
        $libelles = $langue === 'en'
            ? ['remboursement' => 'refund', 'remplacement' => 'replacement', 'consigne' => 'returnable container']
            : ['remboursement' => 'remboursement', 'remplacement' => 'remplacement', 'consigne' => 'contenant consigné'];

        return $libelles[$type] ?? (string) $type;
    }

    private function date(mixed $date, string $langue): ?string
    {
        return $date ? Carbon::parse($date)->format($langue === 'en' ? 'Y-m-d' : 'd/m/Y') : null;
    }
}
