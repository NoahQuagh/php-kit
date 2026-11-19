# PHP Kit Starter Class

Modèle de départ léger en PHP natif fournissant une classe `Database` basée autour de **PDO**, avec chargement automatique des variables d'environnement depuis un fichier `.env`,
plus un modèle de départ de la classe `SessionManager` pour la gestion des sessions PHP et de `CookieManager` pour la gestion des cookies PHP.

## Structure du modèle

```text
├── db/
│   └── Database.php          # Classe Singleton de connexion PDO + parseur .env intégré
├── core/
│   ├── SessionManager.php    # Classe Singleton de gestion de session PHP
│   └── CookieManager.php     # Classe Singleton de gestion des cookies PHP
├── .env.example              # Modèle de configuration des identifiants SQL
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