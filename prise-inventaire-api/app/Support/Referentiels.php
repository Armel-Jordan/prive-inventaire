<?php

namespace App\Support;

use App\Models\BonLivraison;
use App\Models\Caisse;
use App\Models\CaisseSession;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\ComClientEntete;
use App\Models\ComFourEntete;
use App\Models\CompteBancaire;
use App\Models\ConditionPaiement;
use App\Models\ContratLocation;
use App\Models\Devis;
use App\Models\Devise;
use App\Models\DeviseTaux;
use App\Models\Entrepot;
use App\Models\Fabricant;
use App\Models\Facture;
use App\Models\FactureFournisseur;
use App\Models\Fournisseur;
use App\Models\Itineraire;
use App\Models\ModePaiement;
use App\Models\ProduitTenant;
use App\Models\ProduitUnite;
use App\Models\Raison;
use App\Models\RapprochementBancaire;
use App\Models\ReleveBancaire;
use App\Models\Representant;
use App\Models\RetourClientLigne;
use App\Models\RetourFournisseurLigne;
use App\Models\Secteur;
use App\Models\StatutPersonnalise;
use App\Models\Territoire;
use App\Models\Tournee;
use App\Models\TransactionBancaire;
use App\Models\Transporteur;
use App\Models\TypeLocation;
use App\Models\Unite;
use App\Models\UniteConversion;
use App\Models\VirementLot;
use App\Services\AbonnementsClients;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Catalogue des référentiels génériques servis par ReferentielController.
 * Ajouter un référentiel = une entrée ici (avec son modèle et sa table).
 *
 * Clés d'une définition :
 *  - modele            : classe Eloquent, porteuse de BelongsToTenant
 *  - regles            : fn (?int $id): array — règles de validation (id de la fiche modifiée)
 *  - ordre             : [colonne, sens] de la liste, ou liste de paires [[colonne, sens], …]
 *  - avec              : relations chargées avec la liste et les réponses
 *  - majuscules        : champs normalisés en majuscules avant validation
 *  - controle          : fn (array $donnees, ?Model $fiche): ?array — [champ => message] si refus
 *  - suppression       : fn (Model $fiche): ?string — message si la suppression est refusée
 *  - unique_par_defaut : `par_defaut` ne peut être vrai que sur une seule fiche du tenant
 *  - apres             : fn (Model $fiche): void — après création ou modification
 */
final class Referentiels
{
    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * La fiche est-elle encore portée par l'une de ces données ? [modèle, colonne, valeur] ; les modèles de l'entreprise
     * ne voient que ses données (et pas les fiches supprimées). Une fiche utilisée se désactive au lieu d'être supprimée :
     * la suppression douce la retirait en silence des listes alors que des fiches la portaient encore.
     *
     * @param  list<array{class-string<Model>, string, mixed}>  $references
     */
    private static function utilisee(array $references): bool
    {
        foreach ($references as [$modele, $colonne, $valeur]) {
            if ($valeur !== null && $modele::query()->where($colonne, $valeur)->exists()) {
                return true;
            }
        }

        return false;
    }

    public static function definition(string $type): array
    {
        return self::definitions()[$type];
    }

