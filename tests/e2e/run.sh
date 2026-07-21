#!/usr/bin/env bash
#
# Suite de tests bout-en-bout : lance le vrai web/nginx.conf (via nginx) et
# le vrai admin/admin.php (via le serveur intégré PHP), puis exécute des
# requêtes HTTP réelles (curl) pour vérifier le comportement décrit en
# section 5 de la spec. Nécessite : nginx, php-cli (ext zip/fileinfo), curl,
# et l'écriture sur /data (le chemin est en dur dans web/nginx.conf).
#
# Usage : sudo mkdir -p /data && sudo chown "$USER" /data && tests/e2e/run.sh

set -u

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# web/nginx.conf a "listen 80;" en dur (fichier testé sans modification) :
# démarrer nginx nécessite donc les privilèges pour se lier au port 80.
WEB_PORT=80
ADMIN_PORT=18090
ADMIN_PASSWORD="e2e-test-password"

PASS_COUNT=0
FAIL_COUNT=0

pass() { PASS_COUNT=$((PASS_COUNT + 1)); echo "  OK   $1"; }
fail() { FAIL_COUNT=$((FAIL_COUNT + 1)); echo "  FAIL $1"; }

assert_status() {
    local description=$1 expected=$2 actual=$3
    if [ "$expected" = "$actual" ]; then
        pass "$description (HTTP $actual)"
    else
        fail "$description (attendu $expected, obtenu $actual)"
    fi
}

assert_contains() {
    local description=$1 haystack=$2 needle=$3
    if grep -qF "$needle" <<<"$haystack"; then
        pass "$description"
    else
        fail "$description (motif absent : $needle)"
    fi
}

assert_not_contains() {
    local description=$1 haystack=$2 needle=$3
    if grep -qF "$needle" <<<"$haystack"; then
        fail "$description (motif inattendu présent : $needle)"
    else
        pass "$description"
    fi
}

cleanup() {
    [ -n "${NGINX_STARTED:-}" ] && sudo -n /usr/sbin/nginx -s stop -c "$NGINX_CONF" >/dev/null 2>&1
    [ -n "${ADMIN_PID:-}" ] && kill "$ADMIN_PID" >/dev/null 2>&1
    rm -rf "$WORK_DIR"
}
trap cleanup EXIT

if [ ! -w /data ] 2>/dev/null && ! mkdir -p /data 2>/dev/null; then
    echo "Ce test nécessite l'écriture sur /data (chemin en dur dans web/nginx.conf)." >&2
    echo "Exécuter : sudo mkdir -p /data && sudo chown \"\$USER\" /data" >&2
    exit 1
fi

WORK_DIR=$(mktemp -d)
rm -rf /data/apps /data/history /data/index.html /data/style.css
mkdir -p /data/apps /data/history "$WORK_DIR/logs" "$WORK_DIR/run"

echo "== Préparation des données de test =="
mkdir -p /data/apps/exemple
cp "$REPO_ROOT/data/apps/exemple/index.html" "$REPO_ROOT/data/apps/exemple/meta.json" /data/apps/exemple/
mkdir -p /data/apps/sans-meta
echo '<html><body>sans meta</body></html>' > /data/apps/sans-meta/index.html
mkdir -p /data/apps/malicious
printf '<?php echo "EXECUTED " . (1+1); ?>' > /data/apps/malicious/shell.php
echo '<html>malicious</html>' > /data/apps/malicious/index.html
export PORTAL_ASSETS_SEED_DIR="$REPO_ROOT/portal"
php -r 'require $argv[1]; regenerate_portal_menu();' "$REPO_ROOT/admin/includes/apps.php"

echo "== Démarrage de nginx (web/nginx.conf réel) =="
NGINX_CONF="$WORK_DIR/nginx.conf"
cat > "$NGINX_CONF" <<EOF
worker_processes 1;
pid $WORK_DIR/run/nginx.pid;
error_log $WORK_DIR/logs/error.log;
events { worker_connections 64; }
http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    access_log $WORK_DIR/logs/access.log;
    client_body_temp_path $WORK_DIR/run/client_body;
    proxy_temp_path $WORK_DIR/run/proxy;
    fastcgi_temp_path $WORK_DIR/run/fastcgi;
    uwsgi_temp_path $WORK_DIR/run/uwsgi;
    scgi_temp_path $WORK_DIR/run/scgi;
    # Fichier réel du dépôt, non modifié (contient déjà "listen 80;").
    include $REPO_ROOT/web/nginx.conf;
}
EOF
if ! sudo -n /usr/sbin/nginx -t -c "$NGINX_CONF" >"$WORK_DIR/nginx-test.log" 2>&1; then
    cat "$WORK_DIR/nginx-test.log" >&2
    exit 1
fi
sudo -n /usr/sbin/nginx -c "$NGINX_CONF"
NGINX_STARTED=1
sleep 0.5

