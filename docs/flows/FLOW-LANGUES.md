# Flow — Langues (français, anglais)

> Plan de parité fripv2, phase 6.8 (« Traduction complète ») : les messages de l'API (6.8a, cadre ; 6.8b, messages de
> l'application).

## Écrans

Le back-office et l'espace client sont en français ou en anglais :

- **Back-office** : la langue se choisit dans l'en-tête (FR / EN) et se garde dans le navigateur (`prise_language`).
- **Espace client** : la langue choisie (`espace_client_langue`), sinon celle du navigateur.
- Les textes sont dans `src/i18n/translations.ts` de chaque application.

## Messages de l'API

Les messages de l'API (erreurs de validation…) suivent la langue de l'écran :

- **Choix de la langue** : chaque écran envoie l'en-tête `Accept-Language` avec sa langue (`fr` ou `en`). Ce n'est pas
  celle du navigateur, qui peut être en anglais pour un usager qui a choisi le français. Le middleware `DefinirLangue`
  prend la langue préférée de l'en-tête parmi celles prises en charge, sinon le français.
- **Messages du cadre en français** :
  - `lang/fr/validation.php`, avec les noms lisibles des champs courants : « client » pour `client_id`,
    « destinataire » pour `destinataires.*`… ;
  - `lang/fr/auth.php`, `passwords.php`, `pagination.php` ;
  - `lang/fr.json`, pour le résumé « (et 1 autre erreur) ».

  L'anglais vient du cadre.
- **Messages de l'application** (contrôleurs, services, droits, verrous : « Indiquez au moins un destinataire. »…) :
  - le texte français est la source, passé à `__()` ; l'anglais est dans `lang/en.json` (935 messages) ;
  - les données variables sont des paramètres nommés : `__('Permission inconnue : :code.', ['code' => $code])` ;
  - le refus d'un droit nomme le module et l'action dans la langue de l'écran (libellés de `App\Support\Permissions`) ;
  - restent en français, parce que ce sont des données et non des messages : les textes enregistrés (notifications,
    remarques, libellés d'écritures et de mouvements), les courriels, les PDF et les fichiers CSV.
- **Hors requête** (tâches planifiées, files d'attente, commandes) : le français, langue de l'application
  (`DefinirLangue::PAR_DEFAUT`), quel que soit `APP_LOCALE`.
- **Ajouter un message** : l'écrire en français dans `__()`, et sa traduction dans `lang/en.json`. Le test
  `tests/Unit/TraductionsAnglaisesTest` échoue si un message n'a pas de traduction, ou si ses paramètres diffèrent.
  Les tests parlent français par défaut (en-tête `Accept-Language: fr` de `tests/TestCase`).

Exemple, un champ obligatoire manquant :

| En-tête | Message |
|---|---|
| `Accept-Language: fr` (ou absent, ou langue non prise en charge) | Le champ nom est obligatoire. |
| `Accept-Language: en` | The nom field is required. |

Un message de l'application, une fiche en cours de modification :

| En-tête | Message |
|---|---|
| `Accept-Language: fr` | Cette fiche est en cours de modification par Paul Vendeur : réessayez quand la modification sera terminée. |
| `Accept-Language: en` | This record is being edited by Paul Vendeur: try again once the editing is finished. |

## Données traduisibles

- **Fiches produits** : description et description longue par langue (phase 2.4). L'espace client montre celles de
  la langue du visiteur.
- **Rapports de la bibliothèque** : titres, paramètres, colonnes et libellés en français et en anglais.
- **Modèles de courriel** : un modèle par type et par langue.

Code : `App\Http\Middleware\DefinirLangue` (groupe `api` de `bootstrap/app.php`), `lang/fr/*.php`, `lang/fr.json` ;
web `services/http.ts` (en-tête `Accept-Language`) ; espace client `espace/api.ts` ; test
`tests/Feature/Securite/LangueMessagesTest.php`.
