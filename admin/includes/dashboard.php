<?php

declare(strict_types=1);

function render_dashboard_page(): void
{
    $csrf = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');

    $flashHtml = '';
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        $class = !empty($flash['error']) ? 'flash-error' : 'flash-success';
        $flashHtml = '<p class="' . $class . '">' . htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8') . '</p>';
    }

    $apps = scan_apps();
    $appListHtml = render_app_list($apps, $csrf);
    $maxUploadMb = (int) (getenv('MAX_UPLOAD_SIZE_MB') ?: 50);

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
            <h1>Administration du portail</h1>
            <form method="post" action="/admin.php?action=logout">
              <input type="hidden" name="csrf_token" value="{$csrf}">
              <button type="submit" class="link-button">Se déconnecter</button>
            </form>
          </header>

          <main>
            {$flashHtml}

            <section class="panel">
              <h2>Publier une application</h2>
              <form method="post" action="/admin.php?action=upload" enctype="multipart/form-data" id="upload-form">
                <input type="hidden" name="csrf_token" value="{$csrf}">

                <label for="slug">Nom du dossier</label>
                <input type="text" id="slug" name="slug" pattern="[a-z0-9]+(-[a-z0-9]+)*" maxlength="64" required
                       placeholder="mon-app-1">
                <p class="field-hint">Minuscules, chiffres et tirets uniquement.</p>

                <div class="dropzone" id="dropzone">
                  <p id="dropzone-label">Glissez un fichier .zip ici, ou cliquez pour le sélectionner.</p>
                  <input type="file" id="app_zip" name="app_zip" accept=".zip,application/zip" required>
                </div>

                <label class="checkbox-label">
                  <input type="checkbox" name="overwrite" value="1">
                  Écraser l'application si le dossier existe déjà
                </label>

                <p class="field-hint">Taille maximale : {$maxUploadMb} Mo.</p>

                <button type="submit">Publier</button>
              </form>
            </section>

            <section class="panel">
              <h2>Applications publiées</h2>
              {$appListHtml}
            </section>

            <section class="panel">
              <h2>Menu public</h2>
              <form method="post" action="/admin.php?action=regenerate">
                <input type="hidden" name="csrf_token" value="{$csrf}">
                <button type="submit">Régénérer manuellement le menu</button>
              </form>
            </section>
          </main>

          <script src="/assets/admin.js"></script>
        </body>
        </html>

        HTML;
}

function render_app_list(array $apps, string $csrf): string
{
    if ($apps === []) {
        return '<p class="empty-state">Aucune application publiée pour le moment.</p>';
    }

    $rows = '';
    foreach ($apps as $app) {
        $slug = htmlspecialchars($app['slug'], ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($app['title'], ENT_QUOTES, 'UTF-8');
        $icon = htmlspecialchars($app['icon'], ENT_QUOTES, 'UTF-8');
        $iconHtml = $icon !== '' ? "<span class=\"icon\">{$icon}</span> " : '';

        $rows .= <<<HTML
            <li class="app-row">
              <span class="app-row-title">{$iconHtml}{$title}</span>
              <a href="/apps/{$slug}/" target="_blank" rel="noopener" class="app-row-link">Ouvrir</a>
              <form method="post" action="/admin.php?action=delete" class="delete-form">
                <input type="hidden" name="csrf_token" value="{$csrf}">
                <input type="hidden" name="slug" value="{$slug}">
                <input type="hidden" name="confirm" value="0" class="confirm-field">
                <button type="submit" class="danger-button">Supprimer</button>
              </form>
            </li>

            HTML;
    }

    return "<ul class=\"app-list\">\n{$rows}</ul>";
}
