<?php

declare(strict_types=1);

/**
 * Logique de vérification du mot de passe, sans aucun effet de bord sur la
 * session ni sur la réponse HTTP — directement testable. Le seul effet de
 * bord toléré est la mise à jour du compteur de tentatives (nécessaire pour
 * que le rate limiting fonctionne).
 */
function attempt_login(string $password, ?string $csrfToken, string $identifier): array
{
    $remaining = login_lockout_remaining($identifier);
    if ($remaining > 0) {
        return ['success' => false, 'message' => "Trop de tentatives échouées. Réessayez dans {$remaining} secondes."];
    }

    if (!csrf_verify($csrfToken)) {
        return ['success' => false, 'message' => 'Requête invalide (jeton de sécurité manquant ou expiré). Réessayez.'];
    }

    $hash = getenv('ADMIN_PASSWORD_HASH') ?: '';
    if ($hash !== '' && password_verify($password, $hash)) {
        clear_login_attempts($identifier);
        audit_log('login_success');
        return ['success' => true, 'message' => ''];
    }

    register_failed_login($identifier);
    audit_log('login_failed');
    return ['success' => false, 'message' => 'Mot de passe incorrect.'];
}
