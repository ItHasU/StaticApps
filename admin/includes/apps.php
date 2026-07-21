<?php

declare(strict_types=1);

function apps_dir(): string
{
    return getenv('APPS_DIR') ?: '/data/apps';
}

function portal_dir(): string
{
    return getenv('PORTAL_DIR') ?: '/data/portal';
}

function portal_history_dir(): string
{
    return getenv('PORTAL_HISTORY_DIR') ?: '/data/portal/history';
}

/**
 * Scanne le dossier des apps et retourne la liste triée par titre.
 * Chaque entrée : ['slug' => string, 'title' => string, 'description' => string, 'icon' => string]
 */
function scan_apps(?string $appsDir = null): array
{
    $appsDir ??= apps_dir();

    if (!is_dir($appsDir)) {
        return [];
    }

    $apps = [];
    foreach (scandir($appsDir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $appsDir . '/' . $entry;
        if (!is_dir($path)) {
            continue;
        }
        if (!is_file($path . '/index.html')) {
            continue;
        }
        $apps[] = read_app_meta($path, $entry);
    }

    usort($apps, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));

    return $apps;
}

function read_app_meta(string $path, string $slug): array
{
    $title = $slug;
    $description = '';
    $icon = '';

    $metaPath = $path . '/meta.json';
    if (is_file($metaPath)) {
        $raw = file_get_contents($metaPath);
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (is_array($decoded)) {
            if (!empty($decoded['title']) && is_string($decoded['title'])) {
                $title = $decoded['title'];
            }
            if (!empty($decoded['description']) && is_string($decoded['description'])) {
                $description = $decoded['description'];
            }
            if (!empty($decoded['icon']) && is_string($decoded['icon'])) {
                $icon = $decoded['icon'];
            }
        }
    }

    return [
        'slug' => $slug,
        'title' => $title,
        'description' => $description,
        'icon' => $icon,
    ];
}

/**
 * Génère le HTML complet du menu à partir de la liste des apps.
 * Toutes les valeurs issues de meta.json (donc non fiables) sont échappées.
 */
function render_portal_html(array $apps): string
{
    $cards = '';
    foreach ($apps as $app) {
        $slug = htmlspecialchars($app['slug'], ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($app['title'], ENT_QUOTES, 'UTF-8');
        $description = htmlspecialchars($app['description'], ENT_QUOTES, 'UTF-8');
        $icon = htmlspecialchars($app['icon'], ENT_QUOTES, 'UTF-8');

        $iconHtml = $icon !== '' ? "<span class=\"icon\">{$icon}</span>" : '';
        $descriptionHtml = $description !== '' ? "<p>{$description}</p>" : '';

        $cards .= <<<HTML
            <a class="app-card" href="/apps/{$slug}/">
              {$iconHtml}
              <h2>{$title}</h2>
              {$descriptionHtml}
            </a>

        HTML;
    }

    $gridContent = $cards !== ''
        ? "<div class=\"app-grid\">\n{$cards}</div>"
        : '<p class="empty-state">Aucune application publiée pour le moment.</p>';

    return <<<HTML
        <!DOCTYPE html>
        <html lang="fr">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
          <title>Portail des applications</title>
          <link rel="stylesheet" href="/style.css">
        </head>
        <body>
          <header class="portal-header">
            <h1>Portail des applications</h1>
            <p>Sélectionnez une application pour l'ouvrir.</p>
          </header>
          <main>
            {$gridContent}
          </main>
          <footer class="portal-footer">
            <p>Généré automatiquement — ne pas éditer ce fichier à la main.</p>
          </footer>
        </body>
        </html>

        HTML;
}

/**
 * Sauvegarde l'index.html courant (s'il existe) dans history/ avec un
 * horodatage ISO (":" remplacés par "-" pour rester compatible filesystem).
 */
function archive_current_index(?string $portalDir = null, ?string $historyDir = null): ?string
{
    $portalDir ??= portal_dir();
    $historyDir ??= portal_history_dir();

    $currentIndex = $portalDir . '/index.html';
    if (!is_file($currentIndex)) {
        return null;
    }

    if (!is_dir($historyDir)) {
        mkdir($historyDir, 0755, true);
    }

    $timestamp = str_replace(':', '-', (new DateTimeImmutable('now'))->format('Y-m-d\TH-i-s'));
    $archivePath = $historyDir . "/index-{$timestamp}.html";
    copy($currentIndex, $archivePath);

    return $archivePath;
}

/**
 * Point d'entrée : régénère le menu public. Archive l'ancienne version,
 * puis écrit la nouvelle. Appelable manuellement ou après upload/suppression.
 */
function regenerate_portal_menu(
    ?string $appsDir = null,
    ?string $portalDir = null,
    ?string $historyDir = null
): array {
    $appsDir ??= apps_dir();
    $portalDir ??= portal_dir();
    $historyDir ??= portal_history_dir();

    if (!is_dir($portalDir)) {
        mkdir($portalDir, 0755, true);
    }

    archive_current_index($portalDir, $historyDir);

    $apps = scan_apps($appsDir);
    $html = render_portal_html($apps);

    file_put_contents($portalDir . '/index.html', $html);

    return $apps;
}