echo "== Démarrage du serveur admin (admin/admin.php réel) =="
ADMIN_PASSWORD_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$ADMIN_PASSWORD")
export ADMIN_PASSWORD_HASH
export SESSION_SECRET="e2e-session-secret"
export MAX_UPLOAD_SIZE_MB=50
export ADMIN_STATE_DIR="$WORK_DIR/admin-state"
php -d session.save_path="$WORK_DIR" -S "127.0.0.1:$ADMIN_PORT" -t "$REPO_ROOT/admin" \
    > "$WORK_DIR/admin.log" 2>&1 &
ADMIN_PID=$!
sleep 0.5

WEB="http://127.0.0.1:$WEB_PORT"
ADMIN="http://127.0.0.1:$ADMIN_PORT"
COOKIES="$WORK_DIR/cookies.txt"

echo
echo "=== Menu public (web) ==="

body=$(curl -s "$WEB/")
status=$(curl -s -o /dev/null -w '%{http_code}' "$WEB/")
assert_status "menu public accessible" 200 "$status"
assert_contains "app avec meta.json affiche son titre" "$body" "Exemple"
assert_contains "app avec meta.json affiche sa description" "$body" "démonstration"
assert_contains "app sans meta.json affiche le nom du dossier" "$body" "sans-meta"
pos_exemple=$(grep -bo 'href="/apps/exemple' <<<"$body" | head -1 | cut -d: -f1)
pos_sansmeta=$(grep -bo 'href="/apps/sans-meta' <<<"$body" | head -1 | cut -d: -f1)
pos_malicious=$(grep -bo 'href="/apps/malicious' <<<"$body" | head -1 | cut -d: -f1)
if [ "$pos_exemple" -lt "$pos_malicious" ] && [ "$pos_malicious" -lt "$pos_sansmeta" ]; then
    pass "tri alphabétique respecté (exemple, malicious, sans-meta)"
else
    fail "tri alphabétique incorrect"
fi

app_body=$(curl -s "$WEB/apps/exemple/")
assert_contains "app accessible directement via /apps/<dossier>/" "$app_body" "Exemple"

php_status=$(curl -s -o /tmp/e2e-php-response.txt -w '%{http_code}' "$WEB/apps/malicious/shell.php")
php_body=$(cat /tmp/e2e-php-response.txt)
if [ "$php_status" != "200" ]; then
    pass "fichier .php inaccessible (HTTP $php_status)"
else
    fail "fichier .php accessible (HTTP $php_status)"
fi
assert_not_contains "fichier .php jamais interprété (pas de sortie évaluée)" "$php_body" "EXECUTED 2"

headers=$(curl -sI "$WEB/")
assert_contains "en-tête X-Content-Type-Options présent (web)" "$headers" "X-Content-Type-Options"
assert_contains "en-tête X-Frame-Options présent (web)" "$headers" "X-Frame-Options"
assert_contains "en-tête Content-Security-Policy présent (web)" "$headers" "Content-Security-Policy"

history_status=$(curl -s -o /dev/null -w '%{http_code}' "$WEB/history/")
assert_status "historique accessible" 200 "$history_status"

echo
echo "=== Authentification admin ==="

admin_headers=$(curl -sI "$ADMIN/admin.php")
assert_contains "en-tête X-Content-Type-Options présent (admin)" "$admin_headers" "X-Content-Type-Options"

unauth_status=$(curl -s -o /dev/null -w '%{http_code}' -c "$COOKIES" -b "$COOKIES" "$ADMIN/admin.php")
assert_status "accès sans session redirige vers le login" 302 "$unauth_status"

curl -s -c "$COOKIES" -b "$COOKIES" "$ADMIN/admin.php?action=login" -o "$WORK_DIR/login.html"
CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' "$WORK_DIR/login.html" | head -1)

wrong_body=$(curl -s -c "$COOKIES" -b "$COOKIES" -X POST "$ADMIN/admin.php?action=login" \
    --data-urlencode "csrf_token=$CSRF" --data-urlencode "password=wrong-password")
assert_contains "mauvais mot de passe refusé" "$wrong_body" "incorrect"

login_status=$(curl -s -o /dev/null -w '%{http_code}' -c "$COOKIES" -b "$COOKIES" -X POST "$ADMIN/admin.php?action=login" \
    --data-urlencode "csrf_token=$CSRF" --data-urlencode "password=$ADMIN_PASSWORD")
assert_status "bon mot de passe accepté" 302 "$login_status"

dash_status=$(curl -s -o "$WORK_DIR/dash.html" -w '%{http_code}' -c "$COOKIES" -b "$COOKIES" "$ADMIN/admin.php")
assert_status "session persistante après connexion" 200 "$dash_status"
DASH_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' "$WORK_DIR/dash.html" | head -1)

