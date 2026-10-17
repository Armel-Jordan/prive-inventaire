<?php

namespace App\Support;

use App\Models\Caisse;
use App\Models\CaisseSession;
use App\Models\Categorie;
use App\Models\CompteBancaire;
use App\Models\ConditionPaiement;
use App\Models\Devise;
use App\Models\DeviseTaux;
use App\Models\Entrepot;
use App\Models\Fabricant;
use App\Models\Itineraire;
use App\Models\ModePaiement;
use App\Models\Raison;
use App\Models\Representant;
use App\Models\StatutPersonnalise;
use App\Models\Territoire;
use App\Models\Transporteur;
use App\Models\TypeLocation;
use App\Models\Unite;
use App\Models\UniteConversion;
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
                'suppression' => fn (Model $unite) => UniteConversion::where(
                    fn ($q) => $q->where('unite_source_id', $unite->getKey())->orWhere('unite_cible_id', $unite->getKey())
                )->exists() ? 'Cette unité est utilisée dans une conversion.' : null,
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
                    ->exists() ? ['unite_cible_id' => 'Cette conversion existe déjà.'] : null,
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
                    ? 'Cette catégorie contient des sous-catégories.' : null,
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
                    ? 'Cette caisse a déjà servi : désactivez-la plutôt que de la supprimer.' : null,
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
                    ? 'Ce territoire est utilisé par un itinéraire ou un représentant.' : null,
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
                ],
                'ordre' => ['nom', 'asc'],
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
                // Le code est unique par type de raison : le même code peut servir à deux types.
                'controle' => fn (array $d, ?Model $fiche) => Raison::where('type', $d['type'])
                    ->where('code', $d['code'])
                    ->when($fiche, fn ($q) => $q->whereKeyNot($fiche->getKey()))
                    ->exists() ? ['code' => 'Ce code existe déjà pour ce type de raison.'] : null,
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
                    ->exists() ? ['code' => 'Ce code existe déjà pour ce type de document.'] : null,
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
                    return ['parent_id' => 'Une catégorie ne peut pas être placée sous elle-même ou sous une de ses sous-catégories.'];
                }
                $vus[$ancetre] = true;
            }
        }

        $homonyme = Categorie::where('nom', $d['nom'])
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_id'), fn ($q) => $q->where('parent_id', $parentId))
            ->when($fiche, fn ($q) => $q->whereKeyNot($fiche->getKey()))
            ->exists();

        return $homonyme ? ['nom' => 'Une catégorie porte déjà ce nom à cet endroit.'] : null;
    }
}
