<?php

declare(strict_types=1);

function process_delete_request(): void
{
    $slug = trim((string) ($_POST['slug'] ?? ''));

    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        redirect_to_dashboard('Jeton de sécurité invalide ou expiré.', true);
        return;
    }

    if (($_POST['confirm'] ?? '') !== '1') {
        redirect_to_dashboard('Suppression annulée : confirmation manquante.', true);
        return;
    }

    if (!is_valid_app_slug($slug)) {
        redirect_to_dashboard('Nom de dossier invalide.', true);
        return;
    }

    $targetDir = APPS_DIR . '/' . $slug;
    if (!is_dir($targetDir)) {
        redirect_to_dashboard("L'application « {$slug} » n'existe pas.", true);
        return;
    }

    remove_directory_recursive($targetDir);
    regenerate_portal_menu();
    audit_log('delete_success', ['slug' => $slug]);
    redirect_to_dashboard("Application « {$slug} » supprimée.");
}
