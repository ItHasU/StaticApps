<?php

declare(strict_types=1);

function process_delete_request(): void
{
    $result = attempt_delete($_POST);
    redirect_to_dashboard($result['message'], !$result['success']);
}

/**
 * Logique complète de suppression, sans aucun effet de bord HTTP
 * (pas de header()/exit) — directement testable.
 */
function attempt_delete(array $post): array
{
    $slug = trim((string) ($post['slug'] ?? ''));

    if (!csrf_verify($post['csrf_token'] ?? null)) {
        return ['success' => false, 'message' => 'Jeton de sécurité invalide ou expiré.'];
    }

    if (($post['confirm'] ?? '') !== '1') {
        return ['success' => false, 'message' => 'Suppression annulée : confirmation manquante.'];
    }

    if (!is_valid_app_slug($slug)) {
        return ['success' => false, 'message' => 'Nom de dossier invalide.'];
    }

    $targetDir = apps_dir() . '/' . $slug;
    if (!is_dir($targetDir)) {
        return ['success' => false, 'message' => "L'application « {$slug} » n'existe pas."];
    }

    remove_directory_recursive($targetDir);
    regenerate_portal_menu();
    audit_log('delete_success', ['slug' => $slug]);

    return ['success' => true, 'message' => "Application « {$slug} » supprimée."];
}
