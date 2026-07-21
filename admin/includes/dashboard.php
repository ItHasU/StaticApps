<?php

declare(strict_types=1);

function render_dashboard_page(): void
{
    $csrf = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');

    echo <<<HTML
        <!DOCTYPE html>
        <html lang="fr">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>Administration</title>
          <link rel="stylesheet" href="/assets/admin.css">
        </head>
        <body>
          <header class="admin-header">
            <h1>Administration</h1>
            <form method="post" action="/admin.php?action=logout">
              <input type="hidden" name="csrf_token" value="{$csrf}">
              <button type="submit" class="link-button">Se déconnecter</button>
            </form>
          </header>
          <main>
            <p>Connexion réussie. Le tableau de bord complet (upload, liste des apps) arrive à l'étape suivante.</p>
            <form method="post" action="/admin.php?action=regenerate">
              <input type="hidden" name="csrf_token" value="{$csrf}">
              <button type="submit">Régénérer le menu</button>
            </form>
          </main>
        </body>
        </html>

        HTML;
}