    public static function definitions(): array
    {
        return [
            'unites' => [
                'modele' => Unite::class,
                'regles' => fn (?int $id) => [
                    'code' => ['required', 'string', 'max:20', TenantRule::unique('unites', 'code', $id)],
                    'libelle' => ['required', 'string', 'max:100'],
                    'symbole' => ['nullable', 'string', 'max:20'],
                    'decimales' => ['sometimes', 'integer', 'min:0', 'max:6'],
                    'actif' => ['boolean'],
                ],
                'ordre' => ['code', 'asc'],
                'suppression' => fn (Model $unite) => match (true) {
                    UniteConversion::where(
                        fn ($q) => $q->where('unite_source_id', $unite->getKey())->orWhere('unite_cible_id', $unite->getKey())
                    )->exists() => __('Cette unité est utilisée dans une conversion.'),
                    self::utilisee([[ProduitUnite::class, 'unite_id', $unite->getKey()], [ProduitTenant::class, 'mesure', $unite->code]]) => __('Cette unité est utilisée par des produits : désactivez-la plutôt.'),
                    default => null,
                },
            ],

            'unite_conversions' => [
                'modele' => UniteConversion::class,
                'regles' => fn (?int $id) => [
                    'unite_source_id' => ['required', 'integer', TenantRule::exists('unites')->whereNull('deleted_at')],
                    'unite_cible_id' => ['required', 'integer', 'different:unite_source_id', TenantRule::exists('unites')->whereNull('deleted_at')],
                    'facteur' => ['required', 'numeric', 'gt:0'],
                ],
                'ordre' => ['id', 'asc'],
                'avec' => ['source:id,code,libelle', 'cible:id,code,libelle'],
                'controle' => fn (array $d, ?Model $fiche) => UniteConversion::where('unite_source_id', $d['unite_source_id'])
                    ->where('unite_cible_id', $d['unite_cible_id'])
                    ->when($fiche, fn ($q) => $q->whereKeyNot($fiche->getKey()))
                    ->exists() ? ['unite_cible_id' => __('Cette conversion existe déjà.')] : null,
            ],

            'categories' => [
                'modele' => Categorie::class,
                'regles' => fn (?int $id) => [
                    'code' => ['nullable', 'string', 'max:30', TenantRule::unique('categories', 'code', $id)],
                    'nom' => ['required', 'string', 'max:100'],
                    'parent_id' => ['nullable', 'integer', TenantRule::exists('categories')->whereNull('deleted_at')],
                    'description' => ['nullable', 'string', 'max:1000'],
                    'actif' => ['boolean'],
                ],
                'ordre' => ['nom', 'asc'],
                'avec' => ['parent:id,nom'],
                'controle' => fn (array $d, ?Model $fiche) => self::controlerCategorie($d, $fiche),
                'suppression' => fn (Model $categorie) => Categorie::where('parent_id', $categorie->getKey())->exists()
                    ? __('Cette catégorie contient des sous-catégories.') : null,
            ],

            'fabricants' => [
                'modele' => Fabricant::class,
                'regles' => fn (?int $id) => [
                    'code' => ['nullable', 'string', 'max:30', TenantRule::unique('fabricants', 'code', $id)],
                    'nom' => ['required', 'string', 'max:150', TenantRule::unique('fabricants', 'nom', $id)],
                    'site_web' => ['nullable', 'url', 'max:255'],
                    'telephone' => ['nullable', 'string', 'max:30'],
                    'email' => ['nullable', 'email', 'max:150'],
                    'notes' => ['nullable', 'string', 'max:2000'],
                    'actif' => ['boolean'],
                ],
                'ordre' => ['nom', 'asc'],
                'suppression' => fn (Model $fabricant) => self::utilisee([[ProduitTenant::class, 'fabricant_id', $fabricant->getKey()]])
                    ? __('Ce fabricant est rattaché à des produits : désactivez-le plutôt.') : null,
            ],

            'modes_paiement' => [
                'modele' => ModePaiement::class,
                'regles' => fn (?int $id) => [
                    'code' => ['required', 'string', 'max:30', TenantRule::unique('modes_paiement', 'code', $id)],
                    'libelle' => ['required', 'string', 'max:100'],
                    'type' => ['required', Rule::in(ModePaiement::TYPES)],
                    'actif' => ['boolean'],
                ],
                'ordre' => ['libelle', 'asc'],
            ],

            // Caisses : tiroirs-caisses ou points d'encaissement, auxquels se rattachent les sessions de caisse.
            'caisses' => [
                'modele' => Caisse::class,
                'regles' => fn (?int $id) => [
                    'code' => ['required', 'string', 'max:10', TenantRule::unique('caisses', 'code', $id)],
                    'nom' => ['required', 'string', 'max:100'],
                    'actif' => ['boolean'],
                ],
                'ordre' => ['code', 'asc'],
                'majuscules' => ['code'],
                'suppression' => fn (Model $caisse) => CaisseSession::where('caisse_id', $caisse->getKey())->exists()
                    ? __('Cette caisse a déjà servi : désactivez-la plutôt que de la supprimer.') : null,
            ],

            'conditions_paiement' => [
                'modele' => ConditionPaiement::class,
                'regles' => fn (?int $id) => [
                    'code' => ['required', 'string', 'max:30', TenantRule::unique('conditions_paiement', 'code', $id)],
                    'libelle' => ['required', 'string', 'max:100'],
                    'delai_jours' => ['required', 'integer', 'min:0', 'max:3650'],
                    'fin_de_mois' => ['boolean'],
                    'escompte_pourcentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
                    'escompte_jours' => ['nullable', 'integer', 'min:0', 'max:3650', 'required_with:escompte_pourcentage'],
                    'par_defaut' => ['boolean'],
                    'actif' => ['boolean'],
                ],
                'ordre' => ['delai_jours', 'asc'],
                'unique_par_defaut' => true,
                'suppression' => fn (Model $condition) => self::utilisee([
                    [Client::class, 'condition_paiement_id', $condition->getKey()],
                    [Facture::class, 'condition_paiement_id', $condition->getKey()],
                    [FactureFournisseur::class, 'condition_paiement_id', $condition->getKey()],
                ]) ? __('Cette condition de paiement est utilisée par des clients ou des factures : désactivez-la plutôt.') : null,
            ],

            'comptes_bancaires' => [
                'modele' => CompteBancaire::class,
                'regles' => fn (?int $id) => [
                    'nom' => ['required', 'string', 'max:100', TenantRule::unique('comptes_bancaires', 'nom', $id)],
                    'banque' => ['required', 'string', 'max:150'],
                    'agence' => ['nullable', 'string', 'max:150'],
                    'titulaire' => ['nullable', 'string', 'max:150'],
                    'numero_compte' => ['nullable', 'string', 'max:50'],
                    'iban' => ['nullable', 'string', 'max:34'],
                    'bic' => ['nullable', 'string', 'max:11'],
                    'devise_code' => ['nullable', 'alpha', 'size:3'],
                    'par_defaut' => ['boolean'],
                    'actif' => ['boolean'],
                    'notes' => ['nullable', 'string', 'max:2000'],
                ],
                'ordre' => ['nom', 'asc'],
                'majuscules' => ['iban', 'bic', 'devise_code'],
                'unique_par_defaut' => true,
                'suppression' => fn (Model $compte) => self::utilisee([
                    [TransactionBancaire::class, 'compte_bancaire_id', $compte->getKey()],
                    [ReleveBancaire::class, 'compte_bancaire_id', $compte->getKey()],
                    [RapprochementBancaire::class, 'compte_bancaire_id', $compte->getKey()],
                    [VirementLot::class, 'compte_bancaire_id', $compte->getKey()],
                ]) ? __('Ce compte bancaire a des transactions, des relevés ou des virements : désactivez-le plutôt.') : null,
            ],

            'devises' => [
                'modele' => Devise::class,
                'regles' => fn (?int $id) => [
                    'code' => ['required', 'alpha', 'size:3', TenantRule::unique('devises', 'code', $id)],
                    'libelle' => ['required', 'string', 'max:100'],
                    'symbole' => ['nullable', 'string', 'max:10'],
                    'decimales' => ['sometimes', 'integer', 'min:0', 'max:4'],
                    'taux' => ['required', 'numeric', 'gt:0'],
                    'actif' => ['boolean'],
                ],
                'ordre' => ['code', 'asc'],
                'majuscules' => ['code'],
                // Devise d'un tiers, d'un compte bancaire ou d'un document : sans elle, son taux ne se retrouve plus.
                'suppression' => fn (Model $devise) => self::utilisee([
                    [Client::class, 'devise', $devise->code],
                    [Fournisseur::class, 'devise', $devise->code],
                    [CompteBancaire::class, 'devise_code', $devise->code],
                    [Devis::class, 'devise', $devise->code],
                    [ComClientEntete::class, 'devise', $devise->code],
                    [Facture::class, 'devise', $devise->code],
                    [ComFourEntete::class, 'devise', $devise->code],
                    [FactureFournisseur::class, 'devise', $devise->code],
                ]) ? __('Cette devise est utilisée par des clients, des fournisseurs, des comptes bancaires ou des documents : désactivez-la plutôt.') : null,
                // Chaque nouveau taux est historisé (création ou changement de taux).
                'apres' => function (Model $devise): void {
                    if ($devise->wasRecentlyCreated || $devise->wasChanged('taux')) {
                        DeviseTaux::create([
                            'devise_id' => $devise->getKey(),
                            'taux' => $devise->taux,
                            'date_effet' => now()->toDateString(),
                            'created_by' => auth()->id(),
                        ]);
                    }
                },
            ],

            'entrepots' => [
                'modele' => Entrepot::class,
                'regles' => fn (?int $id) => [
                    'code' => ['required', 'string', 'max:20', TenantRule::unique('entrepots', 'code', $id)],
                    'nom' => ['required', 'string', 'max:100'],
                    'type' => ['required', Rule::in(Entrepot::TYPES)],
                    'adresse' => ['nullable', 'string', 'max:255'],
                    'ville' => ['nullable', 'string', 'max:100'],
                    'region' => ['nullable', 'string', 'max:100'],
                    'code_postal' => ['nullable', 'string', 'max:20'],
                    'pays' => ['nullable', 'string', 'max:100'],
                    'telephone' => ['nullable', 'string', 'max:30'],
                    'responsable' => ['nullable', 'string', 'max:150'],
                    'par_defaut' => ['boolean'],
                    'actif' => ['boolean'],
                ],
                'ordre' => ['code', 'asc'],
                'unique_par_defaut' => true,
                'suppression' => fn (Model $entrepot) => self::utilisee([
                    [Secteur::class, 'entrepot_id', $entrepot->getKey()],
                    [ComFourEntete::class, 'entrepot_id', $entrepot->getKey()],
                ]) ? __('Cet entrepôt est utilisé par des secteurs ou des commandes fournisseur : désactivez-le plutôt.') : null,
            ],

            'territoires' => [
                'modele' => Territoire::class,
                'regles' => fn (?int $id) => [
                    'code' => ['required', 'string', 'max:20', TenantRule::unique('territoires', 'code', $id)],
                    'nom' => ['required', 'string', 'max:100'],
                    'description' => ['nullable', 'string', 'max:1000'],
                    'actif' => ['boolean'],
                ],
                'ordre' => ['code', 'asc'],
                'suppression' => fn (Model $territoire) => Itineraire::where('territoire_id', $territoire->getKey())->exists()
                    || Representant::where('territoire_id', $territoire->getKey())->exists()
                    ? __('Ce territoire est utilisé par un itinéraire ou un représentant.') : null,
            ],

            'itineraires' => [
                'modele' => Itineraire::class,
                'regles' => fn (?int $id) => [
                    'code' => ['required', 'string', 'max:20', TenantRule::unique('itineraires', 'code', $id)],
                    'nom' => ['required', 'string', 'max:100'],
                    'territoire_id' => ['nullable', 'integer', TenantRule::exists('territoires')->whereNull('deleted_at')],
                    'description' => ['nullable', 'string', 'max:1000'],
                    'actif' => ['boolean'],
                ],
                'ordre' => ['code', 'asc'],
                'avec' => ['territoire:id,code,nom'],
                'suppression' => fn (Model $itineraire) => self::utilisee([
                    [Client::class, 'itineraire_id', $itineraire->getKey()],
                    [Tournee::class, 'itineraire_id', $itineraire->getKey()],
                ]) ? __('Cet itinéraire est utilisé par des clients ou des tournées : désactivez-le plutôt.') : null,
            ],

            // Modèles de contrats de location : durée, rythme et jour des loyers, à l'avance ou après coup.
            'types_location' => [
                'modele' => TypeLocation::class,
                'regles' => fn (?int $id) => [
                    'code' => ['required', 'string', 'max:20', TenantRule::unique('types_location', 'code', $id)],
                    'nom' => ['required', 'string', 'max:100'],
                    'duree_mois' => ['required', 'integer', 'min:1', 'max:120'],
                    'frequence' => ['required', Rule::in(array_keys(AbonnementsClients::FREQUENCES))],
                    'jour_facturation' => ['required', 'integer', 'between:1,28'],
                    'a_echoir' => ['boolean'],
                    'actif' => ['boolean'],
                ],
                'ordre' => ['code', 'asc'],
                'majuscules' => ['code'],
                'suppression' => fn (Model $type) => self::utilisee([[ContratLocation::class, 'type_location_id', $type->getKey()]])
                    ? __('Ce type de location est utilisé par des contrats : désactivez-le plutôt.') : null,
            ],

            'representants' => [
                'modele' => Representant::class,
                'regles' => fn (?int $id) => [
                    'code' => ['nullable', 'string', 'max:20', TenantRule::unique('representants', 'code', $id)],
                    'nom' => ['required', 'string', 'max:150'],
                    'email' => ['nullable', 'email', 'max:150'],
                    'telephone' => ['nullable', 'string', 'max:30'],
                    'taux_commission' => ['nullable', 'numeric', 'min:0', 'max:100'],
                    'territoire_id' => ['nullable', 'integer', TenantRule::exists('territoires')->whereNull('deleted_at')],
                    'actif' => ['boolean'],
                ],
                'ordre' => ['nom', 'asc'],
                'avec' => ['territoire:id,code,nom'],
                // Rattaché à un client ou à un document de vente : il garde l'attribution des ventes et des commissions.
                'suppression' => fn (Model $representant) => Client::where('representant_id', $representant->getKey())->exists()
                    || Devis::where('representant_id', $representant->getKey())->exists()
                    || ComClientEntete::where('representant_id', $representant->getKey())->exists()
                    || Facture::where('representant_id', $representant->getKey())->exists()
                    ? __('Ce représentant est rattaché à des clients ou à des documents de vente : désactivez-le plutôt.') : null,
            ],

            'transporteurs' => [
                'modele' => Transporteur::class,
                'regles' => fn (?int $id) => [
                    'code' => ['nullable', 'string', 'max:20', TenantRule::unique('transporteurs', 'code', $id)],
                    'nom' => ['required', 'string', 'max:150', TenantRule::unique('transporteurs', 'nom', $id)],
                    'telephone' => ['nullable', 'string', 'max:30'],
                    'email' => ['nullable', 'email', 'max:150'],
                    // Lien de suivi, qui peut contenir {numero} : pas la règle « url », qui refuse les accolades.
                    'url_suivi' => ['nullable', 'string', 'max:255', 'starts_with:http://,https://'],
                    'numero_compte' => ['nullable', 'string', 'max:50'],
                    'notes' => ['nullable', 'string', 'max:2000'],
                    'actif' => ['boolean'],
                    // « Livraison facturée » et frais de transport d'achat (un frais de livraison).
                    'frais_livraison_applicable' => ['boolean'],
                    'frais_livraison_achat_id' => ['nullable', 'integer', TenantRule::exists('frais')->where('application', 'livraison')->whereNull('deleted_at')],
                ],
                'ordre' => ['nom', 'asc'],
                // Rattaché à un tiers ou à un document (phase 1.11b) : l'envoi garde son transporteur.
                'suppression' => function (Model $transporteur) {
                    $id = $transporteur->getKey();
                    $references = array_map(fn (string $modele) => [$modele, 'transporteur_id', $id],
                        [Client::class, Fournisseur::class, Devis::class, ComClientEntete::class, Facture::class, BonLivraison::class, ComFourEntete::class]);

                    return self::utilisee($references)
                        ? __('Ce transporteur est rattaché à des clients, des fournisseurs ou des documents : désactivez-le plutôt.') : null;
                },
            ],

            'raisons' => [
                'modele' => Raison::class,
                'regles' => fn (?int $id) => [
                    'type' => ['required', Rule::in(Raison::TYPES)],
                    'code' => ['required', 'string', 'max:20'],
                    'libelle' => ['required', 'string', 'max:150'],
                    'actif' => ['boolean'],
                ],
                'ordre' => [['type', 'asc'], ['code', 'asc']],
                'suppression' => fn (Model $raison) => self::utilisee([
                    [RetourClientLigne::class, 'raison_id', $raison->getKey()],
                    [RetourFournisseurLigne::class, 'raison_id', $raison->getKey()],
                ]) ? __('Cette raison est utilisée par des retours : désactivez-la plutôt.') : null,
                // Le code est unique par type de raison : le même code peut servir à deux types.
                'controle' => fn (array $d, ?Model $fiche) => Raison::where('type', $d['type'])
                    ->where('code', $d['code'])
                    ->when($fiche, fn ($q) => $q->whereKeyNot($fiche->getKey()))
                    ->exists() ? ['code' => __('Ce code existe déjà pour ce type de raison.')] : null,
            ],

            'statuts_personnalises' => [
                'modele' => StatutPersonnalise::class,
                'regles' => fn (?int $id) => [
                    'document' => ['required', Rule::in(StatutPersonnalise::DOCUMENTS)],
                    'code' => ['required', 'string', 'max:20'],
                    'libelle' => ['required', 'string', 'max:100'],
                    'couleur' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
                    'ordre' => ['sometimes', 'integer', 'min:0', 'max:999'],
                    'actif' => ['boolean'],
                ],
                'ordre' => [['document', 'asc'], ['ordre', 'asc'], ['libelle', 'asc']],
                'controle' => fn (array $d, ?Model $fiche) => StatutPersonnalise::where('document', $d['document'])
                    ->where('code', $d['code'])
                    ->when($fiche, fn ($q) => $q->whereKeyNot($fiche->getKey()))
                    ->exists() ? ['code' => __('Ce code existe déjà pour ce type de document.')] : null,
            ],
        ];
    }

    /** Parent du même tenant, pas de boucle, nom unique entre catégories sœurs. */
    private static function controlerCategorie(array $d, ?Model $fiche): ?array
    {
        $parentId = $d['parent_id'] ?? null;

        if ($fiche && $parentId !== null) {
            // Remonte la lignée du parent : la fiche ne doit pas s'y trouver.
            $vus = [];
            for ($ancetre = (int) $parentId; $ancetre && ! isset($vus[$ancetre]); $ancetre = (int) Categorie::whereKey($ancetre)->value('parent_id')) {
                if ($ancetre === (int) $fiche->getKey()) {
                    return ['parent_id' => __('Une catégorie ne peut pas être placée sous elle-même ou sous une de ses sous-catégories.')];
                }
                $vus[$ancetre] = true;
            }
        }

        $homonyme = Categorie::where('nom', $d['nom'])
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))
            ->when($fiche, fn ($q) => $q->whereKeyNot($fiche->getKey()))
            ->exists();

        return $homonyme ? ['nom' => __('Une catégorie porte déjà ce nom à cet endroit.')] : null;
    }
}