echo
echo "=== Rate limiting login ==="
# Utilise un cookie jar dédié : le rate limiting est par IP (toutes les
# requêtes du script partagent 127.0.0.1), donc déclencher le blocage ici
# ne doit pas affecter la session déjà authentifiée utilisée plus loin.
LOCK_COOKIES="$WORK_DIR/cookies-lockout.txt"
curl -s -c "$LOCK_COOKIES" -b "$LOCK_COOKIES" "$ADMIN/admin.php?action=login" -o "$WORK_DIR/login2.html"
LOCK_CSRF=$(grep -oP 'name="csrf_token" value="\K[^"]+' "$WORK_DIR/login2.html" | head -1)
for i in 1 2 3 4 5; do
    curl -s -c "$LOCK_COOKIES" -b "$LOCK_COOKIES" -X POST "$ADMIN/admin.php?action=login" \
        --data-urlencode "csrf_token=$LOCK_CSRF" --data-urlencode "password=still-wrong-$i" -o /dev/null
done
locked_body=$(curl -s -c "$LOCK_COOKIES" -b "$LOCK_COOKIES" -X POST "$ADMIN/admin.php?action=login" \
    --data-urlencode "csrf_token=$LOCK_CSRF" --data-urlencode "password=$ADMIN_PASSWORD")
assert_contains "blocage actif après 5 échecs (même avec le bon mot de passe)" "$locked_body" "Trop de tentatives"

echo
echo "=== CSRF ==="
csrf_reject_status=$(curl -s -o /dev/null -w '%{http_code}' -c "$COOKIES" -b "$COOKIES" -X POST "$ADMIN/admin.php?action=regenerate" \
    --data-urlencode "csrf_token=invalid-token")
assert_status "régénération sans CSRF valide rejetée" 400 "$csrf_reject_status"

echo
echo "=== Upload ==="
GOOD_ZIP="$WORK_DIR/good.zip"
php -r '
$zip = new ZipArchive();
$zip->open($argv[1], ZipArchive::CREATE);
$zip->addFromString("index.html", "<html>e2e upload</html>");
$zip->close();
' "$GOOD_ZIP"

upload_body=$(curl -s -c "$COOKIES" -b "$COOKIES" -X POST "$ADMIN/admin.php?action=upload" \
    -F "csrf_token=$DASH_CSRF" -F "slug=e2e-app" -F "app_zip=@$GOOD_ZIP;type=application/zip")
sleep 0.2
web_after_upload=$(curl -s "$WEB/")
assert_contains "app publiée visible dans le menu régénéré" "$web_after_upload" "e2e-app"

app_content=$(curl -s "$WEB/apps/e2e-app/")
assert_contains "contenu de l'app uploadée accessible" "$app_content" "e2e upload"

INVALID_SLUG_STATUS=$(curl -s -o "$WORK_DIR/invalid-slug.html" -w '%{http_code}' -c "$COOKIES" -b "$COOKIES" -X POST "$ADMIN/admin.php?action=upload" \
    -F "csrf_token=$DASH_CSRF" -F "slug=Invalid Slug" -F "app_zip=@$GOOD_ZIP;type=application/zip")
assert_status "nom de dossier invalide rejeté (redirection)" 302 "$INVALID_SLUG_STATUS"

NOINDEX_ZIP="$WORK_DIR/noindex.zip"
php -r '
$zip = new ZipArchive();
$zip->open($argv[1], ZipArchive::CREATE);
$zip->addFromString("readme.txt", "no index here");
$zip->close();
' "$NOINDEX_ZIP"
curl -s -c "$COOKIES" -b "$COOKIES" -X POST "$ADMIN/admin.php?action=upload" \
    -F "csrf_token=$DASH_CSRF" -F "slug=noindex-app" -F "app_zip=@$NOINDEX_ZIP;type=application/zip" -o /dev/null
noindex_check=$(curl -s -o /dev/null -w '%{http_code}' "$WEB/apps/noindex-app/")
assert_status "zip sans index.html n'est jamais publié" 404 "$noindex_check"

echo
echo "=== Suppression ==="
delete_no_confirm=$(curl -s -c "$COOKIES" -b "$COOKIES" -X POST "$ADMIN/admin.php?action=delete" \
    -F "csrf_token=$DASH_CSRF" -F "slug=e2e-app")
still_there=$(curl -s -o /dev/null -w '%{http_code}' "$WEB/apps/e2e-app/")
assert_status "suppression sans confirmation ne fait rien" 200 "$still_there"

curl -s -c "$COOKIES" -b "$COOKIES" -X POST "$ADMIN/admin.php?action=delete" \
    -F "csrf_token=$DASH_CSRF" -F "slug=e2e-app" -F "confirm=1" -o /dev/null
sleep 0.2
gone_status=$(curl -s -o /dev/null -w '%{http_code}' "$WEB/apps/e2e-app/")
assert_status "app supprimée n'est plus accessible" 404 "$gone_status"

echo
echo "======================================"
echo "Résultats : $PASS_COUNT réussis, $FAIL_COUNT échoués"
[ "$FAIL_COUNT" -eq 0 ]
