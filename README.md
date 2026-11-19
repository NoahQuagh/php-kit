# PHP Kit Starter Class

Modèle de départ léger en PHP natif fournissant une classe `Database` basée autour de **PDO**, avec chargement automatique des variables d'environnement depuis un fichier `.env`,
plus un modèle de départ de la classe `SessionManager` pour la gestion des sessions PHP et de `CookieManager` pour la gestion des cookies PHP.
Le kit inclut aussi une architecture **MRC** (Model – Resource – Controller) avec un routeur, pour construire une API JSON (voir [Architecture MRC](#architecture-mrc-model--resource--controller)).

## Structure du modèle

```text
├── index.php                 # Point d'entrée : déclaration des routes
├── autoload.php              # Chargement automatique des classes
├── .env.example              # Modèle de configuration des identifiants SQL
├── db/
│   └── Database.php          # Classe Singleton de connexion PDO + parseur .env intégré
├── core/
│   ├── Router.php            # Routeur HTTP (routes, paramètres dynamiques)
│   ├── Controller.php        # Classe de base des contrôleurs
│   ├── Model.php             # Classe de base des modèles (accès à la base)
│   ├── Resource.php          # Classe de base des ressources (formatage JSON)
│   ├── SessionManager.php    # Classe Singleton de gestion de session PHP
│   └── CookieManager.php     # Classe Singleton de gestion des cookies PHP
├── controllers/
│   └── WelcomeController.php # Exemple de contrôleur
├── models/
│   └── Welcome.php           # Exemple de modèle
└── resources/
    └── WelcomeResource.php   # Exemple de ressource
```

## Démarrage rapide

1. Copier `.env.example` en `.env` à la racine du projet (au même niveau que `db/` et `core/`).
2. Renseigner les identifiants de la base de données (voir [Configuration](#configuration)).
3. Inclure les classes (via `require` ou un autoloader) :

```php
require_once 'db/Database.php';
require_once 'core/SessionManager.php';
require_once 'core/CookieManager.php';

use core\SessionManager;
use core\CookieManager;
```

> Avec l'architecture MRC, `autoload.php` charge automatiquement les classes : les `require_once` ne sont alors plus nécessaires.

> Les trois classes sont des **Singletons** : on ne les instancie jamais avec `new`, on passe toujours par `getInstance()`. Le clonage est interdit (une `Exception` est levée).

---

## Configuration

`Database.php` lit automatiquement le fichier `.env` situé dans le dossier parent (`db/../.env`) et charge chaque ligne `CLE=valeur` dans `$_ENV` et `putenv()`.

```ini
# .env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=ma_base
DB_CHARSET=utf8mb4
DB_USER=root
DB_PASS=secret
```

| Variable     | Rôle                                   |
|--------------|----------------------------------------|
| `DB_HOST`    | Hôte du serveur MySQL                  |
| `DB_PORT`    | Port MySQL (généralement `3306`)       |
| `DB_NAME`    | Nom de la base de données              |
| `DB_CHARSET` | Jeu de caractères (ex. `utf8mb4`)      |
| `DB_USER`    | Utilisateur MySQL                      |
| `DB_PASS`    | Mot de passe MySQL                     |

Règles du parseur :
- Les lignes commençant par `#` sont ignorées (commentaires) ainsi que les lignes vides.
- Les guillemets simples ou doubles autour des valeurs sont retirés (`DB_PASS="abc"` → `abc`).
- Chaque ligne non commentée doit contenir un `=`.
- Ne jamais versionner le fichier `.env` (l'ajouter au `.gitignore`).

---

## Database

Connexion MySQL via PDO, créée une seule fois puis réutilisée partout.

### Obtenir la connexion

```php
$db = Database::getInstance();
```

### Utiliser les méthodes PDO

Toute méthode de PDO est accessible directement sur l'instance (via `__call`) : `prepare`, `query`, `exec`, `lastInsertId`, `beginTransaction`, `commit`, `rollBack`…

```php
// Lecture avec paramètres nommés
$stmt = $db->prepare('SELECT * FROM users WHERE email = :email');
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();          // tableau associatif ou false

// Plusieurs lignes
$users = $db->query('SELECT id, nom FROM users')->fetchAll();

// Insertion + récupération de l'ID
$stmt = $db->prepare('INSERT INTO users (nom) VALUES (?)');
$stmt->execute([$nom]);
$id = $db->lastInsertId();
```

### Transactions

#### `transaction(callable $callback)` (recommandé si plusieurs requêtes dépendantes)

Méthode propre à la classe qui exécute plusieurs opérations de façon **atomique** : la transaction est ouverte, validée automatiquement si tout se passe bien, et **annulée** (rollback) si une erreur survient. L'exception est ensuite relancée pour pouvoir être gérée à l'extérieur.

| Élément      | Description                                                              |
|--------------|--------------------------------------------------------------------------|
| `$callback`  | Fonction qui reçoit l'instance `Database` en paramètre                   |
| Retour       | La valeur retournée par le callback                                      |
| Exception    | Toute `Throwable` est relancée après le rollback                         |

Exemple simple : un virement (les deux requêtes réussissent ensemble, ou aucune n'est appliquée).

```php
$db->transaction(function ($db) {
    $db->prepare('UPDATE comptes SET solde = solde - ? WHERE id = ?')->execute([100, 1]);
    $db->prepare('UPDATE comptes SET solde = solde + ? WHERE id = ?')->execute([100, 2]);
});
```

Récupérer une valeur de retour et utiliser des variables externes (mot-clé `use`) :

```php
$orderId = $db->transaction(function ($db) use ($userId, $total) {
    $db->prepare('INSERT INTO commandes (user_id, total) VALUES (?, ?)')->execute([$userId, $total]);
    $id = $db->lastInsertId();

    $db->prepare('INSERT INTO historique (commande_id) VALUES (?)')->execute([$id]);

    return $id;   // renvoyé par transaction()
});
```

Annuler volontairement la transaction : il suffit de lever une exception dans le callback.

```php
try {
    $db->transaction(function ($db) {
        // ... requêtes ...
        if ($solde < 0) {
            throw new Exception('Solde insuffisant');   // -> rollback automatique
        }
    });
} catch (Throwable $e) {
    // le rollback a déjà été effectué
    echo $e->getMessage();
}
```

> Bon à savoir :
> - Les transactions **imbriquées** ne sont pas supportées par PDO : ne pas appeler `transaction()` (ni `beginTransaction()`) à l'intérieur d'un callback.
> - Avec MySQL, les requêtes de structure (`CREATE`, `ALTER`, `DROP`…) provoquent un commit implicite et ne peuvent pas être annulées.

#### Alternative : transaction manuelle

Pour garder le contrôle complet, les méthodes PDO restent utilisables :

```php
try {
    $db->beginTransaction();

    // ... requêtes ...

    $db->commit();
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    throw $e; // ou gestion de l'erreur
}
```

### Options PDO appliquées par défaut

| Option                         | Valeur             | Effet                                                  |
|--------------------------------|--------------------|--------------------------------------------------------|
| `ATTR_ERRMODE`                 | `ERRMODE_EXCEPTION`| Les erreurs SQL lèvent une `PDOException`              |
| `ATTR_DEFAULT_FETCH_MODE`      | `FETCH_ASSOC`      | Les résultats sont des tableaux associatifs            |
| `ATTR_EMULATE_PREPARES`        | `false`            | Vraies requêtes préparées (meilleure protection SQLi)  |

> Utiliser **toujours** `prepare()` + `execute()` avec des paramètres plutôt que de concaténer des variables dans la requête.

> Depuis un fichier utilisant un `namespace`, référencer la classe avec un antislash : `\Database::getInstance()`.

---

## SessionManager

Gère la session PHP. Elle est démarrée automatiquement au premier `getInstance()` avec ces paramètres de cookie : durée `0` (jusqu'à la fermeture du navigateur), chemin `/`, `httponly` activé, `samesite=Lax`.

```php
$session = SessionManager::getInstance();
```

### Méthodes

| Méthode                        | Description                                                        |
|--------------------------------|--------------------------------------------------------------------|
| `set($key, $value)`            | Écrit ou met à jour une valeur                                     |
| `setMultiple(array $data)`     | Écrit plusieurs valeurs d'un coup                                  |
| `get($key, $default = null)`   | Lit une valeur (ou `$default` si absente)                          |
| `has($key)`                    | `true` si la clé existe                                            |
| `remove($key)`                 | Supprime une clé                                                   |
| `all()`                        | Retourne tout le contenu de la session                             |
| `regenerate()`                 | Régénère l'ID de session                                           |
| `flash($key, $value = null)`   | Message éphémère (écriture ou lecture unique)                      |
| `destroy()`                    | Vide et détruit la session, supprime le cookie de session          |

### Lecture / écriture

```php
$session->set('user_id', 42);
$session->setMultiple(['nom' => 'Toto', 'lang' => 'fr']);

$lang = $session->get('lang', 'fr');   // 'fr' par défaut si absent

if ($session->has('user_id')) { /* utilisateur connecté */ }
```

### Syntaxe « propriété » (méthodes magiques)

Équivalent à `set` / `get` / `has` / `remove` :

```php
$session->nom = 'Toto';        // set
echo $session->nom;            // get (null si absent)
isset($session->nom);          // has
unset($session->nom);          // remove
```

### Messages flash

Un message flash est lu **une seule fois**, puis supprimé automatiquement.

```php
// Page A : écriture (valeur non nulle)
$session->flash('success', 'Profil mis à jour !');
header('Location: /profil');

// Page B : lecture (sans 2e argument) -> le message est supprimé
$msg = $session->flash('success');
```

### Connexion et déconnexion

Régénérer l'ID après une connexion réussie protège contre la fixation de session :

```php
$session->regenerate();
$session->set('user_id', $user['id']);
```

```php
$session->destroy();   // déconnexion complète
```

---

## CookieManager

Gère les cookies avec encodage JSON automatique pour les tableaux et objets. Tous les cookies créés utilisent `path=/` et `samesite=Lax`.

```php
$cookies = CookieManager::getInstance();
```

### Méthodes

| Méthode                                              | Description                                           |
|------------------------------------------------------|-------------------------------------------------------|
| `set($name, $value, $days = 30, $httpOnly = true)`   | Crée ou met à jour un cookie                          |
| `get($name, $default = null)`                        | Lit un cookie (décode le JSON si tableau)             |
| `has($name)`                                         | `true` si le cookie existe                            |
| `remove($name)`                                      | Supprime le cookie                                    |
| `all()`                                              | Retourne tous les cookies                             |

### Options de `set()`

| Paramètre   | Défaut | Description                                                                  |
|-------------|--------|------------------------------------------------------------------------------|
| `$value`    | —      | Chaîne, nombre, tableau ou objet (tableau/objet encodé en JSON)              |
| `$days`     | `30`   | Durée de vie en jours                                                        |
| `$httpOnly` | `true` | `true` = inaccessible en JavaScript (recommandé) ; `false` = lisible en JS   |

```php
// Valeur simple, 30 jours, httpOnly
$cookies->set('theme', 'dark');

// Durée personnalisée : 7 jours
$cookies->set('visited', '1', 7);

// Tableau (encodé en JSON), lisible en JS
$cookies->set('prefs', ['lang' => 'fr', 'notif' => true], 90, false);
```

### Lecture

```php
$theme = $cookies->get('theme', 'light');   // 'light' si absent
$prefs = $cookies->get('prefs');            // ['lang' => 'fr', 'notif' => true]

if ($cookies->has('theme')) { /* ... */ }
```

### Suppression

```php
$cookies->remove('theme');
```

### Bon à savoir

- `set()` et `remove()` mettent aussi à jour `$_COOKIE` : la valeur est lisible **immédiatement**, sans attendre la requête suivante.
- Comme pour `setcookie()`, `set()` et `remove()` doivent être appelés **avant** toute sortie HTML (sinon erreur « headers already sent »).
- Un objet est restitué sous forme de **tableau associatif** par `get()`. Une valeur JSON scalaire (ex. `"123"`) est renvoyée telle quelle, en chaîne.
- Le flag `Secure` n'est pas défini : en production sous HTTPS, pensez à l'ajouter dans `setcookie()`.
- Ne jamais stocker de donnée sensible (mot de passe, jeton privé) dans un cookie : utilisez la session.

---

## Architecture MRC (Model – Resource – Controller)

Le kit propose une architecture légère pour construire une **API JSON** en séparant les responsabilités. Le trajet d'une requête :

```text
Requête HTTP → index.php → Router → Controller → Model → Resource → Réponse JSON
```

| Couche       | Dossier        | Namespace      | Rôle                                                        |
|--------------|----------------|----------------|-------------------------------------------------------------|
| `Controller` | `controllers/` | `controllers`  | Reçoit la requête et orchestre le travail                   |
| `Model`      | `models/`      | `models`       | Accède aux données (base de données)                        |
| `Resource`   | `resources/`   | `resources`    | Formate les données brutes et envoie la réponse JSON        |

Les classes de base à étendre (`Controller`, `Model`, `Resource`) ainsi que le `Router` se trouvent dans `core/`.

### Point d'entrée et autoload

`index.php` charge l'autoload, déclare les routes puis lance le routeur :

```php
require_once __DIR__ . '/autoload.php';

use core\Router;

$router = new Router('php-kit');   // préfixe d'URL à ignorer (optionnel)

$router->addRoute('', 'controllers\WelcomeController@show', 'GET');

$router->route(trim($_SERVER['REQUEST_URI'], '/'));
```

`autoload.php` charge les classes automatiquement, il n'y a donc **aucun `require` à écrire** :

- Une classe avec namespace est cherchée au chemin correspondant : `models\Welcome` → `models/Welcome.php`.
- Une classe sans namespace (ex. `Database`) est cherchée dans `core/`, `db/`, `controllers/`, `models/` puis `resources/`.

> Convention à respecter : **namespace = nom du dossier** et **nom du fichier = nom de la classe**.

---

### Router

| Méthode                                       | Description                                                            |
|-----------------------------------------------|------------------------------------------------------------------------|
| `new Router($prefix = '')`                    | Crée le routeur, avec un préfixe d'URL optionnel                       |
| `addRoute($uri, $action, $httpMethod = 'ANY')`| Déclare une route                                                      |
| `route($url, $requestMethod = null)`          | Trouve la route correspondante et exécute l'action                     |

```php
$router->addRoute('users',      'controllers\UserController@index', 'GET');
$router->addRoute('users/{id}', 'controllers\UserController@show',  'GET');
$router->addRoute('users',      'controllers\UserController@store', 'POST');
```

Règles de fonctionnement :
- **Action** : au format `Namespace\Controller@methode`, avec le nom de classe complet.
- **Paramètres dynamiques** : `{id}` capture un segment d'URL (sans `/`). Les valeurs sont passées dans l'ordre à la méthode du contrôleur, sous forme de **chaînes** : `/users/5` appelle `show('5')`.
- **Méthodes HTTP** : `GET`, `POST`, `PUT`, `DELETE` ou `ANY` (par défaut, accepte toutes les méthodes).
- **Préfixe** : avec `new Router('php-kit')`, l'URL `/php-kit/users` est résolue comme `users`. Utile quand le projet est dans un sous-dossier.
- **Ordre** : les routes sont testées dans l'ordre de déclaration, la première qui correspond est utilisée.
- **Query string** : `?page=2` est ignorée par le routeur et reste lisible via `$_GET`.
- **Route introuvable** : réponse `404` en **texte brut** (`404 - Page non trouvée`).
- **Erreurs de configuration** : une `Exception` est levée si l'action ne contient pas de `@`, ou si le contrôleur ou la méthode n'existe pas.

---

### Controller

Un contrôleur étend `core\Controller` et expose une méthode par action.

```php
namespace controllers;

use core\Controller;

class UserController extends Controller
{
    public function show($id) { /* ... */ }
}
```

Ce que la classe de base fournit :

| Élément          | Description                                                                 |
|------------------|-----------------------------------------------------------------------------|
| `$this->session` | Instance de `SessionManager`                                                |
| `$this->cookies` | Instance de `CookieManager`                                                 |
| `getInput()`     | Retourne les données envoyées par le client : corps JSON (ex. `fetch`), sinon `$_POST` |

```php
$data = $this->getInput();                 // ['nom' => 'Toto', ...]
$userId = $this->session->get('user_id');
```

> Le routeur instancie le contrôleur sans argument. Si tu définis un constructeur, appelle `parent::__construct()` pour conserver `$this->session` et `$this->cookies`.

---

### Model

Un modèle étend `core\Model` et regroupe les requêtes d'une table ou d'un domaine. Il retourne des **tableaux bruts**, avec les noms de colonnes de la base.

La classe de base fournit `$this->db`, l'instance de [`Database`](#database) : toutes les méthodes PDO et `transaction()` y sont disponibles.

```php
$stmt = $this->db->prepare('SELECT * FROM users WHERE user_id = ?');
$stmt->execute([$id]);
return $stmt->fetch();
```

---

### Resource

Une ressource étend `core\Resource` et transforme les données brutes en format public de l'API : renommage des clés, filtrage des champs sensibles (ex. mot de passe). Le client ne voit ainsi jamais la structure de la base.

Le tableau brut reçu par le constructeur est disponible dans `$this->resource`. Il faut définir `toArray()` :

```php
public function toArray(): array
{
    return [
        'message' => $this->resource['pk_msg'],
        'version' => $this->resource['pk_app_ver'],
    ];
}
```

| Méthode                                       | Description                                                                       |
|-----------------------------------------------|-----------------------------------------------------------------------------------|
| `toArray()`                                   | **À définir** : renommage / filtrage des clés                                     |
| `send($status = 200)`                         | Envoie **un** élément en JSON                                                     |
| `Resource::collection($items, $status = 200)` | Méthode statique : envoie une **liste** (applique `toArray()` à chaque élément)   |
| `Resource::error($message, $status = 400)`    | Méthode statique : envoie un élément en JSON avec un message d'erreur |

```php
(new UserResource($user))->send();         // 200
(new UserResource($user))->send(201);      // 201 Created
UserResource::collection($users);          // tableau d'éléments
```

> `send()` et `collection()` envoient l'en-tête `Content-Type: application/json; charset=utf-8` puis terminent le script avec `exit` : aucun code n'est exécuté après l'envoi, et il n'y a qu'**une seule réponse par requête**.

---

### Exemple fourni : `GET /php-kit/`

Le contrôleur `WelcomeController` récupère les données du modèle `Welcome` et les passe à `WelcomeResource` :

```php
function show()
{
    new WelcomeResource(new Welcome()->getInfo())->send();
}
```

Le modèle retourne `['pk_msg' => ..., 'pk_app_ver' => ...]`, la ressource renomme les clés, et la réponse est :

```json
{ "message": "Bienvenue sur API PHP Kit", "version": "1.0.2" }
```

> La syntaxe `new Welcome()->getInfo()` (sans parenthèses autour du `new`) nécessite **PHP 8.4**. Avant : `(new Welcome())->getInfo()`.

---

### Ajouter un nouvel endpoint (pas à pas)

Exemple : lister les utilisateurs et en afficher un.

**1. Le modèle** : `models/User.php`

```php
namespace models;

use core\Model;

class User extends Model
{
    public function getAll(): array
    {
        return $this->db->query('SELECT user_id, user_nom FROM users')->fetchAll();
    }

    public function getById($id)
    {
        $stmt = $this->db->prepare('SELECT user_id, user_nom FROM users WHERE user_id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
}
```

**2. La ressource** : `resources/UserResource.php`

```php
namespace resources;

use core\Resource;

class UserResource extends Resource
{
    public function toArray(): array
    {
        return [
            'id'  => $this->resource['user_id'],
            'nom' => $this->resource['user_nom'],
        ];
    }
}
```

**3. Le contrôleur** : `controllers/UserController.php`

```php
namespace controllers;

use core\Controller;
use models\User;
use resources\UserResource;

class UserController extends Controller
{
    public function index()
    {
        UserResource::collection((new User())->getAll());
    }

    public function show($id)
    {
        (new UserResource((new User())->getById($id)))->send();
    }
}
```

**4. Les routes** : dans `index.php`

```php
$router->addRoute('users',      'controllers\UserController@index', 'GET');
$router->addRoute('users/{id}', 'controllers\UserController@show',  'GET');
```

Résultat : `GET /php-kit/users` renvoie la liste, `GET /php-kit/users/5` appelle `show('5')`.

### Bon à savoir

- Toutes les requêtes doivent arriver sur `index.php` : le serveur doit rediriger les URL vers ce fichier (réécriture d'URL, par exemple via `.htaccess` sous Apache), sinon seule la racine fonctionne.
- Si `getById()` ne trouve rien, `fetch()` retourne `false` : prévoir ce cas avant de créer la ressource.
- Pour renvoyer une erreur JSON, passer le message et le statut à `Resource::error()` avant de créer la ressource.