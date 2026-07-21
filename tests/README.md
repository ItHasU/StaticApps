# Tests

Deux suites complémentaires.

## 1. Tests unitaires PHP (logique métier)

Couvrent `admin/includes/*.php` : génération du menu et échappement XSS,
historique, validation des slugs, CSRF, rate limiting, verrouillage après
échecs de connexion, validation de zip (zip slip, zip bomb, MIME/signature,
nombre de fichiers, permissions), upload et suppression complets.

```bash
cd admin
composer install
vendor/bin/phpunit
```

## 2. Tests bout-en-bout (curl, HTTP réel)

Démarre le vrai `web/nginx.conf` (via `nginx`) et le vrai `admin/admin.php`
(via le serveur intégré PHP), puis exécute des requêtes HTTP réelles pour
vérifier le comportement décrit dans la spec : menu public, tri, accès
direct aux apps, non-exécution des `.php`, en-têtes de sécurité,
authentification, rate limiting, CSRF, upload, suppression.

Prérequis : `nginx`, `php-cli` (extensions `zip` et `fileinfo`), `curl`,
et l'écriture sur `/data` (chemin en dur dans `web/nginx.conf`, comme en
production).

```bash
sudo mkdir -p /data && sudo chown "$USER" /data
tests/e2e/run.sh
```

Le script nettoie `/data/apps` et `/data/portal` à chaque exécution : ne
pas le lancer sur une machine où `/data` contient des données réelles.
