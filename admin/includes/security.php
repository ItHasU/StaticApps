<?php

declare(strict_types=1);

const SECURITY_STATE_DIR = '/data/.admin-state';
const LOGIN_ATTEMPTS_FILE = SECURITY_STATE_DIR . '/login_attempts.json';
const AUDIT_LOG_FILE = SECURITY_STATE_DIR . '/audit.log';
const RATE_LIMIT_FILE = SECURITY_STATE_DIR . '/rate_limits.json';

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_LOCKOUT_SECONDS = 300;
const SESSION_IDLE_TIMEOUT_SECONDS = 900;

function ensure_security_state_dir(): void
{
    if (!is_dir(SECURITY_STATE_DIR)) {
        mkdir(SECURITY_STATE_DIR, 0700, true);
    }
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secret = getenv('SESSION_SECRET') ?: '';
    session_name('admin_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    if (!empty($secret) && empty($_SESSION['secret_bound'])) {
        $_SESSION['secret_bound'] = hash('sha256', $secret);
    }

    enforce_idle_timeout();
}

function enforce_idle_timeout(): void
{
    $now = time();
    if (!empty($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT_SECONDS) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = $now;
}

function is_authenticated(): bool
{
    return !empty($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

function require_authentication(): void
{
    if (!is_authenticated()) {
        header('Location: /admin.php?action=login');
        exit;
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(?string $token): bool
{
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Nom de dossier d'app valide : lettres minuscules, chiffres, tirets,
 * pas de tiret en tête/fin, longueur raisonnable, aucun `.` (interdit
 * `..`, chemins absolus, extensions cachées).
 */
function is_valid_app_slug(string $slug): bool
{
    if ($slug === '' || strlen($slug) > 64) {
        return false;
    }
    return (bool) preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug);
}

function client_identifier(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function read_login_attempts(): array
{
    ensure_security_state_dir();
    if (!is_file(LOGIN_ATTEMPTS_FILE)) {
        return [];
    }
    $raw = file_get_contents(LOGIN_ATTEMPTS_FILE);
    $decoded = $raw === false ? null : json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function write_login_attempts(array $attempts): void
{
    ensure_security_state_dir();
    file_put_contents(LOGIN_ATTEMPTS_FILE, json_encode($attempts), LOCK_EX);
}

/**
 * Retourne le nombre de secondes restantes de blocage, ou 0 si l'IP peut
 * tenter une connexion.
 */
function login_lockout_remaining(string $identifier): int
{
    $attempts = read_login_attempts();
    $entry = $attempts[$identifier] ?? null;
    if ($entry === null) {
        return 0;
    }

    if ($entry['count'] >= LOGIN_MAX_ATTEMPTS) {
        $elapsed = time() - $entry['last_attempt'];
        $remaining = LOGIN_LOCKOUT_SECONDS - $elapsed;
        return max(0, $remaining);
    }

    return 0;
}

function register_failed_login(string $identifier): void
{
    $attempts = read_login_attempts();
    $entry = $attempts[$identifier] ?? ['count' => 0, 'last_attempt' => 0];

    if ((time() - $entry['last_attempt']) > LOGIN_LOCKOUT_SECONDS) {
        $entry['count'] = 0;
    }

    $entry['count'] += 1;
    $entry['last_attempt'] = time();
    $attempts[$identifier] = $entry;

    write_login_attempts($attempts);
}

function clear_login_attempts(string $identifier): void
{
    $attempts = read_login_attempts();
    unset($attempts[$identifier]);
    write_login_attempts($attempts);
}

/**
 * Limitation de débit générique par (bucket, identifiant), fenêtre glissante
 * stockée sur disque. Retourne false si la limite est atteinte. Enregistre
 * systématiquement le passage, qu'il soit autorisé ou non.
 */
function rate_limit_allow(string $bucket, string $identifier, int $maxHits, int $windowSeconds): bool
{
    ensure_security_state_dir();

    $data = [];
    if (is_file(RATE_LIMIT_FILE)) {
        $raw = file_get_contents(RATE_LIMIT_FILE);
        $decoded = $raw === false ? null : json_decode($raw, true);
        $data = is_array($decoded) ? $decoded : [];
    }

    $key = $bucket . ':' . $identifier;
    $now = time();
    $hits = array_values(array_filter(
        $data[$key] ?? [],
        static fn ($ts): bool => is_int($ts) && ($now - $ts) < $windowSeconds
    ));

    $allowed = count($hits) < $maxHits;
    $hits[] = $now;
    $data[$key] = $hits;

    file_put_contents(RATE_LIMIT_FILE, json_encode($data), LOCK_EX);

    return $allowed;
}

function remove_directory_recursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir((string) $item);
        } else {
            unlink((string) $item);
        }
    }

    rmdir($dir);
}

function redirect_to_dashboard(string $message, bool $isError = false): void
{
    $_SESSION['flash'] = ['message' => $message, 'error' => $isError];
    header('Location: /admin.php');
    exit;
}

function audit_log(string $event, array $context = []): void
{
    ensure_security_state_dir();
    $line = sprintf(
        '[%s] %s %s%s',
        (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
        $event,
        client_identifier(),
        $context !== [] ? ' ' . json_encode($context) : ''
    );
    file_put_contents(AUDIT_LOG_FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}
