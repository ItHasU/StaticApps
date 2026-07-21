<?php

declare(strict_types=1);

require __DIR__ . '/includes/security.php';
require __DIR__ . '/includes/apps.php';

start_secure_session();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
header('Referrer-Policy: no-referrer-when-downgrade');

$action = $_GET['action'] ?? ($_POST['action'] ?? 'dashboard');

switch ($action) {
    case 'login':
        handle_login();
        break;

    case 'logout':
        handle_logout();
        break;

    case 'regenerate':
        require_authentication();
        handle_regenerate();
        break;

    case 'upload':
        require_authentication();
        handle_upload();
        break;

    case 'delete':
        require_authentication();
        handle_delete();
        break;

    default:
        require_authentication();
        render_dashboard();
}

function handle_login(): void
{
    $error = null;
    $identifier = client_identifier();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $remaining = login_lockout_remaining($identifier);

        if ($remaining > 0) {
            $error = "Trop de tentatives échouées. Réessayez dans {$remaining} secondes.";
        } elseif (!csrf_verify($_POST['csrf_token'] ?? null)) {
            $error = 'Requête invalide (jeton de sécurité manquant ou expiré). Réessayez.';
        } else {
            $password = (string) ($_POST['password'] ?? '');
            $hash = getenv('ADMIN_PASSWORD_HASH') ?: '';

            if ($hash !== '' && password_verify($password, $hash)) {
                clear_login_attempts($identifier);
                session_regenerate_id(true);
                $_SESSION['authenticated'] = true;
                audit_log('login_success');
                header('Location: /admin.php');
                exit;
            }

            register_failed_login($identifier);
            audit_log('login_failed');
            $error = 'Mot de passe incorrect.';
        }
    }

    if (is_authenticated()) {
        header('Location: /admin.php');
        exit;
    }

    render_login_page($error);
}

function handle_logout(): void
{
    audit_log('logout');
    $_SESSION = [];
    session_destroy();
    header('Location: /admin.php?action=login');
    exit;
}

function handle_regenerate(): void
{
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        echo 'Jeton CSRF invalide.';
        return;
    }

    regenerate_portal_menu();
    audit_log('menu_regenerated');
    header('Location: /admin.php');
    exit;
}

function handle_upload(): void
{
    require __DIR__ . '/includes/upload.php';
    process_upload_request();
}

function handle_delete(): void
{
    require __DIR__ . '/includes/delete.php';
    process_delete_request();
}

function render_login_page(?string $error): void
{
    $errorHtml = $error !== null
        ? '<p class="form-error">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>'
        : '';
    $csrf = this_csrf();

    echo <<<HTML
        <!DOCTYPE html>
        <html lang="fr">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>Administration — Connexion</title>
          <link rel="stylesheet" href="/assets/admin.css">
        </head>
        <body class="login-body">
          <main class="login-card">
            <h1>Administration</h1>
            {$errorHtml}
            <form method="post" action="/admin.php?action=login">
              <input type="hidden" name="csrf_token" value="{$csrf}">
              <label for="password">Mot de passe</label>
              <input type="password" id="password" name="password" required autofocus>
              <button type="submit">Se connecter</button>
            </form>
          </main>
        </body>
        </html>

        HTML;
}

function this_csrf(): string
{
    return htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
}

function render_dashboard(): void
{
    require __DIR__ . '/includes/dashboard.php';
    render_dashboard_page();
}
